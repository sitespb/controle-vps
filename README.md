# Controle VPS — Central de Monitoramento CyberPanel

Painel central para monitorar múltiplos servidores VPS com CyberPanel e os sites hospedados neles.

**Versão 1.0 — somente monitoramento.** Esta versão coleta e exibe informações. Ela **não** executa nenhuma ação nos servidores monitorados: sem SSH, sem execução de comandos, sem terminal web, sem criação ou exclusão de sites. O agente instalado em cada VPS apenas **envia** dados e jamais executa nada recebido do painel.

---

## Sumário

| Documento | Conteúdo |
|---|---|
| [docs/INSTALACAO-LOCAL.md](docs/INSTALACAO-LOCAL.md) | Instalar e rodar no Laragon/XAMPP |
| [docs/INSTALACAO-VPS.md](docs/INSTALACAO-VPS.md) | Publicar em produção (Ubuntu + CyberPanel + OpenLiteSpeed) |
| [docs/ARQUITETURA.md](docs/ARQUITETURA.md) | Como as peças se encaixam e por quê |
| [docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md) | Diagnóstico dos problemas mais comuns |
| [agent/README.md](agent/README.md) | Instalar e operar o agente no VPS |
| [.agents/PLAN.md](.agents/PLAN.md) | Especificação original do projeto |
| [.agents/PROGRESS.md](.agents/PROGRESS.md) | O que foi construído, decisão por decisão |
| [.agents/DESIGN.md](.agents/DESIGN.md) | Design system da interface |

---

## Arquitetura em uma tela

```text
                        PAINEL CENTRAL
                    PHP 8.2+ · MySQL 8 · Tailwind
                              │
                         API REST /api/v1
                    (HMAC-SHA256 + nonce + timestamp)
                              │
          ┌───────────────────┼───────────────────┐
          │                   │                   │
      AGENTE VPS 01       AGENTE VPS 02       AGENTE VPS 03
      (PHP CLI, cron)     (PHP CLI, cron)     (PHP CLI, cron)
          │                   │                   │
          ▼                   ▼                   ▼
      CyberPanel          CyberPanel          CyberPanel
      OpenLiteSpeed       OpenLiteSpeed       OpenLiteSpeed
```

**O fluxo é de dentro para fora, sempre.** O agente coleta localmente e faz `POST` no painel. O painel nunca inicia conexão com o VPS, e a resposta da API contém apenas confirmação e um número (`next_interval`) — nunca comandos.

---

## O que o sistema faz

### Servidores
Identificação (hostname, IP, SO, kernel, arquitetura), recursos (CPU, RAM, swap, disco, load average, uptime), serviços (OpenLiteSpeed, MariaDB/MySQL, Redis, CyberPanel, PHP) e histórico com gráficos de 6 h a 30 dias.

### Sites
Descoberta **automática** dos domínios no CyberPanel — o operador não cadastra site nenhum. Para cada domínio: status, HTTP status code, tempo de resposta, HTTPS, certificado SSL com dias restantes, IP, versão do PHP e detecção de WordPress com versão.

### Alertas
Sete tipos (`server_offline`, `server_cpu_high`, `server_memory_high`, `server_disk_high`, `site_offline`, `ssl_expiring`, `ssl_expired`), abertos e **resolvidos automaticamente** pelas regras. Um único alerta por problema enquanto ele durar, com contagem de reincidências e linha do tempo.

### Operação
Dois perfis (Administrador e Operador), log de auditoria de tudo que é administrativo, limpeza automática por retenção configurável e limites de alerta editáveis pela interface.

---

## Requisitos

| Item | Versão mínima | Observação |
|---|---|---|
| PHP | 8.2 | Extensões: `pdo_mysql`, `curl`, `openssl`, `mbstring`, `json` |
| MySQL / MariaDB | 8.0 / 10.5 | |
| Servidor web | Apache ou OpenLiteSpeed | Com `mod_rewrite` ou equivalente |
| Node.js | 18+ | **Somente** para recompilar o CSS ao alterar a interface |

O Composer é **opcional**: o projeto traz um autoloader PSR-4 próprio e não depende de nenhuma biblioteca externa em runtime. Alpine.js e Chart.js estão vendorizados em `public/assets/vendor/` — a aplicação funciona sem internet.

---

## Instalação rápida (local)

```bash
cd C:\laragon\www\controle-vps

# 1. Configuração
copy .env.example .env          # Linux/macOS: cp .env.example .env
# ajuste DB_* e APP_URL no .env

# 2. Instalação completa em um comando
php bin/console.php install --name="Seu Nome" --email=voce@empresa.com.br --password=SuaSenhaForte

# 3. Compilar o CSS (opcional — já vem compilado)
npm install && npm run build:css
```

O comando `install` gera a `APP_KEY`, cria o banco, roda as 16 migrations, cria o administrador e insere os dados de demonstração.

Acesse `http://controle-vps.test` (vhost do Laragon) ou o valor de `APP_URL`.

> Passo a passo detalhado, incluindo cron e vhost: [docs/INSTALACAO-LOCAL.md](docs/INSTALACAO-LOCAL.md).

---

## Dados de demonstração

O sistema vem com uma infraestrutura fictícia para você avaliar o funcionamento antes de conectar servidores reais: **8 servidores, 198 sites, ~9.000 amostras de métricas, ~5.000 verificações e 88 alertas**.

Tudo fica marcado com `is_demo = 1` e exibe o selo **DEMO** na interface.

> Os **alertas não são inventados**: depois de gerar servidores, métricas, sites e certificados, o seeder executa o motor real (`MonitoringService`, `SslService`). O que aparece na tela de Alertas foi produzido pelas mesmas regras que rodariam em produção.

```bash
php bin/console.php db:seed             # inserir
php bin/console.php db:seed --refresh   # rejuvenescer (desloca a série no tempo)
php bin/console.php db:seed --remove    # remover tudo antes de ir para produção
```

O `--refresh` existe porque os dados nascem com o horário da geração. Algumas horas depois, o cron — funcionando exatamente como deveria — marca todos os servidores como offline por falta de heartbeat. O refresh desloca a série inteira no tempo, preservando as curvas e o histórico.

---

## Console

```bash
php bin/console.php                       # lista todos os comandos

php bin/console.php install               # instalação completa
php bin/console.php key:generate          # gera APP_KEY
php bin/console.php db:create             # cria o banco
php bin/console.php db:check              # testa a conexão e conta as linhas
php bin/console.php migrate               # aplica migrations pendentes
php bin/console.php migrate:status        # o que já rodou e o que falta
php bin/console.php migrate:fresh         # recria o schema (destrutivo)
php bin/console.php user:create           # cria usuário
php bin/console.php user:list             # lista usuários
php bin/console.php user:password         # redefine senha
php bin/console.php routes                # rotas registradas
```

---

## Cron do painel

```cron
# Processamento de alertas — a cada 5 minutos
*/5 * * * * php /caminho/do/painel/cron/process-alerts.php --quiet >> /caminho/do/painel/storage/logs/cron.log 2>&1

# Limpeza e retenção — uma vez por dia
15 3 * * * php /caminho/do/painel/cron/cleanup.php --quiet >> /caminho/do/painel/storage/logs/cron.log 2>&1
```

Ambos usam trava de arquivo (`flock`), então uma execução nunca começa por cima da anterior.

---

## Testes

```bash
php tests/run.php              # suíte completa
php tests/run.php Agent        # só o grupo da API de agentes
php tests/run.php Agent replay # filtra o cenário
```

**116 testes** cobrindo login e força bruta, cadastro de servidor, ciclo de vida do token, autenticação HMAC do agente, replay attack, recepção de heartbeat/métricas/sites/serviços, classificação HTTP e SSL, criação e resolução automática de alertas, detecção de servidor offline, retenção, CSRF, SQL injection, XSS, rate limiting e permissões — além dos cenários de falha exigidos (timeout, certificado inválido, disco cheio, coleta vazia, banco indisponível).

A suíte roda contra um banco separado (`<DB_DATABASE>_test`), recriado do zero a cada execução. Os dados de desenvolvimento não são tocados.

---

## Estrutura

```text
controle-vps/
├── agent/                  Agente instalado em cada VPS (PHP CLI + Bash)
│   ├── agent.php           Executável principal
│   ├── install.sh          Instalador com validação e teste de conexão
│   └── lib/                CyberPanelService, SiteDiscoveryService, SslService...
├── app/
│   ├── Controllers/        Painel + Api/ (agentes e frontend, separados)
│   ├── Core/               Router, Request/Response, Database, View, Migrator
│   ├── Helpers/            Funções globais (escape, formatação, status)
│   ├── Middleware/         auth, role, csrf, agent, api, throttle
│   ├── Models/             Acesso por tabela
│   ├── Repositories/       Consultas compostas, filtros e paginação
│   └── Services/           Regras de negócio
├── bin/console.php         CLI de administração
├── config/                 app, database, session, log, monitoring
├── cron/                   process-alerts.php, cleanup.php, lock.php
├── database/
│   ├── migrations/         16 arquivos SQL comentados
│   └── seeders/            DemoSeeder
├── docs/                   Instalação, arquitetura e troubleshooting
├── public/                 Front controller e assets
├── resources/views/        Views em PHP puro
├── routes/                 web.php e api.php
├── storage/                logs/ e cache/
└── tests/                  Suíte executável
```

---

## Segurança

| Medida | Onde |
|---|---|
| Prepared statements em 100% das consultas | `app/Core/Database.php` |
| Senhas com `password_hash`/`password_verify` + rehash automático | `app/Services/AuthService.php` |
| CSRF em toda requisição que altera estado | `app/Middleware/CsrfMiddleware.php` |
| Escape de saída obrigatório | helper `e()` em todas as views |
| Força bruta: bloqueio por e-mail **e** por IP | `app/Services/AuthService.php` |
| Regeneração de sessão após login | `app/Core/Session.php` |
| Token do agente nunca trafega na rede | `app/Services/TokenService.php` |
| Assinatura HMAC-SHA256 cobrindo o corpo inteiro | `agent/lib/Signer.php` |
| Anti-replay por nonce com chave única no banco | `agent_nonces` |
| Rate limiting por servidor e por IP | `app/Services/RateLimiter.php` |
| Logs com redação automática de segredos | `app/Core/Logger.php` |

**A V1 não possui execução remota.** Parâmetros como `?command=`, `?exec=` ou `?cmd=` não têm efeito algum — há teste automatizado provando isso.

---

## O que **não** existe nesta versão

SSH · execução de comandos · terminal web · reiniciar VPS ou serviços · criar/excluir sites · gerenciar bancos, e-mail, DNS ou arquivos · backup e restauração · atualizar WordPress, plugins ou temas · instalar software.

A arquitetura está preparada para receber essas funcionalidades (serviços isolados, camada de API versionada, permissões por perfil), mas nada disso foi implementado — por decisão de escopo, não por omissão.
