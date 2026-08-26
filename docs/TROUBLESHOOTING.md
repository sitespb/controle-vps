# Troubleshooting

Problemas ordenados por frequência real. Cada bloco traz o sintoma, a causa e a correção.

---

## Painel

### A página abre sem estilo nenhum

**Causa provável:** `APP_URL` incompatível com a forma de acesso, ou o CSS não foi compilado.

```bash
# 1. O CSS existe?
ls -la public/assets/css/app.css

# 2. Ele é servido? Deve retornar 200 e text/css
curl -I http://controle-vps.test/assets/css/app.css
```

- **404 no CSS** → `APP_URL` está errado. Acessando por `http://localhost/controle-vps/public`? Então `APP_URL=http://localhost/controle-vps/public`. Usando vhost? Então `APP_URL=http://controle-vps.test`.
- **Arquivo não existe** → `npm install && npm run build:css`.

---

### Erro 500 em branco

```bash
tail -50 storage/logs/app-$(date +%F).log
```

Se não houver log, o problema é anterior ao bootstrap:

```bash
php -l public/index.php
php bin/console.php db:check
ls -ld storage/logs storage/cache     # precisam ser graváveis
```

Em desenvolvimento, `APP_DEBUG=true` mostra a mensagem na tela. **Nunca em produção.**

---

### "Nao foi possivel conectar ao banco de dados"

```bash
php bin/console.php db:check
```

| Mensagem | Causa | Correção |
|---|---|---|
| `Access denied for user` | Usuário ou senha errados | Confira `DB_USERNAME` / `DB_PASSWORD` |
| `Unknown database` | Banco não existe | `php bin/console.php db:create` |
| `Connection refused` | MySQL parado | Inicie o serviço |
| `No such file or directory` | Socket em vez de TCP | Use `DB_HOST=127.0.0.1`, não `localhost` |

> `localhost` faz o PHP tentar socket Unix; `127.0.0.1` força TCP. Em Windows e em muitos containers, só o segundo funciona.

---

### Todos os links dão 404, mas a home abre

`mod_rewrite` desligado ou `AllowOverride` bloqueando o `.htaccess`.

```bash
# Apache
a2enmod rewrite && systemctl restart apache2
```

No Laragon, `mod_rewrite` já vem ativo — se falhou, confira se o `.htaccess` foi copiado (arquivos que começam com ponto são fáceis de esquecer).

No OpenLiteSpeed: **Server Configuration → Modules** e confirme o `mod_rewrite`.

---

### Sessão expira o tempo todo / não consigo logar

- **`SESSION_SECURE=true` sem HTTPS** → o cookie não é gravado. Em local, use `false`.
- **`SESSION_LIFETIME` muito curto** → padrão 120 minutos.
- **`storage/` sem permissão de escrita** → o PHP não grava a sessão.

---

### "Sua sessao expirou. Envie o formulario novamente."

É o CSRF barrando. Causas:

- o formulário ficou aberto além do tempo de sessão — recarregue a página;
- o cookie de sessão não está sendo gravado (veja o item acima);
- o formulário foi criado sem `<?= csrf_field() ?>` (se você editou uma view).

---

### Erro 403 "Esta area e restrita a administradores"

Comportamento correto: o perfil **Operador** tem acesso somente de leitura.

```bash
php bin/console.php user:list
```

Para promover, edite em **Configurações → Usuários**.

---

## Agente

### O `install.sh` para em "Extensoes obrigatorias ausentes"

Desde a versão atual, o instalador exige `curl`, `json`, `openssl` e `mbstring`. Ao detectar alguma ausente, ele:

1. identifica o gerenciador de pacotes (`apt`, `dnf` ou `yum`) e monta o comando certo, já com o nome de pacote correto para a versão do PHP detectada;
2. pergunta se pode instalar na hora (responda `s`), ou instala direto se você rodou com `--yes`;
3. se não reconhecer o gerenciador, ou se a sessão não for interativa (script/cron/CI), só mostra o comando e para — rode-o manualmente e execute o `install.sh` de novo.

Se preferir instalar por conta própria antes de rodar o instalador:

```bash
# Debian/Ubuntu (ajuste 8.1 para a versão do seu PHP)
sudo apt update && sudo apt install -y php8.1-curl php8.1-mbstring

# AlmaLinux/CentOS/RHEL
sudo dnf install -y php-curl php-mbstring
```

`pdo_mysql` continua **opcional**: sem ele a descoberta de sites usa os vhosts do OpenLiteSpeed em vez de consultar o CyberPanel direto no banco.

---

### "Call to undefined function Agent\mb_substr()" ao rodar o agente

Sintoma visto em produção: o `install.sh` termina com sucesso (inclusive `--test` passa, pois heartbeat e métricas não usam `mbstring`), mas a coleta real falha nas etapas `[3/4] Detectando servicos` e `[4/4] Descobrindo e verificando dominios` com esse erro no log.

Causa: a extensão `mbstring` do PHP não está instalada — usada em `HttpCheckService.php`, `ServicesService.php` e `SslService.php` para truncar strings (versão de serviço, erro de handshake TLS, campos do certificado).

Correção:

```bash
sudo apt install -y php8.1-mbstring   # ou o equivalente do seu gerenciador
php /opt/controle-vps-agent/agent.php --verbose   # confirma que as 4 etapas passam
```

Rodar `install.sh` de novo (versão atual) também resolve — ele detecta e oferece instalar essa extensão automaticamente antes de gerar o `config.php`.

---

### `--test` falha em "Alcancando o painel central"

```bash
# Do VPS monitorado
curl -v https://monitoramento.seudominio.com.br/api/v1/health
```

| Sintoma | Causa | Correção |
|---|---|---|
| `Could not resolve host` | DNS | Confira o domínio e o DNS do VPS |
| `Connection timed out` | Firewall de saída | Libere a 443 de saída |
| `SSL certificate problem` | Certificado inválido | Corrija o SSL do painel; em homologação, `VERIFY_TLS => false` |
| `404` | URL sem `/api` | `CENTRAL_URL` precisa terminar em `/api` |

---

### `--test` falha em "Autenticando com o token"

Leia o código do erro no JSON de resposta:

| Código | Significado | Correção |
|---|---|---|
| `no_active_token` | O `SERVER_ID` não tem token ativo | Gere um novo no painel e atualize o `config.php` |
| `signature_mismatch` | Token errado ou corrompido | Confira se copiou o token inteiro, sem espaço no fim |
| `stale_timestamp` | Relógio dessincronizado | `timedatectl set-ntp true` no VPS |
| `replay_detected` | Nonce repetido | Duas execuções simultâneas; confira se há cron duplicado |
| `server_not_found` | Servidor excluído do painel | Cadastre de novo |

**Sobre o relógio:** a API aceita uma janela de 5 minutos. Verifique com:

```bash
date -u                                    # no VPS
curl -sI https://seu-painel/api/v1/health | grep -i ^date   # no painel
```

---

### O servidor aparece "Offline" mesmo com o agente rodando

```bash
# 1. O cron está registrado?
crontab -l | grep agent.php

# 2. O que dizem os logs?
tail -30 /opt/controle-vps-agent/logs/agent-$(date +%F).log
tail -30 /opt/controle-vps-agent/logs/cron.log

# 3. Executando à mão, funciona?
php /opt/controle-vps-agent/agent.php --verbose
```

Se funciona à mão mas não pelo cron, quase sempre é **PATH**: o cron não herda o ambiente do shell. Use o caminho absoluto do PHP:

```bash
which php     # use esta saída completa na linha do crontab
```

Confira também o intervalo: se o cron roda a cada 15 minutos e a tolerância é de 10, o servidor vai oscilar entre online e offline. O cron do agente deve ser **mais frequente** que `SERVER_OFFLINE_AFTER`.

---

### Nenhum site é descoberto

```bash
php /opt/controle-vps-agent/agent.php --only=sites --verbose
```

| Mensagem no log | Causa | Correção |
|---|---|---|
| `CyberPanel nao instalado` | Sem `/usr/local/CyberCP` | Normal fora do CyberPanel; o fallback usa `/home` |
| `Nao foi possivel ler /etc/cyberpanel/mysqlPassword` | Agente não roda como root | Rode o cron como root |
| `banco indisponivel` | MySQL parado ou senha mudou | Verifique o MySQL |
| `Nenhum dominio descoberto` | Nenhum dos três mecanismos achou nada | Confirme que existem sites e que a estrutura é a padrão do CyberPanel |

Verificação manual:

```bash
ls /usr/local/lsws/conf/vhosts/     # vhosts do OpenLiteSpeed
ls /home/                           # diretórios por domínio
mysql -u root -p$(cat /etc/cyberpanel/mysqlPassword) \
      -e "SELECT domain FROM cyberpanel.websiteFunctions_websites LIMIT 5"
```

---

### SSL aparece "Sem dados" para todos os sites

O cURL do sistema pode estar sem suporte a `CERTINFO` (builds com GnuTLS/NSS).

```bash
php -r "print_r(curl_version());" | grep -i ssl_version
```

Se não for OpenSSL, o plano B assume automaticamente. Confirme que está ativo:

```php
'SSL_FALLBACK' => true,   // em config.php
```

---

### A coleta demora demais / o cron se sobrepõe

Servidor com muitos domínios. Ajuste no `config.php`:

```php
'CHECK_CONCURRENCY' => 20,   // mais paralelismo (cuidado com CPU baixa)
'CHECK_TIMEOUT'     => 6,    // desiste mais cedo de site lento
'SSL_FALLBACK'      => false, // desliga a inspeção TLS extra
```

Meça:

```bash
time php /opt/controle-vps-agent/agent.php
```

O ciclo deve terminar **bem antes** do intervalo do cron.

---

## Alertas e dados

### Nenhum alerta é criado

O cron do painel não está rodando.

```bash
php cron/process-alerts.php     # com saída detalhada
tail -30 storage/logs/cron-$(date +%F).log
```

---

### Alertas demais / limites errados

**Configurações → Sistema.** Padrões: atenção em 80%, crítico em 90%, SSL avisa aos 30 dias e fica crítico aos 7.

Depois de alterar:

```bash
rm -f storage/cache/settings.php    # o cache tem TTL de 60 s
```

---

### Um alerta resolvido volta sozinho

Comportamento correto. Resolver manualmente fecha o registro atual; se a condição persistir, a próxima coleta abre de novo. O botão não mascara o problema — ele existe para silenciar ruído já conhecido.

---

### Um domínio já excluído continua alertando "SSL expirado"

Quando um domínio some da descoberta, o painel marca o site como `discovered = 0` e para de checá-lo — e encerra automaticamente os alertas dele (`site_offline`, `ssl_expiring`, `ssl_expired`). Alertas de servidor não são tocados.

Se o alerta continua aparecendo, o domínio **ainda está sendo descoberto** no VPS. Confira em qual das quatro fontes ele sobrou:

```bash
# No VPS monitorado — mesma descoberta que o agente faz:
php -r '
$d = "/opt/controle-vps-agent/lib/";
foreach (["Config.php","Logger.php","Shell.php","CyberPanelService.php","AaPanelService.php","SiteDiscoveryService.php"] as $f) require $d.$f;
$r = (new Agent\SiteDiscoveryService(new Agent\Logger("/tmp", false)))->discover();
echo "FONTE: {$r["source"]}   TOTAL: ".count($r["sites"])."\n";
foreach ($r["sites"] as $s) echo $s["domain"]."\n";
' | grep -i SEU-DOMINIO
```

Sobras típicas: linha órfã em `websiteFunctions_websites` (banco do CyberPanel), `/usr/local/lsws/conf/vhosts/<domínio>/` e `/home/<domínio>/public_html`. Remova a sobra e rode `php agent.php --only=sites`.

**Alertas anteriores à correção** ficaram abertos porque nada os encerrava. Limpe uma única vez:

```bash
php bin/fix-orphan-alerts.php            # simulação: lista sem gravar
php bin/fix-orphan-alerts.php --apply    # executa (faça backup de alerts/alert_events antes)
```

---

### Os dados de demonstração ficaram todos offline

Esperado: os dados nascem com o horário da geração e o cron, funcionando como deveria, marca como offline quem parou de reportar.

```bash
php bin/console.php db:seed --refresh
```

Desloca a série inteira no tempo, preservando as curvas e o histórico.

---

### O banco está crescendo demais

```bash
php cron/cleanup.php --dry-run    # mostra o que seria removido
php cron/cleanup.php              # executa
```

Se ainda estiver grande, reduza a retenção em **Configurações → Sistema**. Estimativa: cada servidor gera ~8.640 amostras em 30 dias (coleta de 5 em 5 minutos).

---

## Diagnóstico rápido

```bash
# Painel
php bin/console.php db:check
php bin/console.php migrate:status
php tests/run.php
tail -50 storage/logs/app-$(date +%F).log
curl -s https://seu-painel/api/v1/health

# Agente (no VPS monitorado)
php /opt/controle-vps-agent/agent.php --test --verbose
php /opt/controle-vps-agent/agent.php --dry-run --verbose
tail -50 /opt/controle-vps-agent/logs/agent-$(date +%F).log
crontab -l | grep agent
```

`--dry-run` coleta tudo e imprime o que **seria** enviado, sem enviar nada. É a forma mais rápida de saber se o problema é de coleta ou de comunicação.

---

## Quando nada disso resolve

Reúna, sem incluir tokens ou senhas:

1. saída de `php bin/console.php db:check`;
2. últimas 50 linhas de `storage/logs/app-*.log`;
3. saída de `php agent.php --test --verbose` no VPS;
4. versões: `php -v`, `mysql --version`, distribuição do VPS;
5. o valor de `APP_URL` e a forma de acesso ao painel.
