<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Camada fina sobre $_SESSION com cookie endurecido e flash messages.
 */
final class Session
{
    private static bool $started = false;

    public static function start(): void
    {
        if (self::$started || \PHP_SAPI === 'cli') {
            return;
        }

        if (session_status() === \PHP_SESSION_ACTIVE) {
            self::$started = true;

            return;
        }

        $lifetime = (int) Config::get('session.lifetime', 120) * 60;

        session_name((string) Config::get('session.name', 'controle_vps_session'));

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => base_path_url() === '' ? '/' : base_path_url() . '/',
            'domain'   => '',
            'secure'   => (bool) Config::get('session.secure', false),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) $lifetime);

        session_start();
        self::$started = true;

        // Expiracao por inatividade.
        $now  = time();
        $last = (int) ($_SESSION['_last_activity'] ?? $now);

        if (isset($_SESSION['user_id']) && ($now - $last) > $lifetime) {
            self::destroy();
            session_start();
            self::flash('warning', 'Sua sessão expirou por inatividade. Entre novamente.');
        }

        $_SESSION['_last_activity'] = $now;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /**
     * Regenera o ID da sessao. Obrigatorio apos o login (secao 33 do PLAN)
     * para evitar session fixation.
     */
    public static function regenerate(): void
    {
        if (session_status() === \PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function destroy(): void
    {
        // Os dados saem primeiro, sempre. Em CLI (crons, console e a suite de
        // testes) nao existe sessao ativa, mas o array precisa ser limpo do
        // mesmo jeito - caso contrario um logout "nao aconteceria" fora do
        // navegador.
        $_SESSION      = [];
        self::$started = false;

        if (session_status() !== \PHP_SESSION_ACTIVE) {
            return;
        }

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }

        session_destroy();
        self::$started = false;
    }

    // -----------------------------------------------------------------
    // Flash messages
    // -----------------------------------------------------------------

    public static function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][$type][] = $message;
    }

    /** @return array<string,array<int,string>> */
    public static function pullFlash(): array
    {
        $flash = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);

        return \is_array($flash) ? $flash : [];
    }

    /** Guarda os dados do formulario para repopular apos erro de validacao. */
    public static function flashInput(array $input): void
    {
        unset($input['password'], $input['password_confirmation'], $input['_token']);
        $_SESSION['_old_input'] = $input;
    }

    /** @return array<string,mixed> */
    public static function pullOldInput(): array
    {
        $old = $_SESSION['_old_input'] ?? [];
        unset($_SESSION['_old_input']);

        return \is_array($old) ? $old : [];
    }

    /** @param array<string,string> $errors */
    public static function flashErrors(array $errors): void
    {
        $_SESSION['_errors'] = $errors;
    }

    /** @return array<string,string> */
    public static function pullErrors(): array
    {
        $errors = $_SESSION['_errors'] ?? [];
        unset($_SESSION['_errors']);

        return \is_array($errors) ? $errors : [];
    }
}
