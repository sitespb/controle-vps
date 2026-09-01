<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Models\Setting;

/**
 * Aplica as configuracoes gravadas no banco sobre os padroes de
 * config/monitoring.php (secao 19 do PLAN).
 *
 * Chamado uma vez por requisicao HTTP e no inicio de cada cron. O resultado
 * fica em cache de arquivo por 60 segundos para nao adicionar uma consulta em
 * toda pagina (secao 39: evitar consultas desnecessarias).
 */
final class SettingsService
{
    private const CACHE_TTL = 60;

    private static bool $applied = false;

    /** Le as settings e sobrescreve o Config em memoria. */
    public static function applyOverrides(bool $force = false): void
    {
        if (self::$applied && !$force) {
            return;
        }

        self::$applied = true;

        try {
            $values = $force ? self::readFromDatabase() : self::readCached();
        } catch (\Throwable $e) {
            // Sem banco, os padroes do arquivo continuam valendo.
            Logger::warning('Não foi possível carregar settings: ' . $e->getMessage());

            return;
        }

        foreach ($values as $key => $value) {
            Config::set($key, $value);
        }
    }

    /** @return array<string,mixed> */
    private static function readCached(): array
    {
        $file = base_dir('storage/cache/settings.php');

        if (is_file($file) && (time() - (int) filemtime($file)) < self::CACHE_TTL) {
            $cached = @include $file;

            if (\is_array($cached)) {
                return $cached;
            }
        }

        $values = self::readFromDatabase();
        self::writeCache($file, $values);

        return $values;
    }

    /** @return array<string,mixed> */
    private static function readFromDatabase(): array
    {
        if (!Database::tableExists('settings')) {
            return [];
        }

        $rows   = Database::select('SELECT `key`, `value`, `type` FROM settings');
        $values = [];

        foreach ($rows as $row) {
            $values[(string) $row['key']] = Setting::castValue(
                $row['value'] === null ? null : (string) $row['value'],
                (string) $row['type']
            );
        }

        return $values;
    }

    /** @param array<string,mixed> $values */
    private static function writeCache(string $file, array $values): void
    {
        $dir = \dirname($file);

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }

        $export = var_export($values, true);
        @file_put_contents($file, "<?php\n\nreturn {$export};\n", LOCK_EX);
    }

    public static function flushCache(): void
    {
        $file = base_dir('storage/cache/settings.php');

        if (is_file($file)) {
            @unlink($file);
        }

        self::$applied = false;
    }

    /**
     * Grava um conjunto de valores validando faixa e tipo.
     *
     * @param  array<string,string> $input key => valor
     * @return array{updated:int,errors:array<string,string>}
     */
    public static function updateMany(array $input, ?int $userId = null): array
    {
        $updated = 0;
        $errors  = [];

        foreach ($input as $key => $value) {
            $setting = Setting::findByKey((string) $key);

            if ($setting === null) {
                continue;
            }

            $validation = self::validateValue($setting, (string) $value);

            if ($validation !== null) {
                $errors[(string) $key] = $validation;
                continue;
            }

            if ((string) $setting['value'] === (string) $value) {
                continue;
            }

            Setting::updateValue((string) $key, (string) $value, $userId);
            $updated++;

            AuditService::log(
                'settings.updated',
                sprintf('Configuração "%s" alterada de %s para %s.', $setting['label'], $setting['value'], $value),
                ['entity_type' => 'setting', 'entity_id' => (int) $setting['id']]
            );
        }

        if ($updated > 0) {
            self::flushCache();
            self::applyOverrides(true);
        }

        return ['updated' => $updated, 'errors' => $errors];
    }

    /** @param array<string,mixed> $setting */
    private static function validateValue(array $setting, string $value): ?string
    {
        $type = (string) $setting['type'];

        if (\in_array($type, ['int', 'float'], true)) {
            if (!is_numeric($value)) {
                return 'Informe um número.';
            }

            $numeric = (float) $value;

            if ($setting['min_value'] !== null && $numeric < (float) $setting['min_value']) {
                return sprintf('Valor mínimo: %s.', rtrim(rtrim((string) $setting['min_value'], '0'), '.'));
            }

            if ($setting['max_value'] !== null && $numeric > (float) $setting['max_value']) {
                return sprintf('Valor máximo: %s.', rtrim(rtrim((string) $setting['max_value'], '0'), '.'));
            }
        }

        if ($type === 'bool' && !\in_array(strtolower($value), ['0', '1', 'true', 'false'], true)) {
            return 'Valor inválido.';
        }

        if ($type === 'json' && json_decode($value) === null && strtolower($value) !== 'null') {
            return 'JSON inválido.';
        }

        return null;
    }

    /**
     * Coerencia entre "atencao" e "critico": nao faz sentido o critico ser
     * menor que o de atencao.
     *
     * @param  array<string,string> $input
     * @return array<string,string> Erros encontrados
     */
    public static function checkCoherence(array $input): array
    {
        $errors = [];

        foreach (['cpu', 'ram', 'disk'] as $metric) {
            $warningKey  = "monitoring.thresholds.{$metric}.warning";
            $criticalKey = "monitoring.thresholds.{$metric}.critical";

            $warning  = isset($input[$warningKey]) ? (float) $input[$warningKey] : (float) Config::get($warningKey, 80);
            $critical = isset($input[$criticalKey]) ? (float) $input[$criticalKey] : (float) Config::get($criticalKey, 90);

            if ($critical <= $warning) {
                $errors[$criticalKey] = 'O limite crítico deve ser maior que o de atenção.';
            }
        }

        $sslWarning  = isset($input['monitoring.ssl.warning'])
            ? (int) $input['monitoring.ssl.warning']
            : (int) Config::get('monitoring.ssl.warning', 30);
        $sslCritical = isset($input['monitoring.ssl.critical'])
            ? (int) $input['monitoring.ssl.critical']
            : (int) Config::get('monitoring.ssl.critical', 7);

        if ($sslCritical >= $sslWarning) {
            $errors['monitoring.ssl.critical'] = 'O limite crítico deve ser menor que o de aviso.';
        }

        return $errors;
    }

    /** @return array<string,string> Rotulo legivel de cada grupo. */
    public static function groupLabels(): array
    {
        return [
            'limites'  => 'Limites de recursos',
            'ssl'      => 'Certificados SSL',
            'coleta'   => 'Coleta e heartbeat',
            'retencao' => 'Retenção de dados',
            'geral'    => 'Geral',
        ];
    }
}
