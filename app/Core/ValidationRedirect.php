<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Sinaliza "voltar ao formulario com os erros na sessao".
 * Capturada pelo handler central de excecoes do App.
 */
final class ValidationRedirect extends RuntimeException
{
    public function __construct(private string $target)
    {
        parent::__construct('Validação falhou.');
    }

    public function target(): string
    {
        return $this->target;
    }
}
