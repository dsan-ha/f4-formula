<?php
declare(strict_types=1);

namespace App\Base;

use App\Base\Magic;

/**
 * SessionService — инкапсулирует управление сессией.
 * Наследуется от Magic: можно хранить настройки (timeout, имя, и пр.) в уле.
 */
final class SessionService extends Magic
{
    /**
     * Гарантирует активную сессию.
     */
    private function ensureStarted(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            // Настройки из “улья” при желании:
            // if ($name = $this->get('session.name'))   session_name((string)$name);
            // if ($sid  = $this->get('session.id'))     session_id((string)$sid);
            // if ($save = $this->get('session.save_path')) session_save_path((string)$save);
            @session_start();
        }
    }

    public function isStarted(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE;
    }

    public function start(): void
    {
        $this->ensureStarted();
    }

    public function id(): string
    {
        $this->ensureStarted();
        return session_id();
    }

    public function regenerateId(bool $deleteOld = true): void
    {
        $this->ensureStarted();
        session_regenerate_id($deleteOld);
    }

    public function get(string $key = '', $def = null) : mixed
    {
        $this->ensureStarted();
        if (empty($key)) {
            return $_SESSION;
        }
        return $_SESSION[$key] ?? $def;
    }
    
    public function exists(string $key) : bool { return $this->has($key); }

    public function has(string $key): bool
    {
        $this->ensureStarted();
        return array_key_exists($key, $_SESSION);
    }

    public function set(string $key, $value) : void
    {
        $this->ensureStarted();
        $_SESSION[$key] = $value;
        // при необходимости можно вынести write_close в отдельный метод/мидлварь
    }


    /**
     * Очистить всю сессию или удалить ключ (и уничтожить идентификатор).
     */
    public function clear(string $key = '') : void //key игнорируется но нужен для повтора в Magic
    {
        $this->ensureStarted();
        if(!empty($key)){
            unset($_SESSION[$key]);
        } else {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                // Сброс cookie сессии
                setcookie(session_name(), '', [
                    'expires'  => 1,
                    'path'     => $params['path'] ?? '/',
                    'domain'   => $params['domain'] ?? '',
                    'secure'   => (bool)($params['secure'] ?? false),
                    'httponly' => (bool)($params['httponly'] ?? true),
                    'samesite' => isset($params['samesite']) ? (string)$params['samesite'] : 'Lax',
                ]);
            }
            session_destroy();
        }
        
    }

    /**
     * Вернуть все данные сессии (копия).
     */
    public function all(): array
    {
        $this->ensureStarted();
        return $_SESSION;
    }

    /**
     * pull: получить значение и удалить ключ (flash-поведение).
     */
    public function pull(string $key, mixed $def = null): mixed
    {
        $this->ensureStarted();
        $val = $_SESSION[$key] ?? $def;
        unset($_SESSION[$key]);
        return $val;
    }

    /**
     * Flash-значение: записать и считать один раз.
     */
    public function flash(string $key, mixed $value): void
    {
        $this->ensureStarted();
        $_SESSION['_flash'][$key] = $value;
    }

    /**
     * Получить flash-значение (и удалить его).
     */
    public function getFlash(string $key, mixed $def = null): mixed
    {
        $this->ensureStarted();
        $val = $_SESSION['_flash'][$key] ?? $def;
        if (array_key_exists($key, $_SESSION['_flash'] ?? [])) {
            unset($_SESSION['_flash'][$key]);
        }
        return $val;
    }

    /**
     * Простейший CSRF-токен (по месту). Можно вынести в отдельный сервис.
     */
    public function csrfToken(bool $regenerateIfMissing = true): string
    {
        $this->ensureStarted();
        if (empty($_SESSION['_csrf'])) {
            if (!$regenerateIfMissing) {
                return '';
            }
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return (string)$_SESSION['_csrf'];
    }

    public function validateCsrf(string $token): bool
    {
        $this->ensureStarted();
        return hash_equals((string)($_SESSION['_csrf'] ?? ''), $token);
    }
}
