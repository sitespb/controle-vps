<?php

declare(strict_types=1);

use App\Core\Env;

return [
    // debug | info | warning | error
    'level'     => (string) Env::get('LOG_LEVEL', 'info'),
    // Dias de arquivos de log mantidos pelo cron de limpeza.
    'max_files' => Env::int('LOG_MAX_FILES', 14),
];
