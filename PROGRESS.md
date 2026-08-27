# Progresso

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

## 2026-08-26 — Alertas de SSL em domínios já excluídos

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

## 2026-08-26/27 — Instalação do agente

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

## 2026-08-27 — Instalação em um comando (v1.1.0)

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

## 2026-08-27 — Incidente: identidade do agente sobrescrita (v1.1.1)

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

## Estado atual

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

## 2026-08-27 — A tela do agente

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

## Próximo passo

Nada pendente. As três fases estão fechadas.

Ideias que ficaram registradas mas não foram feitas:

- `install.sh --upgrade` para atualizar os agentes já instalados sem repassar o
  token;
- token por `stdin` além de parâmetro — hoje ele fica visível em `ps aux` e no
  `~/.bash_history` do servidor;
- rodar `bin/fix-orphan-alerts.php --apply` como rotina não é necessário: a
  correção no `SiteIngestService` já resolve daqui para frente, e o script é
  só para o passivo.
