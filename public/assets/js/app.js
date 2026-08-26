/**
 * ============================================================================
 *  Controle VPS - JavaScript da aplicacao
 * ============================================================================
 *
 *  Vanilla JS puro, sem framework (secao 2 do PLAN). O Alpine.js cuida apenas
 *  de estado visual declarativo (sidebar, menus, toggles); tudo que envolve
 *  rede e dado passa por aqui.
 *
 *  Namespace unico: window.ControleVPS
 * ============================================================================
 */
(function () {
    'use strict';

    const meta = (name) => {
        const el = document.querySelector(`meta[name="${name}"]`);
        return el ? el.getAttribute('content') : '';
    };

    const BASE_URL = meta('base-url') || '';
    const CSRF = meta('csrf-token') || '';

    /** Monta uma URL interna respeitando o prefixo de instalacao. */
    function url(path) {
        return BASE_URL + (path.startsWith('/') ? path : '/' + path);
    }

    /**
     * Chamada a API do painel.
     *
     * Envia o token CSRF em toda requisicao que altera estado e trata o
     * envelope padrao { ok, data } / { ok:false, error }.
     */
    async function api(path, options = {}) {
        const config = {
            method: options.method || 'GET',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(options.headers || {}),
            },
            credentials: 'same-origin',
        };

        if (options.body !== undefined) {
            config.headers['Content-Type'] = 'application/json';
            config.body = JSON.stringify(options.body);
        }

        if (config.method !== 'GET' && config.method !== 'HEAD') {
            config.headers['X-CSRF-Token'] = CSRF;
        }

        const response = await fetch(url(path), config);

        let payload = null;
        try {
            payload = await response.json();
        } catch (e) {
            throw new Error('Resposta invalida do servidor (HTTP ' + response.status + ').');
        }

        if (!response.ok || payload.ok === false) {
            const message = (payload.error && payload.error.message) || 'Erro HTTP ' + response.status;
            const error = new Error(message);
            error.status = response.status;
            error.code = payload.error && payload.error.code;
            throw error;
        }

        return payload.data !== undefined ? payload.data : payload;
    }

    // ---------------------------------------------------------------------
    // Formatacao (espelha os helpers do PHP para manter a UI consistente)
    // ---------------------------------------------------------------------

    function formatNumber(value) {
        return new Intl.NumberFormat('pt-BR').format(value || 0);
    }

    function formatPercent(value, decimals = 0) {
        if (value === null || value === undefined) {
            return '--';
        }
        return new Intl.NumberFormat('pt-BR', {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals,
        }).format(value) + '%';
    }

    /** Le um valor aninhado por notacao de ponto: get(obj, 'servers.total') */
    function get(object, path) {
        return path.split('.').reduce((acc, key) => (acc && acc[key] !== undefined ? acc[key] : null), object);
    }

    // ---------------------------------------------------------------------
    // Atualizacao periodica do dashboard
    // ---------------------------------------------------------------------

    let summaryTimer = null;

    /**
     * Atualiza os cards e as barras do dashboard sem recarregar a pagina.
     *
     * Intervalo padrao de 60 s - deliberadamente folgado, ja que os agentes
     * enviam dados a cada 5 minutos. Polling mais agressivo so geraria carga
     * sem informacao nova (secao 39 do PLAN).
     */
    function startSummaryRefresh(intervalMs = 60000) {
        stopSummaryRefresh();

        const tick = async () => {
            // Aba em segundo plano nao precisa de dado fresco.
            if (document.hidden) {
                return;
            }

            try {
                const data = await api('/api/v1/dashboard/summary');
                applySummary(data);
            } catch (e) {
                // Silencioso de proposito: perder uma atualizacao nao pode
                // encher a tela de erro. O proximo ciclo tenta de novo.
                console.debug('[ControleVPS] Falha ao atualizar resumo:', e.message);
            }
        };

        summaryTimer = setInterval(tick, intervalMs);
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) {
                tick();
            }
        });
    }

    function stopSummaryRefresh() {
        if (summaryTimer) {
            clearInterval(summaryTimer);
            summaryTimer = null;
        }
    }

    function applySummary(data) {
        document.querySelectorAll('[data-summary]').forEach((el) => {
            const value = get(data, el.getAttribute('data-summary'));
            if (value !== null) {
                el.textContent = formatNumber(value);
            }
        });

        updateStatusIndicator(data.overall);
        updateAlertBadge(data.alerts ? data.alerts.total : 0);
    }

    function updateStatusIndicator(overall) {
        if (!overall) {
            return;
        }

        const dot = document.querySelector('[data-status-dot]');
        const label = document.querySelector('[data-status-label]');

        if (dot) {
            dot.className = 'h-2.5 w-2.5 rounded-full ' + ({
                critical: 'bg-red-500',
                warning: 'bg-yellow-400',
                normal: 'bg-green-500',
            }[overall.level] || 'bg-gray-300');
        }

        if (label) {
            label.textContent = overall.label;
            label.className = 'text-sm font-medium ' + ({
                critical: 'text-red-700',
                warning: 'text-yellow-800',
                normal: 'text-gray-600',
            }[overall.level] || 'text-gray-400');
        }
    }

    function updateAlertBadge(total) {
        const badge = document.querySelector('[data-alert-badge]');
        if (!badge) {
            return;
        }

        if (total > 0) {
            badge.textContent = total > 99 ? '99+' : String(total);
            badge.classList.remove('hidden');
        } else {
            badge.classList.add('hidden');
        }
    }

    // ---------------------------------------------------------------------
    // Copiar para a area de transferencia
    // ---------------------------------------------------------------------

    /**
     * Copia o texto de um elemento. Usado nas instrucoes de instalacao do
     * agente (token, bloco de config, linha de cron).
     */
    async function copyText(text, feedbackEl) {
        try {
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(text);
            } else {
                // Fallback para http:// em rede local, onde a Clipboard API
                // nao esta disponivel.
                const textarea = document.createElement('textarea');
                textarea.value = text;
                textarea.style.position = 'fixed';
                textarea.style.opacity = '0';
                document.body.appendChild(textarea);
                textarea.select();
                document.execCommand('copy');
                document.body.removeChild(textarea);
            }

            showCopyFeedback(feedbackEl, 'Copiado!', true);
            return true;
        } catch (e) {
            showCopyFeedback(feedbackEl, 'Nao foi possivel copiar', false);
            return false;
        }
    }

    function showCopyFeedback(el, message, success) {
        if (!el) {
            return;
        }

        const original = el.dataset.originalLabel || el.textContent;
        el.dataset.originalLabel = original;
        el.textContent = message;
        el.classList.toggle('text-green-700', success);
        el.classList.toggle('text-red-700', !success);

        setTimeout(() => {
            el.textContent = original;
            el.classList.remove('text-green-700', 'text-red-700');
        }, 2000);
    }

    /** Liga automaticamente todos os [data-copy] da pagina. */
    function bindCopyButtons() {
        document.querySelectorAll('[data-copy]').forEach((button) => {
            button.addEventListener('click', () => {
                const targetSelector = button.getAttribute('data-copy');
                const target = document.querySelector(targetSelector);
                const text = target ? (target.value !== undefined ? target.value : target.textContent) : '';

                copyText(text.trim(), button.querySelector('[data-copy-label]') || button);
            });
        });
    }

    // ---------------------------------------------------------------------
    // Filtros que se aplicam sozinhos
    // ---------------------------------------------------------------------

    /**
     * <select data-auto-submit> envia o formulario ao mudar.
     * <input data-auto-submit-delay="500"> envia depois da digitacao parar.
     */
    function bindAutoSubmit() {
        document.querySelectorAll('[data-auto-submit]').forEach((el) => {
            el.addEventListener('change', () => el.form && el.form.submit());
        });

        document.querySelectorAll('[data-auto-submit-delay]').forEach((el) => {
            const delay = parseInt(el.getAttribute('data-auto-submit-delay'), 10) || 500;
            let timer = null;

            el.addEventListener('input', () => {
                clearTimeout(timer);
                timer = setTimeout(() => el.form && el.form.submit(), delay);
            });
        });
    }

    // ---------------------------------------------------------------------
    // Confirmacao de acoes destrutivas
    // ---------------------------------------------------------------------

    /**
     * <form data-confirm="Mensagem"> pede confirmacao antes de enviar.
     * Usado em exclusoes e na regeneracao de token.
     */
    function bindConfirmations() {
        document.querySelectorAll('[data-confirm]').forEach((form) => {
            form.addEventListener('submit', (event) => {
                if (!window.confirm(form.getAttribute('data-confirm'))) {
                    event.preventDefault();
                }
            });
        });
    }

    // ---------------------------------------------------------------------
    // Tooltips do menu lateral recolhido
    // ---------------------------------------------------------------------

    /**
     * Com a sidebar recolhida, sobram so os icones e o usuario perde a
     * referencia do que cada um significa. Ao passar o mouse (ou focar via
     * teclado) em um item com [data-tooltip], mostra o nome da secao.
     *
     * O tooltip e um UNICO elemento reaproveitado, afixado direto no
     * <body> e posicionado com `position: fixed` a partir das coordenadas
     * reais do link (getBoundingClientRect). Isso e necessario porque o
     * <nav> da sidebar tem overflow-y-auto: assim que um eixo do overflow
     * deixa de ser "visible", o outro eixo passa a se comportar como
     * "auto" tambem - um posicionamento absoluto simples (`left-full`)
     * ficaria cortado horizontalmente pelo proprio container que rola.
     *
     * A decisao de mostrar ou nao olha o estado JA renderizado pelo Alpine:
     * se o rotulo (.nav-label) do item estiver com largura zero (escondido
     * por x-show="!sidebarCollapsed"), o texto nao esta visivel e o
     * tooltip faz sentido. Com a sidebar expandida o rotulo ja aparece por
     * extenso, entao o tooltip e dispensado.
     */
    function bindSidebarTooltips() {
        const targets = document.querySelectorAll('#sidebar [data-tooltip]');

        if (targets.length === 0) {
            return;
        }

        const tooltip = document.createElement('div');
        tooltip.className =
            'fixed z-[9999] hidden whitespace-nowrap rounded-md bg-gray-900 px-2.5 py-1.5 text-xs font-medium text-white shadow-lg pointer-events-none';
        tooltip.setAttribute('role', 'tooltip');
        document.body.appendChild(tooltip);

        const show = (el) => {
            const label = el.querySelector('.nav-label');

            // Rotulo ja visivel por extenso: tooltip seria redundante.
            if (!label || label.offsetWidth > 0) {
                return;
            }

            const text = el.getAttribute('data-tooltip');
            if (!text) {
                return;
            }

            const rect = el.getBoundingClientRect();

            tooltip.textContent = text;
            tooltip.style.left = `${rect.right + 10}px`;
            tooltip.style.top = `${rect.top + rect.height / 2}px`;
            tooltip.style.transform = 'translateY(-50%)';
            tooltip.classList.remove('hidden');
        };

        const hide = () => {
            tooltip.classList.add('hidden');
        };

        targets.forEach((el) => {
            el.addEventListener('mouseenter', () => show(el));
            el.addEventListener('mouseleave', hide);
            el.addEventListener('focus', () => show(el));
            el.addEventListener('blur', hide);
            // O clique navega ou alterna o estado da sidebar - em ambos os
            // casos o tooltip perde o sentido de continuar visivel.
            el.addEventListener('click', hide);
        });

        // A posicao calculada no hover fica invalida se a janela mudar de
        // tamanho ou se o menu rolar - mais simples esconder do que recalcular.
        const nav = document.getElementById('sidebar-nav');
        if (nav) {
            nav.addEventListener('scroll', hide);
        }
        window.addEventListener('resize', hide);
    }

    // ---------------------------------------------------------------------
    // Exclusao de servidor a partir da listagem
    // ---------------------------------------------------------------------

    /**
     * Botao com [data-delete-server] dentro de um <form> com campo oculto
     * "confirm_name". Pede ao usuario que digite o nome exato do servidor -
     * a mesma trava de seguranca da tela de edicao, so que direto na lista,
     * sem precisar navegar ate a "zona de risco".
     */
    function bindDeleteServerButtons() {
        document.querySelectorAll('[data-delete-server]').forEach((button) => {
            button.addEventListener('click', () => {
                const form = button.closest('form');
                if (!form) {
                    return;
                }

                const expected = button.getAttribute('data-server-name') || '';
                const typed = window.prompt(
                    `Esta acao remove o servidor e TODO o historico (metricas, sites, alertas).\n\n` +
                    `Para confirmar, digite o nome exato do servidor:\n\n${expected}`
                );

                if (typed === null) {
                    return; // cancelado
                }

                if (typed.trim() !== expected) {
                    window.alert('O nome digitado nao confere. Exclusao cancelada.');
                    return;
                }

                const hidden = form.querySelector('input[name="confirm_name"]');
                if (hidden) {
                    hidden.value = typed.trim();
                }

                form.submit();
            });
        });
    }

    // ---------------------------------------------------------------------
    // Acoes em alertas (reconhecer / resolver) sem recarregar
    // ---------------------------------------------------------------------

    function bindAlertActions() {
        document.querySelectorAll('[data-alert-action]').forEach((button) => {
            button.addEventListener('click', async () => {
                const action = button.getAttribute('data-alert-action');
                const id = button.getAttribute('data-alert-id');
                const row = button.closest('[data-alert-row]');

                button.disabled = true;
                button.classList.add('opacity-50', 'cursor-not-allowed');

                try {
                    await api(`/api/v1/alerts/${id}/${action}`, { method: 'POST' });

                    if (row) {
                        row.classList.add('opacity-40');
                    }

                    // Recarrega para que os contadores e filtros fiquem corretos.
                    window.location.reload();
                } catch (e) {
                    window.alert(e.message);
                    button.disabled = false;
                    button.classList.remove('opacity-50', 'cursor-not-allowed');
                }
            });
        });
    }

    // ---------------------------------------------------------------------
    // Inicializacao
    // ---------------------------------------------------------------------

    document.addEventListener('DOMContentLoaded', () => {
        bindCopyButtons();
        bindAutoSubmit();
        bindConfirmations();
        bindSidebarTooltips();
        bindDeleteServerButtons();
        bindAlertActions();
    });

    window.ControleVPS = {
        url,
        api,
        formatNumber,
        formatPercent,
        copyText,
        startSummaryRefresh,
        stopSummaryRefresh,
    };
})();
