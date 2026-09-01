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


---

## 12. Pós-V1 — Correções e avisos (26–27/08/2026)

Registro do que foi corrigido, por quê, e como ficou comprovado. A ordem é
cronológica. Cada entrada guarda o **sintoma**, a **causa real** e a **prova**
— porque em vários casos a causa não era a que parecia.

Ambiente de produção: painel em `vps.sitespb.ia.br`
(`/home/sitespb.ia.br/vps.sitespb.ia.br`), VPS `31.220.96.46`, CyberPanel,
PHP do site em `/usr/local/lsws/lsphp83/bin/php`.

> ⚠️ **O `php` do PATH neste servidor é 7.4.** Todo comando PHP no servidor
> precisa do caminho completo do `lsphp83`. Isso derrubou o instalador e vale
> para qualquer script novo.

---

### 2026-08-26 — Alertas de SSL em domínios já excluídos

### Sintoma

Domínios removidos do CyberPanel — `cursoneuro.com.br` entre eles — continuavam
gerando alerta de SSL no painel, indefinidamente.

### Causa: eram duas, e a segunda escondia a primeira

**1. A coleta não encerrava os alertas do site removido.**
Quando um domínio some da descoberta, `Site::markMissingAsUndiscovered()` marca
`discovered = 0` e o painel para de checá-lo. Mas nenhuma consulta de alerta
filtra por `discovered`, e nada resolvia os alertas já abertos: eles ficavam
para sempre.

**2. O cron reabria o que a coleta encerrava.**
`SslCertificate::needingAttention()` — usada por `SslService::refreshAll()` no
`cron/process-alerts.php`, a cada 5 minutos — **não filtrava `discovered`**,
enquanto as consultas irmãs `offlineForAlerts()` e `onlineForAlerts()` sempre
filtraram. Resultado: a coleta fechava o alerta, o cron reabria 5 minutos
depois, para sempre.

A segunda causa só apareceu porque a verificação em produção foi feita passo a
passo: os alertas órfãos tinham todos o mesmo `last_seen_at`, e esse horário era
o do cron — não o da coleta.

### Correção

| Arquivo | O quê |
|---|---|
| `app/Models/Site.php` | `markMissingAsUndiscovered()` devolve os sites invalidados (`id` + `domain`), não só a contagem. O `SELECT` roda antes do `UPDATE`, porque depois dele `discovered = 1` não acharia mais nada. |
| `app/Services/AlertService.php` | Constante `SITE_ALERT_TYPES` (`site_offline`, `ssl_expiring`, `ssl_expired`) e `resolveForUndiscoveredSite()`. Alertas de servidor ficam de fora: o servidor continua existindo. |
| `app/Services/SiteIngestService.php` | Ao finalizar o ciclo, encerra os alertas dos domínios que saíram. `try/catch` por site — falhar aqui não pode derrubar uma coleta já gravada. |
| `app/Models/SslCertificate.php` | `AND s.discovered = 1` em `needingAttention()`. |
| `app/Controllers/Api/AgentController.php` | Resposta e auditoria passam a reportar `undiscovered` e `alerts_resolved`. |
| `bin/fix-orphan-alerts.php` | **Novo.** Limpa os órfãos anteriores à correção. Dry-run por padrão, `--apply` executa, `--server=ID` limita o escopo. Idempotente. |

### Garantias de segurança da mudança

- Nenhum `DELETE`: só `UPDATE alerts SET status='resolved'` + linha em
  `alert_events`. Sites, métricas, `site_checks` e certificados intactos.
- Escopo por servidor: `markMissingAsUndiscovered` é `WHERE server_id = ?`.
- Age só sobre os IDs que aquele ciclo invalidou — não é varredura global.
- Lista vazia não invalida nada (proteção que já existia).
- Reversível: se o site voltar a ser descoberto, o alerta reabre.

### Prova em produção

```
Limpeza      → 5 alerta(s) encerrado(s)
Cron de SSL  → recalculados 108, avaliados 2   (só os de sites que existem)
Conferência  → Nenhum alerta orfao encontrado
```

Antes da correção, a terceira execução teria listado os 5 de volta.

Detalhe revelador: na primeira simulação eram **2** órfãos; ao aplicar, já eram
**5** — o cron havia reaberto mais três no intervalo, incluindo o
`cursoneuro.com.br`.

O VPS já estava limpo: o agente lê 36 domínios do CyberPanel e `cursoneuro` não
está entre eles. O problema era inteiramente do painel.

### Testes

3 testes de regressão novos (`AgentApiTest`, `MonitoringTest`): alerta é
*resolvido* e não apagado; alerta de servidor não é afetado; o cron não reabre
alerta de site removido, mas continua alertando sites ativos.

---

### 2026-08-26/27 — Instalação do agente

Motivador: as instruções na tela do servidor eram complicadas demais. A
investigação achou três bugs por trás delas.

### B3 — URL da API com `/v1` duplicado

Produção tinha `'/api/v1'` em `ServerProvisionService.php:106`; o repositório
tinha `'/api'`. O agente **sempre** prefixa `v1/` sozinho
(`$api->post('v1/agent/heartbeat', ...)`, `ApiClient.php:193`), então a tela
mandava configurar `.../api/v1` e o agente chamaria `.../api/v1/v1/...` → 404.

Nenhum servidor novo conseguiria conectar. Os 4 existentes funcionavam porque
foram instalados antes, com `/api`.

Corrigido com `sed` na linha, direto no servidor. O md5 depois da correção
ficou idêntico ao do repositório — provando que o `/v1` era a única divergência.

### B1 — O instalador recusava servidores CyberPanel

`install.sh` usava `command -v php`. Em CyberPanel isso é o PHP do sistema —
**7.4** neste servidor — e o script abortava:

> `PHP 7.4 e antigo demais. O agente exige PHP 8.1 ou superior.`

Enquanto `lsphp81`, `lsphp82` e `lsphp83` existiam e nunca eram procurados.
O produto recusava justamente o painel que ele atende.

**Correção — detecção em cascata**, do mais novo para o mais antigo, aceitando
apenas 8.1+:

```
/usr/local/lsws/lsphp8{4,3,2,1}/bin/php     CyberPanel / OpenLiteSpeed
/www/server/php/8{4,3,2,1}/bin/php          aaPanel
/opt/cpanel/ea-php8{4,3,2,1}/root/.../php   cPanel
/opt/plesk/php/8.{4,3,2,1}/bin/php          Plesk
php8.4 … php8.1, php                        PATH (por último, de propósito)
```

Quando nada serve, o erro **lista onde procurou e o que rejeitou com a versão**.
Opção `--php /caminho` para casos manuais.

### B2 — Cron com o binário errado

`ServerProvisionService.php` fixava `/usr/bin/php` na linha de cron exibida —
que nesses servidores é o 7.4. O agente falharia em silêncio a cada 5 minutos.

O painel não tem como saber o caminho no VPS. A tela passa a mostrar o marcador
`CAMINHO_DO_PHP` com a explicação de que o instalador o resolve; o `install.sh`
registra o cron com o **caminho completo** (o PATH do cron é mínimo).

### Nomes de pacote por família de PHP

No lsphp do CyberPanel a extensão vem em `lsphp83-mysqlnd`, não em
`php8.3-mysql` — instalar o pacote do sistema não teria efeito sobre o binário
em uso. Em cPanel, Plesk e aaPanel o script **orienta pela interface do painel**
em vez de instalar pacote do sistema por baixo, o que poderia quebrar a
instalação.

---

### 2026-08-27 — Instalação em um comando (v1.1.0)

### O passo mais difícil era o primeiro

A tela mandava fazer `scp -r agent/ root@IP:...`, o que exige ter o projeto na
máquina local. Quem administra apenas o VPS não tem o código.

### Solução: repositório público + modo bootstrap

Repositório: **github.com/sitespb/controle-vps** (público).

O comando da tela virou:

```bash
curl -fsSL https://raw.githubusercontent.com/sitespb/controle-vps/v1.1.1/agent/install.sh \
  | sudo bash -s -- --token cvps_7_xxxx \
                    --url https://vps.sitespb.ia.br/api
```

- **Modo bootstrap:** rodando sozinho, o `install.sh` baixa o agente do
  repositório na referência de `AGENT_REF` e instala dali.
- **`AGENT_REF` é sempre uma TAG, nunca `main`.** O painel gera o comando
  apontando para a versão que ele conhece, então um painel antigo nunca instala
  um agente novo demais para ele. `--ref` troca a referência e aceita tag ou
  branch.
- **`--server-id` virou opcional:** o id já está dentro do token
  (`cvps_<id>_<hash>`). Um parâmetro a menos é um erro de digitação a menos.

Configuração no painel: `monitoring.agent_repo` e `monitoring.agent_ref`.

### Dois bugs pegos antes de chegar ao usuário

**`unbound variable` no `curl | bash`.** Com o script vindo pelo stdin,
`BASH_SOURCE[0]` fica vazio — e com `set -u` isso abortava na primeira linha
útil. Corrigido com fallback para `$0`. O `--help` também trata os dois modos.

**CRLF.** Com `core.autocrlf=true` no Windows, o Git entregaria o `install.sh`
com CRLF e o bash falharia com `$'\r': command not found` — erro que não diz
nada a quem está instalando. Resolvido com `.gitattributes` forçando LF.

---

### 2026-08-27 — Incidente: identidade do agente sobrescrita (v1.1.1)

### O que aconteceu

Durante o teste do comando único, a instrução pedia para **acrescentar duas
linhas** (`--path /opt/teste-agente --no-cron`) a um comando com pipe e
continuação de barra. Na colagem, as linhas caíram antes do pipe e o instalador
rodou com os valores padrão.

Consequência: o agente do servidor de teste (#7) foi instalado **por cima do
agente de produção** em `/opt/controle-vps-agent`. O servidor #1 parou de
reportar e os dados do VPS passaram a chegar como se fossem do #7.

### Recuperação

O instalador havia preservado o config anterior em
`config.php.bak.<timestamp>`. Restaurar o backup devolveu
`'SERVER_ID' => 1`, confirmado por `--test` autenticando como
*"VPS 31.220.96.46"*. O servidor de teste foi excluído no painel, o que
removeu os dados coletados por engano e invalidou o token.

### Correções que ficaram

**No produto:** o instalador agora **recusa** assumir a identidade de outro
servidor. Quando existe um `config.php` de outro `SERVER_ID` no destino, ele
para e explica as duas saídas — `--force` para assumir mesmo, ou `--path` para
instalar lado a lado. Reinstalar o mesmo servidor continua sem atrito.

**No método de trabalho:** nunca pedir para completar um comando já pela
metade. Comandos com pipe e continuação devem ser entregues inteiros, ou
montados numa variável antes de executar. Um caractere no lugar errado muda o
significado do comando sem nenhum aviso.

---

### Estado atual

### Versões

| | |
|---|---|
| Agente | `v1.1.1` |
| Repositório | github.com/sitespb/controle-vps (público) |
| Painel | sincronizado com o repositório |

### Servidores monitorados

| ID | Nome |
|---|---|
| 1 | VPS 31.220.96.46 (também hospeda o painel) |
| 2 | Portais 147.93.6.150 |
| 3 | Resan 31.56.44.133 |
| 4 | Aapanel 154.53.49.227 |

### Backups do trabalho

`/root/backup-correcao-alertas/` no servidor — dump do banco, dump de
`alerts`/`alert_events`, e cópia de cada arquivo substituído.

### Procedimento de deploy usado

1. `scp` para `/tmp/correcao-alertas/`
2. `lsphp83/bin/php -l` em cada arquivo **antes** de instalar
3. backup do arquivo atual
4. `cp` + `chown sites7608:sites7608` + `chmod 644`
5. `pkill lsphp` (obrigatório — o OPcache mantém o código antigo)
6. conferir md5 contra o local e `curl /api/v1/health`

---

### 2026-08-27 — A tela do agente

Última etapa: apresentação apenas, nenhuma mudança de comportamento.

### O que mudou

**De 5 passos numerados para 1 comando.** O `<ol>` com cinco itens — em que o
primeiro era o `scp` e dois eram opcionais misturados aos obrigatórios — deu
lugar a um bloco único com o comando de instalação, o que ele faz, e o link
para **ler o script antes de executar**. Esse link é o que torna `curl | bash`
uma decisão informada em vez de um ato de fé.

**Estado da instalação, ao vivo.** Substitui o antigo passo *"rode à mão para
conferir"*. A pergunta real do operador é *"deu certo?"*, e agora a tela
responde sozinha:

```
⏳ Aguardando o primeiro contato do agente…   →   ✅ Agente conectado.
```

O navegador guarda o `last_seen_at` renderizado e compara com o que o polling
devolve. Comparar com o valor inicial — em vez de olhar apenas se existe — evita
que um servidor que já reportava ontem apareça como recém-conectado só por
alguém abrir a página. Para de insistir após 5 falhas seguidas, e limpa o
`setInterval` ao sair da página.

**Instalação manual recolhida.** `scp`, `config.php`, linha de cron e execução
manual foram para um `<details>`, cobrindo dois casos reais: servidor sem saída
para a internet, e quem prefere conferir o que será gravado antes de gravar.
Continua disponível, sem competir com o caminho normal.

### Arquivos

| Arquivo | O quê |
|---|---|
| `resources/views/servers/agent.php` | Reescrita do bloco de instalação + polling |
| `app/Controllers/Api/PanelController.php` | `agentStatus()` — payload mínimo, pedido a cada 5s |
| `routes/api.php` | `GET /api/v1/servers/{id}/agent-status` |

O endpoint devolve só o que aquela tela precisa. Quem decide se "conectou" é o
navegador — o servidor não guarda estado de "instalação em andamento".

### Testes

3 novos (122 no total): a tela traz o comando único e o bloco de
acompanhamento; a tela **não** contém `/usr/bin/php` (regressão do bug B2); o
endpoint responde em JSON e dá 404 para servidor inexistente.

---

### Pendências desta etapa

Ideias registradas e não implementadas:

- `install.sh --upgrade` para atualizar os agentes já instalados sem repassar o
  token;
- token por `stdin` além de parâmetro — hoje ele fica visível em `ps aux` e no
  `~/.bash_history` do servidor;
- rodar `bin/fix-orphan-alerts.php --apply` como rotina não é necessário: a
  correção no `SiteIngestService` já resolve daqui para frente, e o script é
  só para o passivo.

---

## 13. Avisos ao administrador — e-mail e WhatsApp (27/08/2026)

> Um sistema de monitoramento que não avisa ninguém não monitora nada. Até
> aqui os alertas só existiam dentro do painel: quem não abrisse a tela não
> ficava sabendo de site nenhum fora do ar.

### Decisões estruturais

**SMTP escrito à mão, sem biblioteca.** O projeto não usa Composer (decisão
nº 1). Puxar PHPMailer só para enviar texto inverteria isso. `mail()` também
foi descartada: depende de MTA local, que num VPS de painel raramente existe —
e quando existe, cai em spam por falta de SPF/DKIM. Enviar autenticado pelo
SMTP do provedor é o que realmente chega. `app/Services/Mailer.php` cobre
EHLO, STARTTLS, AUTH LOGIN, multipart e UTF-8; não faz anexo nem fila, porque
um aviso de indisponibilidade não precisa e cada recurso a mais é superfície
para falhar de madrugada.

**Segredos cifrados, em tabela própria.** Senha de SMTP e token da RyzeAPI são
credenciais de terceiros: quem as obtém envia e-mail e WhatsApp em nome do
operador. `app/Core/Crypto.php` usa AES-256-GCM com chave derivada do
`APP_KEY` — banco e chave em lugares diferentes, que é o ponto. GCM autentica
além de cifrar: valor adulterado falha na tag em vez de decifrar em lixo.

A tabela é própria, e **não** a `settings` existente, por um motivo concreto:
as settings são lidas em bloco e gravadas num cache de arquivo
(`storage/cache/settings.php`). Um segredo passando por ali acabaria em texto
claro no disco — o oposto de cifrar.

**As quatro portas de um aviso** (`NotificationService`):

1. canal ligado e configurado?
2. o operador marcou "ciente" neste domínio?
3. este domínio já foi avisado dentro da janela?
4. o canal estourou o teto da hora?

As portas 3 e 4 resolvem problemas diferentes e nenhuma substitui a outra: a
janela por domínio protege de um site que oscila; o teto por hora protege o
provedor quando um servidor inteiro cai e dezenas de domínios ficam offline no
mesmo ciclo. Padrão acordado: **1 aviso por domínio a cada 6 h**, **20
mensagens por canal por hora**.

Tudo que é barrado vira uma linha `skipped` no log **com o motivo**. Silêncio
sem registro seria indistinguível de bug, e a primeira pergunta de quem não
recebeu é exatamente "por que não chegou?".

**O switcher "ciente"** (`sites.notify_muted`) se desfaz sozinho quando o site
volta a responder. Foi a escolha deliberada contra "só manualmente": um
domínio silenciado e esquecido faria a próxima queda passar despercebida — pior
do que o ruído que o switcher veio resolver.

### Bug encontrado testando de verdade

O `Mailer` foi validado contra um servidor SMTP falso local, e o corpo recebido
mostrou **linha em branco entre os cabeçalhos MIME**. Causa:

```php
str_replace(["\r\n", "\r", "\n"], "\r\n", $body)   // ERRADO
```

`str_replace` com arrays aplica as buscas **em sequência sobre o resultado
anterior**: o `\r` de um `\r\n` já correto vira `\r\n` de novo, e cada quebra
de linha acaba duplicada. No corpo MIME isso encerra o bloco de cabeçalhos
antes da hora e o cliente lê o resto como texto solto. Trocado por uma passada
única de `preg_replace('/\r\n|\r|\n/', ...)`. Um bug que só aparece em cliente
de e-mail real — e apareceu porque o teste usou um servidor de verdade em vez
de conferir a string montada.

### Arquivos

| Arquivo | O quê |
|---|---|
| `app/Core/Crypto.php` | AES-256-GCM para os segredos |
| `app/Models/NotificationSetting.php` | Config por canal; cifra/decifra transparente |
| `app/Models/NotificationLog.php` | Histórico — é o que faz o limite funcionar |
| `app/Services/Mailer.php` | Cliente SMTP próprio |
| `app/Services/RyzeApiClient.php` | WhatsApp: envio de texto e estado da instância |
| `app/Services/NotificationService.php` | As quatro portas, templates e testes |
| `app/Controllers/NotifyController.php` | Tela de Avisos e endpoints de teste |
| `resources/views/notify/index.php` | Duas abas, campos mascarados com olho |
| `database/migrations/018_create_notifications.sql` | Tabelas + `sites.notify_muted` |

Ganchos: `AlertService::siteWentOffline()` dispara o aviso (em try/catch — um
SMTP fora do ar não pode derrubar a coleta); `siteCameBack()` limpa o "ciente".

### RyzeAPI

Base `https://ryzeapi.cloud`, header `token` com o **TokenInstance** (se vazar,
compromete uma instância, não a conta). `POST /api/message/text/{instancia}`
com `{number, message}`; `GET /api/instance/list?instanceName=` para o teste —
que confere a conexão **sem gastar mensagem**.

Atenção: o *quickstart* da documentação usa `"text"` no corpo, mas a referência
do endpoint usa `"message"`. Vale a referência.

### Testes

16 novos (137 no total): ida e volta da cifragem, IV nunca repetido, valor
adulterado recusado, senha ilegível no banco, campo vazio mantém o segredo,
parsing de destinatários, canal desligado, janela por domínio, falha não
bloqueia a próxima, teto por hora, domínio silenciado, "ciente" se desfazendo
sozinho, formato da resposta dos testes, e acesso à tela por perfil.

### Dois bugs que só o uso em produção revelou

Ambos passaram por 136 testes verdes e pelo deploy. Nenhum teste os pegaria,
porque os dois nascem de **integração** — um com o front-end do próprio
painel, outro com o comportamento real da API de terceiro.

**1. "Erro HTTP 200" no teste de WhatsApp.** O helper de API do painel
(`public/assets/js/app.js`) trata `ok:false` no **envelope** como falha de
requisição e monta a mensagem a partir de `payload.error.message`. O controller
devolvia `error` como string, não como objeto — o helper não achava `.message`
e caía no texto genérico, engolindo exatamente o motivo que o operador precisa
ler.

O teste de e-mail passava por acidente: retornava `ok:true` e nunca entrava
nesse ramo. Só o caminho de falha expunha o problema.

Correção: o resultado do teste viaja dentro de uma resposta **bem-sucedida** —
do ponto de vista do HTTP a requisição funcionou mesmo; quem falhou foi o SMTP
ou a RyzeAPI lá fora. Erro de validação de entrada continua sendo `apiError`,
que é o caso em que a requisição realmente está errada.

**2. Teste do WhatsApp aprovava instância com nome errado.** A tela dizia
`Estado da instancia: connected` e o envio seguinte falhava com
`Instance not found` — duas respostas contraditórias para a mesma configuração.

A causa está na documentação da RyzeAPI: o filtro `?instanceName=` **só funciona
com TokenAccount**. Com TokenInstance — que é o que recomendamos, por
comprometer uma instância e não a conta inteira se vazar — o filtro é
**ignorado**, e a API devolve a instância dona do token com qualquer nome que se
peça. O teste lia isso como "o nome está certo", quando o nome nunca havia sido
verificado.

Correção: `instanceState()` passou a devolver também o **nome real**, e o teste
compara com o configurado. Um nome errado agora diz qual é o certo, em vez de
aprovar a configuração e falhar só no primeiro aviso de verdade — de
madrugada, quando um site cai.

A lição das duas: um teste de configuração que só confirma "consegui falar com
o serviço" dá falso positivo. Ele precisa confirmar que falou com **o alvo
certo**, e o caminho de falha precisa ser exercitado tanto quanto o de sucesso.

### Estado em produção

Ambos os canais validados de ponta a ponta em 27/08/2026: e-mail pelo SMTP do
Gmail com senha de app, e WhatsApp pela instância `Atendimento` da RyzeAPI,
com mensagem recebida no aparelho.

---

## 14. Captcha no login — Cloudflare Turnstile (27/08/2026)

Menu: **Sistema** passou a se chamar **Geral**, e `/configuracoes` ganhou duas
abas — *Sistema* (o que já existia) e *Recaptcha*.

### O armazenamento cifrado foi generalizado

`notification_settings` virou **`secure_settings`**, e a coluna `channel` virou
`scope` (`email`, `whatsapp`, `turnstile`).

A chave secreta do Turnstile é exatamente a mesma coisa que a senha do SMTP e o
token da RyzeAPI — credencial de terceiro que não pode ficar legível num dump —
mas não tem relação nenhuma com notificação. Havia duas saídas: uma segunda
tabela com comportamento idêntico, ou generalizar esta. Duas tabelas iguais
convidariam uma terceira, e uma tabela chamada `notification_settings`
guardando captcha seria uma pegadinha para quem lesse o schema depois.

Renomear com um dia de vida custou uma migration e cinco arquivos. Daqui a seis
meses custaria muito mais. `RENAME TABLE` é atômico e preserva as linhas — os
segredos já gravados continuam válidos com a mesma `APP_KEY`.

### Duas decisões de comportamento

**Falha de rede na Cloudflare NÃO bloqueia o login.** É a decisão mais
importante do `TurnstileService`. O captcha protege contra força bruta, e para
isso já existem duas camadas independentes: rate limit por IP e contagem de
tentativas por usuário. Se um problema na Cloudflare trancasse o painel, o
administrador ficaria de fora justamente quando talvez precise investigar uma
queda. Token inválido ou ausente continua sendo recusado; o que liberamos é
apenas o caso *"não consegui perguntar"*.

**"Ligado" sem as duas chaves não conta como ativo.** Exibir um widget que
nunca valida deixaria a tela de login intransponível e sem explicação em lugar
nenhum. O formulário também recusa a ativação nesse estado.

### O teste que não precisa de captcha resolvido

Validar as chaves normalmente exigiria abrir a tela de login e resolver o
widget. O truque: mandamos a chave secreta com um token propositalmente
inválido. A Cloudflare valida o **segredo primeiro** — se estiver errado
responde `invalid-input-secret`; se estiver certo, a reclamação passa a ser
sobre o token (`invalid-input-response`). O erro que volta diz exatamente qual
das duas coisas está errada.

### Verificação antes da senha

`TurnstileService::verify()` roda **antes** de `AuthService::attempt()`. Um bot
que nem passou pelo widget não deve consumir uma tentativa da contagem de força
bruta daquele e-mail — senão ele bloqueia a conta de um usuário legítimo apenas
martelando o formulário.

### ⚠️ Produção é MariaDB, o ambiente local é MySQL 8

A migration 019 **falhou pela metade** no primeiro deploy. O terceiro comando
era `ALTER TABLE ... RENAME INDEX`, sintaxe de MySQL 5.7+ que o MariaDB de
produção não reconhece.

Os dois primeiros comandos tinham funcionado (tabela e coluna renomeadas, os 14
segredos intactos), mas o migrator só grava o registro quando **todos** os
comandos passam — então a 019 ficou aplicada de fato e pendente no histórico.
Um `migrate` seguinte tentaria renomear uma tabela que já não existe.

Correção: o comando foi removido. O índice único continua se chamando
`uq_notification_settings` mesmo na tabela `secure_settings`. A alternativa
(`DROP INDEX` + `ADD UNIQUE`) deixaria a tabela alguns instantes **sem a
restrição de unicidade** — que é justamente o que faz o
`ON DUPLICATE KEY UPDATE` do `SecureSetting::save()` não duplicar linhas.
Trocar um risco real por um ganho cosmético seria mau negócio.

**Para as próximas migrations:** o ambiente local aceita sintaxe que produção
recusa. Vale conferir compatibilidade com MariaDB antes de escrever qualquer
`ALTER` fora do trivial — e preferir comandos que funcionem nos dois.

### Testes do captcha

10 novos. Um merece nota: o que verifica que o login é recusado
sem captcha carrega um **controle positivo** — primeiro prova que aquelas
credenciais *entram* com o captcha desligado, e só então que são barradas com
ele ligado. Sem isso, o teste passaria mesmo que o login estivesse falhando por
qualquer outro motivo (CSRF, validação, senha errada no setup), dando a falsa
impressão de que o captcha está funcionando.

---

## 15. Falso alarme de site offline (28/08/2026)

Chegou aviso de site fora do ar, e o site estava no ar. O caso concreto:
`clubevanilla.com`.

### Duas causas, e a segunda invalidava a medição inteira

**1. Uma única falha virava aviso.** Não havia confirmação temporal. O que
parecia repetição no `HttpCheckService` é outra coisa: se o HTTPS não responde
nada, o agente tenta uma vez em HTTP simples — *fallback de protocolo*, para
não marcar como offline um site legitimamente sem TLS. Um pico de latência
bastava para disparar o aviso.

**2. O agente estava cronometrando o próprio timeout.** O histórico mostrava
tempos grudados em 10.400 ms, com `CHECK_TIMEOUT = 10s`. Um site realmente
lento teria variação — 3 s, 7 s, 12 s. Aquela constância era o cURL desistindo,
e o número gravado no banco era o limite, não o site.

Medido de fora, o `clubevanilla.com` levava **12,6 s**, com detalhamento:

```
dns:            0,038s
conexao:        0,040s
tls:            0,061s
primeiro byte: 10,185s   <-- backend
total:         10,474s
```

Rede instantânea; dez segundos inteiros o backend montando a página. Problema
de aplicação do site, não de monitoramento — mas que o monitoramento reportava
errado, como *offline* em vez de *lento*.

> **Hipótese descartada:** suspeitei de cadeia longa de redirecionamento, já que
> o histórico gravava `301` e o agente segue até 3 saltos. O `curl` mostrou
> `saltos: 0`. Os `301` eram timeout no meio do caminho, não redirecionamento.
> Vale o registro: a medição de fora derrubou uma explicação que encaixava bem
> nos dados parciais.

### Correções

**Confirmação por ciclos consecutivos.** O aviso só sai após N verificações
seguidas com falha — padrão 3, em `monitoring.http.offline_confirmations`. Com
coleta a cada 5 minutos, isso é avisar ~10 minutos depois da primeira falha.

Confirmar em ciclos é melhor do que repetir a requisição na hora: as coletas
são espaçadas em minutos, então duas falhas seguidas dizem *"está fora há um
tempo"*, enquanto três tentativas separadas por milissegundos apenas repetiriam
o mesmo instante ruim.

O portão fica **dentro** de `AlertService::siteWentOffline`, não em quem chama:
são dois caminhos independentes (a ingestão da coleta e o cron de alertas) e
duplicar a condição criaria a chance de um deles escapar numa alteração futura.

O **status** do site continua mudando na hora — a tela mostra a realidade
agora; só o aviso espera confirmação.

**`CHECK_TIMEOUT` padrão: 10 s → 15 s** (v1.2.0). Um falso "offline" custa mais
caro que um ciclo de coleta mais longo. No servidor de produção foi para 20 s,
por causa desse site específico; o ciclo completo com 36 domínios passou a
levar ~31 s, folgado nos 5 minutos entre coletas.

### Testes

2 novos (149 no total): cinco leituras boas e uma ruim não geram aviso, e três
seguidas geram; e a contagem exige falhas **consecutivas** — duas falhas, uma
recuperação e outra falha não somam três.

---

## 16. O CSS que não viajava no deploy (29/08/2026)

O switcher "Ciente" **sumia** ao ser marcado. Não sumia: ficava invisível.

O botão usa `bg-amber-500` quando ligado e `translate-x-6` para mover a
bolinha. Nenhuma das duas existia no `app.css` compilado — que era de **20 de
agosto**, anterior a tudo que foi construído depois. Sem cor de fundo, o botão
virava um retângulo transparente com um ponto branco sobre um card branco.

### A causa é de processo, não de código

O Tailwind só inclui no CSS as classes que **encontra no código-fonte**. Escrevi
classes novas em três funcionalidades seguidas — avisos, Turnstile e o switcher
— e nunca rodei `npm run build:css`. Como o `app.css` estava no `.gitignore`,
ele também nunca entrou em nenhum dos pacotes de deploy.

Outras coisas estavam sutilmente sem estilo desde o dia anterior e ninguém
notou: o selo "Ciente" na lista de sites, a caixa âmbar da aba Recaptcha e a
caixa azul do limite de envio na tela de Avisos.

### Por que essa falha é pior que um erro

Nada quebra. Nenhum log, nenhuma exceção, **149 testes passando**. A interface
só fica errada — e de um jeito que parece bug de lógica, não de build. Custou
uma investigação inteira num botão cujo código estava correto desde o começo.

### Correção

O `public/assets/css/app.css` passou a ser **versionado**. O argumento usual
contra — "é artefato de build, polui o diff" — vale para projetos que compilam
no servidor. Aqui o deploy é `scp` manual, e um artefato não versionado é
exatamente o que se esquece.

Versionado, o estilo viaja junto com a view que depende dele. O custo é um diff
feio de vez em quando; o benefício é uma classe inteira de bug que deixa de
existir. O `docs/INSTALACAO-VPS.md` também deixou de pedir Node no servidor.

**Regra que fica:** ao alterar qualquer view, rodar `npm run build:css` antes
de commitar.

---

## 17. Domínios duplicados entre servidores (29/08/2026)

O operador encontrou o mesmo site, com conteúdo idêntico, em dois servidores.
Significa que o DNS aponta para um deles e o outro é sobra: espaço ocupado,
backup inflado, e o risco de alguém editar a cópia que ninguém vê.

### Detecção era grátis; dizer quem serve, não

A chave única de `sites` é `(server_id, domain)`, então o mesmo domínio em dois
servidores **já existia como duas linhas**. Detectar é um `GROUP BY` sobre o
índice `idx_sites_domain` que já existia. Nenhuma mudança no agente, nenhuma
coleta nova, nenhuma migration.

O problema real é outro: **não dá para saber pelo status HTTP qual cópia está no
ar**. Cada agente faz `curl` no domínio, o DNS resolve para o mesmo lugar, e os
dois servidores reportam o site como online — inclusive aquele que só tem
arquivos parados no disco.

O sinal certo já estava sendo coletado: `sites.ip` vem de
`CURLINFO_PRIMARY_IP` ([HttpCheckService.php:202](../agent/lib/HttpCheckService.php#L202)),
o IP em que a requisição **de fato conectou**. Comparando com `servers.ip`:

```
sites.ip == servers.ip  ->  este servidor é quem responde
sites.ip != servers.ip  ->  esta cópia está obsoleta
```

### O caso em que a resposta é "não sei"

Com Cloudflare ou qualquer proxy na frente, o IP conectado é o do proxy e não
bate com servidor nenhum. `DuplicateSiteService` devolve `SERVING_UNKNOWN` e a
tela diz isso com todas as letras, pedindo conferência manual.

Chutar um servidor seria pior que não apontar nenhum: a tela sugere apagar a
cópia inútil, e o operador apagaria o site que funciona. Há teste para esse
cenário.

### Onde ficou na interface

Deliberadamente **não** em Alertas. Alerta é para algo que mudou e exige ação
agora; uma duplicidade está assim há meses. Colocá-la ali poluiria a tela,
dispararia e-mail e WhatsApp por algo não urgente, e ficaria aberta para sempre
— o mesmo problema dos alertas órfãos corrigidos na seção 12.

| Onde | O quê |
|---|---|
| Lista de Sites | selo **Duplicado** ao lado do domínio |
| Filtro da lista | "Somente duplicados", com contagem — **só aparece quando há** |
| Página do site | faixa no topo com as duas cópias, quem serve, caminho e espaço |

A faixa fica acima de tudo porque muda a leitura da página inteira: se aquela
cópia não é a que responde, o status, o SSL e o tempo de resposta exibidos
descrevem o site de *outro* servidor.

### Detalhe que quase repetiu o bug do CSS

A faixa muda de cor conforme o caso (laranja quando exige ação, azul quando é
informação). A primeira versão montava a classe como `bg-{$cor}-50` — que o
Tailwind **nunca encontraria** no código-fonte, produzindo uma caixa sem cor
nenhuma e sem erro nenhum, exatamente a falha da seção 16. As classes ficaram
por extenso em cada ramo do ternário, com comentário explicando o porquê.

### Testes

12 novos (161 no total). Cobrem detecção, o caso `discovered = 0` que não conta
como duplicidade, os três veredictos de quem serve — incluindo o proxy —, o
filtro, e a renderização real das duas telas.

---

## 18. Itens por página na listagem de sites (29/08/2026)

O backend já aceitava `por_pagina` desde a V1 — só não havia controle na tela.
Faltava expor.

Opções: **10, 20, 50, 100** (antes eram 25/50/100, sem seletor). O padrão
passou de 25 para **20**, porque o valor padrão precisa estar entre as opções,
senão o seletor abriria sem seleção.

### Onde ficou

Na barra de paginação, à esquerda, ao lado de "Exibindo X a Y de Z" — a
posição prevista no `DESIGN.md` seção 9, que já define o contêiner como
`flex ... justify-between` com dois lados.

O seletor é **opcional no partial**: só aparece quando quem chama passa
`perPageOptions`. O mesmo partial serve alertas e logs, cujos controllers não
aceitam `por_pagina` — um seletor que não muda nada seria pior que nenhum.

### O detalhe que separa "funciona" de "funciona no segundo clique"

A escolha precisa sobreviver a **todo** caminho da tela. Bastava esquecer um
para ela se perder:

| Caminho | Como preserva |
|---|---|
| Links de página | `por_pagina` nos `queryParams` do partial |
| Links de ordenação | `por_pagina` no closure `$sortLink` |
| Formulário de filtros | `<input type="hidden" name="por_pagina">` |
| O próprio seletor | formulário próprio com os filtros em campos ocultos |

O formulário do seletor **omite** `pagina` de propósito: trocar 10 por 100
estando na página 7 deve levar ao início da lista.

### Dois erros meus, pegos pelos testes

**Teste falso-positivo.** A primeira versão afirmava `value="10"` no HTML — que
casava com o `input hidden` e com outros campos da tela, passando sem o seletor
existir. Corrigido para `<option value="10"`.

**Uso errado do harness.** Requisitei `/sites?por_pagina=50`, mas
`TestCase::request()` recebe a query string num **5º parâmetro** — o `?` virava
parte do caminho e a resposta era 404. Dois testes estavam medindo uma página
de erro e "passando".

Ambos reforçam a mesma coisa: um teste que passa não prova nada se a asserção
puder ser satisfeita por acidente.

### Testes

4 novos (165 no total): o seletor aparece com todas as opções, a quantidade
limita a listagem, valor fora da lista volta ao padrão em vez de aceitar
qualquer número, e a escolha sobrevive a filtros e ordenação.
