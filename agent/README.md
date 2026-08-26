# Agente de Monitoramento — Controle VPS

Coletor leve instalado em cada VPS. Roda por cron, sem daemon e sem interface.

---

## O que ele faz — e o que não faz

**Faz:** lê métricas do sistema, detecta serviços, descobre os domínios hospedados (aaPanel, CyberPanel, OpenLiteSpeed), verifica HTTP e SSL de cada domínio, e envia tudo ao painel por HTTPS.

**Não faz:** executar comandos recebidos do painel. Não existe caminho de código no agente que interprete a resposta da API como comando, script ou caminho de arquivo. O único campo lido da resposta é `next_interval`, um número usado apenas para log.

Todos os comandos de sistema que o agente executa são literais do repositório, restritos por uma allowlist em `lib/Shell.php`. Argumentos dinâmicos passam por `escapeshellarg()`.

---

## Requisitos

| Item | Mínimo |
|---|---|
| Linux | Ubuntu, Debian, AlmaLinux, CentOS |
| PHP CLI | 8.1+ com `curl`, `json`, `openssl`, `mbstring` |
| `pdo_mysql` | *opcional* — sem ele, a descoberta usa os vhosts do aaPanel/OpenLiteSpeed |
| Acesso | **root** — para ler os vhosts do aaPanel, `/etc/cyberpanel/mysqlPassword` e `/proc` |

O agente não instala nada, não altera configuração do aaPanel, do CyberPanel, do OpenLiteSpeed ou do MySQL — a única exceção são as próprias extensões PHP que faltarem, e só com a sua confirmação (veja abaixo).

---

## Instalação

### 1. Cadastre o servidor no painel

**Servidores → Novo servidor.** Anote o **Server ID** e copie o **token** — ele é exibido **uma única vez**.

### 2. Envie os arquivos

```bash
scp -r agent/ root@IP_DO_VPS:/opt/controle-vps-agent
```

### 3. Rode o instalador

```bash
ssh root@IP_DO_VPS

sudo bash /opt/controle-vps-agent/install.sh \
    --server-id 27 \
    --token cvps_27_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx \
    --url https://monitoramento.seudominio.com.br/api
```

O instalador:

1. valida os parâmetros — inclusive se o token pertence ao `--server-id` informado;
2. confere PHP 8.1+ e as extensões `curl`, `json`, `openssl`, `mbstring`;
   - se faltar alguma, detecta o gerenciador de pacotes (`apt`/`dnf`/`yum`), monta o comando de instalação com os nomes de pacote corretos (ex.: `pdo_mysql` → `php8.1-mysql` no apt, `php-mysqlnd` no dnf/yum) e **pergunta se pode instalar na hora**; com `--yes`, instala sem perguntar; sem gerenciador reconhecido ou fora de um terminal interativo, só mostra o comando e para;
   - `pdo_mysql` continua opcional — sem ele, a descoberta cai para os vhosts do aaPanel/OpenLiteSpeed, e o instalador também sugere o pacote pra quem quiser habilitar;
3. copia os arquivos e ajusta permissões;
4. gera o `config.php` com permissão **600** (só root lê);
5. **testa a conexão e a autenticação** de ponta a ponta;
6. registra o cron, removendo qualquer linha anterior do mesmo agente.

### Opções

| Opção | Padrão | Para quê |
|---|---|---|
| `--interval 300` | 300 s | Segundos entre coletas |
| `--path /opt/controle-vps-agent` | | Diretório de instalação |
| `--no-cron` | | Não registra o agendamento |
| `--no-verify-tls` | | Aceita certificado inválido (só homologação) |
| `--yes` | | Instala automaticamente as extensões PHP que faltarem, sem perguntar (uso não interativo) |

---

## Uso

```bash
php /opt/controle-vps-agent/agent.php                  # ciclo completo (modo cron)
php /opt/controle-vps-agent/agent.php --verbose        # com o passo a passo na tela
php /opt/controle-vps-agent/agent.php --test           # só testa conexão e autenticação
php /opt/controle-vps-agent/agent.php --dry-run        # coleta e imprime, sem enviar
php /opt/controle-vps-agent/agent.php --only=metrics   # uma etapa só
```

Etapas válidas em `--only`: `heartbeat`, `metrics`, `services`, `sites`.

> `--dry-run` é a ferramenta de diagnóstico mais útil: mostra exatamente o que **seria** enviado. Se o payload está correto e mesmo assim o painel não recebe, o problema é de comunicação, não de coleta.

---

## Configuração

`/opt/controle-vps-agent/config.php` — gerado pelo instalador.

### Obrigatório

```php
'SERVER_ID'    => 27,
'SERVER_TOKEN' => 'cvps_27_...',
'CENTRAL_URL'  => 'https://monitoramento.seudominio.com.br/api',
```

### Comunicação

| Chave | Padrão | Descrição |
|---|---|---|
| `HTTP_TIMEOUT` | 20 | Segundos para a resposta completa |
| `HTTP_CONNECT_TIMEOUT` | 8 | Segundos para conectar |
| `HTTP_RETRIES` | 2 | Novas tentativas em falha temporária |
| `VERIFY_TLS` | `true` | **Mantenha `true` em produção** |
| `SITES_BATCH_SIZE` | 100 | Domínios por requisição |

### Verificação dos domínios

| Chave | Padrão | Descrição |
|---|---|---|
| `CHECK_CONCURRENCY` | 10 | Domínios verificados em paralelo |
| `CHECK_TIMEOUT` | 10 | Segundos por domínio |
| `SSL_FALLBACK` | `true` | Leitura TLS alternativa quando o cURL não traz o certificado |
| `SSL_FALLBACK_LIMIT` | 100 | Teto de inspeções extras por ciclo |

O `config.php` contém o token: mantenha em **600**, dono **root**, e nunca versione.

---

## O que é coletado

### Sistema (heartbeat)

hostname · IP público · SO e versão · kernel · arquitetura · vCPUs · modelo da CPU · uptime

Lido de `/etc/os-release`, `/proc/cpuinfo`, `/proc/uptime` e `php_uname()`.

O IP público vem da rota de saída (`ip route get 1.1.1.1`) — **sem chamada a serviço externo**. O agente não deve depender de terceiros nem gerar tráfego de saída a cada 5 minutos só para descobrir o próprio IP.

### Recursos (métricas)

CPU · RAM total/usada/disponível · swap · disco total/usado/livre · load average (1, 5, 15) · uptime · processos

**CPU:** `/proc/stat` lido duas vezes com 500 ms de intervalo. Uma leitura única daria a média desde o boot, que não serve para monitoramento.

**RAM:** usa `MemAvailable`, que já desconta cache reutilizável — o número que reflete a memória realmente disponível, ao contrário de `MemFree`.

Valor que não pôde ser lido vira `null`, nunca zero. A diferença importa: 0% de CPU é um fato, ausência de leitura não é.

### Serviços

OpenLiteSpeed · MariaDB/MySQL · Redis · CyberPanel · aaPanel · Nginx · Apache · PHP

Detecção: `systemctl is-active` → `pgrep` → arquivo de versão. Quatro estados:

| Estado | Significado |
|---|---|
| `running` | Instalado e ativo |
| `stopped` | Instalado, porém parado — **isto sim merece atenção** |
| `not_installed` | Não existe neste servidor — **normal, não é erro** |
| `unknown` | Não foi possível determinar |

Um VPS sem Redis é uma configuração legítima. Nenhum status daqui gera alerta na V1.

### Sites

domínio · URL · HTTP status · tempo de resposta · HTTPS · certificado (emissor, validade, expiração) · IP · versão do PHP · WordPress com versão

**WordPress é detectado no disco** (`wp-includes/version.php`), não no HTML. Um site com cache de página ou com a meta generator removida continua sendo detectado, e a versão exata vem do próprio arquivo. A inspeção do HTML existe só como complemento.

---

## Como o agente se autentica

O token **nunca trafega na rede**:

```text
chave      = sha256(token)                            (derivada localmente)
canonical  = serverId \n timestamp \n nonce \n sha256(corpo)
assinatura = HMAC-SHA256(canonical, chave)

Cabeçalhos: X-Server-Id, X-Timestamp, X-Nonce, X-Signature
```

- assinatura cobre o corpo inteiro — alterar um byte invalida o envio;
- timestamp limita a janela de uso a 5 minutos;
- nonce, com chave única no banco do painel, impede replay dentro dessa janela.

Detalhes em [../docs/ARQUITETURA.md](../docs/ARQUITETURA.md).

---

## Logs

```bash
tail -f /opt/controle-vps-agent/logs/agent-$(date +%F).log
tail -f /opt/controle-vps-agent/logs/cron.log
```

Um arquivo por dia, limpos automaticamente conforme `LOG_KEEP_DAYS` (padrão 14). Chaves que parecem segredo são mascaradas antes da gravação.

---

## Quando o painel está fora do ar

O agente registra o erro localmente, tenta novamente com backoff crescente e **não interrompe permanentemente**. No ciclo seguinte tenta de novo, do zero.

Erros definitivos (400, 401, 403, 404, 409, 422) **não** são retentados — assinatura inválida ou token revogado continuariam falhando igual, e insistir só geraria ruído.

---

## Desinstalar

```bash
crontab -l | grep -v 'controle-vps-agent' | crontab -
rm -rf /opt/controle-vps-agent
```

Depois, exclua o servidor no painel (**Servidores → Editar → Excluir**). A exclusão remove também métricas, sites, verificações, certificados e alertas associados.

---

## Problemas

[../docs/TROUBLESHOOTING.md](../docs/TROUBLESHOOTING.md) — seção **Agente**.

Diagnóstico em três comandos:

```bash
php agent.php --test --verbose      # conexão e autenticação
php agent.php --dry-run --verbose   # o que seria enviado
tail -50 logs/agent-$(date +%F).log # o que aconteceu
```
