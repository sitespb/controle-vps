# Arquitetura

Como as peças se encaixam e, principalmente, **por quê**.

---

## 1. Visão geral

```text
┌──────────────────────────────────────────────────────────────────┐
│                        PAINEL CENTRAL                            │
│                                                                  │
│   Navegador ──▶ public/index.php ──▶ Router ──▶ Middleware       │
│                                                    │             │
│                                                    ▼             │
│                                              Controller          │
│                                                    │             │
│                              ┌─────────────────────┤             │
│                              ▼                     ▼             │
│                          Service              Repository         │
│                              │                     │             │
│                              └──────────┬──────────┘             │
│                                         ▼                        │
│                                      MySQL                       │
└──────────────────────────────────────────────────────────────────┘
                                    ▲
                                    │  POST assinado (HMAC-SHA256)
                                    │  /api/v1/agent/*
                                    │
┌───────────────────────────────────┴──────────────────────────────┐
│                        AGENTE (em cada VPS)                      │
│                                                                  │
│   cron ──▶ agent.php ──▶ ServerMetricsService   (/proc, PHP)     │
│                     ├──▶ ServicesService        (systemctl)      │
│                     ├──▶ SiteDiscoveryService   (CyberPanel)     │
│                     ├──▶ HttpCheckService       (curl_multi)     │
│                     └──▶ SslService             (TLS direto)     │
└──────────────────────────────────────────────────────────────────┘
```

**O fluxo é sempre de fora para dentro.** O painel nunca abre conexão com o VPS. Não existe porta aberta no servidor monitorado por causa do monitoramento — apenas saída HTTPS.

---

## 2. Por que o agente empurra, em vez do painel puxar

A alternativa óbvia seria o painel se conectar por SSH e coletar. Foi descartada por três motivos:

1. **Superfície de ataque.** Um painel com chave SSH de todos os servidores é um alvo único cujo comprometimento entrega a infraestrutura inteira. Com push, o pior caso do vazamento de um token é a injeção de métricas falsas em **um** servidor.
2. **Rede.** Push funciona atrás de NAT, firewall restritivo e IP dinâmico. Pull exige porta aberta e IP alcançável em cada VPS.
3. **Escala.** Coletar de 50 servidores em série trava o processo do painel. Com push, cada VPS trabalha por conta própria e o painel só recebe.

O preço é que o painel não sabe distinguir "servidor caiu" de "agente parou". A seção 28 do PLAN resolve isso tratando as duas situações como a mesma coisa: **silêncio prolongado é offline**, e o alerta diz exatamente desde quando não há comunicação.

---

## 3. Autenticação do agente

### O problema

O agente precisa provar que é ele. A solução ingênua — mandar o token em um header `Authorization` — tem duas falhas: o segredo trafega em toda requisição, e qualquer captura permite replay indefinido.

### A solução implementada

```text
Cadastro do servidor
  ├─ gera token:  cvps_<serverId>_<64 hex>   (random_bytes, CSPRNG)
  ├─ exibe UMA vez na tela
  └─ grava no banco apenas:  sha256(token)

A cada requisição do agente
  ├─ chave      = sha256(token)                       (derivada localmente)
  ├─ canonical  = serverId \n timestamp \n nonce \n sha256(corpo)
  ├─ assinatura = HMAC-SHA256(canonical, chave)
  └─ envia: X-Server-Id, X-Timestamp, X-Nonce, X-Signature
```

O painel guarda exatamente a mesma derivação (`sha256(token)`) e recalcula a assinatura. **O token em si nunca sai do VPS.**

### O que cada elemento protege

| Elemento | Ataque que impede |
|---|---|
| HMAC sobre o corpo | Adulteração do payload em trânsito |
| `sha256(corpo)` no canonical | Troca de conteúdo mantendo a assinatura |
| Timestamp (janela de 5 min) | Reuso de uma assinatura antiga |
| Nonce com chave única no banco | Replay **dentro** da janela |
| `hash_equals` | Timing attack na comparação |
| Token nunca transmitido | Captura do segredo mesmo com TLS quebrado |

O nonce é validado pelo próprio banco: `UNIQUE (server_id, nonce)`. A segunda tentativa de usar a mesma assinatura viola a chave e é rejeitada — **sem janela de corrida no PHP**, o que um `SELECT` seguido de `INSERT` teria.

### Limitação assumida

A chave HMAC fica no banco. Quem tem leitura do banco consegue forjar chamadas de agente. É o mesmo modelo de qualquer API de chave compartilhada, e é aceitável aqui porque **a V1 é somente leitura**: um atacante nesse nível injetaria métricas falsas, nunca comandos — o agente não executa nada vindo do painel.

Está documentado em `app/Services/TokenService.php`, não escondido.

---

## 4. Fluxo de uma coleta

```text
1. cron dispara            php /opt/controle-vps-agent/agent.php

2. HEARTBEAT               hostname, SO, kernel, arquitetura, vCPUs, uptime
   POST /agent/heartbeat   → servidor marcado ONLINE, identificação atualizada
                           → se estava offline, o alerta é resolvido na hora

3. MÉTRICAS                /proc/stat (2 leituras, 500 ms), /proc/meminfo,
   POST /agent/metrics       disk_*_space(), /proc/loadavg, /proc/uptime
                           → grava em server_metrics
                           → AlertService avalia CPU, RAM e disco

4. SERVIÇOS                systemctl is-active → pgrep → arquivo de versão
   POST /agent/services    → upsert em server_services

5. SITES                   descoberta (CyberPanel → vhosts OLS → /home)
   POST /agent/sites         + curl_multi (HTTP, tempo, IP, certificado)
                             + wp-includes/version.php (WordPress)
                           → upsert em sites, histórico em site_checks,
                             certificado em ssl_certificates
                           → alertas de site offline e SSL
```

Cada etapa é isolada: se a descoberta de sites falhar, as métricas já enviadas continuam válidas.

---

## 5. Descoberta de sites

Cadeia de mecanismos em `agent/lib/SiteDiscoveryService.php`, parando no primeiro que produzir resultado:

| Ordem | Mecanismo | O que traz | Quando falha |
|---|---|---|---|
| 1 | Banco `cyberpanel` | domínio, versão do PHP, estado | Sem root, MySQL parado, sem CyberPanel |
| 2 | `/usr/local/lsws/conf/vhosts/` | domínio, PHP pelo `lsphp82` do vhost | Sem OpenLiteSpeed |
| 3 | `/home/<domínio>/public_html` | domínio | Estrutura fora do padrão |

A API HTTP do CyberPanel foi **descartada** de propósito: exigiria credenciais de administrador em texto no config do agente e faria uma chamada de rede para ler dado que já está no disco local.

Toda dependência de estrutura interna do CyberPanel está isolada em `CyberPanelService`. Trocar de painel no futuro significa escrever uma classe nova com a mesma assinatura pública — nada mais no agente muda.

### Domínio que some não é apagado

Se um domínio deixa de aparecer na descoberta, ele recebe `discovered = 0` e para de contar como ativo, mas **o registro e todo o histórico permanecem**. Uma coleta incompleta (MySQL do CyberPanel momentaneamente parado) não pode destruir meses de dados.

E **lista vazia não invalida nada**: se o agente reporta zero domínios, o painel registra um aviso e preserva os conhecidos.

---

## 6. Verificação HTTP e SSL

### curl_multi

Um servidor com 200 domínios verificados em série, com timeout de 10 s, levaria mais de meia hora no pior caso — inviável para um cron de 5 minutos. Com `curl_multi` e concorrência de 10, o mesmo lote termina em segundos.

### Certificado sem segunda conexão

`CURLOPT_CERTINFO` devolve emissor, início e fim da validade **na mesma requisição** que já fizemos para o HTTP. Abrir um segundo socket TLS por domínio só para ler a data de expiração seria o dobro do trabalho pelo mesmo dado.

Há um plano B (`agent/lib/SslService.php`, socket TLS direto) para builds de cURL sem OpenSSL, onde `CERTINFO` volta vazio.

### Verificação de peer desligada — de propósito

Nas sondas, `CURLOPT_SSL_VERIFYPEER = false`. Isso é deliberado: o objetivo é **diagnosticar** certificados expirados, auto-assinados ou com cadeia quebrada. Abortar o handshake esconderia exatamente o problema que queremos relatar.

> Isso vale só para as sondas de monitoramento. A comunicação do agente **com o painel** verifica TLS normalmente (`VERIFY_TLS => true`).

### Classificação de status (seção 17 do PLAN)

| Faixa | Status | Raciocínio |
|---|---|---|
| 200–399 | ONLINE | Respondeu normalmente |
| 400–499 | **ATENÇÃO** | 404/403 significam que o servidor web está **no ar** |
| 500–599 | OFFLINE | Erro de servidor: indisponível para o visitante |
| Sem resposta | OFFLINE | Timeout, DNS, conexão recusada |
| 200 muito lento | ATENÇÃO | No ar, mas degradado |

Tratar 4xx como offline geraria alarme falso em todo site com uma página removida.

---

## 7. Motor de alertas

### Deduplicação por fingerprint

```php
fingerprint = sha1(tipo | server_id | site_id)
```

Enquanto o problema durar, existe **um** alerta aberto com aquele fingerprint. Cada nova detecção incrementa `occurrences` e move `last_seen_at` — não cria linha nova.

O índice `(fingerprint, status)` torna a busca por "já existe alerta aberto para isso?" uma operação de índice, não uma varredura.

### Resolução automática

Quando a condição desaparece, o mesmo fingerprint localiza o alerta e o marca como resolvido, com `resolved_at` e evento na linha do tempo. Não há intervenção humana no caminho.

As ações manuais (reconhecer, resolver) são **complementares**: se a condição persistir, a próxima coleta reabre. O botão não engana o operador.

### Onde as regras rodam

| Momento | O quê |
|---|---|
| Na chegada dos dados | CPU/RAM/disco, site offline, SSL — reação imediata |
| No cron (5 min) | Servidores sem heartbeat, reavaliação dos limites |
| No cron (diário) | Recálculo dos dias de SSL |

O recálculo diário existe porque um certificado coletado há 20 dias continuaria mostrando os dias daquele momento.

---

## 8. Estrutura do código

```text
app/Core/         Infraestrutura sem regra de negócio
                  Router, Request, Response, Database, View, Session,
                  Csrf, Validator, Logger, Migrator, Model, Controller

app/Middleware/   Decisões antes do controller
                  auth, guest, role, csrf, agent, api, throttle

app/Controllers/  Traduzem HTTP ↔ domínio. Sem SQL, sem regra.
    Api/          Superfícies separadas: agentes (HMAC) e painel (sessão)

app/Services/     Onde as regras vivem
                  AuthService, TokenService, AlertService, MonitoringService,
                  MetricsIngestService, SiteIngestService, SslService,
                  HttpStatusService, RetentionService, SettingsService

app/Repositories/ Consultas compostas, filtros, paginação, agregações

app/Models/       Acesso por tabela, uma classe por tabela
```

### A regra que mantém isso honesto

**Controller não tem SQL. Model não tem regra de negócio.** Uma regra que apareça em dois controllers é sinal de que ela pertence a um service.

---

## 9. Decisões de performance

| Problema | Solução | Onde |
|---|---|---|
| N+1 na lista de servidores | `ServerMetric::latestForAll()` — subselect com `MAX(id)` por `server_id`, uma consulta para todos | `app/Models/ServerMetric.php` |
| 30 dias de disco = 8.640 pontos no gráfico | Downsample para no máximo 288 pontos, preservando o último real | `ServerMetric::downsample()` |
| Contagens do dashboard | `GROUP BY` agregado — nenhuma linha carregada só para ser contada | `ServerRepository::statusSummary()` |
| Estado geral pedido duas vezes por página | Memoização por requisição | `MonitoringService::overallStatus()` |
| Settings do banco em toda página | Cache em arquivo com TTL de 60 s | `SettingsService` |
| Polling do dashboard | 60 s, e pausado com a aba em segundo plano | `public/assets/js/app.js` |
| Tabelas que crescem sem limite | Retenção configurável + `DELETE` em lotes | `RetentionService` |

Índices para os padrões de acesso reais: `(server_id, created_at)` em métricas, `(site_id, created_at)` em verificações, `(fingerprint, status)` em alertas, `(server_id, domain)` único em sites.

---

## 10. Tratamento de erro

A regra da seção 32 do PLAN — *"a indisponibilidade do VPS 03 não pode impedir o processamento dos demais"* — aparece em quatro camadas:

| Camada | Comportamento |
|---|---|
| Ingestão de sites | Cada domínio em seu próprio try/catch; um payload corrompido não derruba os outros 183 |
| Crons | Cada etapa isolada; o resumo informa quantas falharam |
| Agente | Cada etapa isolada; falha em uma não impede as demais |
| Rate limiter | Se a tabela de controle falhar, **deixa passar** — barrar coleta legítima é pior que perder o controle de limite |

O agente sem painel: registra o erro localmente, tenta novamente com backoff, e volta ao normal no ciclo seguinte. Erros definitivos (401, 403, 422) não são retentados — continuariam falhando igual.

---

## 11. Preparação para a V2

O que já está pronto para receber gerenciamento remoto:

- **API versionada** (`/api/v1/`) — a V2 pode introduzir `/api/v2/` sem quebrar os agentes instalados.
- **Superfícies separadas** — agentes e painel já têm autenticação independente.
- **Permissões por perfil** — `RoleMiddleware` já aceita lista de papéis; basta acrescentar.
- **Serviços isolados** — trocar o mecanismo de descoberta não afeta o resto.
- **Auditoria completa** — a trilha para ações administrativas já existe.

O que **falta deliberadamente**, e não deve ser improvisado:

- Canal de comando painel → agente (hoje inexistente por decisão de segurança).
- Autenticação forte para ações destrutivas (segundo fator, confirmação fora de banda).
- Fila de execução com resultado assíncrono.

> A ausência de execução remota não é uma lacuna a ser preenchida no primeiro pedido. É a garantia que torna a V1 segura: um painel comprometido hoje não derruba nem invade nenhum servidor.
