<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Единственная конечная точка преобразования framework/runtime ошибок в HTTP Response.
 *
 * В штатном режиме экземпляр живёт в DI. До сборки контейнера тот же экземпляр
 * доступен через static bootstrap()/instance() и обслуживает глобальные PHP handlers.
 */
final class ErrorHandler
{
    private static ?self $instance = null;

    private ?\Throwable $lastError = null;
    private ?Response $lastResponse = null;
    private ?int $lastStatus = null;
    private bool $handling = false;
    private bool $registered = false;
    private bool $debug = false;

    public function __construct(
        private Environment $environment
    ) {
        self::$instance ??= $this;
    }

    /**
     * Early/bootstrap mode before PHP-DI is ready.
     * The returned object must later be registered in DI as the same instance.
     */
    public static function bootstrap(Environment $environment): self
    {
        if (self::$instance === null) {
            self::$instance = new self($environment);
        }

        return self::$instance;
    }

    public static function instance(): self
    {
        return self::$instance ??= new self(Environment::instance());
    }

    /** Static emergency wrapper for code running before DI is available. */
    public static function emergency(\Throwable $error): never
    {
        self::instance()->terminate($error);
    }

    /**
     * Install the PHP-level safety net. Normal HTTP errors are still handled by Router.
     */
    public function setDebug(bool $debug): self
    {
        $this->debug = $debug;
        return $this;
    }

    public function register(): self
    {
        if ($this->registered) {
            return $this;
        }

        set_error_handler(function (int $level, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $level)) {
                return false;
            }

            throw new \ErrorException($message, 0, $level, $file, $line);
        });

        set_exception_handler(function (\Throwable $error): void {
            $this->terminate($error);
        });

        register_shutdown_function(function (): void {
            $error = error_get_last();
            if (!$error || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                return;
            }

            $this->terminate(new \ErrorException(
                (string)$error['message'],
                0,
                (int)$error['type'],
                (string)$error['file'],
                (int)$error['line']
            ), false);
        });

        $this->registered = true;
        return $this;
    }

    /**
     * Convert an unhandled Throwable into a response. Does not send or terminate.
     */
    public function handle(\Throwable $error, ?Response $response = null): Response
    {
        if ($this->handling) {
            return $this->fallbackResponse(500, 'Internal Server Error', $response);
        }

        $this->handling = true;
        try {
            $this->lastError = $error;
            $this->logThrowable($error);

            if ($this->debug && !$this->wantsJson($response ?? new Response())) {
                return $this->debugResponse($error, $response);
            }

            $message = $this->debug
                ? $error::class . ': ' . $error->getMessage()
                : 'Internal Server Error';

            return $this->respond(500, $message, $response);
        } finally {
            $this->handling = false;
        }
    }

    /**
     * Convert an expected HTTP routing/framework condition into a response.
     */
    public function http(int $status, string $message = '', ?Response $response = null): Response
    {
        $message = trim($message);
        if ($message === '') {
            $message = Response::reasonPhrase($status) ?: 'HTTP Error';
        }

        return $this->respond($status, $message, $response);
    }

    /**
     * Global PHP safety-net path. This is the only method allowed to send and exit.
     */
    public function terminate(\Throwable $error, bool $exit = true): void
    {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        $response = $this->handle($error);
        if (!$response->isSent()) {
            $response->send($this->environment->isCli());
        }

        if ($exit) {
            exit(1);
        }
    }

    public function lastError(): ?\Throwable
    {
        return $this->lastError;
    }

    public function lastResponse(): ?Response
    {
        return $this->lastResponse;
    }

    public function lastStatus(): ?int
    {
        return $this->lastStatus;
    }

    public function hasError(): bool
    {
        return $this->lastError !== null || ($this->lastStatus !== null && $this->lastStatus >= 400);
    }

    public function clear(): void
    {
        $this->lastError = null;
        $this->lastResponse = null;
        $this->lastStatus = null;
    }

    private function respond(int $status, string $message, ?Response $response): Response
    {
        $response ??= new Response();

        if ($this->wantsJson($response)) {
            $response = $this->jsonResponse($response, $status, $message);
        } else {
            $response = $this->htmlResponse($response, $status, $message);
        }

        $this->lastStatus = $status;
        $this->lastResponse = $response;

        return $response;
    }

    private function wantsJson(Response $response): bool
    {
        $responseType = strtolower($response->getHeaderLine('Content-Type'));
        if (str_contains($responseType, 'application/json')) {
            return true;
        }

        $request = $this->environment->getRequest();
        $accept = strtolower((string)$request->getHeader('Accept', ''));
        $contentType = strtolower((string)$request->getHeader('Content-Type', ''));

        return str_contains($accept, 'application/json')
            || str_contains($contentType, 'application/json')
            || $request->isAjax();
    }


    private function debugResponse(\Throwable $error, ?Response $response): Response
    {
        $response ??= new Response();
        $title = $error::class;
        $message = $error->getMessage();
        $trace = $error->getTraceAsString();

        $body = '<!DOCTYPE html><html><head><meta charset="utf-8">'
            . '<title>500 ' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</title>'
            . '<style>body{font-family:monospace;padding:24px;line-height:1.45}pre{white-space:pre-wrap}</style>'
            . '</head><body><h1>500 ' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h1>'
            . '<p>' . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
            . '<p>' . htmlspecialchars($error->getFile(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ':' . $error->getLine() . '</p>'
            . '<pre>' . htmlspecialchars($trace, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre></body></html>';

        $response = $response
            ->withStatus(500)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($body);

        $this->lastStatus = 500;
        return $this->lastResponse = $response;
    }

    private function jsonResponse(Response $response, int $status, string $message): Response
    {
        $response = $response
            ->error($message)
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8');

        return $response->withBody((string)json_encode(
            $response->makeBody(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
    }

    private function htmlResponse(Response $response, int $status, string $message): Response
    {
        $body = $this->renderEmergencyHtml($status, $message);

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($body);
    }

    private function renderEmergencyHtml(int $status, string $message): string
    {
        $file = defined('SITE_ROOT')
            ? rtrim((string)SITE_ROOT, '/\\') . '/lib/ui/error/' . $status . '.php'
            : '';

        if ($file !== '' && is_file($file)) {
            ob_start();
            try {
                $errorStatus = $status;
                $errorMessage = $message;
                require $file;
                return (string)ob_get_clean();
            } catch (\Throwable $renderError) {
                @ob_end_clean();
                $this->logThrowable($renderError);
            }
        }

        $reason = Response::reasonPhrase($status) ?: 'Error';
        $safeReason = htmlspecialchars($reason, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeMessage = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<!DOCTYPE html><html><head><meta charset="utf-8">'
            . '<title>' . $status . ' ' . $safeReason . '</title></head><body>'
            . '<h1>' . $status . ' ' . $safeReason . '</h1>'
            . ($safeMessage !== '' && $safeMessage !== $safeReason ? '<p>' . $safeMessage . '</p>' : '')
            . '</body></html>';
    }

    private function fallbackResponse(int $status, string $message, ?Response $response): Response
    {
        $response ??= new Response();
        $this->lastStatus = $status;

        if ($this->environment->isCli()) {
            $response = $response->withStatus($status)->withBody($status . ' ' . $message . PHP_EOL);
        } else {
            $response = $response
                ->withStatus($status)
                ->withHeader('Content-Type', 'text/plain; charset=utf-8')
                ->withBody($status . ' ' . $message);
        }

        return $this->lastResponse = $response;
    }

    private function logThrowable(\Throwable $error): void
    {
        @error_log(sprintf(
            '[%s] %s in %s:%d%s%s',
            $error::class,
            $error->getMessage(),
            $error->getFile(),
            $error->getLine(),
            PHP_EOL,
            $error->getTraceAsString()
        ));
    }
}
