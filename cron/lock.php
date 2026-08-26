<?php

declare(strict_types=1);

/**
 * Trava de execucao para os scripts de cron.
 *
 * Impede que uma nova execucao comece enquanto a anterior ainda roda - o que
 * aconteceria, por exemplo, se a limpeza de uma tabela grande demorasse mais
 * que o intervalo do cron.
 *
 * Usa flock() sobre um arquivo em storage/cache. O lock e liberado pelo
 * sistema operacional mesmo se o processo morrer, entao nao ha risco de travar
 * o cron para sempre por causa de um crash.
 *
 * A trava tambem guarda o horario de inicio, o que permite detectar execucoes
 * penduradas por tempo demais.
 */
final class CronLock
{
    /** @var resource|null */
    private $handle = null;

    private string $file;

    public function __construct(
        private string $name,
        private int $staleAfterSeconds = 3600
    ) {
        $directory = BASE_PATH . '/storage/cache';

        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        $safeName   = preg_replace('/[^a-z0-9\-_]/i', '', $name) ?: 'cron';
        $this->file = $directory . '/' . $safeName . '.lock';
    }

    public function acquire(): bool
    {
        $this->releaseIfStale();

        $handle = @fopen($this->file, 'c+');

        if ($handle === false) {
            // Sem poder criar a trava, deixamos passar: bloquear o cron por
            // causa de permissao de arquivo seria pior que o risco de
            // sobreposicao.
            return true;
        }

        if (!flock($handle, \LOCK_EX | \LOCK_NB)) {
            fclose($handle);

            return false;
        }

        ftruncate($handle, 0);
        fwrite($handle, (string) json_encode([
            'pid'        => getmypid(),
            'started_at' => date('c'),
            'script'     => $this->name,
        ]));
        fflush($handle);

        $this->handle = $handle;

        return true;
    }

    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }

        flock($this->handle, \LOCK_UN);
        fclose($this->handle);
        $this->handle = null;

        @unlink($this->file);
    }

    /**
     * Remove uma trava obviamente abandonada.
     *
     * flock ja se resolve quando o processo morre, mas o ARQUIVO fica. Em
     * sistemas onde o lock nao e liberado (NFS, por exemplo), este limite de
     * tempo evita que o cron pare de rodar em definitivo.
     */
    private function releaseIfStale(): void
    {
        if (!is_file($this->file)) {
            return;
        }

        $age = time() - (int) filemtime($this->file);

        if ($age > $this->staleAfterSeconds) {
            @unlink($this->file);
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
