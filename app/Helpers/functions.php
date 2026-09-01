<?php

declare(strict_types=1);

/**
 * Funcoes auxiliares globais.
 *
 * Carregado uma unica vez no bootstrap (e pelo autoload do Composer, quando
 * presente). Todas as funcoes sao guardadas por function_exists para permitir
 * o carregamento duplo sem erro.
 */

use App\Core\Config;
use App\Core\Csrf;

if (!function_exists('base_dir')) {
    /** Caminho absoluto da raiz do projeto. */
    function base_dir(string $append = ''): string
    {
        static $base = null;

        if ($base === null) {
            $base = \defined('BASE_PATH') ? BASE_PATH : \dirname(__DIR__, 2);
        }

        return $append === '' ? $base : $base . DIRECTORY_SEPARATOR . ltrim($append, '/\\');
    }
}

if (!function_exists('base_path_url')) {
    /**
     * Prefixo de caminho da instalacao, extraido de APP_URL.
     *
     *   APP_URL=http://controle-vps.test              -> ''
     *   APP_URL=http://localhost/controle-vps/public  -> '/controle-vps/public'
     */
    function base_path_url(): string
    {
        static $prefix = null;

        if ($prefix !== null) {
            return $prefix;
        }

        $url  = (string) Config::get('app.url', '');
        $path = $url === '' ? '' : (string) (parse_url($url, PHP_URL_PATH) ?? '');
        $path = rtrim($path, '/');

        return $prefix = ($path === '/' ? '' : $path);
    }
}

if (!function_exists('url')) {
    /** Monta um caminho interno ja com o prefixo de instalacao. */
    function url(string $path = '/'): string
    {
        $prefix = base_path_url();
        $path   = '/' . ltrim($path, '/');

        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        $full = $prefix . $path;

        return $full === '' ? '/' : $full;
    }
}

if (!function_exists('full_url')) {
    /** URL absoluta (usada nas instrucoes de instalacao do agente). */
    function full_url(string $path = '/'): string
    {
        $base = rtrim((string) Config::get('app.url', ''), '/');

        return $base . '/' . ltrim($path, '/');
    }
}

if (!function_exists('asset')) {
    /** URL de um asset com cache-busting por filemtime. */
    function asset(string $file): string
    {
        $file     = ltrim($file, '/');
        $absolute = base_dir('public/assets/' . $file);
        $version  = is_file($absolute) ? (string) filemtime($absolute) : '1';

        return url('/assets/' . $file) . '?v=' . $version;
    }
}

if (!function_exists('e')) {
    /** Escape de saida HTML. Obrigatorio em toda interpolacao nas views. */
    function e(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (\is_bool($value)) {
            return $value ? '1' : '0';
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('e_attr')) {
    /** Escape para uso dentro de atributos JS/JSON. */
    function e_attr(mixed $value): string
    {
        return htmlspecialchars(
            (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return Csrf::token();
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return Csrf::field();
    }
}

if (!function_exists('old')) {
    /**
     * Valor anterior de um campo apos falha de validacao.
     *
     * @param array<string,mixed> $old
     */
    function old(array $old, string $key, mixed $default = ''): string
    {
        $value = $old[$key] ?? $default;

        return \is_scalar($value) ? (string) $value : '';
    }
}

if (!function_exists('now_string')) {
    function now_string(): string
    {
        return date('Y-m-d H:i:s');
    }
}

if (!function_exists('mask_secret')) {
    /**
     * Mascara um segredo mantendo apenas o inicio, para permitir identificacao
     * sem expor o valor. Usado nos logs e na listagem de tokens.
     */
    function mask_secret(string $secret, int $visible = 8): string
    {
        $length = \strlen($secret);

        if ($length <= $visible) {
            return str_repeat('*', $length);
        }

        return substr($secret, 0, $visible) . str_repeat('*', min(24, $length - $visible));
    }
}

if (!function_exists('format_bytes')) {
    /** Bytes -> "12,4 GB". */
    function format_bytes(?float $bytes, int $decimals = 1): string
    {
        if ($bytes === null || $bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $power = (int) floor(log($bytes, 1024));
        $power = min($power, \count($units) - 1);
        $value = $bytes / (1024 ** $power);

        return number_format($value, $power === 0 ? 0 : $decimals, ',', '.') . ' ' . $units[$power];
    }
}

if (!function_exists('format_percent')) {
    function format_percent(?float $value, int $decimals = 0): string
    {
        if ($value === null) {
            return '--';
        }

        return number_format($value, $decimals, ',', '.') . '%';
    }
}

if (!function_exists('format_uptime')) {
    /** Segundos -> "12d 4h 33min". */
    function format_uptime(?int $seconds): string
    {
        if ($seconds === null || $seconds <= 0) {
            return '--';
        }

        $days    = intdiv($seconds, 86400);
        $hours   = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        $parts = [];
        if ($days > 0) {
            $parts[] = $days . 'd';
        }
        if ($hours > 0) {
            $parts[] = $hours . 'h';
        }
        if ($minutes > 0 && $days === 0) {
            $parts[] = $minutes . 'min';
        }

        return $parts === [] ? '< 1min' : implode(' ', $parts);
    }
}

if (!function_exists('format_datetime')) {
    function format_datetime(?string $datetime, string $format = 'd/m/Y H:i'): string
    {
        if ($datetime === null || $datetime === '' || str_starts_with($datetime, '0000')) {
            return '--';
        }

        $timestamp = strtotime($datetime);

        return $timestamp === false ? '--' : date($format, $timestamp);
    }
}

if (!function_exists('format_date')) {
    function format_date(?string $date): string
    {
        return format_datetime($date, 'd/m/Y');
    }
}

if (!function_exists('time_ago')) {
    /** "2 min atras", "3 h atras", "ontem". */
    function time_ago(?string $datetime): string
    {
        if ($datetime === null || $datetime === '') {
            return 'nunca';
        }

        $timestamp = strtotime($datetime);
        if ($timestamp === false) {
            return 'nunca';
        }

        $diff = time() - $timestamp;

        if ($diff < 0) {
            return 'agora';
        }
        if ($diff < 60) {
            return $diff <= 5 ? 'agora' : $diff . ' s atras';
        }
        if ($diff < 3600) {
            return intdiv($diff, 60) . ' min atras';
        }
        if ($diff < 86400) {
            return intdiv($diff, 3600) . ' h atras';
        }
        if ($diff < 172800) {
            return 'ontem';
        }
        if ($diff < 2592000) {
            return intdiv($diff, 86400) . ' dias atras';
        }

        return date('d/m/Y', $timestamp);
    }
}

if (!function_exists('threshold_level')) {
    /**
     * Classifica um percentual segundo os limites da secao 19 do PLAN.
     *
     * @return 'normal'|'warning'|'critical'|'unknown'
     */
    function threshold_level(?float $percent, string $metric = 'cpu'): string
    {
        if ($percent === null) {
            return 'unknown';
        }

        $warning  = (float) Config::get("monitoring.thresholds.{$metric}.warning", 80);
        $critical = (float) Config::get("monitoring.thresholds.{$metric}.critical", 90);

        if ($percent > $critical) {
            return 'critical';
        }

        if ($percent >= $warning) {
            return 'warning';
        }

        return 'normal';
    }
}

if (!function_exists('level_bar_class')) {
    /** Classe Tailwind da barra de progresso conforme o nivel. */
    function level_bar_class(string $level): string
    {
        return match ($level) {
            'critical' => 'bg-red-500',
            'warning'  => 'bg-yellow-400',
            'normal'   => 'bg-green-500',
            default    => 'bg-gray-300',
        };
    }
}

if (!function_exists('level_text_class')) {
    function level_text_class(string $level): string
    {
        return match ($level) {
            'critical' => 'text-red-700',
            'warning'  => 'text-yellow-800',
            'normal'   => 'text-gray-900',
            default    => 'text-gray-400',
        };
    }
}

if (!function_exists('status_badge_class')) {
    /**
     * Mapa oficial de cores de status - DESIGN.md secao 8.
     * Nao criar cores novas para status fora deste mapa.
     */
    function status_badge_class(string $status): string
    {
        return match ($status) {
            'online', 'valid', 'running', 'active', 'resolved' => 'bg-green-100 text-green-800',
            'warning', 'expiring', 'acknowledged'              => 'bg-yellow-100 text-yellow-800',
            'offline', 'expired', 'stopped', 'critical', 'open' => 'bg-red-100 text-red-800',
            'inactive'                                          => 'bg-gray-100 text-gray-600',
            default                                             => 'bg-gray-100 text-gray-800',
        };
    }
}

if (!function_exists('status_dot_class')) {
    /** Cor do ponto indicador de status. */
    function status_dot_class(string $status): string
    {
        return match ($status) {
            'online', 'valid', 'running', 'active' => 'bg-green-500',
            'warning', 'expiring'                  => 'bg-yellow-400',
            'offline', 'expired', 'stopped'        => 'bg-red-500',
            default                                => 'bg-gray-300',
        };
    }
}

if (!function_exists('status_label')) {
    function status_label(string $status): string
    {
        return match ($status) {
            'online'       => 'Online',
            'offline'      => 'Offline',
            'warning'      => 'Atenção',
            'unknown'      => 'Desconhecido',
            'valid'        => 'Válido',
            'expiring'     => 'Vencendo',
            'expired'      => 'Expirado',
            'running'      => 'Ativo',
            'stopped'      => 'Parado',
            'active'       => 'Ativo',
            'inactive'     => 'Inativo',
            'open'         => 'Aberto',
            'acknowledged' => 'Reconhecido',
            'resolved'     => 'Resolvido',
            'critical'     => 'Crítico',
            'info'         => 'Informativo',
            default        => ucfirst($status),
        };
    }
}

if (!function_exists('array_get')) {
    /** Acesso seguro a array aninhado por notacao de ponto. */
    function array_get(array $array, string $key, mixed $default = null): mixed
    {
        $value = $array;

        foreach (explode('.', $key) as $segment) {
            if (!\is_array($value) || !\array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}

if (!function_exists('str_limit')) {
    function str_limit(string $value, int $limit = 80, string $end = '...'): string
    {
        return mb_strlen($value) <= $limit ? $value : rtrim(mb_substr($value, 0, $limit)) . $end;
    }
}
