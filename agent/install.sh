#!/usr/bin/env bash
#
# ============================================================================
#  CONTROLE VPS - INSTALADOR DO AGENTE
# ============================================================================
#
#  Uso:
#      sudo bash install.sh --server-id 27 \
#                           --token cvps_27_xxxxxxxx \
#                           --url https://monitoramento.exemplo.com.br/api
#
#  Opcoes adicionais:
#      --interval 300        segundos entre coletas (padrao 300 = 5 min)
#      --path /opt/controle-vps-agent
#      --no-cron             nao registra o agendamento
#      --no-verify-tls       aceita certificado invalido no painel (homologacao)
#      --yes                 instala automaticamente as extensoes PHP que
#                             faltarem, sem perguntar (uso nao interativo)
#
#  O que o script faz:
#      1. confere os requisitos (PHP CLI e as extensoes curl, json, openssl,
#         mbstring; se faltar alguma, detecta o gerenciador de pacotes e
#         oferece o comando de instalacao, podendo instalar na hora)
#      2. copia os arquivos do agente para o destino
#      3. gera o config.php com permissao 600
#      4. roda o teste de conectividade e autenticacao
#      5. registra o cron
#
#  O script NAO altera nada do CyberPanel, do OpenLiteSpeed ou do MySQL.
#  Ele so instala o agente e agenda a coleta.
# ============================================================================

set -euo pipefail

# ---------------------------------------------------------------------------
# Padroes
# ---------------------------------------------------------------------------
SERVER_ID=""
SERVER_TOKEN=""
CENTRAL_URL=""
INTERVAL=300
INSTALL_PATH="/opt/controle-vps-agent"
SETUP_CRON=1
VERIFY_TLS="true"
AUTO_YES=0

SOURCE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# ---------------------------------------------------------------------------
# Saida
# ---------------------------------------------------------------------------
if [ -t 1 ]; then
    RED=$'\033[0;31m'; GREEN=$'\033[0;32m'; YELLOW=$'\033[0;33m'; BOLD=$'\033[1m'; RESET=$'\033[0m'
else
    RED=""; GREEN=""; YELLOW=""; BOLD=""; RESET=""
fi

ok()    { echo "  ${GREEN}[OK]${RESET}   $1"; }
warn()  { echo "  ${YELLOW}[!]${RESET}    $1"; }
fail()  { echo "  ${RED}[ERRO]${RESET} $1" >&2; }
title() { echo; echo "${BOLD}=== $1 ===${RESET}"; }

die() {
    fail "$1"
    exit 1
}

# ---------------------------------------------------------------------------
# Argumentos
# ---------------------------------------------------------------------------
while [[ $# -gt 0 ]]; do
    case "$1" in
        --server-id)     SERVER_ID="${2:-}"; shift 2 ;;
        --token)         SERVER_TOKEN="${2:-}"; shift 2 ;;
        --url)           CENTRAL_URL="${2:-}"; shift 2 ;;
        --interval)      INTERVAL="${2:-}"; shift 2 ;;
        --path)          INSTALL_PATH="${2:-}"; shift 2 ;;
        --no-cron)       SETUP_CRON=0; shift ;;
        --no-verify-tls) VERIFY_TLS="false"; shift ;;
        --yes)           AUTO_YES=1; shift ;;
        -h|--help)
            sed -n '2,30p' "$0" | sed 's/^# \{0,1\}//'
            exit 0
            ;;
        *) die "Opcao desconhecida: $1  (use --help)" ;;
    esac
done

echo
echo "${BOLD}  Controle VPS - Instalador do Agente${RESET}"
echo "  ============================================================"

# ---------------------------------------------------------------------------
# 1. Validacao
# ---------------------------------------------------------------------------
title "Validando parametros"

[[ -n "$SERVER_ID" ]]    || die "Informe --server-id (veja no painel, tela do servidor)."
[[ -n "$SERVER_TOKEN" ]] || die "Informe --token (exibido uma unica vez no cadastro)."
[[ -n "$CENTRAL_URL" ]]  || die "Informe --url (ex.: https://monitoramento.exemplo.com.br/api)."

[[ "$SERVER_ID" =~ ^[0-9]+$ ]] || die "--server-id deve ser numerico."
[[ "$INTERVAL"  =~ ^[0-9]+$ ]] || die "--interval deve ser numerico (segundos)."

if [[ ! "$SERVER_TOKEN" =~ ^cvps_[0-9]+_[a-f0-9]{64}$ ]]; then
    die "Formato de token invalido. Esperado: cvps_<id>_<64 caracteres hexadecimais>."
fi

TOKEN_SERVER_ID="$(echo "$SERVER_TOKEN" | cut -d'_' -f2)"
if [[ "$TOKEN_SERVER_ID" != "$SERVER_ID" ]]; then
    die "O token pertence ao servidor #${TOKEN_SERVER_ID}, mas --server-id e ${SERVER_ID}."
fi

if [[ ! "$CENTRAL_URL" =~ ^https:// ]]; then
    warn "CENTRAL_URL nao usa HTTPS. Em producao configure TLS no painel."
fi

ok "Parametros consistentes (servidor #${SERVER_ID})."

# ---------------------------------------------------------------------------
# 2. Requisitos
# ---------------------------------------------------------------------------
title "Verificando requisitos"

if [[ "$EUID" -ne 0 ]]; then
    die "Rode como root: o agente precisa ler /etc/cyberpanel/mysqlPassword e /proc."
fi

command -v php >/dev/null 2>&1 || die "PHP CLI nao encontrado. Instale o pacote php-cli."

PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
PHP_OK="$(php -r 'echo PHP_VERSION_ID >= 80100 ? 1 : 0;')"

if [[ "$PHP_OK" != "1" ]]; then
    die "PHP ${PHP_VERSION} e antigo demais. O agente exige PHP 8.1 ou superior."
fi

ok "PHP ${PHP_VERSION} ($(command -v php))"

# Gerenciador de pacotes - usado so para sugerir/instalar as extensoes que
# faltarem. Se nao reconhecer nenhum, o script apenas orienta manualmente.
PKG_MANAGER=""
if command -v apt-get >/dev/null 2>&1; then
    PKG_MANAGER="apt"
elif command -v dnf >/dev/null 2>&1; then
    PKG_MANAGER="dnf"
elif command -v yum >/dev/null 2>&1; then
    PKG_MANAGER="yum"
fi

# Nome do pacote da extensao em cada gerenciador. Ex.: a extensao pdo_mysql
# vem no pacote "php8.1-mysql" no Debian/Ubuntu, mas "php-mysqlnd" no
# RHEL/AlmaLinux - por isso o caso especial.
pkg_name_for_ext() {
    local ext="$1"
    case "${PKG_MANAGER}:${ext}" in
        apt:pdo_mysql)               echo "php${PHP_VERSION}-mysql" ;;
        apt:*)                       echo "php${PHP_VERSION}-${ext}" ;;
        dnf:pdo_mysql|yum:pdo_mysql) echo "php-mysqlnd" ;;
        dnf:*|yum:*)                 echo "php-${ext}" ;;
        *)                           echo "${ext}" ;;
    esac
}

REQUIRED_EXT="curl json openssl mbstring"
OPTIONAL_EXT="pdo_mysql"

MISSING=""
MISSING_PKGS=""
for ext in $REQUIRED_EXT; do
    if php -m | grep -qi "^${ext}$"; then
        ok "extensao ${ext}"
    else
        MISSING="${MISSING} ${ext}"
        MISSING_PKGS="${MISSING_PKGS} $(pkg_name_for_ext "$ext")"
    fi
done

OPTIONAL_PKGS=""
for ext in $OPTIONAL_EXT; do
    if php -m | grep -qi "^${ext}$"; then
        ok "extensao ${ext}"
    else
        warn "extensao ${ext} ausente - a descoberta usara os vhosts do OpenLiteSpeed"
        OPTIONAL_PKGS="${OPTIONAL_PKGS} $(pkg_name_for_ext "$ext")"
    fi
done

if [[ -n "$MISSING" ]]; then
    fail "Extensoes obrigatorias ausentes:${MISSING}"

    if [[ -z "$PKG_MANAGER" ]]; then
        die "Gerenciador de pacotes nao reconhecido. Instale manualmente as extensoes PHP:${MISSING}"
    fi

    case "$PKG_MANAGER" in
        apt) INSTALL_CMD="apt-get update && apt-get install -y${MISSING_PKGS}" ;;
        dnf) INSTALL_CMD="dnf install -y${MISSING_PKGS}" ;;
        yum) INSTALL_CMD="yum install -y${MISSING_PKGS}" ;;
    esac

    echo
    echo "  Comando para instalar o que falta:"
    echo "    ${INSTALL_CMD}"
    echo

    DO_INSTALL=0
    if [[ "$AUTO_YES" -eq 1 ]]; then
        DO_INSTALL=1
    elif [[ -t 0 && -t 1 ]]; then
        read -r -p "  Instalar agora e continuar? [s/N] " REPLY
        if [[ "$REPLY" =~ ^[sSyY] ]]; then
            DO_INSTALL=1
        fi
    fi

    if [[ "$DO_INSTALL" -eq 0 ]]; then
        die "Rode o comando acima (ou 'install.sh ... --yes') e execute o instalador de novo."
    fi

    eval "$INSTALL_CMD" || die "Falha ao instalar as extensoes. Rode o comando acima manualmente e tente de novo."

    STILL_MISSING=""
    for ext in $REQUIRED_EXT; do
        php -m | grep -qi "^${ext}$" || STILL_MISSING="${STILL_MISSING} ${ext}"
    done
    [[ -z "$STILL_MISSING" ]] || die "Ainda faltam extensoes apos a instalacao:${STILL_MISSING} (pode ser preciso reiniciar o PHP-FPM/servico correspondente)."

    ok "Extensoes instaladas: ${MISSING}"
fi

if [[ -n "$OPTIONAL_PKGS" && -n "$PKG_MANAGER" ]]; then
    case "$PKG_MANAGER" in
        apt) echo "       (opcional) descoberta via banco: apt-get install -y${OPTIONAL_PKGS}" ;;
        *)   echo "       (opcional) descoberta via banco: ${PKG_MANAGER} install -y${OPTIONAL_PKGS}" ;;
    esac
fi

if [[ -d /www/server/panel ]]; then
    ok "aaPanel detectado"
elif [[ -d /usr/local/CyberCP ]]; then
    ok "CyberPanel detectado"
else
    warn "Nenhum painel (aaPanel/CyberPanel) detectado - a descoberta de sites usara os vhosts do OpenLiteSpeed e os diretorios em /home"
fi

# ---------------------------------------------------------------------------
# 3. Instalacao dos arquivos
# ---------------------------------------------------------------------------
title "Instalando em ${INSTALL_PATH}"

[[ -f "${SOURCE_DIR}/agent.php" ]] || die "agent.php nao encontrado em ${SOURCE_DIR}."

mkdir -p "${INSTALL_PATH}/lib" "${INSTALL_PATH}/logs"

if [[ "$(cd "$SOURCE_DIR" && pwd)" != "$(cd "$INSTALL_PATH" 2>/dev/null && pwd)" ]]; then
    cp -f "${SOURCE_DIR}/agent.php"            "${INSTALL_PATH}/agent.php"
    cp -f "${SOURCE_DIR}/config.example.php"   "${INSTALL_PATH}/config.example.php"
    cp -f "${SOURCE_DIR}/lib/"*.php            "${INSTALL_PATH}/lib/"
    [[ -f "${SOURCE_DIR}/README.md" ]] && cp -f "${SOURCE_DIR}/README.md" "${INSTALL_PATH}/README.md"
    ok "Arquivos copiados."
else
    ok "Executando a partir do destino final - nada a copiar."
fi

# ---------------------------------------------------------------------------
# 4. Configuracao
# ---------------------------------------------------------------------------
title "Gerando config.php"

CONFIG_FILE="${INSTALL_PATH}/config.php"

if [[ -f "$CONFIG_FILE" ]]; then
    BACKUP="${CONFIG_FILE}.bak.$(date +%Y%m%d%H%M%S)"
    cp "$CONFIG_FILE" "$BACKUP"
    chmod 600 "$BACKUP"
    warn "Config anterior preservado em $(basename "$BACKUP")"
fi

cat > "$CONFIG_FILE" <<PHPEOF
<?php

/**
 * Configuracao do agente Controle VPS.
 * Gerado por install.sh em $(date '+%Y-%m-%d %H:%M:%S').
 *
 * CONTEM O TOKEN DO SERVIDOR - mantenha em 600 e nao versione.
 */

return [
    'SERVER_ID'    => ${SERVER_ID},
    'SERVER_TOKEN' => '${SERVER_TOKEN}',
    'CENTRAL_URL'  => '${CENTRAL_URL}',

    'INTERVAL' => ${INTERVAL},
    'TIMEZONE' => '$(cat /etc/timezone 2>/dev/null || echo "UTC")',

    'HTTP_TIMEOUT'         => 20,
    'HTTP_CONNECT_TIMEOUT' => 8,
    'HTTP_RETRIES'         => 2,
    'VERIFY_TLS'           => ${VERIFY_TLS},
    'ALLOW_INSECURE'       => false,
    'SITES_BATCH_SIZE'     => 100,

    'CHECK_CONCURRENCY'     => 10,
    'CHECK_TIMEOUT'         => 10,
    'CHECK_CONNECT_TIMEOUT' => 5,

    'SSL_FALLBACK'       => true,
    'SSL_TIMEOUT'        => 6,
    'SSL_FALLBACK_LIMIT' => 100,

    'LOG_PATH'      => __DIR__ . '/logs',
    'LOG_KEEP_DAYS' => 14,
];
PHPEOF

# O token esta neste arquivo: ninguem alem do root pode ler.
chown root:root "$CONFIG_FILE"
chmod 600 "$CONFIG_FILE"

chown -R root:root "$INSTALL_PATH"
chmod 750 "$INSTALL_PATH"
chmod 640 "${INSTALL_PATH}/lib/"*.php
chmod 750 "${INSTALL_PATH}/logs"
chmod 640 "${INSTALL_PATH}/agent.php"

ok "config.php criado com permissao 600 (somente root)."

# ---------------------------------------------------------------------------
# 5. Teste de conexao
# ---------------------------------------------------------------------------
title "Testando conexao com o painel"

if php "${INSTALL_PATH}/agent.php" --test --verbose; then
    ok "Comunicacao com o painel funcionando."
    TEST_OK=1
else
    fail "O teste de conexao falhou. O agente foi instalado, mas nao esta reportando."
    warn "Corrija o problema apontado acima e rode: php ${INSTALL_PATH}/agent.php --test --verbose"
    TEST_OK=0
fi

# ---------------------------------------------------------------------------
# 6. Cron
# ---------------------------------------------------------------------------
if [[ "$SETUP_CRON" -eq 1 ]]; then
    title "Configurando o agendamento"

    CRON_MINUTES=$(( INTERVAL / 60 ))
    [[ "$CRON_MINUTES" -lt 1 ]] && CRON_MINUTES=1
    [[ "$CRON_MINUTES" -gt 59 ]] && CRON_MINUTES=59

    PHP_BIN="$(command -v php)"
    CRON_LINE="*/${CRON_MINUTES} * * * * ${PHP_BIN} ${INSTALL_PATH}/agent.php >> ${INSTALL_PATH}/logs/cron.log 2>&1"

    # Remove a linha anterior deste mesmo agente antes de inserir a nova,
    # para nao acumular duplicatas em reinstalacoes.
    ( crontab -l 2>/dev/null | grep -v -F "${INSTALL_PATH}/agent.php" || true; echo "$CRON_LINE" ) | crontab -

    ok "Cron registrado: a cada ${CRON_MINUTES} minuto(s)."
    echo "       ${CRON_LINE}"
else
    warn "Cron nao configurado (--no-cron). Adicione manualmente:"
    echo "       */$(( INTERVAL / 60 )) * * * * $(command -v php) ${INSTALL_PATH}/agent.php >> ${INSTALL_PATH}/logs/cron.log 2>&1"
fi

# ---------------------------------------------------------------------------
# Resumo
# ---------------------------------------------------------------------------
echo
echo "  ============================================================"
if [[ "${TEST_OK:-0}" -eq 1 ]]; then
    echo "  ${GREEN}${BOLD} INSTALACAO CONCLUIDA${RESET}"
else
    echo "  ${YELLOW}${BOLD} INSTALADO, MAS SEM COMUNICACAO${RESET}"
fi
echo "  ============================================================"
echo
echo "   Servidor .....: #${SERVER_ID}"
echo "   Diretorio ....: ${INSTALL_PATH}"
echo "   Painel .......: ${CENTRAL_URL}"
echo "   Intervalo ....: ${INTERVAL}s"
echo
echo "   Comandos uteis:"
echo "     php ${INSTALL_PATH}/agent.php --verbose    executar agora, com detalhes"
echo "     php ${INSTALL_PATH}/agent.php --test       so testar a conexao"
echo "     php ${INSTALL_PATH}/agent.php --dry-run    coletar sem enviar"
echo "     tail -f ${INSTALL_PATH}/logs/agent-\$(date +%F).log"
echo
echo "   O agente somente COLETA e ENVIA. Ele nao executa nenhum comando"
echo "   recebido do painel."
echo

exit 0
