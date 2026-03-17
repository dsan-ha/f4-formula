<?php
namespace App\Middleware;

use App\Http\Request;
use App\Http\Response;
use App\Http\MiddlewareInterface;
use App\Base\SessionService;

class CsrfMiddleware implements MiddlewareInterface
{
    private SessionService $session;
    private array $except = [];
    private string $tokenKey = '_csrf_token';
    private string $headerName = 'X-CSRF-TOKEN';

    public function __construct(SessionService $session)
    {
        $this->session = $session;
    }

    /**
     * Exclude URIs from CSRF protection
     * @param array $except
     * @return $this
     */
    public function except(array $except): self
    {
        $this->except = $except;
        return $this;
    }

    /**
     * Set custom token key
     * @param string $key
     * @return $this
     */
    public function tokenKey(string $key): self
    {
        $this->tokenKey = $key;
        return $this;
    }

    /**
     * Set custom header name
     * @param string $name
     * @return $this
     */
    public function headerName(string $name): self
    {
        $this->headerName = $name;
        return $this;
    }

    public function __invoke(Request $req, Response $res, array $params, callable $next): Response
    {
        // Skip CSRF check for safe methods
        if (in_array($req->getMethod(), ['GET', 'HEAD', 'OPTIONS'])) {
            return $next($req, $res, $params);
        }

        // Skip if URI is in except list
        if ($this->shouldSkip($req)) {
            return $next($req, $res, $params);
        }

        // Verify CSRF token
        if (!$this->validateToken($req)) {
            return $res
                ->withStatus(419)
                ->withBody('Invalid CSRF token');
        }

        return $next($req, $res, $params);
    }

    private function shouldSkip(Request $req): bool
    {
        $path = $req->getPath();
        
        foreach ($this->except as $pattern) {
            $pattern = str_replace(['*', '?'], ['.*', '.'], preg_quote($pattern, '/'));
            if (preg_match('/^' . $pattern . '$/', $path)) {
                return true;
            }
        }
        
        return false;
    }

    private function validateToken(Request $req): bool
    {
        $token = $this->getTokenFromRequest($req);
        
        if (!$token) {
            return false;
        }
        
        return $this->session->validateCsrf($token);
    }

    private function getTokenFromRequest(Request $req): ?string
    {
        // Try header first
        $token = $req->getHeader($this->headerName);
        
        if ($token) {
            return $token;
        }
        
        // Try POST parameter
        return $req->post($this->tokenKey);
    }

    /**
     * Generate CSRF token
     * @return string
     */
    public function generateToken(): string
    {
        return $this->session->csrfToken(true);
    }

    /**
     * Get CSRF token
     * @return string
     */
    public function getToken(): string
    {
        return $this->session->csrfToken(false);
    }

    /**
     * Get token key for forms
     * @return string
     */
    public function getTokenKey(): string
    {
        return $this->tokenKey;
    }

    /**
     * Get header name for AJAX
     * @return string
     */
    public function getHeaderName(): string
    {
        return $this->headerName;
    }
}