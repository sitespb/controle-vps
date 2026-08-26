<?php

declare(strict_types=1);

use App\Core\Env;

return [
    'name'     => (string) Env::get('SESSION_NAME', 'controle_vps_session'),
    // Minutos de inatividade ate a sessao expirar.
    'lifetime' => Env::int('SESSION_LIFETIME', 120),
    // Cookie apenas em HTTPS. Obrigatorio true em producao.
    'secure'   => Env::bool('SESSION_SECURE', false),
];
