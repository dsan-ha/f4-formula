<?php
namespace App\Utils;

class CurlClient
{
    private string $baseUrl;

    // security settings
    private array $securityOptions = [
        'ssl_verify'        => true,           // включает CURLOPT_SSL_VERIFYPEER
        'verify_host'       => 2,              // CURLOPT_SSL_VERIFYHOST
        'ca_info'           => null,           // путь к ca-bundle, либо null
        'certinfo'          => false,          // CURLOPT_CERTINFO
        'follow_location'   => false,          // CURLOPT_FOLLOWLOCATION
        'max_redirects'     => 5,              // CURLOPT_MAXREDIRS
        'user_agent'        => 'F4 framework/1.0',    // по-умолчанию
        'encoding'          => '',             // поддержка gzip/deflate
        'ip_resolve'        => null,           // CURL_IPRESOLVE_V4 | CURL_IPRESOLVE_V6 | null
        'forbid_reuse'      => false,          // CURLOPT_FORBID_REUSE
        'fresh_connect'     => false,          // CURLOPT_FRESH_CONNECT
        'timeout'           => null,           // если нужно переопределить
        'connect_timeout'   => null,
        'cert_pin'          => null,           // массив ожидаемых fingerprint (sha256)
        'retry'             => 0,              // retry count (logika, не cURL)
    ];

    // proxy settings
    private bool $useProxy = false;
    private string $proxyDSN = 'socks5h://127.0.0.1:9050'; 
    private int $proxyType; // will be resolved in constructor

    public function __construct(string $baseUrl = '')
    {
        $this->baseUrl = rtrim($baseUrl, '/');

        if (defined('CURLPROXY_SOCKS5_HOSTNAME')) {
            $this->proxyType = CURLPROXY_SOCKS5_HOSTNAME;
        } elseif (defined('CURLPROXY_SOCKS5')) {
            $this->proxyType = CURLPROXY_SOCKS5;
        } else {
            $this->proxyType = 0;
        }
    }


    public function setSecurityOptions(array $opts): self
    {
        $this->securityOptions = array_merge($this->securityOptions, $opts);
        return $this;
    }

    // setters
    public function setUseProxy(bool $flag): self
    {
        $this->useProxy = $flag;
        return $this;
    }

    public function setProxyDSN(string $dsn): self
    {
        $this->proxyDSN = $dsn;
        return $this;
    }


    public function getProxyDSN(): string
    {
        return $this->proxyDSN;
    }

    private function buildUrl(string $path): string
    {
        if (str_starts_with($path, 'http')) {
            return $path;
        }
        return $this->baseUrl . '/' . ltrim($path, '/');
    }

    /**
     * Универсальный POST-запрос (JSON или multipart)
     * @param string $path
     * @param array|string $data
     * @param array $headers
     * @param array $options
     * @return array [$body, $httpCode, $err, $respHeaders]
     */
    public function post(string $path, $data = [], array $headers = [], array $options = []): array
    {
        $url = $this->buildUrl($path);
        $ch  = curl_init($url);

        $hasFile = $this->arrayHasCurlFile(is_array($data) ? $data : []);
        $httpHeaders = $headers ?: ['Accept: application/json'];

        if ($hasFile) {
            // multipart
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        } elseif (is_array($data)) {
            // JSON
            $body = json_encode($data, JSON_UNESCAPED_UNICODE);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            curl_setopt($ch, CURLOPT_POST, true);
            $httpHeaders[] = 'Content-Type: application/json; charset=utf-8';
        } else {
            // raw body (строка)
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            curl_setopt($ch, CURLOPT_POST, true);
        }

        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => $httpHeaders,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => $options['timeout'] ?? 180,
            CURLOPT_CONNECTTIMEOUT => $options['connect_timeout'] ?? 15
        ]);

        $useProxy = $this->useProxy;
        if (isset($options['use_proxy'])) {
            $useProxy = (bool)$options['use_proxy'];
        }
        if ($useProxy) {
            $this->applyProxyOptions($ch);
        }
        
        // curl security
        //$this->applySecurityOptions($ch, $options);

        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hdrSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) return [null, $code ?: 0, $err, []];

        $rawHeaders = substr($resp, 0, $hdrSize);
        $body       = substr($resp, $hdrSize);
        $respHeaders= $this->parseHeaders($rawHeaders);

        return [$body, $code, $err, $respHeaders];
    }

    /**
     * GET-запрос
     * @return array [$body, $httpCode, $err, $respHeaders]
     */
    public function get(string $path, array $headers = [], array $options = []): array
    {
        $url = $this->buildUrl($path);
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => $headers ?: ['Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => $options['timeout'] ?? 60,
        ]);

        $useProxy = $this->useProxy;
        if (isset($options['use_proxy'])) {
            $useProxy = (bool)$options['use_proxy'];
        }
        if ($useProxy) {
            $this->applyProxyOptions($ch);
        }

        // curl security
        //$this->applySecurityOptions($ch, $options);

        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hdrSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) return [null, $code ?: 0, $err, []];

        $rawHeaders = substr($resp, 0, $hdrSize);
        $body       = substr($resp, $hdrSize);
        $respHeaders= $this->parseHeaders($rawHeaders);

        return [$body, $code, $err, $respHeaders];
    }

    private function parseHeaders(string $raw): array
    {
        $headers = [];
        foreach (preg_split("/\r\n|\n|\r/", $raw) as $line) {
            $p = strpos($line, ':');
            if ($p !== false) {
                $k = strtolower(trim(substr($line, 0, $p)));
                $v = trim(substr($line, $p + 1));
                $headers[$k] = $v;
            }
        }
        return $headers;
    }

    private function arrayHasCurlFile(array $data): bool
    {
        foreach ($data as $v) {
            if ($v instanceof \CURLFile) return true;
            if (is_array($v) && $this->arrayHasCurlFile($v)) return true;
        }
        return false;
    }

    /**
     * Применить опции прокси к ресурсу curl
     */
    private function applyProxyOptions($ch): void
    {
        $parts = @parse_url($this->proxyDSN);
        if ($parts !== false && isset($parts['host'])) {
            $proxyHost = $parts['host'] ?? '127.0.0.1';
            $proxyPort = isset($parts['port']) ? (int)$parts['port'] : 80;
            $proxyScheme = $parts['scheme'] ?? 'http';

            // set proxyType according to scheme when possible
            $scheme = strtolower($proxyScheme);
            if (strpos($scheme, 'socks5') !== false) {
                if (defined('CURLPROXY_SOCKS5_HOSTNAME')) {
                    $this->proxyType = CURLPROXY_SOCKS5_HOSTNAME;
                } elseif (defined('CURLPROXY_SOCKS5')) {
                    $this->proxyType = CURLPROXY_SOCKS5;
                }
            } elseif ($scheme === 'http') {
                if (defined('CURLPROXY_HTTP')) {
                    $this->proxyType = CURLPROXY_HTTP;
                }
            }
            // build proxy string. using scheme (socks5h://...) forces remote DNS if supported
            $proxyString = "{$proxyHost}:{$proxyPort}";
            if (!empty($proxyScheme)) {
                $proxyString = "{$proxyHost}:{$proxyPort}";
            }

            if (!empty($parts['user']) || !empty($parts['pass'])) {
                $user = $parts['user'] ?? '';
                $pass = $parts['pass'] ?? '';
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, $user . ':' . $pass);
            }

            curl_setopt($ch, CURLOPT_PROXY, $proxyString);

            // если известно значение типа прокси — попробуем его поставить
            if (!empty($this->proxyType) && is_int($this->proxyType) && $this->proxyType !== 0) {
                curl_setopt($ch, CURLOPT_PROXYTYPE, $this->proxyType);
            }
        }
    }
    
    /**
     * $options - те опции, что переданы в вызове get()/post() (чтобы можно было переопределить)
     */
    private function applySecurityOptions($ch, array $options = []): void
    {
        $sec = array_merge($this->securityOptions, $options);

        // SSL verify
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $sec['ssl_verify'] ? 1 : 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $sec['verify_host'] ? 2 : 0);

        if (!empty($sec['ca_info'])) {
            curl_setopt($ch, CURLOPT_CAINFO, $sec['ca_info']);
        }

        if (!empty($sec['certinfo'])) {
            // Включаем сбор info о сертификате (если сборка curl поддерживает)
            curl_setopt($ch, CURLOPT_CERTINFO, 1);
        }

        // redirects
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, $sec['follow_location'] ? 1 : 0);
        curl_setopt($ch, CURLOPT_MAXREDIRS, (int)$sec['max_redirects']);

        // headers / ua / encoding
        curl_setopt($ch, CURLOPT_USERAGENT, $sec['user_agent']);
        curl_setopt($ch, CURLOPT_ENCODING, $sec['encoding']);

        // ip resolve
        if (!empty($sec['ip_resolve'])) {
            curl_setopt($ch, CURLOPT_IPRESOLVE, $sec['ip_resolve']); // e.g. CURL_IPRESOLVE_V4
        }

        if (!empty($sec['forbid_reuse'])) {
            curl_setopt($ch, CURLOPT_FORBID_REUSE, 1);
        }
        if (!empty($sec['fresh_connect'])) {
            curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
        }

        // timeouts override (если заданы в securityOptions)
        if (!empty($sec['timeout'])) {
            curl_setopt($ch, CURLOPT_TIMEOUT, (int)$sec['timeout']);
        }
        if (!empty($sec['connect_timeout'])) {
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, (int)$sec['connect_timeout']);
        }
    }
}