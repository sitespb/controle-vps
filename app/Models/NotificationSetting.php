<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Crypto;
use App\Core\Database;
use App\Core\Logger;

/**
 * Configuracao dos canais de aviso (e-mail e WhatsApp).
 *
 * Guarda pares chave/valor por canal. Os campos marcados como segredo -
 * senha do SMTP, token da RyzeAPI - sao cifrados na gravacao e decifrados na
 * leitura; nada fora desta classe precisa saber disso.
 *
 * Nao ha cache: a leitura acontece quando um aviso vai ser enviado ou quando a
 * tela de Avisos abre, nao em toda pagina. Cachear segredos em arquivo seria
 * desfazer a cifragem (ver o comentario da migration 018).
 */
final class NotificationSetting
{
    public const CHANNEL_EMAIL = 'email';

    public const CHANNEL_WHATSAPP = 'whatsapp';

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
        self::CHANNEL_EMAIL    => ['smtp_password'],
        self::CHANNEL_WHATSAPP => ['token'],
    ];

    /**
     * Valores iniciais. O e-mail ja nasce apontando para o SMTP do Google,
     * que foi o pedido; o operador so preenche usuario e senha.
     *
     * @var array<string,array<string,string>>
     */
    public const DEFAULTS = [
        self::CHANNEL_EMAIL => [
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
        self::CHANNEL_WHATSAPP => [
            'enabled'       => '0',
            'base_url'      => 'https://ryzeapi.cloud',
            'instance'      => '',
            'token'         => '',
            'recipients'    => '',
        ],
    ];

    /**
     * Configuracao completa de um canal, com os segredos ja decifrados.
     *
     * @return array<string,string>
     */
    public static function all(string $channel): array
    {
        $values = self::DEFAULTS[$channel] ?? [];

        if (!Database::tableExists('notification_settings')) {
            return $values;
        }

        $rows = Database::select(
            'SELECT `key`, `value`, `is_secret` FROM notification_settings WHERE channel = ?',
            [$channel]
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
                        'channel' => $channel,
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
    public static function get(string $channel, string $key, string $default = ''): string
    {
        $all = self::all($channel);

        return $all[$key] ?? $default;
    }

    public static function isEnabled(string $channel): bool
    {
        return self::get($channel, 'enabled', '0') === '1';
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
    public static function save(string $channel, array $values, ?int $userId = null): void
    {
        $secrets = self::SECRETS[$channel] ?? [];
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
                'INSERT INTO notification_settings (`channel`, `key`, `value`, `is_secret`, `updated_by`, `created_at`, `updated_at`)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `updated_by` = VALUES(`updated_by`), `updated_at` = VALUES(`updated_at`)',
                [$channel, $key, $value, $isSecret ? 1 : 0, $userId, $now, $now]
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
    public static function recipients(string $channel): array
    {
        $raw = self::get($channel, 'recipients', '');

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

            $valid = $channel === self::CHANNEL_EMAIL
                ? filter_var($part, \FILTER_VALIDATE_EMAIL) !== false
                : preg_match('/^\+?[0-9]{10,15}$/', preg_replace('/\D/', '', $part) ?? '') === 1;

            if (!$valid) {
                continue;
            }

            if ($channel === self::CHANNEL_WHATSAPP) {
                $part = preg_replace('/\D/', '', $part) ?? $part;
            }

            $list[$part] = $part;
        }

        return array_values($list);
    }
}
