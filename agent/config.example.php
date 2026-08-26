<?php

/**
 * ============================================================================
 *  CONTROLE VPS - CONFIGURACAO DO AGENTE
 * ============================================================================
 *
 *  Copie este arquivo para config.php e preencha os tres primeiros valores
 *  com o que o painel mostrou na tela de instalacao do agente.
 *
 *      cp config.example.php config.php
 *      chmod 600 config.php
 *      chown root:root config.php
 *
 *  ESTE ARQUIVO CONTEM O TOKEN DO SERVIDOR. Ele nao pode ser legivel por
 *  outros usuarios do sistema nem ser versionado.
 * ============================================================================
 */

return [
    // -----------------------------------------------------------------------
    // OBRIGATORIO - copiado do painel
    // -----------------------------------------------------------------------

    /** ID numerico do servidor no painel. */
    'SERVER_ID' => 0,

    /** Token exibido UMA UNICA VEZ no cadastro. Formato: cvps_<id>_<64 hex> */
    'SERVER_TOKEN' => 'SEU_TOKEN_AQUI',

    /**
     * URL da API central, terminando em /api.
     * Exemplo: https://monitoramento.seudominio.com.br/api
     */
    'CENTRAL_URL' => 'https://monitoramento.exemplo.com.br/api',

    // -----------------------------------------------------------------------
    // COLETA
    // -----------------------------------------------------------------------

    /**
     * Intervalo esperado entre execucoes, em segundos. Usado apenas para
     * mensagens e para a linha de cron sugerida - quem agenda de fato e o cron.
     */
    'INTERVAL' => 300,

    /** Fuso horario usado nos logs locais do agente. */
    'TIMEZONE' => 'America/Sao_Paulo',

    // -----------------------------------------------------------------------
    // COMUNICACAO COM O PAINEL
    // -----------------------------------------------------------------------

    'HTTP_TIMEOUT'         => 20,  // segundos para a resposta completa
    'HTTP_CONNECT_TIMEOUT' => 8,   // segundos para estabelecer a conexao
    'HTTP_RETRIES'         => 2,   // novas tentativas em falha temporaria

    /**
     * Verificacao do certificado TLS do PAINEL.
     *
     * Mantenha true em producao. Coloque false somente em homologacao com
     * certificado auto assinado - e volte para true depois.
     */
    'VERIFY_TLS' => true,

    /**
     * Silencia o aviso de CENTRAL_URL sem HTTPS. Use apenas em rede interna
     * controlada, conscientemente.
     */
    'ALLOW_INSECURE' => false,

    /** Dominios enviados por requisicao. Reduza se o painel recusar por tamanho. */
    'SITES_BATCH_SIZE' => 100,

    // -----------------------------------------------------------------------
    // VERIFICACAO DOS DOMINIOS
    // -----------------------------------------------------------------------

    /**
     * Quantos dominios sao verificados em paralelo.
     *
     * 10 e um bom equilibrio: rapido o bastante para centenas de sites sem
     * pesar no VPS. Aumente com cuidado em servidores com pouca CPU.
     */
    'CHECK_CONCURRENCY' => 10,

    'CHECK_TIMEOUT'         => 10, // segundos por dominio
    'CHECK_CONNECT_TIMEOUT' => 5,

    /**
     * Leitura alternativa do certificado (socket TLS direto) quando o cURL
     * nao entrega o certificado. Custa uma conexao a mais por dominio.
     */
    'SSL_FALLBACK'       => true,
    'SSL_TIMEOUT'        => 6,
    'SSL_FALLBACK_LIMIT' => 100,

    // -----------------------------------------------------------------------
    // LOG LOCAL
    // -----------------------------------------------------------------------

    'LOG_PATH'      => __DIR__ . '/logs',
    'LOG_KEEP_DAYS' => 14,
];
