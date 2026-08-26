# Instalação local (Laragon / XAMPP / WAMP)

Guia completo para colocar o painel no ar na sua máquina antes de migrar para a VPS.

---

## 1. Requisitos

| Item | Mínimo | Como verificar |
|---|---|---|
| PHP | 8.2 | `php -v` |
| MySQL / MariaDB | 8.0 / 10.5 | `mysql --version` |
| Apache | 2.4 com `mod_rewrite` | painel do Laragon |
| Node.js | 18+ *(opcional)* | `node -v` |

### Extensões obrigatórias do PHP

```bash
php -m
```

Precisam aparecer: `pdo_mysql`, `curl`, `openssl`, `mbstring`, `json`.

No Laragon, se alguma faltar: **Menu → PHP → Extensions** e marque a que faltou.

> **Atenção no Laragon:** algumas instalações trazem mais de uma versão do PHP em `C:\laragon\bin\php\`. A versão usada pelo Apache é a definida em **Menu → PHP → Version**. Confirme que ela é 8.2+ e que tem as extensões acima — uma pasta de PHP recém-descompactada pode estar sem `php.ini` e, portanto, sem extensão nenhuma.

---

## 2. Colocar os arquivos no lugar

O projeto deve ficar em `C:\laragon\www\controle-vps` (ou o equivalente no seu ambiente).

---

## 3. Criar o banco

Você **não precisa** criar o banco à mão — o instalador faz isso. Se preferir criar manualmente:

```sql
CREATE DATABASE `controle-vps`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
```

O nome padrão contém hífen (`controle-vps`), o que é válido em MySQL desde que sempre venha entre crases — e o projeto sempre usa.

---

## 4. Configurar o `.env`

```bash
cd C:\laragon\www\controle-vps
copy .env.example .env
```

Ajuste o essencial:

```ini
APP_ENV=local
APP_DEBUG=true
APP_URL=http://controle-vps.test

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=controle-vps
DB_USERNAME=root
DB_PASSWORD=
```

### Sobre o `APP_URL`

Este valor define o **prefixo de todos os links internos**. Escolha conforme a forma de acesso:

| Forma de acesso | `APP_URL` |
|---|---|
| Vhost do Laragon | `http://controle-vps.test` |
| Sem vhost | `http://localhost/controle-vps/public` |
| Produção | `https://monitoramento.seudominio.com.br` |

Errar aqui é a causa nº 1 de "a página abre sem estilo" ou "os links dão 404".

---

## 5. Instalar

Um comando faz tudo:

```bash
php bin/console.php install --name="Seu Nome" --email=voce@empresa.com.br --password=SuaSenhaForte
```

Ele executa, em ordem:

1. gera a `APP_KEY` e grava no `.env`;
2. cria o banco, se não existir;
3. aplica as 16 migrations;
4. cria o usuário administrador;
5. insere os dados fictícios de demonstração.

### Ou passo a passo

```bash
php bin/console.php key:generate
php bin/console.php db:create
php bin/console.php migrate
php bin/console.php user:create      # interativo
php bin/console.php db:seed          # opcional
```

Para instalar **sem** os dados de demonstração:

```bash
php bin/console.php install --no-seed --name="..." --email=... --password=...
```

### Conferindo

```bash
php bin/console.php db:check
```

Deve listar 17 tabelas (16 do sistema + `migrations`) com as respectivas contagens.

---

## 6. Vhost (recomendado)

O Laragon cria o vhost automaticamente. Com o projeto em `C:\laragon\www\controle-vps`:

1. **Menu → Apache → Reload** (ou **Recarregar**)
2. Acesse `http://controle-vps.test`

### Por que existe um `.htaccess` na raiz

O vhost automático do Laragon aponta o `DocumentRoot` para a **raiz do projeto**, não para `public/`. O `.htaccess` da raiz encaminha tudo para `public/` e bloqueia acesso direto às pastas de código:

```apache
RewriteCond %{ENV:REDIRECT_STATUS} ^$
RewriteCond %{REQUEST_URI} !^/public/
RewriteRule ^(.*)$ public/$1 [L]
```

As duas condições juntas tornam a regra à prova de loop: `REDIRECT_STATUS` fica preenchido depois do primeiro rewrite interno, então a regra só dispara na requisição original.

O mesmo arquivo funciona para `http://localhost/controle-vps/` — basta ajustar o `APP_URL`.

---

## 7. Entrar

Acesse `http://controle-vps.test/login` e use o e-mail e a senha informados na instalação.

Se esqueceu a senha:

```bash
php bin/console.php user:password --email=voce@empresa.com.br --password=NovaSenhaForte
```

---

## 8. Compilar o CSS

**Só é necessário se você alterar as views ou adicionar classes Tailwind novas.** O `public/assets/css/app.css` já vem compilado.

```bash
npm install
npm run build:css      # compila e minifica
npm run watch:css      # recompila a cada alteração, durante o desenvolvimento
```

O `<link>` no layout usa cache-busting por `filemtime`, então o navegador pega a versão nova sozinho.

> Classe nova sem rebuild simplesmente não existe no CSS compilado — o estilo não aparece e não há mensagem de erro. É o preço de não usar o Tailwind via CDN, em troca de 21 KB estáticos no lugar de ~4 MB de runtime JavaScript.

---

## 9. Cron do painel

O painel precisa de dois agendamentos.

### Windows / Laragon

O Laragon inclui o **Cronical**. Alternativa: Agendador de Tarefas do Windows.

**Processamento de alertas — a cada 5 minutos:**

```
Programa:   C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe
Argumentos: C:\laragon\www\controle-vps\cron\process-alerts.php --quiet
```

**Limpeza — uma vez por dia:**

```
Programa:   C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe
Argumentos: C:\laragon\www\controle-vps\cron\cleanup.php --quiet
```

> Ajuste o caminho do `php.exe` para a versão que você tem instalada.

### Linux / macOS

```cron
*/5 * * * * php /caminho/do/painel/cron/process-alerts.php --quiet >> /caminho/do/painel/storage/logs/cron.log 2>&1
15 3  * * * php /caminho/do/painel/cron/cleanup.php --quiet >> /caminho/do/painel/storage/logs/cron.log 2>&1
```

### Testando à mão

```bash
php cron/process-alerts.php          # com saída detalhada
php cron/cleanup.php --dry-run       # mostra o que seria removido, sem remover
```

Sem o cron, os servidores **nunca** são marcados como offline e os certificados param de ter os dias recalculados.

---

## 10. Cadastrar o primeiro servidor real

1. **Servidores → Novo servidor**
2. Preencha nome, provedor, hostname e IP
3. Ao salvar, o painel exibe o **Server ID** e o **token** — o token aparece **uma única vez**
4. Copie o comando de instalação exibido na tela
5. Siga [agent/README.md](../agent/README.md) para instalar o agente no VPS

Enquanto o agente não rodar, o servidor fica com status **Desconhecido** — o que está correto: o painel não inventa dado que não recebeu.

---

## 11. Rodar os testes

```bash
php tests/run.php
```

A suíte cria e migra o banco `controle-vps_test`, separado do de desenvolvimento. São 116 testes; a execução leva cerca de 30 segundos.

---

## 12. Limpar a demonstração antes de usar de verdade

```bash
php bin/console.php db:seed --remove
```

Remove os 8 servidores fictícios e, por cascata, todos os sites, métricas, verificações, certificados e alertas associados. Os usuários e as configurações permanecem.

---

## Checklist final

- [ ] `php bin/console.php db:check` lista as tabelas sem erro
- [ ] O login funciona
- [ ] O dashboard mostra os cards preenchidos
- [ ] Os gráficos de métricas renderizam
- [ ] Os dois crons estão agendados
- [ ] `php tests/run.php` passa integralmente
- [ ] Um servidor real foi cadastrado e o agente instalado
- [ ] O status do servidor virou **Online** após a primeira coleta

Problemas? [docs/TROUBLESHOOTING.md](TROUBLESHOOTING.md).
