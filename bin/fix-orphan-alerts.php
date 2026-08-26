<?php

declare(strict_types=1);

/**
 * ============================================================================
 *  Controle VPS - Encerra alertas orfaos de sites removidos
 * ============================================================================
 *
 *  POR QUE ESTE SCRIPT EXISTE
 *  --------------------------------------------------------------------------
 *  Quando um dominio deixa de ser descoberto no servidor, o painel marca o
 *  site como `discovered = 0` e para de checa-lo - mas os alertas que ja
 *  estavam abertos continuavam para sempre, porque nenhuma consulta de alerta
 *  filtra por `discovered`.
 *
 *  A correcao no SiteIngestService resolve isso DAQUI PARA FRENTE. Este
 *  script limpa o que ficou acumulado ANTES da correcao. Rode uma vez, depois
 *  pode esquecer dele.
 *
 *  O QUE ELE FAZ - E O QUE NAO FAZ
 *  --------------------------------------------------------------------------
 *  Faz:      marca como `resolved` os alertas ABERTOS de sites ja marcados
 *            como nao descobertos, e grava o motivo em `alert_events`.
 *  Nao faz:  nao apaga alerta nenhum, nao toca em sites, metricas,
 *            certificados ou historico, e nao mexe em alertas de SERVIDOR
 *            (CPU, RAM, disco, offline) - o servidor continua existindo.
 *
 *  Se um site voltar a ser descoberto, o ciclo seguinte reabre o alerta
 *  normalmente. Nada aqui e irreversivel do ponto de vista do monitoramento.
 *
 *  USO
 *  --------------------------------------------------------------------------
 *    php bin/fix-orphan-alerts.php                 # simulacao (nao grava nada)
 *    php bin/fix-orphan-alerts.php --apply         # executa
 *    php bin/fix-orphan-alerts.php --server=3      # limita a um servidor
 *    php bin/fix-orphan-alerts.php --apply --server=3
 *
 *  FACA BACKUP ANTES DO --apply:
 *    mysqldump -u USUARIO -p BANCO alerts alert_events > backup-alertas.sql
 * ============================================================================
 */

if (\PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script roda apenas via linha de comando.\n");
}

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/Core/Autoloader.php';

App\Core\Autoloader::register(BASE_PATH);
App\Core\App::bootstrap(BASE_PATH);

use App\Core\Database;
use App\Models\Alert;
use App\Models\AlertEvent;
use App\Services\AlertService;

// ---------------------------------------------------------------------------
// Argumentos
// ---------------------------------------------------------------------------

$options = [];

foreach (\array_slice($argv, 1) as $arg) {
    if (!str_starts_with($arg, '--')) {
        continue;
    }

    $arg = substr($arg, 2);

    if (str_contains($arg, '=')) {
        [$key, $value] = explode('=', $arg, 2);
        $options[$key] = trim($value, "\"'");
    } else {
        $options[$arg] = true;
    }
}

$apply    = isset($options['apply']);
$serverId = isset($options['server']) ? (int) $options['server'] : null;

echo \PHP_EOL;
echo '  Alertas orfaos de sites removidos' . \PHP_EOL;
echo '  ' . str_repeat('-', 68) . \PHP_EOL;
echo '  Modo: ' . ($apply ? 'APLICAR (grava no banco)' : 'SIMULACAO (nao grava nada)') . \PHP_EOL;

if ($serverId !== null) {
    echo '  Escopo: apenas o servidor #' . $serverId . \PHP_EOL;
}

echo \PHP_EOL;

// ---------------------------------------------------------------------------
// Levantamento
// ---------------------------------------------------------------------------

$types        = AlertService::SITE_ALERT_TYPES;
$placeholders = implode(',', array_fill(0, \count($types), '?'));

$sql = "SELECT a.id, a.type, a.status, a.severity, a.title, a.last_seen_at,
               s.id AS site_id, s.domain, s.server_id,
               srv.name AS server_name
        FROM alerts a
        INNER JOIN sites s ON s.id = a.site_id
        LEFT JOIN servers srv ON srv.id = s.server_id
        WHERE s.discovered = 0
          AND a.status IN ('open','acknowledged')
          AND a.type IN ($placeholders)";

$bindings = $types;

if ($serverId !== null) {
    $sql       .= ' AND s.server_id = ?';
    $bindings[] = $serverId;
}

$sql .= ' ORDER BY srv.name, s.domain, a.type';

$orphans = Database::select($sql, $bindings);

if ($orphans === []) {
    echo '  Nenhum alerta orfao encontrado. Nada a fazer.' . \PHP_EOL . \PHP_EOL;
    exit(0);
}

// ---------------------------------------------------------------------------
// Relatorio
// ---------------------------------------------------------------------------

$porServidor = [];

foreach ($orphans as $row) {
    $chave = (string) ($row['server_name'] ?? ('servidor #' . $row['server_id']));

    $porServidor[$chave][] = $row;
}

foreach ($porServidor as $servidor => $linhas) {
    echo '  ' . $servidor . '  (' . \count($linhas) . ' alerta(s))' . \PHP_EOL;

    foreach ($linhas as $row) {
        echo sprintf(
            '      #%-6d %-14s %-13s %-38s visto em %s%s',
            $row['id'],
            $row['type'],
            $row['status'],
            mb_substr((string) $row['domain'], 0, 38),
            (string) $row['last_seen_at'],
            \PHP_EOL
        );
    }

    echo \PHP_EOL;
}

$total         = \count($orphans);
$sitesAfetados = \count(array_unique(array_column($orphans, 'site_id')));
$reconhecidos  = \count(array_filter(
    $orphans,
    static fn (array $r): bool => $r['status'] === Alert::STATUS_ACKNOWLEDGED
));

echo '  ' . str_repeat('-', 68) . \PHP_EOL;
echo sprintf('  Total: %d alerta(s) em %d site(s) nao descoberto(s).%s', $total, $sitesAfetados, \PHP_EOL);

if ($reconhecidos > 0) {
    echo sprintf(
        '  Destes, %d ja estava(m) reconhecido(s) e tambem sera(o) encerrado(s).%s',
        $reconhecidos,
        \PHP_EOL
    );
}

if (!$apply) {
    echo \PHP_EOL;
    echo '  Simulacao: NADA foi gravado.' . \PHP_EOL;
    echo '  Confira a lista acima e, se estiver correta, rode de novo com --apply.' . \PHP_EOL;
    echo '  Antes disso: mysqldump ... alerts alert_events > backup-alertas.sql' . \PHP_EOL . \PHP_EOL;

    exit(0);
}

// ---------------------------------------------------------------------------
// Aplicacao
// ---------------------------------------------------------------------------

echo \PHP_EOL . '  Encerrando...' . \PHP_EOL;

$encerrados = 0;
$falhas     = 0;

foreach ($orphans as $row) {
    $id  = (int) $row['id'];
    $now = now_string();

    try {
        Database::statement(
            'UPDATE alerts SET status = ?, resolved_at = ?, updated_at = ? WHERE id = ?',
            [Alert::STATUS_RESOLVED, $now, $now, $id]
        );

        AlertEvent::record(
            $id,
            AlertEvent::RESOLVED,
            sprintf(
                'Dominio %s nao esta mais no servidor: alerta encerrado pela limpeza de orfaos.',
                (string) $row['domain']
            )
        );

        $encerrados++;
    } catch (\Throwable $e) {
        $falhas++;

        echo sprintf('      [ERRO] alerta #%d: %s%s', $id, $e->getMessage(), \PHP_EOL);
    }
}

echo \PHP_EOL;
echo sprintf('  %d alerta(s) encerrado(s).%s', $encerrados, \PHP_EOL);

if ($falhas > 0) {
    echo sprintf('  %d falha(s) - veja as mensagens acima.%s', $falhas, \PHP_EOL);
}

echo \PHP_EOL;
echo '  Observacao: alertas resolvidos passam a ser elegiveis para o expurgo' . \PHP_EOL;
echo '  de retencao do cron/cleanup.php apos o prazo configurado.' . \PHP_EOL . \PHP_EOL;

exit($falhas > 0 ? 1 : 0);
