<?php

declare(strict_types=1);

namespace Agent;

/**
 * Deteccao dos servicos do VPS (secao 6 do PLAN).
 *
 * Servicos verificados: OpenLiteSpeed, MariaDB/MySQL, Redis, CyberPanel,
 * aaPanel, Nginx, Apache e PHP.
 *
 * REGRA IMPORTANTE: a ausencia de um servico NAO e erro. Um VPS sem Redis e
 * uma configuracao legitima. Por isso existem tres desfechos distintos:
 *
 *   running        - instalado e ativo
 *   stopped        - instalado, porem parado (isso sim merece atencao)
 *   not_installed  - nao existe neste servidor (normal)
 *   unknown        - nao foi possivel determinar (sem systemctl/pgrep)
 */
final class ServicesService
{
    public function __construct(private Logger $logger)
    {
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function collect(): array
    {
        return [
            $this->openLiteSpeed(),
            $this->database(),
            $this->redis(),
            $this->cyberPanel(),
            $this->aaPanel(),
            $this->nginx(),
            $this->apache(),
            $this->php(),
        ];
    }

    /** @return array<string,mixed> */
    private function openLiteSpeed(): array
    {
        $installed = is_dir('/usr/local/lsws');

        if (!$installed) {
            return $this->entry('openlitespeed', 'OpenLiteSpeed', 'not_installed', null, 'Diretorio /usr/local/lsws nao encontrado');
        }

        $version = null;
        $content = Shell::readFile('/usr/local/lsws/VERSION', 64);

        if ($content !== null) {
            $version = trim($content);
        }

        if ($version === null) {
            $output = Shell::firstLine('cat', ['/usr/local/lsws/autoupdate/release'], 3);
            $version = $output === null ? null : trim($output);
        }

        $active = Shell::serviceIsActive('lsws', ['litespeed', 'lshttpd', 'openlitespeed']);

        return $this->entry('openlitespeed', 'OpenLiteSpeed', $this->statusFrom($active), $version);
    }

    /** @return array<string,mixed> */
    private function database(): array
    {
        $active = Shell::serviceIsActive('mariadb', ['mariadbd', 'mysqld']);

        // Tenta a unit alternativa sempre que a primeira NAO confirmou - e
        // nao apenas quando ela devolveu null. O aaPanel registra o banco
        // como `mysql`, entao perguntar so por `mariadb` da negativo num
        // servidor onde o servico esta perfeitamente no ar.
        if ($active !== true) {
            $alternativa = Shell::serviceIsActive('mysql', ['mysqld', 'mariadbd']);

            if ($alternativa !== null) {
                $active = $alternativa;
            }
        }

        $version = null;
        $output  = Shell::firstLine('mysql', ['--version'], 4);

        if ($output !== null && preg_match('/(?:Distrib|Ver)\s+([0-9.]+(?:-MariaDB)?)/i', $output, $m) === 1) {
            $version = $m[1];
        }

        $label = $version !== null && stripos($version, 'mariadb') !== false
            ? 'MariaDB'
            : 'MariaDB / MySQL';

        if ($active === null && $version === null) {
            return $this->entry('mariadb', $label, 'not_installed', null, 'Nenhum servidor MySQL/MariaDB detectado');
        }

        return $this->entry('mariadb', $label, $this->statusFrom($active), $version);
    }

    /** @return array<string,mixed> */
    private function redis(): array
    {
        $hasBinary = Shell::isAvailable('redis-server') || Shell::isAvailable('redis-cli');

        if (!$hasBinary && !is_file('/etc/redis/redis.conf')) {
            return $this->entry('redis', 'Redis', 'not_installed', null, 'Servico nao instalado neste servidor');
        }

        $version = null;
        $output  = Shell::firstLine('redis-server', ['--version'], 4);

        if ($output !== null && preg_match('/v=([0-9.]+)/', $output, $m) === 1) {
            $version = $m[1];
        }

        $active = Shell::serviceIsActive('redis', ['redis-server']);

        if ($active === null) {
            $active = Shell::serviceIsActive('redis-server', ['redis-server']);
        }

        return $this->entry('redis', 'Redis', $this->statusFrom($active), $version);
    }

    /** @return array<string,mixed> */
    private function cyberPanel(): array
    {
        $installed = is_dir('/usr/local/CyberCP');

        if (!$installed) {
            return $this->entry('cyberpanel', 'CyberPanel', 'not_installed', null, 'Diretorio /usr/local/CyberCP nao encontrado');
        }

        $version = CyberPanelService::detectVersion();

        // O painel do CyberPanel roda sob gunicorn (unidade lscpd).
        $active = Shell::serviceIsActive('lscpd', ['lscpd', 'gunicorn']);

        return $this->entry('cyberpanel', 'CyberPanel', $this->statusFrom($active), $version);
    }

    /** @return array<string,mixed> */
    private function aaPanel(): array
    {
        $installed = AaPanelService::isInstalled();

        if (!$installed) {
            return $this->entry('aapanel', 'aaPanel', 'not_installed', null, 'Diretorio /www/server/panel nao encontrado');
        }

        $version = AaPanelService::detectVersion();

        // O painel do aaPanel roda como servico `bt` (processos BT-Panel/BT-Task).
        $active = Shell::serviceIsActive('bt', ['BT-Panel', 'BT-Task']);

        return $this->entry('aapanel', 'aaPanel', $this->statusFrom($active), $version);
    }

    /** @return array<string,mixed> */
    private function nginx(): array
    {
        // aaPanel instala o Nginx em /www/server/nginx; outros setups usam o
        // binario no PATH. A versao nao e lida aqui de proposito: `nginx -v`
        // imprime em stderr, e a allowlist de Shell nao cobre esse caso.
        $installed = is_dir('/www/server/nginx') || Shell::isAvailable('nginx');

        if (!$installed) {
            return $this->entry('nginx', 'Nginx', 'not_installed', null, 'Nginx nao encontrado');
        }

        $active = Shell::serviceIsActive('nginx', ['nginx']);

        return $this->entry('nginx', 'Nginx', $this->statusFrom($active), null);
    }

    /** @return array<string,mixed> */
    private function apache(): array
    {
        // aaPanel instala o Apache em /www/server/apache; a unidade systemd
        // costuma ser `httpd` (CentOS/RHEL) ou `apache2` (Debian/Ubuntu).
        $installed = is_dir('/www/server/apache')
            || Shell::isAvailable('httpd')
            || Shell::isAvailable('apache2');

        if (!$installed) {
            return $this->entry('apache', 'Apache', 'not_installed', null, 'Apache nao encontrado');
        }

        $active = Shell::serviceIsActive('httpd', ['httpd', 'apache2']);

        return $this->entry('apache', 'Apache', $this->statusFrom($active), null);
    }

    /** @return array<string,mixed> */
    private function php(): array
    {
        // A versao do CLI que roda o agente. As versoes por site vem da
        // descoberta de dominios, que le a selecao de PHP de cada vhost.
        return $this->entry('php', 'PHP', 'running', \PHP_VERSION, 'Versao do PHP CLI que executa o agente');
    }

    /**
     * @return array<string,mixed>
     */
    private function entry(string $name, string $label, string $status, ?string $version, ?string $detail = null): array
    {
        return [
            'name'    => $name,
            'label'   => $label,
            'status'  => $status,
            'version' => $version === null ? null : mb_substr($version, 0, 60),
            'detail'  => $detail,
        ];
    }

    private function statusFrom(?bool $active): string
    {
        if ($active === null) {
            return 'unknown';
        }

        return $active ? 'running' : 'stopped';
    }
}
