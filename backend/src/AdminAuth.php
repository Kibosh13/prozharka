<?php

declare(strict_types=1);

namespace Prozharka;

use RuntimeException;

final class AdminAuth
{
    public function __construct(private readonly string $storageRoot)
    {
    }

    public function start(): void
    {
        session_name('prozharka_editor');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_set_cookie_params([
            'lifetime' => 0, 'path' => '/admin/',
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true, 'samesite' => 'Strict',
        ]);
        session_start();
        if (!isset($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
    }

    public function csrf(): string
    {
        return (string) ($_SESSION['csrf'] ?? '');
    }

    public function checkCsrf(): void
    {
        $actual = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf'] ?? '');
        if ($actual === '' || !hash_equals($this->csrf(), $actual)) {
            throw new RuntimeException('Сессия устарела. Обновите страницу и повторите действие.');
        }
    }

    public function authenticated(): bool
    {
        $credentials = $this->credentials();
        $expected = hash('sha256', (string) ($credentials['password_hash'] ?? ''));
        return isset($_SESSION['admin'], $_SESSION['auth_hash'], $_SESSION['last_seen'])
            && $_SESSION['admin'] === ($credentials['username'] ?? null)
            && hash_equals($expected, (string) $_SESSION['auth_hash'])
            && time() - (int) $_SESSION['last_seen'] < 8 * 3600;
    }

    public function touch(): void
    {
        $_SESSION['last_seen'] = time();
    }

    public function login(string $username, string $password): bool
    {
        $lock = fopen($this->storageRoot . '/cms-login.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('Вход временно недоступен');
        }
        try {
            $rateFile = $this->storageRoot . '/cms-login-attempts.json';
            $rates = is_file($rateFile) ? json_decode((string) file_get_contents($rateFile), true) : [];
            $rates = is_array($rates) ? $rates : [];
            $rates = array_filter($rates, fn(array $item): bool => ($item['until'] ?? 0) > time());
            $key = hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
            $attempt = $rates[$key] ?? ['count' => 0, 'until' => time() + 900];
            if ($attempt['count'] >= 5) {
                throw new RuntimeException('Слишком много попыток. Повторите вход через 15 минут.');
            }
            $credentials = $this->credentials();
            $passwordHash = (string) ($credentials['password_hash'] ?? '$2y$10$Nl1QWOphxsSDLFTRdolQ5eBSpIOiJDZD27KRT40MRXnoxtto7lJTK');
            $validPassword = password_verify($password, $passwordHash);
            if (!$validPassword || !hash_equals((string) ($credentials['username'] ?? ''), $username)) {
                $attempt['count']++;
                $rates[$key] = $attempt;
                SiteContent::writeJson($rateFile, $rates);
                return false;
            }
            unset($rates[$key]);
            SiteContent::writeJson($rateFile, $rates);
            session_regenerate_id(true);
            $_SESSION = ['admin' => $username, 'auth_hash' => hash('sha256', $passwordHash),
                'last_seen' => time(), 'csrf' => bin2hex(random_bytes(32))];
            return true;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function changePassword(string $currentPassword, string $newPassword): void
    {
        $credentials = $this->credentials();
        if (!password_verify($currentPassword, (string) ($credentials['password_hash'] ?? ''))) {
            throw new RuntimeException('Текущий пароль неверен');
        }
        if (strlen($newPassword) < 12 || strlen($newPassword) > 128) {
            throw new RuntimeException('Новый пароль должен содержать от 12 до 128 символов');
        }
        $credentials['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
        SiteContent::writeJson($this->storageRoot . '/cms-admin.json', $credentials);
        session_regenerate_id(true);
        $_SESSION['auth_hash'] = hash('sha256', $credentials['password_hash']);
        unset($_SESSION['cms_preview']);
    }

    public function logout(): void
    {
        $_SESSION = [];
        $params = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $params['path'],
            'secure' => $params['secure'], 'httponly' => true, 'samesite' => 'Strict']);
        session_destroy();
    }

    private function credentials(): array
    {
        $file = $this->storageRoot . '/cms-admin.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        return is_array($data) ? $data : [];
    }
}
