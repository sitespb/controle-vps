<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Crypto;
use App\Core\Database;
use App\Core\Logger;

/**
 * Configuracao que contem SEGREDO, agrupada por escopo.
 *
 * Hoje: avisos por e-mail (senha do SMTP), avisos por WhatsApp (token da
 * RyzeAPI) e o Turnstile do Cloudflare (chave secreta). Coisas diferentes,
 * mesma exigencia: sao credenciais de terceiros que nao podem ficar legiveis
 * num dump do banco.
 *
 * Os campos listados em SECRETS sao cifrados na gravacao e decifrados na
 * leitura; nada fora desta classe precisa saber disso.
 *
 * Nao ha cache: a leitura acontece ao enviar um aviso, ao abrir a tela de
 * configuracao ou ao validar um login - nunca em toda pagina. Cachear
 * segredos em arquivo seria desfazer a cifragem (ver a migration 018).
 */
final class SecureSetting
{
    public const SCOPE_EMAIL = 'email';

    public const SCOPE_TURNSTILE = 'turnstile';

    public const SCOPE_WHATSAPP = 'whatsapp';

    /**
     * Campos que sao gravados cifrados.
     *
     * Ficam declarados aqui, e nao espalhados nos formularios, para que
     * acrescentar um segredo novo seja uma linha so - e para que esquecer de
     * marcar um campo como secreto seja um erro visivel em um lugar unico.
     *
     * @var array<string,array<int,string>>
     */
    public const SECRETS = [
        self::SCOPE_EMAIL     => ['smtp_password'],
        self::SCOPE_WHATSAPP  => ['token'],
        self::SCOPE_TURNSTILE => ['secret_key'],
    ];

    /**
     * Valores iniciais. O e-mail ja nasce apontando para o SMTP do Google,
     * que foi o pedido; o operador so preenche usuario e senha.
     *
     * @var array<string,array<string,string>>
     */
    public const DEFAULTS = [
        self::SCOPE_EMAIL => [
            'enabled'        => '0',
            'smtp_host'      => 'smtp.gmail.com',
            'smtp_port'      => '587',
            'smtp_security'  => 'tls',
            'smtp_user'      => '',
            'smtp_password'  => '',
            'from_email'     => '',
            'from_name'      => 'Controle VPS',
            'recipients'     => '',
        ],
        self::SCOPE_WHATSAPP => [
            'enabled'       => '0',
            'base_url'      => 'https://ryzeapi.cloud',
            'instance'      => '',
            'token'         => '',
            'recipients'    => '',
        ],
        self::SCOPE_TURNSTILE => [
            'enabled'    => '0',
            'site_key'   => '',
            'secret_key' => '',
        ],
    ];

    /**
     * Configuracao completa de um canal, com os segredos ja decifrados.
     *
     * @return array<string,string>
     */
    public static function all(string $scope): array
    {
        $values = self::DEFAULTS[$scope] ?? [];

        if (!Database::tableExists('secure_settings')) {
            return $values;
        }

        $rows = Database::select(
            'SELECT `key`, `value`, `is_secret` FROM secure_settings WHERE scope = ?',
            [$scope]
        );

        foreach ($rows as $row) {
            $key   = (string) $row['key'];
            $value = (string) ($row['value'] ?? '');

            if ((int) $row['is_secret'] === 1 && $value !== '') {
                try {
                    $value = Crypto::decrypt($value);
                } catch (\Throwable $e) {
                    // APP_KEY trocada, ou linha adulterada. Devolver vazio faz
                    // a tela pedir o segredo de novo, em vez de derrubar a
                    // pagina inteira de configuracao.
                    Logger::error('Segredo de aviso ilegivel: ' . $e->getMessage(), [
                        'channel' => $scope,
                        'key'     => $key,
                    ]);

                    $value = '';
                }
            }

            $values[$key] = $value;
        }

        return $values;
    }

    /** Um valor isolado, ja decifrado quando for segredo. */
    public static function get(string $scope, string $key, string $default = ''): string
    {
        $all = self::all($scope);

        return $all[$key] ?? $default;
    }

    public static function isEnabled(string $scope): bool
    {
        return self::get($scope, 'enabled', '0') === '1';
    }

    /**
     * Grava um conjunto de valores.
     *
     * Um segredo com string vazia NAO apaga o que ja existe: o formulario
     * exibe a senha mascarada, e enviar o formulario sem digitar nada tem que
     * significar "mantenha o que esta la", nunca "apague". Para limpar de
     * verdade existe o valor especial `__limpar__`.
     *
     * @param array<string,string> $values
     */
    public static function save(string $scope, array $values, ?int $userId = null): void
    {
        $secrets = self::SECRETS[$scope] ?? [];
        $now     = now_string();

        foreach ($values as $key => $value) {
            $isSecret = \in_array($key, $secrets, true);
            $value    = (string) $value;

            if ($isSecret) {
                if ($value === '') {
                    continue; // mantem o segredo atual
                }

                if ($value === '__limpar__') {
                    $value = '';
                } else {
                    $value = Crypto::encrypt($value);
                }
            }

            Database::statement(
                'INSERT INTO secure_settings (`scope`, `key`, `value`, `is_secret`, `updated_by`, `created_at`, `updated_at`)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `updated_by` = VALUES(`updated_by`), `updated_at` = VALUES(`updated_at`)',
                [$scope, $key, $value, $isSecret ? 1 : 0, $userId, $now, $now]
            );
        }
    }

    /**
     * Lista de destinatarios de um canal, ja normalizada.
     *
     * Aceita virgula, ponto-e-virgula ou quebra de linha como separador -
     * quem cola de uma planilha nao deveria ter que se preocupar com isso.
     *
     * @return array<int,string>
     */
    public static function recipients(string $scope): array
    {
        $raw = self::get($scope, 'recipients', '');

        if (trim($raw) === '') {
            return [];
        }

        $parts = preg_split('/[,;\r\n]+/', $raw) ?: [];
        $list  = [];

        foreach ($parts as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            $valid = $scope === self::SCOPE_EMAIL
                ? filter_var($part, \FILTER_VALIDATE_EMAIL) !== false
                : preg_match('/^\+?[0-9]{10,15}$/', preg_replace('/\D/', '', $part) ?? '') === 1;

            if (!$valid) {
                continue;
            }

            if ($scope === self::SCOPE_WHATSAPP) {
                $part = preg_replace('/\D/', '', $part) ?? $part;
            }

            $list[$part] = $part;
        }

        return array_values($list);
    }
}
