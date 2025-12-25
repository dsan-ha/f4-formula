<?php
declare(strict_types=1);

namespace App\Http;

/**
 * Синглтон-окружение: нормализация $_SERVER, заголовков, CLI-эмуляция,
 * единичное чтение php://input, вычисление base/port/scheme + фабрика Request.
 *
 * USE:
 *   Environment::init(function(Environment $env) {
 *       $env->setTrustedProxies(['127.0.0.1','10.0.0.0/8'])
 *           ->setTrustedHosts(['^fff\\.local$']);
 *   });
 *   $req = Environment::instance()->getRequest();
 */
final class Environment
{
    private static ?self $instance = null;

    private array   $server = [];
    private array   $headers = [];      // lowercased
    private ?string $rawBody = null;
    protected mixed $body;
    private bool    $cli = false;

    // derived
    private string $base = '/';
    private string $scheme = 'http';
    private int    $port = 80;

    // trust config (минимально)
    private array $trustedProxies = []; // IPv4/CIDR
    private array $trustedHosts   = []; // regex/домены
    private bool  $honorForwardedHeader = true;
    private bool  $honorXForwarded      = true;

    private ?Request $request = null;

    private function __construct() {}

    /** Инициализация один раз. */
    public static function init(?callable $configure = null, bool $readBody = true): self
    {
        if (self::$instance) {
            throw new \LogicException('Environment already initialized');
        }
        $env = new self();
        $env->captureFromGlobals($readBody);
        if ($configure) {
            $configure($env);
        }
        return self::$instance = $env;
    }

    public static function instance(): self
    {
        if (!self::$instance) {
            throw new \LogicException('Environment is not initialized. Call Environment::init() first.');
        }
        return self::$instance;
    }

    /* ---------------- public API ---------------- */

    public function setTrustedProxies(array $cidrs): self { $this->trustedProxies = array_values($cidrs); return $this; }
    public function setTrustedHosts(array $patterns): self { $this->trustedHosts   = array_values($patterns); return $this; }
    public function honorForwarded(bool $forwarded = true, bool $xForwarded = true): self
    { $this->honorForwardedHeader = $forwarded; $this->honorXForwarded = $xForwarded; return $this; }

    public function server(): array  { return $this->server; }
    public function headers(): array { return $this->headers; }
    public function rawBody(): ?string { return $this->rawBody; }
    public function isCli(): bool { return $this->cli; }
    public function base(): string { return $this->base; }
    public function scheme(): string { return $this->scheme; }
    public function port(): int { return $this->port; }

    /** Параметры cookie для сессии (без старта сессии). */
    public function sessionCookieParams(): array
    {
        $serverName = $this->server['SERVER_NAME'] ?? 'localhost';
        $domain = (is_int(strpos($serverName, '.')) && !filter_var($serverName, FILTER_VALIDATE_IP)) ? $serverName : '';
        return [
            'lifetime' => 0,
            'path'     => $this->base ?: '/',
            'domain'   => $domain,
            'secure'   => ($this->scheme === 'https'),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }

    /** Ленивое построение Request из снимка. */
    public function getRequest(): Request
    {
        if ($this->request) return $this->request;

        $srv    = $this->server;
        $h      = $this->headers;
        $remote = $srv['REMOTE_ADDR'] ?? '0.0.0.0';
        $trusted = $this->isTrustedIp($remote);

        // Метод (+ override уже учтен в capture)
        $method = strtoupper($srv['REQUEST_METHOD'] ?? 'GET');
        if (!in_array($method, ['GET','POST','PUT','PATCH','DELETE','HEAD','OPTIONS'], true)) {
            $method = 'GET';
        }

        // Host (+ валидируем)
        $host = $h['host'] ?? ($srv['SERVER_NAME'] ?? 'localhost');
        $f = null;
        if ($trusted) {
            if ($this->honorForwardedHeader && isset($h['forwarded'])) {
                $f = self::parseForwarded($h['forwarded']);
                $host = $f['host'] ?? $host;
            } elseif ($this->honorXForwarded && isset($h['x-forwarded-host'])) {
                $host = trim(explode(',', $h['x-forwarded-host'])[0]);
            }
        }
        [$host, $portFromHost] = self::splitHostPort($host);
        $host = $this->sanitizeHost($host);

        // Scheme
        $isHttps = ($this->scheme === 'https');
        if ($trusted) {
            if ($this->honorForwardedHeader && $f) {
                $isHttps = (isset($f['proto']) && strtolower($f['proto']) === 'https') ?: $isHttps;
            } elseif ($this->honorXForwarded && isset($h['x-forwarded-proto'])) {
                $isHttps = (stripos($h['x-forwarded-proto'], 'https') === 0) ?: $isHttps;
            }
        }
        $scheme = $isHttps ? 'https' : 'http';

        // Port
        $port = $portFromHost ?? $this->port;
        if ($trusted) {
            if ($this->honorForwardedHeader && $f && isset($f['host']) && str_contains($f['host'], ':')) {
                $port = (int)substr(strrchr($f['host'], ':'), 1);
            } elseif ($this->honorXForwarded && isset($h['x-forwarded-port'])) {
                $port = (int)trim(explode(',', $h['x-forwarded-port'])[0]);
            }
        }
        if ($port <= 0) $port = $isHttps ? 443 : 80;

        // Путь/Query
        $requestUri = $srv['REQUEST_URI'] ?? '/';
        $path  = parse_url($requestUri, PHP_URL_PATH)  ?: '/';
        $query = parse_url($requestUri, PHP_URL_QUERY) ?: '';

        // Клиентский IP
        $clientIp = $this->resolveClientIp($remote, $h['x-forwarded-for'] ?? null, $trusted);

        return $this->request = new Request(
            $method,
            $scheme,
            $host,
            $port,
            $path,
            $query,
            $this->headers,
            $this->server,
            $this->body,
            $clientIp,
            $this->cli
        );
    }

    /* ---------------- capture & helpers ---------------- */

    private function captureFromGlobals(bool $readBody): void
    {
        $this->cli = (PHP_SAPI === 'cli');

        // SERVER_NAME по умолчанию
        if (!isset($_SERVER['SERVER_NAME']) || $_SERVER['SERVER_NAME'] === '') {
            $_SERVER['SERVER_NAME'] = gethostname() ?: 'localhost';
        }

        // CLI эмуляция минимального запроса (оставляем как было)
        if ($this->cli) {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            if (!isset($_SERVER['argv'][1])) {
                ++$_SERVER['argc'];
                $_SERVER['argv'][1] = '/';
            }
            $req = '';
            $query = '';
            if (substr($_SERVER['argv'][1], 0, 1) === '/') {
                $req   = $_SERVER['argv'][1];
                $query = parse_url($req, PHP_URL_QUERY);
            } else {
                foreach ($_SERVER['argv'] as $i => $arg) {
                    if (!$i) continue;
                    if (preg_match('/^\-(\-)?(\w+)(?:\=(.*))?$/', $arg, $m)) {
                        foreach ($m[1] ? [$m[2]] : str_split($m[2]) as $k) {
                            $query .= ($query ? '&' : '') . urlencode($k) . '=';
                        }
                        if (isset($m[3])) $query .= urlencode($m[3]);
                    } else {
                        $req .= '/' . $arg;
                    }
                }
                if (!$req) $req = '/';
                if ($query) $req .= '?' . $query;
            }
            $_SERVER['REQUEST_URI'] = $req;
            parse_str($query ?: '', $GLOBALS['_GET']);
        }

        // Заголовки (сбор + санитизация)
        $headers = $this->collectHeaders($_SERVER);
        $headers = $this->sanitizeHeaders($headers);

        // Override метода
        if (!empty($headers['x-http-method-override'])) {
            $_SERVER['REQUEST_METHOD'] = strtoupper($headers['x-http-method-override']);
        } elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['_method'])) {
            $_SERVER['REQUEST_METHOD'] = strtoupper((string)$_POST['_method']);
        }

        // Scheme
        $this->scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';

        // Apache VirtualDocumentRoot fix
        if (function_exists('apache_setenv')) {
            $_SERVER['DOCUMENT_ROOT'] = str_replace($_SERVER['SCRIPT_NAME'], '', $_SERVER['SCRIPT_FILENAME']);
            apache_setenv('DOCUMENT_ROOT', $_SERVER['DOCUMENT_ROOT']);
        }
        $_SERVER['DOCUMENT_ROOT'] = realpath($_SERVER['DOCUMENT_ROOT']) ?: $_SERVER['DOCUMENT_ROOT'];

        // BASE
        $this->base = $this->cli ? '/' : rtrim($this->fixSlashes(dirname($_SERVER['SCRIPT_NAME'])), '/');

        // REQUEST_URI нормализация (path?query#fragment)
        $rawUri = $_SERVER['REQUEST_URI'] ?? '/';
        $uri = parse_url((preg_match('/^\w+:\/\//', $rawUri) ? '' : ($this->scheme.'://'.$_SERVER['SERVER_NAME'])) . $rawUri);
        $_SERVER['REQUEST_URI'] =
            ($uri['path'] ?? '/') .
            (isset($uri['query']) ? '?'.$uri['query'] : '') .
            (isset($uri['fragment']) ? '#'.$uri['fragment'] : '');

        // Порт (без учёта XFP — доверие делаем в Request)
        $this->port = !empty($_SERVER['SERVER_PORT'])
            ? (int)$_SERVER['SERVER_PORT']
            : ($this->scheme === 'https' ? 443 : 80);

        // Единственное чтение тела
        $this->rawBody = $readBody ? file_get_contents('php://input') : null;

        // НОВОЕ: нормализованное body (строка или массив)
        $contentType = $headers['content-type'] ?? ($_SERVER['CONTENT_TYPE'] ?? null);
        if ($readBody && $this->rawBody !== null && stripos((string)$contentType, 'application/json') !== false) {
            $this->body = self::decodeJsonSecure($this->rawBody);
        } else {
            $this->body = $this->rawBody;
        }

        // Зафиксировать снимки
        $this->server  = $_SERVER;
        $this->headers = $headers;
    }


    private function collectHeaders(array $server): array
    {
        $out = [];
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $key => $val) {
                $out[strtolower($key)] = $val;
            }
        } else {
            if (isset($server['CONTENT_LENGTH'])) $out['content-length'] = $server['CONTENT_LENGTH'];
            if (isset($server['CONTENT_TYPE']))   $out['content-type']   = $server['CONTENT_TYPE'];
            foreach (array_keys($server) as $k) {
                if (strncmp($k, 'HTTP_', 5) === 0) {
                    $name = strtolower(strtr(substr($k, 5), '_', '-'));
                    $out[$name] = $server[$k];
                }
            }
        }
        return $out;
    }

    private function sanitizeHeaders(array $headers): array
    {
        $clean = [];
        foreach ($headers as $k => $v) {
            $vv = trim(str_replace(["\r", "\n"], '', (string)$v));
            $clean[strtolower($k)] = $vv;
        }
        return $clean;
    }

    private function httpKey(string $name): string
    {
        return 'HTTP_' . strtoupper(strtr($name, '-', '_'));
    }

    private function fixSlashes(string $p): string
    {
        return str_replace('\\', '/', $p);
    }

    private static function parseForwarded(string $value): array
    {
        $out = [];
        foreach (explode(';', strtolower($value)) as $part) {
            $kv = array_map('trim', explode('=', $part, 2));
            if (count($kv) === 2) {
                [$k, $v] = $kv;
                $v = trim($v, "\"'");
                if (in_array($k, ['proto','host','for'], true)) $out[$k] = $v;
            }
        }
        return $out;
    }

    private static function splitHostPort(string $host): array
    {
        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            $pos = strrpos($host, ':');
            $h = substr($host, 0, $pos);
            $p = (int)substr($host, $pos + 1);
            return [$h, $p > 0 ? $p : null];
        }
        return [$host, null];
    }

    private function sanitizeHost(string $host): string
    {
        $host = trim(preg_replace('/[\r\n]/', '', $host));
        if (!preg_match('/^[a-z0-9\.\-]+$/i', $host)) {
            $host = 'localhost';
        }
        if ($this->trustedHosts) {
            $ok = false;
            foreach ($this->trustedHosts as $rx) {
                if (@preg_match('/'.$rx.'/i', $host)) { $ok = true; break; }
            }
            if (!$ok) $host = 'localhost';
        }
        return $host;
    }

    private function isTrustedIp(string $ip): bool
    {
        if (!$this->trustedProxies) return false;
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return false; // упрощённо для IPv4
        $lip = ip2long($ip);
        foreach ($this->trustedProxies as $cidr) {
            if (strpos($cidr, '/') === false) {
                if ($ip === $cidr) return true;
            } else {
                [$net, $bits] = explode('/', $cidr, 2);
                if (!filter_var($net, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) continue;
                $mask = -1 << (32 - (int)$bits);
                if ((ip2long($net) & $mask) === ($lip & $mask)) return true;
            }
        }
        return false;
    }

    private function resolveClientIp(string $remote, ?string $xff, bool $trustedProxy): string
    {
        if (!$trustedProxy || !$xff) return $remote;
        foreach (explode(',', $xff) as $part) {
            $ip = trim($part);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
        return $remote;
    }

    private static function decodeJsonSecure(string $json, int $depth = 512, int $options = 0): array
    {
        try {
            if ($json === '') {
                throw new \RuntimeException('Empty JSON string');
            }
            if ($depth < 1) {
                throw new \RuntimeException('Depth must be greater than zero');
            }

            if (self::containsMaliciousContent($json)) {
                throw new \RuntimeException('Potentially dangerous JSON content detected');
            }

            $data = json_decode(
                $json,
                true,
                $depth,
                $options | JSON_THROW_ON_ERROR | JSON_OBJECT_AS_ARRAY
            );

            if (self::containsMaliciousStructures($data)) {
                throw new \RuntimeException('Potentially dangerous data structures detected');
            }

            if (!is_array($data)) {
                // JSON body у нас всегда массив
                throw new \RuntimeException('JSON payload must decode to array');
            }

            return $data;

        } catch (\Throwable $e) {
            // логирование по флагу из конфига
            self::logSuspiciousJson($json, $e->getMessage());
            // дальше пробрасываем как 400 на уровне error handler (или Router)
            throw $e;
        }
    }

    private static function containsMaliciousContent(string $json): bool
    {
        if (preg_match('/"\s*:\s*["\']\s*\+?\s*[a-z0-9_]+\s*\(\s*["\']/i', $json)) return true;
        if (preg_match('/"[^"]{10000,}"/', $json)) return true;
        return false;
    }

    private static function containsMaliciousStructures(mixed $data): bool
    {
        // перенос 1-в-1 из JsonHandler【turn4file2†L50-L76】
        if (is_array($data) || is_object($data)) {
            foreach ($data as $key => $value) {
                if (is_string($key) && preg_match('/^(on|javascript|vbscript|data):/i', $key)) {
                    return true;
                }
                if (self::containsMaliciousStructures($value)) {
                    return true;
                }
            }
        }

        if (is_string($data) && (
            preg_match('/<(script|iframe|frame|object|embed)/i', $data) ||
            preg_match('/(on\w+=|javascript:|data:text\/html|<script\b[^>]*>)/i', $data)
        )) {
            return true;
        }

        return false;
    }

    private static function logSuspiciousJson(string $raw, string $reason): void
    {
        try {
            $f4 = \App\F4::instance();
            $enabled = (bool)$f4->get('log.json_on');
            if (!$enabled) return;

            $file = (string)$f4->get('log.json_log');
            if ($file === '') return;

            $line = json_encode([
                'time'   => date('c'),
                'ip'     => $_SERVER['REMOTE_ADDR'] ?? null,
                'reason' => $reason,
                'raw'    => $raw,
            ], JSON_UNESCAPED_UNICODE);

            // простой append
            @file_put_contents($file, $line . PHP_EOL, FILE_APPEND);

        } catch (\Throwable $e) {
            // логирование не должно валить запрос
        }
    }

}
