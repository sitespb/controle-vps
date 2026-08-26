# PROGRESS.md — Registro de execução

> Diário do que foi construído, decisão por decisão, com o **porquê** de cada escolha não óbvia.
>
> **Projeto:** Controle VPS — Central de Monitoramento CyberPanel · **Versão:** 1.0.0
> **Início:** 14/08/2026 · **Conclusão da V1:** 15/08/2026
> Complementa: [PLAN.md](PLAN.md) (escopo) · [DESIGN.md](DESIGN.md) (interface)

---

## Situação: **V1 concluída e funcional**

| Verificação | Resultado |
|---|---|
| Suíte de testes | **116/116 passando** (~30 s) |
| Lint (`php -l`) em todos os `.php` | **0 erros** |
| Páginas do painel renderizando | **17/17** |
| Migrations aplicadas | **16/16** |
| Agente real → API real → banco | **validado ponta a ponta** |
| Crons executados de verdade | **process-alerts e cleanup** |

---

## 1. Ambiente apurado antes de escrever código

Antes de decidir qualquer coisa, levantei o que a máquina realmente tem:

| Item | Encontrado | Consequência |
|---|---|---|
| PHP ativo do Laragon | **8.3.30** com `pdo_mysql`, `curl`, `openssl`, `mbstring` | Atende o requisito de 8.2+ |
| PHP 8.2.31 (outra pasta) | **sem `php.ini`**, portanto sem extensão nenhuma | Descartado — documentado no guia de instalação |
| MySQL | **8.0.30** rodando | — |
| Banco `controle-vps` | já existia, **vazio** | Nenhum dado a preservar |
| Node / npm | 24.14.1 / 11.11.0 | Build do Tailwind viável |
| Composer | **ausente do PATH** | Decisão: autoloader PSR-4 próprio |

**Decisão nº 1 — não depender do Composer.** O projeto traz `app/Core/Autoloader.php`. Se o `vendor/autoload.php` existir, ele é usado; se não, o autoloader próprio resolve o namespace `App\`. Zero dependência em runtime. O `composer.json` existe para quem quiser adicionar bibliotecas no futuro, mas nada quebra sem ele.

**Decisão nº 2 — vendorizar Alpine.js e Chart.js.** Baixados via npm e copiados para `public/assets/vendor/`. O DESIGN.md previa CDN; preferi local porque um painel de monitoramento precisa abrir quando a internet está com problema — que é justamente quando você mais precisa dele.

---

## 2. Decisões confirmadas com o usuário

Perguntei apenas o que mudaria o trabalho de forma material:

1. **Paleta** — o `DESIGN.md` define `primary: #c8102e` (vermelho). Num painel de monitoramento, vermelho é a cor de "crítico". Levantei o conflito e o usuário optou por **manter o vermelho**. Resolvido na prática seguindo a própria regra do documento: `bg-primary` só para ação primária; status e alertas usam os tons semânticos (`red-100`/`red-800` em badges, `border-red-500` em bordas). Nos **gráficos**, séries usam azul/violeta/teal/âmbar — verde/amarelo/vermelho ficam reservados ao significado.

2. **URL local** — vhost `http://controle-vps.test`. Exigiu um `.htaccess` na raiz, já que o vhost automático do Laragon aponta para a raiz do projeto, não para `public/`.

---

## 3. O que foi construído

### Etapa 1 — Estrutura e Core

`app/Core/` com o mínimo necessário e nada além: `Autoloader`, `Env`, `Config`, `Database`, `Request`, `Response`, `Router`, `View`, `Session`, `Csrf`, `Validator`, `Logger`, `Model`, `Controller`, `Migrator`, `HttpException`, `App`.

Notas de projeto:

- **`Env`** guarda os valores só em memória — não escreve em `$_ENV` nem `putenv()`, para não vazar em `phpinfo()` nem em processos filhos.
- **`Database`** com `ATTR_EMULATE_PREPARES => false` (prepares reais no servidor, a melhor barreira contra injeção) e `ATTR_ERRMODE => EXCEPTION`.
- **`Router`** com pipeline de middleware, restrição inline de parâmetro (`{id:\d+}`) e **405 em vez de 404** quando o caminho existe em outro método.
- **`View`** com `pushScript()`: a view renderiza antes do layout, então ela consegue empilhar o próprio JS sem que o layout precise conhecê-la.

### Etapa 2 — Banco de dados

**16 migrations SQL**, cada uma comentada com o porquê das escolhas:

| Tabela | Ponto de projeto |
|---|---|
| `users` | Somente hash de senha |
| `login_attempts` | Bloqueio por e-mail **e** por IP — trocar de e-mail não contorna o limite |
| `servers` | `is_demo` separa demonstração de dado real |
| `server_tokens` | Só o SHA-256; regenerar cria linha nova e revoga a anterior (histórico auditável) |
| `server_metrics` | Índice `(server_id, created_at)` — o padrão de acesso real |
| `sites` | Único `(server_id, domain)` → reenviar a lista atualiza, não duplica |
| `site_checks` | `status_changed` marca transições, evitando varrer todo o histórico |
| `ssl_certificates` | `days_remaining` desnormalizado de propósito, recalculado por cron |
| `alerts` | `fingerprint` + índice `(fingerprint, status)` = deduplicação em O(1) |
| `agent_nonces` | Único `(server_id, nonce)` — **o banco** rejeita o replay, sem corrida no PHP |
| `settings` | Notação de ponto igual à do `Config`, sobrepondo os padrões do arquivo |

### Etapa 3 a 5 — Autenticação, dashboard, servidores

Login com `password_verify`, rehash automático quando o algoritmo padrão do PHP evolui, `session_regenerate_id()` após autenticar e **resposta idêntica** para "usuário não existe" e "senha errada" — enumerar e-mails válidos por diferença de mensagem é um vetor real, e há teste provando que as mensagens são iguais.

Dashboard com os seis cards da seção 10, medidores de CPU/RAM/disco e listas de atenção.

Cadastro de servidor: gera UID, token seguro, salva e mostra as instruções de instalação. **O token viaja pela sessão com validade de 15 minutos e é consumido na leitura** — recarregar a página já não o mostra. Há teste para isso.

### Etapa 6 e 7 — API e agente

Duas superfícies deliberadamente separadas:

- `/api/v1/agent/*` — assinatura HMAC, sem cookie, **somente POST de entrada**;
- `/api/v1/*` — sessão do navegador, consumida pelo `fetch` das telas.

O agente (`agent/`) tem 11 arquivos, roda em PHP CLI e não instala nada. O `install.sh` valida os requisitos, confere se o token pertence ao `--server-id` informado, gera o `config.php` em 600 e **testa a conexão antes de registrar o cron**.

### Etapa 8 a 11 — Coleta, descoberta, HTTP e SSL

**Métricas:** `/proc/stat` lido duas vezes com 500 ms de intervalo (uma leitura única daria a média desde o boot); `MemAvailable` em vez de `MemFree`; valor não lido vira `null`, nunca zero.

**Descoberta:** cadeia de três mecanismos — banco do CyberPanel → vhosts do OpenLiteSpeed → `/home`. A API HTTP do CyberPanel foi descartada: exigiria credenciais de administrador em texto no config e faria chamada de rede para ler dado que já está no disco.

**HTTP:** `curl_multi` com concorrência de 10. Em série, 200 domínios × 10 s de timeout passariam de meia hora — inviável para um cron de 5 minutos.

**SSL:** `CURLOPT_CERTINFO` traz o certificado **na mesma requisição** do HTTP. Um segundo socket TLS por domínio seria o dobro do trabalho pelo mesmo dado. Existe plano B (socket direto) para builds de cURL sem OpenSSL.

> **Detalhe deliberado:** nas sondas, a verificação de peer fica **desligada**. O objetivo é diagnosticar o certificado ruim — abortar o handshake esconderia exatamente o que queremos relatar. A comunicação do agente **com o painel** verifica TLS normalmente.

### Etapa 12 a 14 — Histórico, alertas e gráficos

Downsample para no máximo 288 pontos: 30 dias de disco são 8.640 amostras, o que travaria o Chart.js. O último ponto real é sempre preservado.

Motor de alertas com deduplicação por fingerprint e **resolução automática**. As ações manuais são complementares — se a condição persistir, a próxima coleta reabre. O botão não engana o operador.

### Etapa 15 a 19 — Logs, segurança, testes, documentação

Auditoria em banco + arquivo, com `Logger::redact()` mascarando automaticamente qualquer chave que pareça segredo. Suíte de 116 testes. Quatro documentos em `docs/` mais os READMEs.

---

## 4. Bugs encontrados e corrigidos

Registro honesto do que quebrou durante a construção — todos capturados por verificação, não por sorte.

### 4.1 `ssl` é palavra reservada no MySQL 8

**Sintoma:** erro de sintaxe em toda consulta que usava `ssl_certificates ssl`.
**Correção:** alias renomeado para `cert` em `Site`, `SslCertificate` e `SiteRepository`.

### 4.2 `only_full_group_by` com placeholder repetido

**Sintoma:** o dashboard quebrava em `sslSummary()`.
**Causa:** `COALESCE(cert.status, ?)` no `SELECT` e no `GROUP BY` — o MySQL não reconhece dois placeholders como a mesma expressão.
**Correção:** `'none'` como literal. É constante do próprio código, sem superfície para injeção.

### 4.3 Transação aninhada deixava a conexão travada

**Sintoma:** 60+ testes falhando com *"There is already an active transaction"*.
**Causa:** `ServerProvisionService::create()` abre transação e chama `TokenService::generateFor()`, que abre outra. A exceção do `beginTransaction()` acontece **antes** do try/rollback — a transação externa ficava pendurada e envenenava tudo depois.
**Correção:** `Database::transaction()` passou a ser **reentrante**: com transação já aberta, executa o callback dentro dela. O commit pertence sempre ao chamador mais externo.

### 4.4 Placeholder nomeado repetido nos upserts *(o mais grave)*

**Sintoma:** 5 testes falhando — serviços e certificados não chegavam ao banco.
**Causa:** `ServerService::upsert()` e `SslCertificate::upsert()` usavam `:now` duas vezes. Com prepares **nativos** (`EMULATE_PREPARES = false`), o MySQL não aceita o mesmo parâmetro nomeado repetido.
**Correção:** placeholders distintos (`:created_at`, `:updated_at`).

> **Por que este importa:** o seeder usava `Database::insert()` (placeholders únicos) e funcionava perfeitamente. A falha existia **apenas no caminho do agente real** — exatamente o caminho que não dá para verificar olhando a tela. Sem a suíte de testes, isso teria ido para produção e o sintoma seria "os serviços e o SSL nunca aparecem", sem erro visível em lugar nenhum.

### 4.5 `*/5` dentro de bloco de comentário PHP

**Sintoma:** erro de parse em `cron/process-alerts.php`.
**Causa:** a sequência `*/` de `*/5 * * * *` fechava o comentário.
**Correção:** a linha de crontab saiu do docblock e foi para a documentação.

### 4.6 `Session::destroy()` inerte em CLI

**Sintoma:** o teste de logout falhava.
**Causa:** o método retornava cedo quando não havia sessão ativa — em CLI, `$_SESSION` nunca era limpo.
**Correção:** os dados saem primeiro, sempre; o `session_destroy()` do PHP vem depois, se aplicável.

### 4.7 Demonstração sem alertas de recurso

**Sintoma:** só 1 dos 3 alertas de recurso previstos aparecia.
**Causa:** a ondulação diária das métricas geradas deixava o último ponto num vale — e o motor sempre olha a **última** amostra.
**Correção:** as 3 amostras mais recentes recebem exatamente o valor-alvo do perfil, mantendo a série contínua.

### 4.8 Servidor offline da demonstração sem alerta

**Causa:** o seeder inseria o servidor já com `status = 'offline'`, e `staleServers()` ignora quem já está offline — o alerta nunca era criado.
**Correção:** todos nascem `online`; quem tem `last_seen_at` antigo é rebaixado pelo **motor real**. O alerta passou a ser genuíno.

### 4.9 Ruído de shell no Windows

**Correção:** `Shell::canExecute()` retorna `false` fora do Linux. O agente é para VPS Linux; as leituras de `/proc` e as funções nativas do PHP continuam funcionando.

### 4.10 BOM UTF-8 injetado por edição via PowerShell

**Sintoma:** risco de "headers already sent".
**Causa:** `Set-Content -Encoding UTF8` no Windows PowerShell 5.1 grava **com BOM**.
**Correção:** BOM removido dos 3 arquivos afetados; edições posteriores feitas apenas por ferramentas que não inserem BOM.

---

## 5. Validação executada

### Suíte de testes — 116 cenários

| Grupo | Cenários | Cobre |
|---|---:|---|
| Autenticação | 12 | Login, senha errada, usuário inativo, força bruta, logout, não-enumeração de e-mails |
| Servidores e tokens | 11 | Cadastro, hash do token, regeneração, exclusão em cascata, CSRF, permissões |
| API dos agentes | 21 | Assinatura, corpo adulterado, token de outro servidor, token revogado, timestamp velho/futuro, **replay**, ingestão de métricas/sites/serviços, idempotência |
| Monitoramento e alertas | 35 | Classificação HTTP e SSL, limites, deduplicação, resolução automática, offline/recuperação, retenção |
| Segurança | 21 | CSRF, SQL injection, XSS, rate limiting, permissões, ausência de execução remota, redação de logs |
| Cenários de falha | 16 | Timeout, certificado inválido, disco cheio, coleta vazia, tipos errados, rate limiter indisponível |

Roda contra `controle-vps_test`, recriado do zero a cada execução.

### Validação ponta a ponta do agente real

Não me contentei com testes em processo. Subi um servidor HTTP, cadastrei um servidor de verdade, gerei `config.php` e rodei o binário do agente:

```text
[1/4] Heartbeat        OK  DELL-I7 | Windows
[2/4] Métricas         OK  Disco 94.58%
                       ALERTA aberto no painel: server_disk_high
[3/4] Serviços         OK  5 serviços reportados
[4/4] Sites            OK  0 domínios (sem CyberPanel — degradou como previsto)
```

Confirmado no banco: identificação gravada, métrica persistida, 5 serviços, **1 alerta crítico aberto pelas regras reais** a partir do disco real da máquina, 5 nonces consumidos, `last_used_at` do token atualizado.

CPU, RAM e load vieram `null` — correto: o Windows não tem `/proc`, e o agente reporta ausência de dado em vez de fabricar zeros.

### Crons executados de verdade

```text
process-alerts:  7 servidores marcados offline, 165 certificados recalculados,
                 53 avaliados, 31 sites offline — em 1,27 s
cleanup:         política aplicada, 80 métricas fora do prazo identificadas
```

---

## 6. Dados de demonstração

**8 servidores · 198 sites · 9.020 métricas · 4.950 verificações · 168 certificados · 88 alertas**

Perfis desenhados para exercitar cada regra:

| Servidor | Perfil | Alerta que produz |
|---|---|---|
| VPS Recife | disco em 87,4% | `server_disk_high` (atenção) |
| VPS São Paulo 02 | CPU em 93,2% | `server_cpu_high` (crítico) |
| VPS Fortaleza | RAM em 84,6% | `server_memory_high` (atenção) |
| VPS Natal | sem heartbeat há 2h20 | `server_offline` (crítico) |
| Outros 4 | saudáveis | nenhum |

Certificados distribuídos para as quatro cores: 112 válidos, 48 vencendo, 5 expirados, 3 sem dados.

> **Os alertas não são inventados.** Depois de gravar tudo, o seeder chama `MonitoringService` e `SslService` — o motor real. Os números na tela batem com os limites configurados porque foram produzidos por eles.

Tudo marcado com `is_demo = 1` e selo **DEMO** na interface. `db:seed --refresh` desloca a série no tempo para a demonstração não envelhecer; `db:seed --remove` limpa antes de produção.

---

## 7. Conformidade com o PLAN

### Resultado esperado da V1 (seção 40) — 24/24

| # | Item | # | Item |
|---|---|---|---|
| 1 | Fazer login ✅ | 13 | Visualizar uptime ✅ |
| 2 | Cadastrar servidores ✅ | 14 | Verificar SSL ✅ |
| 3 | Gerar tokens ✅ | 15 | Verificar HTTP ✅ |
| 4 | Instalar agentes ✅ | 16 | Detectar servidores offline ✅ |
| 5 | Receber dados dos agentes ✅ | 17 | Detectar sites offline ✅ |
| 6 | Descobrir sites automaticamente ✅ | 18 | Criar alertas ✅ |
| 7 | Visualizar todos os servidores ✅ | 19 | Resolver alertas automaticamente ✅ |
| 8 | Visualizar todos os sites ✅ | 20 | Visualizar histórico ✅ |
| 9 | Visualizar CPU ✅ | 21 | Visualizar gráficos ✅ |
| 10 | Visualizar RAM ✅ | 22 | Administrar usuários ✅ |
| 11 | Visualizar disco ✅ | 23 | Visualizar logs ✅ |
| 12 | Visualizar load ✅ | 24 | Manutenção automática via cron ✅ |

### Testes obrigatórios (seção 43) — todos cobertos

Login, logout, autenticação, criação de servidor, geração de token, autenticação do agente, heartbeat, métricas, descoberta de sites, site online/offline, SSL válido/expirado, servidor offline, criação e resolução de alerta, limpeza de métricas, permissões, CSRF, validação de API — mais os cenários de falha: sem internet, sem acesso ao painel, sem banco, site indisponível, timeout, certificado inválido, disco cheio.

### Segurança (seção 33) — implementada integralmente

Prepared statements · PDO · CSRF · escaping · validação · anti-SQL-injection · anti-XSS · anti-força-bruta · sessões seguras · regeneração de sessão · HTTPS em produção · tokens CSPRNG · rate limiting.

### Fora de escopo (seção 41) — nada implementado

SSH · execução de comandos · terminal · reiniciar VPS ou serviços · criar/excluir sites · bancos · e-mail · DNS · arquivos · backup · WordPress · plugins · temas · instalação de software.

**Verificado por teste:** `testParametrosDeComandoNaoTemEfeito` e `testNaoExisteRotaDeExecucaoRemota`.

---

## 8. Desvios do PLAN — e a justificativa

Três, todos conscientes:

| # | Desvio | Por quê |
|---|---|---|
| 1 | **Sem Composer como requisito** | Não estava no PATH da máquina. Autoloader próprio, zero dependência em runtime. O `composer.json` existe para o futuro. |
| 2 | **Alpine.js e Chart.js locais, não CDN** | O DESIGN.md previa CDN. Um painel de monitoramento precisa abrir quando a internet está com problema. |
| 3 | **JS em `public/assets/js/`, não em `resources/js/`** | O JS não passa por build. Mantê-lo em `resources/` exigiria um passo de cópia sem benefício. O `resources/css/` continua como fonte do Tailwind, esse sim compilado. |

Nenhum altera a arquitetura nem impede a migração para produção.

---

## 9. Números finais

| Métrica | Valor |
|---|---|
| Arquivos PHP (aplicação, agente e testes) | 130 |
| Migrations SQL | 16 |
| Rotas registradas | 44 (26 GET + 18 POST) |
| Views | 27 |
| Testes | 116 |
| Endpoints de agente | 4 |
| Tipos de alerta | 7 |
| CSS compilado | 23,3 KB minificado |
| JS vendorizado | 250 KB (Alpine 46 KB + Chart.js 204 KB) |

---

## 10. Próximos passos sugeridos

**Antes de usar em produção**

1. `php bin/console.php db:seed --remove`
2. `.env`: `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE=true`
3. Seguir [docs/INSTALACAO-VPS.md](../docs/INSTALACAO-VPS.md)
4. Instalar o agente no primeiro VPS real e confirmar o status **Online**

**Melhorias naturais da V1.x** (não são V2, não envolvem execução remota)

- Notificação externa de alertas (e-mail, Telegram, webhook)
- Agregação de métricas antigas para retenção longa com menos linhas
- Exportação CSV/PDF dos relatórios
- Filtro por tag ou grupo de servidores

**V2** — exige projeto próprio de segurança. O ponto de partida está em [docs/ARQUITETURA.md](../docs/ARQUITETURA.md), seção 11: o que já está preparado e o que falta **deliberadamente**.

---

## 11. Pós-V1 — Suporte ao aaPanel (em andamento)

A V1 foi feita em torno do CyberPanel. A partir de 23/08/2026, o objetivo é estender a
descoberta de sites para o **aaPanel**, que é o painel do servidor real `154.53.49.227`
(servidor **#4**, nome "Aapanel 154.53.49.227"). O servidor do sistema de monitoramento
em si é outro: `31.220.96.46`, que roda **CloudPanel** e já tem agente instalado.

> **Cuidado de contexto:** esses dois servidores não podem ser confundidos. O agente que
> falta instalar é o do aaPanel (`154.53.49.227`); o `31.220.96.46` já hospeda o sistema
> e o agente dele está ativo.

### 11.1 Bug corrigido — URL de instalação gerada com `/api/v1` duplicado

**Sintoma:** o comando de instalação gerado por `ServerProvisionService::installationInstructions()`
montava a URL como `.../api/v1`, mas o `ApiClient` do agente já concatena `/v1/...` por
conta própria. O agente batia em `.../api/v1/v1/agent/heartbeat` → **404** no primeiro
heartbeat.

**Causa:** `rtrim(Config::get('app.url'), '/') . '/api/v1'` — o sufixo `/v1` pertence ao
cliente, não à configuração.

**Correção:** a URL base passou a terminar em `/api` (sem `/v1`). O agente monta a rota
completa sozinho.

### 11.2 Agente instalado no servidor aaPanel (#4)

Instalado o agente em `154.53.49.227` (aaPanel). Resultado do `install.sh`:

```text
INSTALAÇÃO CONCLUÍDA
autenticado como 'Aapanel 154.53.49.227'
0 domínios descobertos
```

O coração do agente (heartbeat + autenticação HMAC) funcionou de primeira. Os **0 domínios**
são esperados e temporários: a cadeia de descoberta ainda só conhece CyberPanel / OpenLiteSpeed
/ `/home`, e o aaPanel organiza os sites em `/www/server/panel/vhost/{nginx,apache}/*.conf`
e `/www/wwwroot/<domínio>`. É exatamente o que a seção 11.3 está implementando.

### 11.3 Suporte ao aaPanel no agente *(implementação em andamento)*

Estratégia: um novo `Agent\AaPanelService` com a **mesma assinatura pública** do
`CyberPanelService` (`listWebsites()`, `isInstalled()`, `detectVersion()`, `documentRootFor()`,
`lastError()`), para a descoberta continuar agnóstica ao painel.

- **Fonte dos dados:** os vhosts no disco (`/www/server/panel/vhost/nginx/*.conf` e
  `.../apache/*.conf`), não o banco nem a API HTTP do painel — mesmo raciocínio do
  CyberPanel: o dado já está no disco, legível por root, e traz domínio, `document_root`
  e a versão de PHP por site (`include enable-php-XX.conf`).
- **Posição na cadeia:** aaPanel entra como **primeiro** mecanismo de descoberta, por ser
  o mais autoritativo nesse tipo de servidor.

*Este registro é atualizado conforme cada etapa concluir.*

### 11.4 Bug corrigido — lote de sites apagava os lotes anteriores

**Sintoma:** o servidor aaPanel (`154.53.49.227`) descobriu **110 domínios**, enviados em
2 lotes (100 + 10), mas o painel exibia só **10** — os do último lote.

**Causa:** o agente divide a lista em lotes (`SITES_BATCH_SIZE = 100`) e cada `POST` em
`/api/v1/agent/sites` chamava `SiteIngestService::store()`, que no fim executa
`Site::markMissingAsUndiscovered($serverId, $seenDomains)`. Essa rotina marca como
`discovered = 0` todo domínio que **não está no lote atual**. Resultado: o lote 1
invalidava os 10 domínios que ainda viriam no lote 2; o lote 2 invalidava os 100 do
lote 1. Sobrava só o último lote.

**Correção:** a finalização passou a ser **explícita**. Só o **último lote** envia
`finalize: true` + `domains` (a lista completa de domínios do ciclo); os lotes
intermediários enviam `finalize: false` e apenas fazem o upsert, sem marcar nada como
ausente. O painel só chama `markMissingAsUndiscovered` quando `finalize` é verdadeiro,
usando a lista completa (normalizada). Retrocompatível: agente antigo que não envia
`finalize` cai no default `true` e se comporta como antes (correto para um lote único).

Arquivos: `agent/agent.php`, `app/Controllers/Api/AgentController.php`,
`app/Services/SiteIngestService.php`.

### 11.5 Bug corrigido — domínios fantasmas na descoberta do aaPanel

**Sintoma:** o painel exibia 110 domínios para o servidor aaPanel, mas o painel original
do aaPanel tem apenas **54 sites**. Vários apareciam "de forma estranha", com um código
hexadecimal antes do domínio (ex.: `9af614aa.oliveiraimov.com.br`) e status Offline.

**Causa:** os vhosts do aaPanel têm artefatos internos que não são sites reais:

1. **Vhosts Apache de terminação SSL** — cada site com HTTPS gera um bloco Apache com
   `ServerName` prefixado por um id interno de 8 hex (`305f7428.amiljoaopessoa.com.br`)
   ou pelo marcador `SSL.` (`SSL.amiljoaopessoa.com.br`). Esses nomes não têm DNS; o
   domínio verdadeiro está no vhost nginx (`server_name` limpo) e no `DocumentRoot`.
   O parser tratava cada prefixo como um site separado → dobrava/sobrava domínios.
2. **`phpfpm_status.conf`** — vhost interno do painel com `server_name 127.0.0.1`
   (página de status do PHP-FPM). O `primaryDomain()` aceitava o IP como domínio.
3. **`apache/0.default.conf`** — site padrão do aaPanel com `ServerName bt.default.com`
   e `DocumentRoot "/www/server/apache/htdocs"` (placeholder, não site de cliente).

**Correção** (arquivo `agent/lib/AaPanelService.php`):

- Novo método `stripInternalPrefix()`: remove `^(?:[0-9a-f]{8}|ssl)\.` do `ServerName`
  Apache antes da deduplicação, para que o vhost SSL colapse no domínio real.
- `primaryDomain()` passou a descartar `localhost`, `bt.default.com` e qualquer IP
  (`FILTER_VALIDATE_IP`), junto com `_` e wildcards.

**Resultado:** descoberta passou de 110 → **54 domínios**, batendo exatamente com o
aaPanel original (44 online, 5 em atenção, 5 offline).

