<?php

declare(strict_types=1);

use App\Core\Env;

return [
    'name'     => (string) Env::get('APP_NAME', 'Controle VPS'),
    'env'      => (string) Env::get('APP_ENV', 'production'),
    'debug'    => Env::bool('APP_DEBUG', false),
    'url'      => rtrim((string) Env::get('APP_URL', 'http://localhost'), '/'),
    'key'      => (string) Env::get('APP_KEY', ''),
    'timezone' => (string) Env::get('APP_TIMEZONE', 'America/Sao_Paulo'),
    'version'  => '1.0.0',

    /*
     * Confiar em X-Forwarded-For. Ative apenas quando a aplicacao estiver
     * atras de um proxy reverso confiavel (Cloudflare, load balancer).
     * Com isso desligado, o rate limit nao pode ser burlado por spoofing.
     */
    'trust_proxy' => Env::bool('APP_TRUST_PROXY', false),
];
