<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Renderizador de views em PHP puro.
 *
 * Uma view vive em resources/views/<caminho>.php e recebe as chaves de $data
 * como variaveis locais. O conteudo renderizado e injetado no layout atraves
 * da variavel $content.
 *
 * Todo dado impresso deve passar por e() (helper de escape) - ver DESIGN.md
 * e secao 33 do PLAN.
 */
final class View
{
    private static string $viewPath = '';

    /** @var array<string,mixed> Dados disponiveis em todas as views. */
    private static array $shared = [];

    /**
     * Blocos de <script> empilhados pelas views.
     *
     * A view e renderizada ANTES do layout, entao o que ela empilha aqui ja
     * esta disponivel quando o layout imprime o rodape. E o jeito de uma
     * pagina levar seu proprio JS sem que o layout precise conhece-la.
     *
     * @var array<int,string>
     */
    private static array $scripts = [];

    public static function configure(string $viewPath): void
    {
        self::$viewPath = rtrim($viewPath, '/\\');
    }

    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    /** Empilha um bloco de HTML (normalmente <script>) para o rodape do layout. */
    public static function pushScript(string $html): void
    {
        self::$scripts[] = $html;
    }

    /** Consome e devolve os blocos empilhados. */
    public static function flushScripts(): string
    {
        $html          = implode("\n", self::$scripts);
        self::$scripts = [];

        return $html;
    }

    /** @param array<string,mixed> $data */
    public static function render(string $view, array $data = [], ?string $layout = 'layouts/app'): string
    {
        $content = self::renderFile($view, $data);

        if ($layout === null) {
            return $content;
        }

        return self::renderFile($layout, $data + ['content' => $content]);
    }

    /** @param array<string,mixed> $data */
    public static function partial(string $view, array $data = []): string
    {
        return self::renderFile($view, $data);
    }

    /** @param array<string,mixed> $data */
    private static function renderFile(string $view, array $data): string
    {
        $file = self::resolve($view);

        $scope = self::$shared;
        foreach ($data as $key => $value) {
            if (\is_string($key) && preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key) === 1) {
                $scope[$key] = $value;
            }
        }

        $level = ob_get_level();
        ob_start();

        try {
            (static function (string $__file, array $__scope): void {
                extract($__scope, EXTR_SKIP);
                require $__file;
            })($file, $scope);
        } catch (\Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $e;
        }

        return (string) ob_get_clean();
    }

    private static function resolve(string $view): string
    {
        // Impede path traversal vindo de um nome de view dinamico.
        if (str_contains($view, '..') || str_contains($view, "\0")) {
            throw new RuntimeException('Nome de view inválido: ' . $view);
        }

        $file = self::$viewPath . DIRECTORY_SEPARATOR
            . str_replace(['/', '.php'], [DIRECTORY_SEPARATOR, ''], $view) . '.php';

        if (!is_file($file)) {
            throw new RuntimeException('View não encontrada: ' . $view . ' (' . $file . ')');
        }

        return $file;
    }

    public static function exists(string $view): bool
    {
        try {
            self::resolve($view);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }
}
