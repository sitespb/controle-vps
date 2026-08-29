# Instalação em produção (Ubuntu + CyberPanel + OpenLiteSpeed)

Guia para publicar o painel central em uma VPS. A arquitetura é a mesma do ambiente local — o que muda são TLS, permissões e o cron do sistema.

> **Escolha um servidor para o painel.** Ele pode ser um dos VPS monitorados, mas o ideal é que seja outro: se o servidor monitorado cair, você quer que o painel continue de pé para te contar isso.

---

## 1. Requisitos no servidor do painel

| Item | Versão |
|---|---|
| Ubuntu | 20.04 / 22.04 / 24.04 |
| CyberPanel + OpenLiteSpeed | qualquer versão recente |
| PHP | 8.2+ com `pdo_mysql`, `curl`, `openssl`, `mbstring`, `json` |
| MariaDB / MySQL | 10.5+ / 8.0+ |
| Domínio ou subdomínio | com DNS já apontado |

---

## 2. Criar o site no CyberPanel

1. **Websites → Create Website**
2. Domínio: `monitoramento.seudominio.com.br`
3. PHP: **8.2** (ou superior)
4. Marque **SSL** e **DKIM** conforme sua política

O CyberPanel cria `/home/monitoramento.seudominio.com.br/public_html`.

---

## 3. Enviar os arquivos

```bash
# Da sua máquina
scp -r controle-vps/ root@IP_DO_PAINEL:/tmp/

# No servidor
cd /home/monitoramento.seudominio.com.br
rm -rf public_html
mv /tmp/controle-vps public_html
```

Não envie `node_modules/`, `.env` local nem `agent/config.php`.

---

## 4. Ajustar o DocumentRoot

O front controller fica em `public/`. Duas opções:

### Opção A — apontar o vhost para `public/` (recomendado)

No CyberPanel: **Websites → List Websites → Manage → vHost Conf** e ajuste:

```apache
docRoot                   $VH_ROOT/public_html/public
```

Salve e reinicie o OpenLiteSpeed.

Com esta opção o `.htaccess` da raiz não é usado, e as pastas de código ficam fora do alcance do servidor web por construção — que é o mais seguro.

### Opção B — manter o DocumentRoot padrão

Funciona sem alterar o vhost: o `.htaccess` da raiz encaminha tudo para `public/` e bloqueia acesso direto às pastas de código. Exige `mod_rewrite` habilitado no OpenLiteSpeed (**Server Configuration → Modules**).

---

## 5. Banco de dados

No CyberPanel: **Databases → Create Database**.

Anote o nome, o usuário e a senha gerados.

```bash
cd /home/monitoramento.seudominio.com.br/public_html
cp .env.example .env
nano .env
```

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://monitoramento.seudominio.com.br
APP_TIMEZONE=America/Sao_Paulo

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=nome_do_banco_criado
DB_USERNAME=usuario_criado
DB_PASSWORD=senha_gerada

SESSION_SECURE=true

LOG_LEVEL=warning
```

> **`APP_DEBUG=false` é obrigatório em produção.** Com `true`, mensagens de exceção — inclusive detalhes do banco — aparecem na tela de erro.
>
> **`SESSION_SECURE=true`** faz o cookie de sessão só trafegar por HTTPS. Ative depois que o certificado estiver funcionando, senão você não consegue logar.

---

## 6. Instalar

```bash
cd /home/monitoramento.seudominio.com.br/public_html

php bin/console.php key:generate
php bin/console.php migrate
php bin/console.php user:create --name="Seu Nome" --email=voce@empresa.com.br --password='SenhaForteAqui' --role=admin
```

**Não rode `db:seed` em produção.** Se rodar por engano:

```bash
php bin/console.php db:seed --remove
```

Conferir:

```bash
php bin/console.php db:check
```

---

## 7. Permissões

```bash
cd /home/monitoramento.seudominio.com.br

# Dono correto para o CyberPanel
chown -R monitoramento:monitoramento public_html

# Padrão: leitura e execução, sem escrita
find public_html -type d -exec chmod 755 {} \;
find public_html -type f -exec chmod 644 {} \;

# As duas únicas pastas que o PHP precisa escrever
chmod -R 775 public_html/storage/logs
chmod -R 775 public_html/storage/cache

# O .env contém a senha do banco
chmod 640 public_html/.env
```

### Verificação

```bash
# Deve dar 403 ou 404 — nunca exibir o conteúdo
curl -I https://monitoramento.seudominio.com.br/.env
curl -I https://monitoramento.seudominio.com.br/app/Core/Database.php
curl -I https://monitoramento.seudominio.com.br/storage/logs/

# Deve dar 200
curl -s https://monitoramento.seudominio.com.br/api/v1/health
```

Se o `.env` for exibido, **pare tudo**: o DocumentRoot está errado ou o `mod_rewrite` está desligado. Corrija antes de seguir e troque a senha do banco.

---

## 8. HTTPS

No CyberPanel: **SSL → Manage SSL → Issue SSL** (Let's Encrypt).

Force o redirecionamento em **Websites → Manage → Rewrite Rules → Force HTTPS**.

Sem HTTPS os agentes recusam a comunicação por padrão (`VERIFY_TLS => true`) — e devem recusar mesmo.

---

## 9. Cron do painel

```bash
crontab -e
```

```cron
# Controle VPS — processamento de alertas (a cada 5 minutos)
*/5 * * * * /usr/local/lsws/lsphp82/bin/php /home/monitoramento.seudominio.com.br/public_html/cron/process-alerts.php --quiet >> /home/monitoramento.seudominio.com.br/public_html/storage/logs/cron.log 2>&1

# Controle VPS — limpeza e retenção (diária, 03:15)
15 3 * * * /usr/local/lsws/lsphp82/bin/php /home/monitoramento.seudominio.com.br/public_html/cron/cleanup.php --quiet >> /home/monitoramento.seudominio.com.br/public_html/storage/logs/cron.log 2>&1
```

Confirme o caminho do binário PHP:

```bash
ls /usr/local/lsws/lsphp*/bin/php
```

Teste antes de confiar no agendamento:

```bash
/usr/local/lsws/lsphp82/bin/php /home/.../cron/process-alerts.php
```

---

## 10. Instalar os agentes

Para cada VPS a monitorar:

1. No painel: **Servidores → Novo servidor**
2. Copie o **Server ID** e o **token** (exibido uma única vez)
3. No VPS monitorado:

```bash
scp -r agent/ root@IP_DO_VPS:/opt/controle-vps-agent

ssh root@IP_DO_VPS
sudo bash /opt/controle-vps-agent/install.sh \
    --server-id 27 \
    --token cvps_27_xxxxxxxxxxxxxxxx \
    --url https://monitoramento.seudominio.com.br/api
```

O instalador valida os requisitos, gera o `config.php` com permissão 600, testa a conexão e registra o cron.

Detalhes: [agent/README.md](../agent/README.md).

---

## 11. Endurecimento

### Fuso horário do sistema

```bash
timedatectl set-timezone America/Sao_Paulo
timedatectl set-ntp true
```

O relógio importa: a API rejeita requisições com timestamp fora de uma janela de 5 minutos. Relógio dessincronizado no VPS monitorado = agente recusado.

### Firewall

```bash
ufw allow 22/tcp
ufw allow 80/tcp
ufw allow 443/tcp
ufw allow 8090/tcp   # painel do CyberPanel
ufw enable
```

Os VPS monitorados precisam apenas de **saída** na 443 — nenhuma porta de entrada é aberta por causa do monitoramento.

### Backup do banco

```bash
mysqldump -u usuario -p nome_do_banco | gzip > /backup/controle-vps-$(date +%F).sql.gz
```

Agende diariamente. Guarde fora do servidor.

### Rotação de log

Os logs da aplicação já rotacionam por data e são limpos pelo `cleanup.php` conforme `LOG_MAX_FILES`. Para o `cron.log`:

```bash
cat > /etc/logrotate.d/controle-vps <<'EOF'
/home/monitoramento.seudominio.com.br/public_html/storage/logs/cron.log {
    weekly
    rotate 4
    compress
    missingok
    notifempty
}
EOF
```

---

## 12. Atualizações futuras

```bash
cd /home/monitoramento.seudominio.com.br/public_html

# 1. Backup primeiro, sempre
mysqldump -u usuario -p banco | gzip > /backup/antes-da-atualizacao.sql.gz

# 2. Atualize os arquivos (sem sobrescrever .env)

# 3. Novas migrations
php bin/console.php migrate:status
php bin/console.php migrate

# 4. Limpe o cache de configurações
rm -f storage/cache/settings.php

# 5. O OPcache guarda o código antigo em memória — sem isto, os
#    arquivos novos ficam no disco sem entrar em uso
pkill lsphp   # ou: systemctl restart lsws
```

As migrations são idempotentes (`CREATE TABLE IF NOT EXISTS`, `INSERT IGNORE`) e controladas pela tabela `migrations` — rodar de novo não duplica nada.

> **Node não é necessário no servidor.** O `public/assets/css/app.css` é versionado no repositório e viaja junto com os arquivos. Quem **desenvolve** roda `npm run build:css` antes de commitar; quem faz o deploy só copia.
>
> Isso é deliberado: enquanto o CSS ficava fora do repositório, o deploy não o levava e a interface quebrava em silêncio — nenhum erro, nenhum log, testes passando, apenas um botão invisível porque a classe do fundo não existia no CSS.

---

## Checklist de produção

- [ ] `APP_ENV=production` e `APP_DEBUG=false`
- [ ] `SESSION_SECURE=true` com HTTPS ativo
- [ ] `APP_KEY` gerada
- [ ] `curl -I .../.env` retorna 403 ou 404
- [ ] `curl .../api/v1/health` retorna `{"ok":true,...}`
- [ ] Os dois crons agendados e testados à mão
- [ ] Nenhum dado de demonstração (`db:seed --remove`)
- [ ] Fuso horário e NTP configurados
- [ ] Backup do banco agendado
- [ ] Ao menos um agente instalado e reportando **Online**
