<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Validador simples baseado em regras declarativas.
 *
 *   $v = Validator::make($request->all(), [
 *       'name'  => 'required|string|max:120',
 *       'email' => 'required|email|max:190',
 *       'role'  => 'required|in:admin,operator',
 *   ]);
 *   if ($v->fails()) { ... $v->errors() ... }
 *
 * Regras: required, string, int, numeric, bool, email, url, ip, domain,
 *         min:n, max:n, between:a,b, in:a,b,c, regex:/.../, confirmed,
 *         nullable, array, date, same:campo
 */
final class Validator
{
    /** @var array<string,string> */
    private array $errors = [];

    /** @var array<string,mixed> */
    private array $validated = [];

    /**
     * @param array<string,mixed>  $data
     * @param array<string,string> $rules
     * @param array<string,string> $labels Nome amigavel do campo nas mensagens.
     */
    private function __construct(
        private array $data,
        private array $rules,
        private array $labels = []
    ) {
        $this->run();
    }

    /**
     * @param array<string,mixed>  $data
     * @param array<string,string> $rules
     * @param array<string,string> $labels
     */
    public static function make(array $data, array $rules, array $labels = []): self
    {
        return new self($data, $rules, $labels);
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): ?string
    {
        return $this->errors === [] ? null : reset($this->errors);
    }

    /** @return array<string,mixed> */
    public function validated(): array
    {
        return $this->validated;
    }

    /** Lanca HttpException 422 quando ha erro - atalho para os controllers de API. */
    public function orFail(): array
    {
        if ($this->fails()) {
            throw HttpException::validation($this->errors);
        }

        return $this->validated;
    }

    private function run(): void
    {
        foreach ($this->rules as $field => $ruleString) {
            $rules    = explode('|', $ruleString);
            $value    = $this->data[$field] ?? null;
            $nullable = \in_array('nullable', $rules, true);
            $required = \in_array('required', $rules, true);

            $isEmpty = $value === null
                || (\is_string($value) && trim($value) === '')
                || (\is_array($value) && $value === []);

            if ($required && $isEmpty) {
                $this->addError($field, 'O campo %s é obrigatório.');
                continue;
            }

            if ($isEmpty) {
                // Campo opcional vazio: guarda null e nao aplica as demais regras.
                if ($nullable || !$required) {
                    $this->validated[$field] = $nullable ? null : $value;
                }
                continue;
            }

            $failed = false;

            foreach ($rules as $rule) {
                if ($rule === '' || $rule === 'required' || $rule === 'nullable') {
                    continue;
                }

                [$name, $param] = array_pad(explode(':', $rule, 2), 2, null);

                if (!$this->applyRule($field, (string) $name, $param, $value)) {
                    $failed = true;
                    break;
                }
            }

            if (!$failed) {
                $this->validated[$field] = $this->cast($rules, $value);
            }
        }
    }

    private function applyRule(string $field, string $rule, ?string $param, mixed $value): bool
    {
        switch ($rule) {
            case 'string':
                if (!\is_string($value)) {
                    return $this->addError($field, 'O campo %s deve ser um texto.');
                }
                break;

            case 'int':
            case 'integer':
                if (filter_var($value, FILTER_VALIDATE_INT) === false) {
                    return $this->addError($field, 'O campo %s deve ser um número inteiro.');
                }
                break;

            case 'numeric':
                if (!is_numeric($value)) {
                    return $this->addError($field, 'O campo %s deve ser numérico.');
                }
                break;

            case 'bool':
            case 'boolean':
                if (!\in_array((string) $value, ['0', '1', 'true', 'false', 'on', 'off'], true) && !\is_bool($value)) {
                    return $this->addError($field, 'O campo %s deve ser verdadeiro ou falso.');
                }
                break;

            case 'array':
                if (!\is_array($value)) {
                    return $this->addError($field, 'O campo %s deve ser uma lista.');
                }
                break;

            case 'email':
                if (filter_var((string) $value, FILTER_VALIDATE_EMAIL) === false) {
                    return $this->addError($field, 'Informe um e-mail válido no campo %s.');
                }
                break;

            case 'url':
                if (filter_var((string) $value, FILTER_VALIDATE_URL) === false) {
                    return $this->addError($field, 'Informe uma URL valida no campo %s.');
                }
                break;

            case 'ip':
                if (filter_var((string) $value, FILTER_VALIDATE_IP) === false) {
                    return $this->addError($field, 'Informe um endereço IP valido no campo %s.');
                }
                break;

            case 'domain':
                if (preg_match('/^(?=.{1,253}$)(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/i', (string) $value) !== 1) {
                    return $this->addError($field, 'Informe um domínio valido no campo %s.');
                }
                break;

            case 'hostname':
                if (preg_match('/^[a-z0-9]([a-z0-9\-\.]{0,251}[a-z0-9])?$/i', (string) $value) !== 1) {
                    return $this->addError($field, 'Informe um hostname valido no campo %s.');
                }
                break;

            case 'date':
                if (strtotime((string) $value) === false) {
                    return $this->addError($field, 'Informe uma data válida no campo %s.');
                }
                break;

            case 'min':
                $min = (float) $param;
                if (\is_string($value) && mb_strlen($value) < $min) {
                    return $this->addError($field, sprintf('O campo %%s deve ter no mínimo %d caracteres.', (int) $min));
                }
                if (is_numeric($value) && !\is_string($value) && (float) $value < $min) {
                    return $this->addError($field, sprintf('O campo %%s deve ser no mínimo %s.', $param));
                }
                break;

            case 'max':
                $max = (float) $param;
                if (\is_string($value) && mb_strlen($value) > $max) {
                    return $this->addError($field, sprintf('O campo %%s deve ter no máximo %d caracteres.', (int) $max));
                }
                if (is_numeric($value) && !\is_string($value) && (float) $value > $max) {
                    return $this->addError($field, sprintf('O campo %%s deve ser no máximo %s.', $param));
                }
                break;

            case 'between':
                [$a, $b] = array_pad(explode(',', (string) $param), 2, '0');
                $num     = (float) $value;
                if ($num < (float) $a || $num > (float) $b) {
                    return $this->addError($field, sprintf('O campo %%s deve estar entre %s e %s.', $a, $b));
                }
                break;

            case 'in':
                $options = explode(',', (string) $param);
                if (!\in_array((string) $value, $options, true)) {
                    return $this->addError($field, 'O valor selecionado em %s é inválido.');
                }
                break;

            case 'regex':
                if (@preg_match((string) $param, (string) $value) !== 1) {
                    return $this->addError($field, 'O campo %s tem formato inválido.');
                }
                break;

            case 'confirmed':
                if (($this->data[$field . '_confirmation'] ?? null) !== $value) {
                    return $this->addError($field, 'A confirmacao do campo %s não confere.');
                }
                break;

            case 'same':
                if (($this->data[(string) $param] ?? null) !== $value) {
                    return $this->addError($field, 'O campo %s não confere.');
                }
                break;
        }

        return true;
    }

    /** @param array<int,string> $rules */
    private function cast(array $rules, mixed $value): mixed
    {
        foreach ($rules as $rule) {
            if ($rule === 'int' || $rule === 'integer') {
                return (int) $value;
            }
            if ($rule === 'numeric') {
                return (float) $value;
            }
            if ($rule === 'bool' || $rule === 'boolean') {
                return \in_array((string) $value, ['1', 'true', 'on'], true) || $value === true;
            }
        }

        return \is_string($value) ? trim($value) : $value;
    }

    private function addError(string $field, string $template): bool
    {
        if (!isset($this->errors[$field])) {
            $this->errors[$field] = sprintf($template, $this->labels[$field] ?? $field);
        }

        return false;
    }
}
