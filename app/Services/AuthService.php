<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Session;
use App\Models\User;

/**
 * Autenticacao por sessao do painel (secoes 23 e 33 do PLAN).
 *
 * Pontos de seguranca implementados aqui:
 *  - senha verificada com password_verify() contra o hash do banco;
 *  - rehash automatico quando o algoritmo padrao do PHP evolui;
 *  - session_regenerate_id() apos o login (anti session fixation);
 *  - contagem de tentativas por e-mail E por IP (anti forca bruta);
 *  - resposta identica para "usuario nao existe" e "senha errada", para nao
 *    revelar quais e-mails estao cadastrados.
 */
final class AuthService
{
    /** Cache do usuario da requisicao atual. */
    private static ?array $cachedUser = null;

    private static bool $userLoaded = false;

    /**
     * @return array{ok:bool,message:string,user:?array<string,mixed>}
     */
    public static function attempt(string $email, string $password, string $ip, string $userAgent = ''): array
    {
        $email = mb_strtolower(trim($email));

        if (self::isLocked($email, $ip)) {
            $minutes = (int) Config::get('monitoring.login.decay_minutes', 15);

            return [
                'ok'      => false,
                'message' => sprintf(
                    'Muitas tentativas de acesso. Aguarde %d minutos antes de tentar novamente.',
                    $minutes
                ),
                'user'    => null,
            ];
        }

        $user    = User::findByEmail($email);
        $generic = 'E-mail ou senha incorretos.';

        // Verifica a senha mesmo quando o usuario nao existe: mantem o tempo
        // de resposta constante e nao revela quais e-mails estao cadastrados.
        $hash = $user['password_hash'] ?? '$2y$12$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidin';

        if (!password_verify($password, $hash) || $user === null) {
            self::recordAttempt($email, $ip, false, $userAgent);
            AuditService::log('login.failed', 'Tentativa de login sem sucesso para ' . $email, [
                'level'   => 'warning',
                'ip'      => $ip,
                'context' => ['email' => $email],
            ]);

            return ['ok' => false, 'message' => $generic, 'user' => null];
        }

        if (($user['status'] ?? '') !== 'active') {
            self::recordAttempt($email, $ip, false, $userAgent);
            AuditService::log('login.blocked', 'Login recusado: usuario inativo (' . $email . ')', [
                'level'   => 'warning',
                'ip'      => $ip,
                'user_id' => (int) $user['id'],
            ]);

            return [
                'ok'      => false,
                'message' => 'Este usuario esta inativo. Fale com um administrador.',
                'user'    => null,
            ];
        }

        // Atualiza o hash se o custo/algoritmo padrao mudou.
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            User::updateById((int) $user['id'], ['password_hash' => User::hashPassword($password)]);
        }

        self::recordAttempt($email, $ip, true, $userAgent);
        self::login($user, $ip);

        return ['ok' => true, 'message' => 'Bem-vindo, ' . $user['name'] . '.', 'user' => $user];
    }

    /** @param array<string,mixed> $user */
    public static function login(array $user, string $ip = ''): void
    {
        // Obrigatorio antes de gravar os dados na sessao.
        Session::regenerate();

        Session::set('user_id', (int) $user['id']);
        Session::set('user_name', (string) $user['name']);
        Session::set('user_email', (string) $user['email']);
        Session::set('user_role', (string) $user['role']);
        Session::set('logged_in_at', time());

        self::$cachedUser = $user;
        self::$userLoaded = true;

        User::touchLogin((int) $user['id'], $ip);

        AuditService::log('login', 'Login realizado com sucesso.', [
            'user_id' => (int) $user['id'],
            'actor'   => (string) $user['name'],
            'ip'      => $ip,
        ]);
    }

    public static function logout(): void
    {
        $user = self::user();

        if ($user !== null) {
            AuditService::log('logout', 'Logout realizado.', [
                'user_id' => (int) $user['id'],
                'actor'   => (string) $user['name'],
            ]);
        }

        Csrf::rotate();
        Session::destroy();

        self::$cachedUser = null;
        self::$userLoaded = true;
    }

    public static function check(): bool
    {
        return Session::has('user_id');
    }

    /** @return array<string,mixed>|null */
    public static function user(): ?array
    {
        if (self::$userLoaded) {
            return self::$cachedUser;
        }

        self::$userLoaded = true;

        $id = Session::get('user_id');

        if (!\is_int($id) && !ctype_digit((string) $id)) {
            return self::$cachedUser = null;
        }

        $user = User::find((int) $id);

        // Usuario removido ou desativado durante a sessao: encerra o acesso.
        if ($user === null || ($user['status'] ?? '') !== 'active') {
            Session::destroy();

            return self::$cachedUser = null;
        }

        return self::$cachedUser = $user;
    }

    public static function id(): ?int
    {
        $user = self::user();

        return $user === null ? null : (int) $user['id'];
    }

    public static function role(): string
    {
        $user = self::user();

        return (string) ($user['role'] ?? '');
    }

    public static function isAdmin(): bool
    {
        return self::role() === User::ROLE_ADMIN;
    }

    /**
     * Verifica se o usuario tem um dos papeis informados.
     *
     * A arquitetura ja suporta permissoes mais finas (basta trocar a
     * implementacao daqui), mas a V1 usa apenas admin/operator conforme a
     * secao 23 do PLAN.
     */
    public static function hasRole(string ...$roles): bool
    {
        $current = self::role();

        if ($current === '') {
            return false;
        }

        // Administrador tem acesso completo.
        if ($current === User::ROLE_ADMIN) {
            return true;
        }

        return \in_array($current, $roles, true);
    }

    // -----------------------------------------------------------------
    // Forca bruta
    // -----------------------------------------------------------------

    public static function isLocked(string $email, string $ip): bool
    {
        $maxAttempts = (int) Config::get('monitoring.login.max_attempts', 5);
        $decay       = (int) Config::get('monitoring.login.decay_minutes', 15);

        if ($maxAttempts <= 0) {
            return false;
        }

        $failures = (int) Database::scalar(
            'SELECT COUNT(*) FROM login_attempts
             WHERE success = 0
               AND created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
               AND (email = ? OR ip = ?)',
            [$decay, mb_strtolower($email), $ip]
        );

        return $failures >= $maxAttempts;
    }

    public static function remainingAttempts(string $email, string $ip): int
    {
        $maxAttempts = (int) Config::get('monitoring.login.max_attempts', 5);
        $decay       = (int) Config::get('monitoring.login.decay_minutes', 15);

        $failures = (int) Database::scalar(
            'SELECT COUNT(*) FROM login_attempts
             WHERE success = 0
               AND created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
               AND (email = ? OR ip = ?)',
            [$decay, mb_strtolower($email), $ip]
        );

        return max(0, $maxAttempts - $failures);
    }

    private static function recordAttempt(string $email, string $ip, bool $success, string $userAgent): void
    {
        Database::insert('login_attempts', [
            'email'      => mb_substr($email, 0, 190),
            'ip'         => $ip,
            'success'    => $success ? 1 : 0,
            'user_agent' => mb_substr($userAgent, 0, 250),
            'created_at' => now_string(),
        ]);

        // Login bem sucedido limpa o historico de falhas daquele e-mail.
        if ($success) {
            Database::statement(
                'DELETE FROM login_attempts WHERE email = ? AND success = 0',
                [mb_strtolower($email)]
            );
        }
    }

    /** Limpeza periodica das tentativas antigas (cron). */
    public static function pruneAttempts(int $days = 7): int
    {
        return Database::statement(
            'DELETE FROM login_attempts WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
            [max(1, $days)]
        );
    }

    /** Usado pelos testes para limpar o cache entre cenarios. */
    public static function resetCache(): void
    {
        self::$cachedUser = null;
        self::$userLoaded = false;
    }
}
