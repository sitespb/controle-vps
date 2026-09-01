#!/usr/bin/env bash
#
# ============================================================================
#  CONTROLE VPS - INSTALADOR DO AGENTE
# ============================================================================
#
#  Uso (o comando pronto esta no painel, na tela do servidor):
#      curl -fsSL https://raw.githubusercontent.com/sitespb/controle-vps/v1.2.1/agent/install.sh \
#        | sudo bash -s -- --token cvps_27_xxxxxxxx \
#                          --url https://monitoramento.exemplo.com.br/api
#
#  O --server-id nao e necessario: ele ja esta dentro do token.
#
#  Opcoes adicionais:
#      --server-id 27        confere se o token pertence mesmo a este servidor
#      --interval 300        segundos entre coletas (padrao 300 = 5 min)
#      --path /opt/controle-vps-agent
#      --no-cron             nao registra o agendamento
#      --no-verify-tls       aceita certificado invalido no painel (homologacao)
#      --yes                 instala automaticamente as extensoes PHP que
#                             faltarem, sem perguntar (uso nao interativo)
#      --php /caminho/php    usa este binario em vez de procurar sozinho
#      --ref v1.2.1          versao do agente a baixar (padrao: a deste script)
#      --force               substitui um agente ja instalado que pertence a
#                             OUTRO servidor (sem isto, o script recusa)
#
#  O que o script faz:
#      1. escolhe o PHP 8.1+ do servidor - procurando primeiro os binarios
#         gerenciados pelo painel (CyberPanel/lsphp, aaPanel, cPanel, Plesk)
#         e so depois o PATH, porque o `php` do PATH costuma ser o do sistema
#         e antigo demais
#      2. confere as extensoes curl, json, openssl e mbstring; se faltar
#         alguma, sugere o pacote certo para aquele PHP e pode instalar
#      3. copia os arquivos do agente - baixando o pacote do repositorio
#         publico quando o script foi executado sozinho
#      4. gera o config.php com permissao 600
#      5. roda o teste de conectividade e autenticacao
#      6. registra o cron com o caminho completo do PHP escolhido
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
PHP_BIN=""
FORCE=0

# ---------------------------------------------------------------------------
# Origem do codigo do agente (modo bootstrap)
# ---------------------------------------------------------------------------
# Quando o install.sh e baixado sozinho - `curl ... | bash` - nao existe pasta
# agent/ ao lado dele. Nesse caso o proprio script busca o restante no
# repositorio publico, na MESMA referencia de onde ele veio.
#
# AGENT_REF e fixado numa TAG, nunca em `main`, de proposito: o painel gera o
# comando de instalacao apontando para a versao que ele conhece. Assim um
# painel antigo nunca instala um agente novo demais para ele.
AGENT_REPO="sitespb/controle-vps"
AGENT_REF="v1.2.2"

# Executado por `curl ... | bash`, BASH_SOURCE fica VAZIO - e com `set -u`
# isso seria "unbound variable" na primeira linha util do script. O fallback
# para $0 resolve os dois modos: arquivo em disco e leitura pelo stdin.
SOURCE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")" 2>/dev/null && pwd || echo /tmp)"
BOOTSTRAP_TMP=""

# Remove o download temporario aconteca o que acontecer.
cleanup_bootstrap() {
    [[ -n "$BOOTSTRAP_TMP" && -d "$BOOTSTRAP_TMP" ]] && rm -rf "$BOOTSTRAP_TMP"
    return 0
}
trap cleanup_bootstrap EXIT

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
        --php)           PHP_BIN="${2:-}"; shift 2 ;;
        --ref)           AGENT_REF="${2:-}"; shift 2 ;;
        --force)         FORCE=1; shift ;;
        -h|--help)
            # Lido pelo stdin ($0 = "bash"), nao ha arquivo para reler.
            if [[ -f "$0" ]]; then
                sed -n '2,41p' "$0" | sed 's/^# \{0,1\}//'
            else
                echo "Ajuda completa em: https://github.com/${AGENT_REPO}/blob/${AGENT_REF}/agent/install.sh"
            fi
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

[[ -n "$SERVER_TOKEN" ]] || die "Informe --token (exibido uma unica vez no cadastro)."
[[ -n "$CENTRAL_URL" ]]  || die "Informe --url (ex.: https://monitoramento.exemplo.com.br/api)."

[[ "$INTERVAL" =~ ^[0-9]+$ ]] || die "--interval deve ser numerico (segundos)."

if [[ ! "$SERVER_TOKEN" =~ ^cvps_[0-9]+_[a-f0-9]{64}$ ]]; then
    die "Formato de token invalido. Esperado: cvps_<id>_<64 caracteres hexadecimais>."
fi

# O ID do servidor JA VEM dentro do token (cvps_<id>_<hash>). Pedi-lo de novo
# so criava uma chance a mais de erro de digitacao. Quando vier, conferimos.
TOKEN_SERVER_ID="$(echo "$SERVER_TOKEN" | cut -d'_' -f2)"

if [[ -z "$SERVER_ID" ]]; then
    SERVER_ID="$TOKEN_SERVER_ID"
else
    [[ "$SERVER_ID" =~ ^[0-9]+$ ]] || die "--server-id deve ser numerico."

    if [[ "$TOKEN_SERVER_ID" != "$SERVER_ID" ]]; then
        die "O token pertence ao servidor #${TOKEN_SERVER_ID}, mas --server-id e ${SERVER_ID}."
    fi
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

# ---------------------------------------------------------------------------
# Escolha do binario do PHP
# ---------------------------------------------------------------------------
# Em CyberPanel e aaPanel o `php` do PATH e o do SISTEMA - com frequencia 7.x -
# enquanto o PHP 8 fica num caminho proprio do painel (lsphp83, /www/server/...).
# Procurar so no PATH fazia o instalador recusar servidores perfeitamente
# compativeis, que e o caso mais comum do produto. Por isso a cascata abaixo:
# primeiro os PHP gerenciados pelo painel, do mais novo para o mais antigo,
# depois os do PATH.
#
# Um binario so e aceito se for 8.1+. Nao aceitamos "o que existir": rodar o
# agente num PHP velho falharia depois, no cron, em silencio.

PHP_SEARCHED=()
PHP_REJECTED=()

php_version_of() {
    "$1" -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;' 2>/dev/null
}

php_is_supported() {
    [[ -x "$1" ]] || return 1
    "$1" -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);' >/dev/null 2>&1
}

# Anota o candidato e devolve 0 quando ele serve.
php_try() {
    local bin="$1"

    [[ -n "$bin" ]] || return 1

    PHP_SEARCHED+=("$bin")

    if [[ ! -x "$bin" ]]; then
        return 1
    fi

    if php_is_supported "$bin"; then
        return 0
    fi

    PHP_REJECTED+=("$bin ($(php_version_of "$bin"))")

    return 1
}

detect_php() {
    local candidate serie

    # 1. CyberPanel / OpenLiteSpeed
    for serie in 84 83 82 81; do
        php_try "/usr/local/lsws/lsphp${serie}/bin/php" && return 0
    done

    # 2. aaPanel
    for serie in 84 83 82 81; do
        php_try "/www/server/php/${serie}/bin/php" && return 0
    done

    # 3. cPanel / EasyApache
    for serie in 84 83 82 81; do
        php_try "/opt/cpanel/ea-php${serie}/root/usr/bin/php" && return 0
    done

    # 4. Plesk
    for serie in 8.4 8.3 8.2 8.1; do
        php_try "/opt/plesk/php/${serie}/bin/php" && return 0
    done

    # 5. PATH, das versoes explicitas para a generica
    for candidate in php8.4 php8.3 php8.2 php8.1 php; do
        local resolved
        resolved="$(command -v "$candidate" 2>/dev/null || true)"

        [[ -n "$resolved" ]] && php_try "$resolved" && return 0
    done

    return 1
}

if [[ -n "$PHP_BIN" ]]; then
    # Escolha explicita do operador: nao adivinhamos nada, mas ainda validamos.
    [[ -x "$PHP_BIN" ]] || die "O PHP informado em --php nao existe ou nao e executavel: ${PHP_BIN}"

    php_is_supported "$PHP_BIN" \
        || die "O PHP informado em --php e $(php_version_of "$PHP_BIN"); o agente exige 8.1 ou superior."
else
    detect_php || {
        fail "Nenhum PHP 8.1+ encontrado. Procurei em:"
        for candidate in "${PHP_SEARCHED[@]}"; do
            echo "         ${candidate}"
        done

        if [[ ${#PHP_REJECTED[@]} -gt 0 ]]; then
            echo
            fail "Encontrei estes, mas sao antigos demais:"
            for candidate in "${PHP_REJECTED[@]}"; do
                echo "         ${candidate}"
            done
        fi

        echo
        echo "  Saidas possiveis:"
        echo "    - instale o PHP 8.1+ pelo seu painel (CyberPanel: Server > PHP);"
        echo "    - ou aponte um binario existente: install.sh ... --php /caminho/do/php"
        echo
        exit 1
    }

    # O ultimo candidato anotado e o que passou.
    PHP_BIN="${PHP_SEARCHED[-1]}"
fi

PHP_VERSION="$(php_version_of "$PHP_BIN")"

# A "familia" do PHP muda o nome dos pacotes das extensoes: no lsphp do
# CyberPanel a extensao pdo_mysql vem em lsphp83-mysqlnd, e nao em
# php8.3-mysql. Sem isto, sugeririamos instalar pacote que nao existe.
case "$PHP_BIN" in
    /usr/local/lsws/lsphp*)  PHP_FLAVOR="lsphp" ;;
    /www/server/php/*)       PHP_FLAVOR="aapanel" ;;
    /opt/cpanel/ea-php*)     PHP_FLAVOR="cpanel" ;;
    /opt/plesk/php/*)        PHP_FLAVOR="plesk" ;;
    *)                       PHP_FLAVOR="system" ;;
esac

ok "PHP ${PHP_VERSION} (${PHP_BIN})"

if [[ "$PHP_FLAVOR" != "system" ]]; then
    echo "         binario do painel (${PHP_FLAVOR}) - o cron sera registrado com este caminho completo"
fi

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
#
# Quando o PHP escolhido pertence a um painel, o pacote NAO e o do PHP do
# sistema: no lsphp do CyberPanel a extensao vem em "lsphp83-mysqlnd", e
# instalar "php8.3-mysql" nao teria efeito nenhum sobre o binario em uso.
pkg_name_for_ext() {
    local ext="$1"

    if [[ "$PHP_FLAVOR" == "lsphp" ]]; then
        # lsphp83 -> a serie sem ponto e a mesma do caminho do binario.
        local serie="${PHP_VERSION/./}"

        case "$ext" in
            pdo_mysql) echo "lsphp${serie}-mysqlnd" ;;
            *)         echo "lsphp${serie}-${ext}" ;;
        esac

        return
    fi

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
    if "$PHP_BIN" -m | grep -qi "^${ext}$"; then
        ok "extensao ${ext}"
    else
        MISSING="${MISSING} ${ext}"
        MISSING_PKGS="${MISSING_PKGS} $(pkg_name_for_ext "$ext")"
    fi
done

OPTIONAL_PKGS=""
for ext in $OPTIONAL_EXT; do
    if "$PHP_BIN" -m | grep -qi "^${ext}$"; then
        ok "extensao ${ext}"
    else
        warn "extensao ${ext} ausente - a descoberta usara os vhosts do OpenLiteSpeed"
        OPTIONAL_PKGS="${OPTIONAL_PKGS} $(pkg_name_for_ext "$ext")"
    fi
done

if [[ -n "$MISSING" ]]; then
    fail "Extensoes obrigatorias ausentes:${MISSING}"

    # Em cPanel, Plesk e aaPanel as extensoes se instalam PELA INTERFACE do
    # painel; sair instalando pacote do sistema por baixo dele pode quebrar a
    # instalacao. Nesses casos orientamos, mas nao mexemos.
    case "$PHP_FLAVOR" in
        aapanel)
            die "Instale as extensoes${MISSING} em aaPanel > App Store > PHP ${PHP_VERSION} > Setting > Install extensions."
            ;;
        cpanel)
            die "Instale as extensoes${MISSING} em WHM > EasyApache 4, na versao ea-php${PHP_VERSION/./}."
            ;;
        plesk)
            die "Instale as extensoes${MISSING} pelo Plesk, em Tools & Settings > PHP Settings (PHP ${PHP_VERSION})."
            ;;
    esac

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
        "$PHP_BIN" -m | grep -qi "^${ext}$" || STILL_MISSING="${STILL_MISSING} ${ext}"
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

# ---------------------------------------------------------------------------
# Protecao: ja existe um agente de OUTRO servidor neste diretorio
# ---------------------------------------------------------------------------
# Sem esta checagem, reinstalar por engano no caminho padrao troca a
# identidade do agente: o servidor antigo para de reportar e os dados deste
# VPS passam a chegar no painel como se fossem de outro. O config anterior
# fica salvo em .bak, mas ninguem percebe o problema ate dar falta dos dados.
# Trocar a identidade tem que ser um ato deliberado.
EXISTING_CONFIG="${INSTALL_PATH}/config.php"

if [[ -f "$EXISTING_CONFIG" ]]; then
    EXISTING_ID="$(grep -oE "'SERVER_ID'[[:space:]]*=>[[:space:]]*[0-9]+" "$EXISTING_CONFIG" 2>/dev/null | grep -oE '[0-9]+$' || true)"

    if [[ -n "$EXISTING_ID" && "$EXISTING_ID" != "$SERVER_ID" ]]; then
        fail "Ja existe um agente em ${INSTALL_PATH}, e ele pertence ao servidor #${EXISTING_ID}."
        echo
        echo "  Continuar faria o servidor #${EXISTING_ID} parar de reportar, e os dados"
        echo "  deste VPS passariam a chegar no painel como se fossem do #${SERVER_ID}."
        echo
        echo "  Se e mesmo isso que voce quer:  ... --force"
        echo "  Para instalar os dois lado a lado:  ... --path /opt/outro-agente"
        echo

        [[ "$FORCE" -eq 1 ]] || exit 1

        warn "--force informado: assumindo a identidade do servidor #${SERVER_ID} no lugar do #${EXISTING_ID}."
    fi
fi

# ---------------------------------------------------------------------------
# Bootstrap: buscar o codigo do agente quando ele nao veio junto
# ---------------------------------------------------------------------------
# Acontece sempre que o instalador e baixado sozinho. Em vez de exigir que a
# pessoa tenha o projeto na maquina local e faca scp, buscamos o agente no
# repositorio publico, na referencia fixada em AGENT_REF.
#
# A confianca aqui vem do HTTPS somado a tag: o conteudo de uma tag nao muda
# sozinho. Nao ha checksum publicado porque o tarball gerado pelo GitHub nao
# tem hash estavel entre gerações - fixar a tag e a garantia real.
bootstrap_agent() {
    command -v tar >/dev/null 2>&1 || die "O comando 'tar' e necessario para baixar o agente."

    local fetch=""

    if command -v curl >/dev/null 2>&1; then
        fetch="curl -fsSL --retry 2 -o"
    elif command -v wget >/dev/null 2>&1; then
        fetch="wget -q -O"
    else
        die "Nem curl nem wget encontrados. Instale um dos dois ou envie a pasta agent/ manualmente."
    fi

    BOOTSTRAP_TMP="$(mktemp -d)"

    echo "         baixando o agente ${AGENT_REF} de github.com/${AGENT_REPO}"

    # AGENT_REF normalmente e uma tag, mas aceitamos branch tambem: quem esta
    # testando uma correcao quer poder pedir --ref main sem editar o script.
    # Uma referencia inexistente devolve 404, e o erro precisa dizer isso - e
    # nao "tar: arquivo corrompido" tres linhas depois.
    local base="https://codeload.github.com/${AGENT_REPO}/tar.gz/refs"
    local baixou=0

    for tipo in tags heads; do
        if $fetch "${BOOTSTRAP_TMP}/agente.tar.gz" "${base}/${tipo}/${AGENT_REF}" 2>/dev/null; then
            baixou=1
            break
        fi
    done

    [[ "$baixou" -eq 1 ]] \
        || die "Nao encontrei a referencia '${AGENT_REF}' em ${AGENT_REPO} (nem tag, nem branch). Confira --ref e a saida de internet do servidor."

    tar -xzf "${BOOTSTRAP_TMP}/agente.tar.gz" -C "$BOOTSTRAP_TMP" \
        || die "O arquivo baixado nao pode ser extraido."

    # O GitHub empacota tudo dentro de <repo>-<ref>/, cujo nome varia conforme
    # a tag. Procurar a pasta agent/ e mais robusto que montar o caminho.
    local found
    found="$(find "$BOOTSTRAP_TMP" -maxdepth 3 -type d -name agent -print -quit)"

    [[ -n "$found" && -f "${found}/agent.php" ]] \
        || die "O pacote ${AGENT_REF} nao contem a pasta agent/ esperada."

    SOURCE_DIR="$found"

    ok "Agente ${AGENT_REF} baixado."
}

if [[ ! -f "${SOURCE_DIR}/agent.php" ]]; then
    bootstrap_agent
fi

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
    // 15s, e nao 10s.
    //
    // Um site legitimo, no ar, respondendo em 12 segundos era reportado como
    // OFFLINE com o limite antigo - e o tempo gravado no historico era o
    // proprio timeout, nao o do site. Aconteceu em producao com um WordPress
    // sem cache cujo primeiro byte levava ~10s.
    //
    // Um falso "offline" custa mais caro que um ciclo de coleta mais longo.
    // Ajuste para cima neste arquivo se o servidor tiver sites notoriamente
    // lentos; para baixo se tiver centenas de dominios e o ciclo encostar no
    // intervalo do cron.
    'CHECK_TIMEOUT'         => 15,
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

if "$PHP_BIN" "${INSTALL_PATH}/agent.php" --test --verbose; then
    ok "Comunicacao com o painel funcionando."
    TEST_OK=1
else
    fail "O teste de conexao falhou. O agente foi instalado, mas nao esta reportando."
    warn "Corrija o problema apontado acima e rode: ${PHP_BIN} ${INSTALL_PATH}/agent.php --test --verbose"
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

    # O cron recebe o CAMINHO COMPLETO do PHP escolhido, e nao `php`: o
    # ambiente do cron tem PATH minimo, e em CyberPanel/aaPanel o `php` do
    # PATH e justamente o do sistema, antigo demais para o agente.
    CRON_LINE="*/${CRON_MINUTES} * * * * ${PHP_BIN} ${INSTALL_PATH}/agent.php >> ${INSTALL_PATH}/logs/cron.log 2>&1"

    # Remove a linha anterior deste mesmo agente antes de inserir a nova,
    # para nao acumular duplicatas em reinstalacoes.
    ( crontab -l 2>/dev/null | grep -v -F "${INSTALL_PATH}/agent.php" || true; echo "$CRON_LINE" ) | crontab -

    ok "Cron registrado: a cada ${CRON_MINUTES} minuto(s)."
    echo "       ${CRON_LINE}"
else
    warn "Cron nao configurado (--no-cron). Adicione manualmente:"
    echo "       */$(( INTERVAL / 60 )) * * * * ${PHP_BIN} ${INSTALL_PATH}/agent.php >> ${INSTALL_PATH}/logs/cron.log 2>&1"
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
echo "     ${PHP_BIN} ${INSTALL_PATH}/agent.php --verbose    executar agora, com detalhes"
echo "     ${PHP_BIN} ${INSTALL_PATH}/agent.php --test       so testar a conexao"
echo "     ${PHP_BIN} ${INSTALL_PATH}/agent.php --dry-run    coletar sem enviar"
echo "     tail -f ${INSTALL_PATH}/logs/agent-\$(date +%F).log"
echo
echo "   O agente somente COLETA e ENVIA. Ele nao executa nenhum comando"
echo "   recebido do painel."
echo

exit 0
