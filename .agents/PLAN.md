# PLAN.md — Prompt oficial do projeto

> Este arquivo guarda **na íntegra** o prompt enviado pelo usuário em 14/08/2026.
> É a fonte de verdade do escopo. Sempre que houver dúvida sobre o que fazer (ou
> sobre o que **não** fazer), consulte este documento antes de decidir.
>
> Complementa: [DESIGN.md](DESIGN.md) (design system) · [PROGRESS.md](PROGRESS.md) (execução)

---

## Contexto de execução informado pelo usuário

| Item | Valor |
|---|---|
| Pasta do projeto | `C:\laragon\www\controle-vps` |
| Banco de dados | `controle-vps` em `localhost` |
| Usuário do banco | `root` |
| Senha do banco | *(vazia)* |
| Idioma de comunicação | Português do Brasil |
| Restrição | **Jamais acessar o navegador para testar** — o usuário testa |
| Documentação | Registrar tudo em `.agents/PROGRESS.md` |
| Dados | Popular tabelas com informações fictícias para demonstração |

### Decisões confirmadas pelo usuário (14/08/2026)

1. **Paleta:** manter `primary: #c8102e` conforme `DESIGN.md`. Regra do próprio
   documento permanece válida — `bg-primary` + `hover:bg-red-800` apenas para ação
   primária; status/alertas usam os tons semânticos (`red-100`/`red-800` em badges,
   `border-red-500` em bordas).
2. **URL local:** vhost `http://controle-vps.test` (DocumentRoot = raiz do projeto),
   com `.htaccess` na raiz encaminhando para `public/`.

---

# Desenvolvimento da V1 — Central de Monitoramento de Servidores CyberPanel

## 1. Objetivo do projeto

Desenvolva uma aplicação web para gerenciamento e monitoramento centralizado de múltiplos servidores VPS que utilizam CyberPanel.

A aplicação será instalada inicialmente em ambiente local para desenvolvimento e testes e posteriormente migrada para uma VPS de produção.

O objetivo da V1 é ser uma **central simples, leve, segura e confiável de monitoramento**, permitindo visualizar em um único painel informações importantes de todos os servidores e dos sites hospedados neles.

A aplicação NÃO deverá executar ações administrativas nos servidores nesta primeira versão.

A V1 será exclusivamente de **monitoramento e coleta de informações**.

Não implementar nesta versão:

* reinicialização de servidores;
* execução arbitrária de comandos;
* gerenciamento de arquivos;
* criação/exclusão de sites;
* gerenciamento de bancos;
* gerenciamento de e-mails;
* instalação de plugins;
* atualização de WordPress;
* backup remoto;
* gerenciamento completo do CyberPanel.

Esses recursos poderão ser adicionados posteriormente.

---

# 2. Stack obrigatória

Utilizar:

### Backend

* PHP 8.2+
* MySQL 8+ ou MariaDB compatível
* PDO
* arquitetura MVC organizada;
* API REST;
* sessões PHP;
* autenticação baseada em sessão para o painel;
* tokens seguros para comunicação dos agentes.

### Frontend

* HTML5
* Tailwind CSS
* JavaScript puro/Vanilla JS
* Fetch API/AJAX
* Chart.js para gráficos quando necessário.

Não utilizar Bootstrap.

Não utilizar frameworks frontend pesados como React, Vue ou Angular nesta V1.

Não utilizar Laravel nesta versão.

A aplicação deve ser desenvolvida em PHP estruturado, porém com arquitetura organizada e preparada para crescimento futuro.

---

# 3. Conceito da arquitetura

A aplicação será dividida em:

```text
                    PAINEL CENTRAL
                  PHP + MySQL + Tailwind
                         │
          ┌──────────────┼──────────────┐
          │              │              │
          ▼              ▼              ▼
      AGENTE VPS 01  AGENTE VPS 02  AGENTE VPS 03
          │              │              │
          ▼              ▼              ▼
      CyberPanel      CyberPanel      CyberPanel
```

O painel central será responsável por:

* autenticação;
* cadastro dos servidores;
* gerenciamento dos servidores;
* recebimento dos dados;
* armazenamento das métricas;
* armazenamento dos sites;
* análise dos dados;
* dashboard;
* alertas;
* histórico.

Cada servidor monitorado terá um pequeno **agente de monitoramento**.

O agente será responsável por coletar informações localmente no VPS e enviá-las para a aplicação central através de HTTPS.

---

# 4. Agente de monitoramento

Criar um pequeno agente para ser instalado em cada VPS.

O agente deve ser extremamente leve.

Preferencialmente utilizar PHP CLI e/ou Bash para coleta das informações do sistema.

O agente não deverá possuir interface gráfica.

A instalação deverá ser simples.

Exemplo conceitual:

```text
/opt/server-monitor/
    agent.php
    config.php
    logs/
```

O agente deverá possuir um token exclusivo para aquele servidor.

Exemplo:

```text
SERVER_ID=12
SERVER_TOKEN=token-seguro
CENTRAL_URL=https://monitoramento.exemplo.com/api
```

O agente deverá enviar dados para o painel central através de HTTPS.

---

# 5. Segurança do agente

Cada servidor deve possuir um token único.

O painel central deverá gerar esse token quando o servidor for cadastrado.

Nunca armazenar tokens em texto visível no frontend.

Armazenar tokens de forma segura no banco.

A API deverá validar:

* servidor;
* token;
* assinatura ou mecanismo equivalente;
* timestamp da requisição;
* formato dos dados.

Evitar replay attacks utilizando timestamp/nonce quando aplicável.

Nunca aceitar comandos arbitrários vindos do painel.

A V1 é somente coleta de dados.

O agente jamais deverá executar comandos recebidos através da API.

---

# 6. Informações coletadas do servidor

O agente deverá coletar, sempre que possível:

### Identificação

* hostname;
* nome configurado no painel;
* IP público;
* sistema operacional;
* versão do sistema;
* arquitetura;
* kernel.

### Hardware/recursos

* quantidade de CPUs;
* utilização de CPU;
* load average;
* memória RAM total;
* memória RAM utilizada;
* memória RAM disponível;
* swap total;
* swap utilizada;
* espaço total em disco;
* espaço utilizado;
* espaço livre;
* percentual de utilização.

### Serviços

Detectar e informar, quando possível:

* OpenLiteSpeed;
* MariaDB/MySQL;
* Redis;
* CyberPanel;
* PHP.

Não considerar a ausência de um desses serviços como erro crítico, pois diferentes servidores podem possuir configurações diferentes.

### Sistema

* uptime;
* horário da última coleta;
* horário da última comunicação com o painel.

---

# 7. Informações dos sites

O agente deverá descobrir automaticamente os sites/domínios hospedados no CyberPanel.

Não exigir que o usuário cadastre cada site manualmente.

Cada site deverá estar associado ao servidor correspondente.

Para cada domínio coletar:

* domínio;
* status;
* URL;
* HTTP status code;
* tempo de resposta;
* HTTPS disponível;
* validade do SSL;
* data de expiração do SSL;
* IP;
* PHP version quando possível;
* indicação se é WordPress quando possível.

Exemplo:

```text
example.com.br

Status: Online
HTTP: 200
Resposta: 183 ms
SSL: Válido
SSL expira: 18/09/2026
PHP: 8.2
WordPress: Detectado
```

---

# 8. Descoberta de sites

A aplicação deverá permitir que o agente descubra automaticamente os domínios existentes no servidor.

Priorizar mecanismos nativos do CyberPanel/OpenLiteSpeed sempre que possível.

Caso a API do CyberPanel seja utilizada, encapsular sua implementação em uma classe/serviço separado.

Exemplo:

```text
CyberPanelService
ServerMetricsService
SiteDiscoveryService
SSLService
```

A arquitetura deve permitir substituir futuramente o mecanismo de descoberta sem alterar o restante da aplicação.

---

# 9. Comunicação

Criar API REST para comunicação entre agentes e painel.

Exemplo:

```text
POST /api/v1/agent/heartbeat
POST /api/v1/agent/metrics
POST /api/v1/agent/sites
POST /api/v1/agent/services
```

O endpoint de heartbeat deverá indicar que o servidor está ativo.

O agente deverá enviar dados periodicamente.

A periodicidade inicial deve ser configurável, com valor padrão de 5 minutos.

---

# 10. Dashboard principal

Criar um dashboard moderno, limpo e responsivo.

O dashboard deverá apresentar imediatamente uma visão geral da infraestrutura.

Exibir cards:

```text
Servidores
12

Online
11

Offline
1

Sites
184

Sites Online
181

Alertas
4
```

Adicionar seção de utilização dos servidores.

Exemplo:

```text
CPU
██████░░░░ 61%

RAM
█████░░░░░ 48%

DISCO
████████░░ 79%
```

Utilizar indicadores visuais:

* verde = normal;
* amarelo = atenção;
* vermelho = crítico.

Não utilizar excesso de cores.

---

# 11. Lista de servidores

Criar página:

```text
Servidores
```

Tabela contendo:

* nome;
* provedor;
* hostname;
* IP;
* status;
* CPU;
* RAM;
* disco;
* sites;
* última comunicação;
* ações.

Exemplo:

```text
VPS João Pessoa
Provedor X
45.XX.XX.XX
🟢 Online
CPU 34%
RAM 52%
Disco 67%
32 sites
2 min atrás
```

Ações:

* visualizar;
* editar;
* excluir;
* gerar novo token;
* visualizar sites.

Não criar ações administrativas sobre o VPS na V1.

---

# 12. Cadastro de servidor

Criar formulário:

```text
Nome do servidor
Provedor
Hostname
IP
Descrição
```

Ao salvar:

1. gerar identificação única;
2. gerar token seguro;
3. salvar servidor;
4. mostrar instruções de instalação do agente.

Exemplo:

```text
Servidor cadastrado com sucesso.

SERVER ID:
27

TOKEN:
xxxxxxxxxxxxxxxx

Instalação do agente:

[comando de instalação]
```

O token completo deverá ser mostrado apenas no momento apropriado.

Permitir gerar um novo token caso o atual seja comprometido.

Ao regenerar, invalidar o token anterior.

---

# 13. Página individual do servidor

Ao clicar em um servidor, abrir uma página detalhada.

Mostrar:

## Informações

* nome;
* provedor;
* hostname;
* IP;
* sistema operacional;
* kernel;
* PHP;
* OpenLiteSpeed;
* uptime.

## Recursos

Gráficos de:

* CPU;
* RAM;
* disco;
* load average.

## Serviços

Exemplo:

```text
OpenLiteSpeed     🟢
MariaDB           🟢
Redis             🟢
CyberPanel        🟢
```

## Sites

Mostrar todos os sites daquele servidor.

---

# 14. Página de sites

Criar página:

```text
Sites
```

Permitir:

* pesquisar por domínio;
* filtrar por servidor;
* filtrar por status;
* filtrar por SSL;
* ordenar;
* paginação.

Tabela:

```text
Domínio
Servidor
Status
HTTP
SSL
Expiração SSL
PHP
WordPress
Resposta
Última verificação
```

---

# 15. Página individual do site

Criar uma página detalhada para cada domínio.

Mostrar:

```text
example.com.br

🟢 ONLINE

Servidor:
VPS 01

HTTP:
200

Tempo de resposta:
183 ms

SSL:
Válido

Expira:
18/09/2026

PHP:
8.2

WordPress:
6.x
```

Caso esteja offline:

```text
🔴 OFFLINE

HTTP:
503

Última resposta:
503 Service Unavailable
```

Registrar histórico das verificações.

---

# 16. Monitoramento de SSL

O sistema deverá verificar certificados SSL.

Classificar:

### Verde

Certificado válido por mais de 30 dias.

### Amarelo

Certificado vence em até 30 dias.

### Vermelho

Certificado expirado.

### Cinza

Não foi possível verificar.

Mostrar:

* emissor;
* validade;
* data de emissão;
* data de expiração;
* dias restantes.

---

# 17. Status dos sites

Considerar inicialmente:

### ONLINE

HTTP 200–399.

### OFFLINE

Erros HTTP 500–599 ou impossibilidade de conexão.

### ATENÇÃO

Problemas temporários, timeout ou informações incompletas.

Não considerar automaticamente qualquer HTTP 4xx como servidor offline.

Por exemplo:

```text
404 = servidor provavelmente está online
403 = servidor provavelmente está online
500 = problema
502 = problema
503 = problema
timeout = problema
```

---

# 18. Sistema de alertas

Criar alertas internos.

Exemplos:

```text
🔴 Servidor VPS 03 offline.

⚠️ VPS 01 está utilizando 87% do disco.

⚠️ example.com.br SSL vence em 12 dias.

🔴 loja.com.br retornou HTTP 503.
```

Tipos:

* server_offline;
* server_disk_high;
* server_memory_high;
* server_cpu_high;
* site_offline;
* ssl_expiring;
* ssl_expired.

Cada alerta deve possuir:

* tipo;
* severidade;
* servidor;
* site, quando aplicável;
* mensagem;
* data;
* status;
* data de resolução.

---

# 19. Regras iniciais de alerta

Configurar valores padrão:

CPU:

```text
< 80% = normal
80–90% = atenção
> 90% = crítico
```

RAM:

```text
< 80% = normal
80–90% = atenção
> 90% = crítico
```

Disco:

```text
< 80% = normal
80–90% = atenção
> 90% = crítico
```

SSL:

```text
> 30 dias = normal
8–30 dias = atenção
<= 7 dias = crítico
expirado = crítico
```

Esses valores deverão ficar configuráveis futuramente.

---

# 20. Histórico

Armazenar histórico das métricas.

Não armazenar dados a cada poucos segundos.

A coleta inicial será de 5 em 5 minutos.

Registrar:

* CPU;
* RAM;
* disco;
* load;
* uptime;
* timestamp.

Permitir posteriormente gerar gráficos:

```text
CPU últimas 24 horas
RAM últimas 24 horas
Disco últimos 30 dias
Load últimas 24 horas
```

---

# 21. Retenção dos dados

Para evitar crescimento excessivo do banco:

* métricas detalhadas: 30 dias;
* histórico resumido: possibilidade futura de retenção maior;
* logs de sistema: conforme configuração.

Criar estrutura que permita posteriormente implementar limpeza automática via cron.

Não apagar dados importantes de servidores/sites.

---

# 22. Banco de dados

Criar migrations/scripts SQL para as principais tabelas.

Estrutura sugerida:

```text
users
servers
server_tokens
server_metrics
server_services
sites
site_checks
ssl_certificates
alerts
alert_events
audit_logs
settings
```

### users

Campos:

```text
id
name
email
password_hash
role
status
created_at
updated_at
last_login_at
```

### servers

```text
id
name
provider
hostname
ip
description
status
last_seen_at
created_at
updated_at
```

### server_tokens

```text
id
server_id
token_hash
created_at
last_used_at
revoked_at
```

### server_metrics

```text
id
server_id
cpu_usage
ram_total
ram_used
ram_percent
swap_total
swap_used
disk_total
disk_used
disk_percent
load_1
load_5
load_15
uptime
created_at
```

### sites

```text
id
server_id
domain
status
http_status
response_time
php_version
wordpress_detected
wordpress_version
last_check_at
created_at
updated_at
```

### ssl_certificates

```text
id
site_id
issuer
valid_from
valid_until
days_remaining
status
checked_at
```

### alerts

```text
id
server_id
site_id
type
severity
title
message
status
created_at
resolved_at
```

### audit_logs

Registrar eventos administrativos importantes.

---

# 23. Usuários

Criar autenticação.

V1 inicialmente pode possuir:

### Administrador

Acesso completo ao painel.

### Operador

Pode visualizar servidores, sites e alertas.

Não precisa implementar gerenciamento complexo de permissões nesta primeira versão, mas a arquitetura deve suportar isso.

Nunca armazenar senha em texto puro.

Utilizar:

```php
password_hash()
password_verify()
```

---

# 24. Interface

A interface deve ser:

* moderna;
* minimalista;
* profissional;
* responsiva;
* rápida;
* adequada para desktop;
* utilizável em tablet.

Sidebar:

```text
Dashboard

Infraestrutura
 ├── Servidores
 └── Sites

Monitoramento
 ├── Métricas
 └── Alertas

Configurações
 ├── Usuários
 └── Sistema
```

No topo:

* usuário logado;
* status geral;
* botão de logout.

---

# 25. Design

Utilizar Tailwind CSS.

Priorizar:

* fundo claro;
* cards limpos;
* bordas discretas;
* boa hierarquia visual;
* tipografia moderna;
* espaçamento consistente.

Status:

* verde para normal;
* amarelo para atenção;
* vermelho para crítico;
* cinza para desconhecido.

Não criar uma interface visualmente exagerada.

O foco é administração e leitura rápida de informações.

---

# 26. Responsividade

O dashboard deverá funcionar em:

* desktop;
* notebook;
* tablet;
* celular.

No celular:

* sidebar deverá se transformar em menu;
* tabelas deverão possuir comportamento responsivo;
* cards deverão reorganizar automaticamente.

---

# 27. Cron Jobs

Criar scripts CLI independentes.

Exemplo:

```text
cron/
    cleanup.php
    process-alerts.php
```

O agente terá seu próprio cron.

Exemplo:

```text
*/5 * * * * php /opt/server-monitor/agent.php
```

O painel central deverá possuir cron para:

* limpeza de métricas antigas;
* processamento de alertas;
* detecção de servidores sem comunicação;
* tarefas de manutenção.

---

# 28. Servidor offline

Se o painel não receber heartbeat de um servidor dentro de determinado período:

```text
último heartbeat > 10 minutos
```

marcar como:

```text
OFFLINE
```

Não gerar dezenas de alertas iguais.

Criar apenas um alerta enquanto o problema persistir.

Quando o servidor voltar:

```text
ONLINE
```

resolver automaticamente o alerta correspondente.

---

# 29. Site offline

Aplicar lógica semelhante aos sites.

Se um site estiver indisponível:

* criar alerta;
* evitar duplicação;
* monitorar novamente;
* resolver automaticamente quando voltar.

Registrar histórico das mudanças.

---

# 30. API

Criar uma camada de API organizada:

```text
/api/v1/
```

Separar:

```text
/api/v1/auth
/api/v1/agent
/api/v1/servers
/api/v1/sites
```

A API dos agentes deve ser separada das APIs utilizadas pelo frontend.

Nunca expor endpoints administrativos sem autenticação.

---

# 31. Logs

Criar sistema de logs da aplicação.

Registrar:

* login;
* logout;
* criação de servidor;
* exclusão de servidor;
* regeneração de token;
* comunicação de agente;
* erros de API;
* erros de coleta;
* mudanças importantes.

Não registrar:

* senhas;
* tokens completos;
* dados sensíveis.

---

# 32. Tratamento de erros

A aplicação não deve quebrar caso um servidor esteja indisponível.

Exemplo:

```text
VPS 01 🟢
VPS 02 🟢
VPS 03 🔴
VPS 04 🟢
```

A indisponibilidade do VPS 03 não pode impedir o processamento dos demais.

O agente também deverá tratar falhas de conexão com o painel central.

Se o painel estiver temporariamente indisponível:

* registrar erro local;
* tentar novamente na próxima execução;
* não interromper permanentemente o agente.

---

# 33. Segurança geral

Implementar obrigatoriamente:

* prepared statements;
* PDO;
* CSRF protection;
* escaping de saída;
* validação de dados;
* proteção contra SQL Injection;
* proteção contra XSS;
* proteção contra brute force no login;
* sessões seguras;
* regeneração de session ID após login;
* HTTPS em produção;
* tokens aleatórios criptograficamente seguros;
* rate limiting básico na API.

Nunca executar comandos recebidos diretamente de parâmetros HTTP.

Nunca permitir:

```text
?command=...
?exec=...
?cmd=...
```

A V1 não possuirá execução remota de comandos.

---

# 34. Configuração

Não deixar credenciais diretamente no código.

Criar arquivo:

```text
.env
```

Exemplo:

```text
APP_ENV=local
APP_DEBUG=true

DB_HOST=127.0.0.1
DB_DATABASE=server_monitor
DB_USERNAME=root
DB_PASSWORD=

APP_URL=http://localhost/server-monitor
```

No ambiente de produção:

```text
APP_ENV=production
APP_DEBUG=false
```

Nunca versionar `.env`.

Criar `.env.example`.

---

# 35. Estrutura de diretórios

Criar uma estrutura semelhante a:

```text
server-monitor/
│
├── app/
│   ├── Controllers/
│   ├── Models/
│   ├── Services/
│   ├── Repositories/
│   ├── Middleware/
│   ├── Helpers/
│   └── Core/
│
├── config/
│
├── database/
│   ├── migrations/
│   └── seeders/
│
├── public/
│   ├── index.php
│   ├── assets/
│   └── uploads/
│
├── resources/
│   ├── views/
│   ├── css/
│   └── js/
│
├── routes/
│   ├── web.php
│   └── api.php
│
├── storage/
│   ├── logs/
│   └── cache/
│
├── cron/
│
├── agent/
│
├── .env.example
├── .gitignore
└── README.md
```

Utilizar autoload PSR-4 via Composer se necessário, mas não transformar o projeto em um framework.

---

# 36. Instalação local

Criar documentação para instalação local.

A documentação deve explicar:

1. requisitos;
2. criação do banco;
3. configuração do `.env`;
4. instalação das dependências;
5. execução das migrations;
6. criação do usuário administrador;
7. execução do sistema;
8. configuração do agente;
9. configuração do cron.

A aplicação deve funcionar em ambiente local antes de ser migrada para VPS.

---

# 37. Instalação em VPS

Criar documentação para produção considerando:

* Ubuntu;
* CyberPanel;
* OpenLiteSpeed;
* PHP 8.2;
* MySQL/MariaDB;
* HTTPS;
* domínio/subdomínio;
* permissões de arquivos;
* cron.

Não depender de configurações exclusivas do ambiente local.

---

# 38. Não utilizar dados fictícios na aplicação final

Durante o desenvolvimento pode utilizar seeders para demonstração.

Porém, deixar claramente identificados como dados de teste.

O dashboard de produção deve trabalhar com dados reais dos agentes.

---

# 39. Performance

A aplicação deverá ser leve.

Evitar:

* consultas SQL desnecessárias;
* polling excessivo;
* chamadas externas a cada carregamento de página;
* armazenamento excessivo de métricas.

O frontend deverá carregar os dados através de consultas eficientes.

Criar índices adequados no banco.

Especial atenção para:

```text
server_id
site_id
created_at
status
domain
last_seen_at
```

---

# 40. Resultado esperado da V1

Ao final, o sistema deverá permitir:

1. fazer login;
2. cadastrar servidores;
3. gerar tokens;
4. instalar agentes;
5. receber dados dos agentes;
6. descobrir sites automaticamente;
7. visualizar todos os servidores;
8. visualizar todos os sites;
9. visualizar CPU;
10. visualizar RAM;
11. visualizar disco;
12. visualizar load;
13. visualizar uptime;
14. verificar SSL;
15. verificar HTTP;
16. detectar servidores offline;
17. detectar sites offline;
18. criar alertas;
19. resolver alertas automaticamente;
20. visualizar histórico;
21. visualizar gráficos;
22. administrar usuários;
23. visualizar logs;
24. executar manutenção automática via cron.

---

# 41. Importante: não implementar funcionalidades da V2

Não adicionar por iniciativa própria:

* SSH;
* execução de comandos;
* terminal web;
* reiniciar VPS;
* reiniciar serviços;
* criação de sites;
* exclusão de sites;
* criação de bancos;
* gerenciamento de e-mail;
* gerenciamento DNS;
* gerenciamento de arquivos;
* backup;
* restauração;
* atualização automática de WordPress;
* gerenciamento de plugins;
* gerenciamento de temas;
* instalação de software.

Esses recursos serão planejados separadamente.

---

# 42. Ordem de desenvolvimento

Desenvolver seguindo esta ordem:

### Etapa 1

Estrutura inicial da aplicação.

### Etapa 2

Banco de dados.

### Etapa 3

Autenticação.

### Etapa 4

Dashboard.

### Etapa 5

Cadastro de servidores.

### Etapa 6

API de agentes.

### Etapa 7

Desenvolvimento do agente.

### Etapa 8

Coleta de métricas.

### Etapa 9

Descoberta automática dos sites.

### Etapa 10

Monitoramento HTTP.

### Etapa 11

Monitoramento SSL.

### Etapa 12

Histórico.

### Etapa 13

Alertas.

### Etapa 14

Gráficos.

### Etapa 15

Logs.

### Etapa 16

Segurança e hardening.

### Etapa 17

Testes.

### Etapa 18

Documentação de instalação local.

### Etapa 19

Documentação de instalação em VPS.

---

# 43. Testes obrigatórios

Criar testes para:

* login;
* logout;
* autenticação;
* criação de servidor;
* geração de token;
* autenticação do agente;
* recebimento de heartbeat;
* recebimento de métricas;
* descoberta de sites;
* site online;
* site offline;
* SSL válido;
* SSL expirado;
* servidor offline;
* criação de alerta;
* resolução de alerta;
* limpeza de métricas;
* permissões;
* proteção CSRF;
* validação de API.

Testar também situações de falha:

* servidor sem internet;
* agente sem acesso ao painel;
* painel sem acesso ao banco;
* site indisponível;
* timeout;
* certificado inválido;
* servidor com disco cheio.

---

# 44. Critério de qualidade

Não entregar apenas uma interface visual.

O sistema deve ser funcional.

O agente deve realmente coletar informações.

A API deve realmente receber os dados.

Os dados devem realmente ser persistidos no MySQL.

O dashboard deve utilizar dados reais do banco.

Os sites devem ser descobertos automaticamente quando possível.

Os alertas devem ser gerados através das regras reais.

Não criar botões que não possuem funcionalidade.

Não utilizar placeholders para funcionalidades que deveriam estar implementadas.

---

# 45. Preparação para V2

Embora a V1 não implemente gerenciamento remoto, criar uma arquitetura preparada para futuramente adicionar:

```text
V2

SSH
Serviços
Logs
Terminal
Backups
WordPress
CyberPanel
DNS
E-mail
Gerenciamento de arquivos
```

Porém, não implementar essas funcionalidades agora.

A prioridade absoluta da V1 é:

**monitorar servidores e sites de forma confiável, segura e centralizada.**

---

# 46. Entrega final

Ao concluir o desenvolvimento, fornecer:

* código completo;
* estrutura de banco;
* migrations;
* seed inicial;
* agente;
* API;
* dashboard;
* documentação;
* `.env.example`;
* instruções de instalação local;
* instruções de produção;
* instruções para instalar o agente;
* instruções para configurar cron;
* instruções para criar o primeiro administrador;
* instruções de troubleshooting.

Também fornecer uma explicação clara da arquitetura:

```text
Painel Central
      ↓
API
      ↓
Agentes
      ↓
Servidores
      ↓
CyberPanel / OpenLiteSpeed
```

O projeto deve ser entregue de forma que possa ser executado localmente primeiro e posteriormente migrado para uma VPS sem necessidade de alterar a arquitetura principal.
