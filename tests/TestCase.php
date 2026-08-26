<?php

declare(strict_types=1);

namespace Tests;

use App\Core\App;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

/**
 * Base minima de testes.
 *
 * Nao usamos PHPUnit de proposito: o projeto nao tem Composer instalado como
 * requisito (secao 35 do PLAN - "nao transformar o projeto em um framework"),
 * e uma suite executavel com `php tests/run.php` continua sendo uma suite de
 * verdade. Aqui estao apenas as asserts que os testes usam.
 */
abstract class TestCase
{
    /** @var array<int,array{name:string,ok:bool,message:string}> */
    public array $results = [];

    protected App $app;

    /** Nome legivel do grupo de testes. */
    abstract public function name(): string;

    public function __construct()
    {
        $this->app = new App(\BASE_PATH);
    }

    /** Roda antes de cada cenario. */
    protected function setUp(): void
    {
    }

    /** Roda depois de cada cenario. */
    protected function tearDown(): void
    {
    }

    /**
     * Executa todos os metodos que comecam com "test".
     *
     * @return array{passed:int,failed:int}
     */
    public function run(?string $filter = null): array
    {
        $passed = 0;
        $failed = 0;

        foreach (get_class_methods($this) as $method) {
            if (!str_starts_with($method, 'test')) {
                continue;
            }

            if ($filter !== null && stripos($method, $filter) === false) {
                continue;
            }

            $label = $this->humanize($method);

            try {
                $this->setUp();
                $this->{$method}();
                $this->tearDown();

                $this->results[] = ['name' => $label, 'ok' => true, 'message' => ''];
                $passed++;
            } catch (AssertionFailed $e) {
                $this->results[] = ['name' => $label, 'ok' => false, 'message' => $e->getMessage()];
                $failed++;

                try {
                    $this->tearDown();
                } catch (\Throwable) {
                    // tearDown com problema nao deve mascarar a falha real.
                }
            } catch (\Throwable $e) {
                $this->results[] = [
                    'name'    => $label,
                    'ok'      => false,
                    'message' => sprintf(
                        '%s: %s (%s:%d)',
                        $e::class,
                        $e->getMessage(),
                        basename($e->getFile()),
                        $e->getLine()
                    ),
                ];
                $failed++;

                try {
                    $this->tearDown();
                } catch (\Throwable) {
                }
            }
        }

        return ['passed' => $passed, 'failed' => $failed];
    }

    private function humanize(string $method): string
    {
        $text = preg_replace('/^test/', '', $method) ?? $method;
        $text = preg_replace('/(?<!^)[A-Z]/', ' $0', $text) ?? $text;

        return trim(mb_strtolower($text));
    }

    // -----------------------------------------------------------------
    // Asserts
    // -----------------------------------------------------------------

    protected function assertTrue(bool $condition, string $message = 'Esperava verdadeiro.'): void
    {
        if (!$condition) {
            throw new AssertionFailed($message);
        }
    }

    protected function assertFalse(bool $condition, string $message = 'Esperava falso.'): void
    {
        $this->assertTrue(!$condition, $message);
    }

    protected function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            throw new AssertionFailed(sprintf(
                '%sEsperado: %s | Obtido: %s',
                $message === '' ? '' : $message . ' ',
                $this->stringify($expected),
                $this->stringify($actual)
            ));
        }
    }

    protected function assertNotEquals(mixed $unexpected, mixed $actual, string $message = ''): void
    {
        if ($unexpected === $actual) {
            throw new AssertionFailed(
                ($message === '' ? '' : $message . ' ') . 'Os valores nao deveriam ser iguais: ' . $this->stringify($actual)
            );
        }
    }

    protected function assertNull(mixed $value, string $message = 'Esperava null.'): void
    {
        $this->assertTrue($value === null, $message . ' Obtido: ' . $this->stringify($value));
    }

    protected function assertNotNull(mixed $value, string $message = 'Esperava valor diferente de null.'): void
    {
        $this->assertTrue($value !== null, $message);
    }

    protected function assertCount(int $expected, array $actual, string $message = ''): void
    {
        $this->assertEquals($expected, \count($actual), $message === '' ? 'Quantidade incorreta.' : $message);
    }

    protected function assertContainsString(string $needle, string $haystack, string $message = ''): void
    {
        if (!str_contains($haystack, $needle)) {
            throw new AssertionFailed(
                ($message === '' ? '' : $message . ' ') . sprintf('"%s" nao encontrado no texto.', $needle)
            );
        }
    }

    protected function assertNotContainsString(string $needle, string $haystack, string $message = ''): void
    {
        if (str_contains($haystack, $needle)) {
            throw new AssertionFailed(
                ($message === '' ? '' : $message . ' ') . sprintf('"%s" NAO deveria aparecer no texto.', $needle)
            );
        }
    }

    protected function assertStatus(int $expected, Response $response, string $message = ''): void
    {
        if ($response->status() !== $expected) {
            $body = mb_substr(strip_tags($response->content()), 0, 200);

            throw new AssertionFailed(sprintf(
                '%sStatus esperado %d, obtido %d. Corpo: %s',
                $message === '' ? '' : $message . ' ',
                $expected,
                $response->status(),
                trim(preg_replace('/\s+/', ' ', $body) ?? '')
            ));
        }
    }

    // -----------------------------------------------------------------
    // Utilidades de requisicao
    // -----------------------------------------------------------------

    /**
     * @param array<string,mixed>  $body
     * @param array<string,string> $headers
     */
    protected function request(
        string $method,
        string $path,
        array $body = [],
        array $headers = [],
        array $query = []
    ): Response {
        $request = new Request(
            $method,
            $path,
            $query,
            $body,
            $headers + ['accept' => 'text/html'],
            ''
        );

        return $this->app->handle($request);
    }

    /**
     * Requisicao JSON com corpo cru - necessario para a API de agentes, onde
     * a assinatura cobre exatamente os bytes enviados.
     *
     * @param array<string,string> $headers
     */
    protected function jsonRequest(string $method, string $path, string $rawBody, array $headers = []): Response
    {
        $decoded = json_decode($rawBody, true);

        $request = new Request(
            $method,
            $path,
            [],
            \is_array($decoded) ? $decoded : [],
            $headers + ['content-type' => 'application/json', 'accept' => 'application/json'],
            $rawBody
        );

        return $this->app->handle($request);
    }

    /** @return array<string,mixed> */
    protected function decodeJson(Response $response): array
    {
        $decoded = json_decode($response->content(), true);

        if (!\is_array($decoded)) {
            throw new AssertionFailed('A resposta nao e um JSON valido: ' . mb_substr($response->content(), 0, 200));
        }

        return $decoded;
    }

    /** Autentica a sessao de teste como o usuario informado. */
    protected function loginAs(int $userId, string $role = 'admin'): void
    {
        $_SESSION['user_id']     = $userId;
        $_SESSION['user_role']   = $role;
        $_SESSION['_csrf_token'] = str_repeat('t', 64);

        \App\Services\AuthService::resetCache();
    }

    protected function logout(): void
    {
        $_SESSION = [];
        \App\Services\AuthService::resetCache();
    }

    protected function csrfToken(): string
    {
        if (!isset($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = str_repeat('t', 64);
        }

        return (string) $_SESSION['_csrf_token'];
    }

    /** Limpa as tabelas informadas respeitando as chaves estrangeiras. */
    protected function truncate(string ...$tables): void
    {
        $pdo = Database::connection();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        foreach ($tables as $table) {
            $pdo->exec('TRUNCATE TABLE `' . str_replace('`', '', $table) . '`');
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function stringify(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (\is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return (string) $value;
    }
}

/** Sinaliza a falha de uma assercao. */
final class AssertionFailed extends \RuntimeException
{
}
