<?php
namespace App\Utils\Security;

use App\Base\SessionService;

class CsrfTokenManager
{
    private SessionService $session;
    private string $tokenKey = '_csrf_token';
    private ?string $token = null;

    public function __construct(SessionService $session)
    {
        $this->session = $session;
    }

    /**
     * Generate or get CSRF token
     * @param bool $regenerate
     * @return string
     */
    public function token(bool $regenerate = false): string
    {
        if ($regenerate || empty($this->token)) {
            $this->token = $this->session->csrfToken($regenerate);
        }
        return $this->token;
    }

    /**
     * Verify CSRF token
     * @param string $token
     * @return bool
     */
    public function verify(string $token): bool
    {
        return $this->session->validateCsrf($token);
    }

    /**
     * Generate hidden input for forms
     * @return string
     */
    public function field(): string
    {
        $token = $this->token();
        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            $this->tokenKey,
            htmlspecialchars($token, ENT_QUOTES, 'UTF-8')
        );
    }

    /**
     * Get meta tag for AJAX requests
     * @return string
     */
    public function metaTag(): string
    {
        $token = $this->token();
        return sprintf(
            '<meta name="csrf-token" content="%s">',
            htmlspecialchars($token, ENT_QUOTES, 'UTF-8')
        );
    }

    /**
     * Get JavaScript snippet to set CSRF header
     * @return string
     */
    public function script(): string
    {
        $token = $this->token();
        $headerName = 'X-CSRF-TOKEN';
        
        return sprintf(
            '<script>
                document.addEventListener("DOMContentLoaded", function() {
                    var token = "%s";
                    var headerName = "%s";
                    
                    // Set token for fetch API
                    if (!window.fetchWrapper) {
                        window.fetchWrapper = function(url, options) {
                            options = options || {};
                            options.headers = options.headers || {};
                            options.headers[headerName] = token;
                            return fetch(url, options);
                        };
                    }
                    
                    // Set token for XMLHttpRequest
                    var originalOpen = XMLHttpRequest.prototype.open;
                    XMLHttpRequest.prototype.open = function() {
                        this.addEventListener("loadstart", function() {
                            this.setRequestHeader(headerName, token);
                        });
                        originalOpen.apply(this, arguments);
                    };
                });
            </script>',
            htmlspecialchars($token, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($headerName, ENT_QUOTES, 'UTF-8')
        );
    }

    /**
     * Set custom token key
     * @param string $key
     * @return $this
     */
    public function setTokenKey(string $key): self
    {
        $this->tokenKey = $key;
        return $this;
    }

    /**
     * Get token key
     * @return string
     */
    public function getTokenKey(): string
    {
        return $this->tokenKey;
    }
}