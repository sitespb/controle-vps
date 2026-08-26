<?php

declare(strict_types=1);

/**
 * ============================================================================
 *  CONTROLE VPS - EXECUTOR DA SUITE DE TESTES
 * ============================================================================
 *
 *  Uso:
 *      php tests/run.php                    roda tudo
 *      php tests/run.php Auth               so os grupos cujo nome contem "Auth"
 *      php tests/run.php Agent replay       filtra grupo e cenario
 *
 *  Roda contra um banco SEPARADO (<DB_DATABASE>_test), recriado do zero a
 *  cada execucao. Os dados de desenvolvimento nao sao tocados.
 * ============================================================================
 */

if (\PHP_SAPI !== 'cli') {
    exit("A suite roda apenas via linha de comando.\n");
}

$testDatabase = require __DIR__ . '/bootstrap.php';

$groupFilter = $argv[1] ?? null;
$testFilter  = $argv[2] ?? null;

// ---------------------------------------------------------------------------
// Descoberta dos arquivos de teste
// ---------------------------------------------------------------------------

$files = glob(__DIR__ . '/Feature/*Test.php') ?: [];
sort($files);

/** @var array<int,\Tests\TestCase> $suites */
$suites = [];

foreach ($files as $file) {
    require_once $file;

    $class = 'Tests\\Feature\\' . basename($file, '.php');

    if (!class_exists($class)) {
        fwrite(\STDERR, "Classe nao encontrada em {$file}: {$class}\n");
        continue;
    }

    /** @var \Tests\TestCase $suite */
    $suite = new $class();

    if ($groupFilter !== null && stripos($suite->name(), $groupFilter) === false
        && stripos(basename($file, '.php'), $groupFilter) === false) {
        continue;
    }

    $suites[] = $suite;
}

if ($suites === []) {
    fwrite(\STDERR, "Nenhum grupo de teste corresponde ao filtro.\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Execucao
// ---------------------------------------------------------------------------

$colors = stream_isatty(\STDOUT);

$green = $colors ? "\033[0;32m" : '';
$red   = $colors ? "\033[0;31m" : '';
$gray  = $colors ? "\033[0;90m" : '';
$bold  = $colors ? "\033[1m" : '';
$reset = $colors ? "\033[0m" : '';

echo \PHP_EOL;
echo "{$bold}  Controle VPS - Suite de testes{$reset}" . \PHP_EOL;
echo "  " . str_repeat('=', 68) . \PHP_EOL;
echo "  Banco de teste: {$testDatabase}" . \PHP_EOL;
echo "  PHP: " . \PHP_VERSION . \PHP_EOL;

$totalPassed = 0;
$totalFailed = 0;
$failures    = [];
$started     = microtime(true);

foreach ($suites as $suite) {
    echo \PHP_EOL;
    echo "  {$bold}" . $suite->name() . "{$reset}" . \PHP_EOL;
    echo "  " . str_repeat('-', 68) . \PHP_EOL;

    $result = $suite->run($testFilter);

    foreach ($suite->results as $item) {
        if ($item['ok']) {
            echo "    {$green}PASSOU{$reset}  " . $item['name'] . \PHP_EOL;
        } else {
            echo "    {$red}FALHOU{$reset}  " . $item['name'] . \PHP_EOL;
            echo "            {$gray}" . $item['message'] . "{$reset}" . \PHP_EOL;

            $failures[] = $suite->name() . ' :: ' . $item['name'] . ' -> ' . $item['message'];
        }
    }

    if ($suite->results === []) {
        echo "    {$gray}(nenhum cenario corresponde ao filtro){$reset}" . \PHP_EOL;
    }

    $totalPassed += $result['passed'];
    $totalFailed += $result['failed'];
}

$elapsed = round(microtime(true) - $started, 2);

// ---------------------------------------------------------------------------
// Resumo
// ---------------------------------------------------------------------------

echo \PHP_EOL;
echo "  " . str_repeat('=', 68) . \PHP_EOL;

if ($totalFailed === 0) {
    echo "  {$green}{$bold}  {$totalPassed} teste(s) passaram em {$elapsed}s.{$reset}" . \PHP_EOL;
} else {
    echo "  {$red}{$bold}  {$totalFailed} falha(s) de " . ($totalPassed + $totalFailed) . " teste(s) em {$elapsed}s.{$reset}" . \PHP_EOL;
    echo \PHP_EOL;
    echo "  Falhas:" . \PHP_EOL;

    foreach ($failures as $failure) {
        echo "    - {$failure}" . \PHP_EOL;
    }
}

echo \PHP_EOL;

exit($totalFailed === 0 ? 0 : 1);
