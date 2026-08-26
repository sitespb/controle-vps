/**
 * ============================================================================
 *  Controle VPS - Graficos (Chart.js)
 * ============================================================================
 *
 *  Carregado apenas nas paginas que tem grafico. Depende de chart.umd.min.js
 *  (vendorizado em public/assets/vendor/) e de app.js para o helper de API.
 *
 *  PALETA - as cores de SERIE sao deliberadamente diferentes das cores de
 *  STATUS. Verde/amarelo/vermelho ficam reservados ao significado
 *  normal/atencao/critico (DESIGN.md secao 2); usa-las para "esta e a linha
 *  da CPU" tornaria o painel ambiguo. As series usam azul/violeta/teal/ambar.
 * ============================================================================
 */
(function () {
    'use strict';

    if (typeof Chart === 'undefined') {
        console.warn('[ControleVPS] Chart.js nao carregado.');
        return;
    }

    // Padroes visuais alinhados ao DESIGN.md (text-sm, cinzas discretos).
    Chart.defaults.font.family =
        'ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif';
    Chart.defaults.font.size = 12;
    Chart.defaults.color = '#6b7280';          // gray-500
    Chart.defaults.borderColor = '#e5e7eb';    // gray-200
    Chart.defaults.animation.duration = 300;

    const SERIES = {
        cpu: '#2563eb',   // blue-600
        ram: '#7c3aed',   // violet-600
        disk: '#0d9488',  // teal-600
        load: '#f59e0b',  // amber-500
        response: '#2563eb',
    };

    const SEVERITY = {
        critical: '#ef4444', // red-500
        warning: '#facc15',  // yellow-400
        info: '#3b82f6',     // blue-500
    };

    /** Converte hex em rgba, usado no preenchimento sob a linha. */
    function alpha(hex, opacity) {
        const value = hex.replace('#', '');
        const r = parseInt(value.substring(0, 2), 16);
        const g = parseInt(value.substring(2, 4), 16);
        const b = parseInt(value.substring(4, 6), 16);
        return `rgba(${r}, ${g}, ${b}, ${opacity})`;
    }

    const baseLineOptions = (unit) => ({
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: {
                display: true,
                position: 'top',
                align: 'end',
                labels: { boxWidth: 10, boxHeight: 10, usePointStyle: true, pointStyle: 'circle', padding: 16 },
            },
            tooltip: {
                backgroundColor: '#111827',
                padding: 10,
                cornerRadius: 8,
                titleFont: { size: 12, weight: '600' },
                bodyFont: { size: 12 },
                displayColors: true,
                callbacks: {
                    label(context) {
                        const value = context.parsed.y;
                        if (value === null) {
                            return `${context.dataset.label}: sem dados`;
                        }
                        return `${context.dataset.label}: ${new Intl.NumberFormat('pt-BR', {
                            maximumFractionDigits: 2,
                        }).format(value)}${unit}`;
                    },
                },
            },
        },
        scales: {
            x: {
                grid: { display: false },
                ticks: { maxTicksLimit: 8, maxRotation: 0, autoSkip: true },
            },
            y: {
                beginAtZero: true,
                grid: { color: '#f3f4f6' },
                ticks: {
                    callback: (value) => value + unit,
                },
            },
        },
    });

    const lineDataset = (label, data, color, extra = {}) => ({
        label,
        data,
        borderColor: color,
        backgroundColor: alpha(color, 0.08),
        borderWidth: 2,
        pointRadius: 0,
        pointHoverRadius: 4,
        pointHoverBackgroundColor: color,
        tension: 0.3,
        fill: true,
        spanGaps: true,
        ...extra,
    });

    const instances = new Map();

    function mount(canvasId, config) {
        const canvas = document.getElementById(canvasId);
        if (!canvas) {
            return null;
        }

        if (instances.has(canvasId)) {
            instances.get(canvasId).destroy();
        }

        const chart = new Chart(canvas.getContext('2d'), config);
        instances.set(canvasId, chart);

        return chart;
    }

    /**
     * Grafico de recursos do servidor: CPU, RAM, disco (%) em um canvas e
     * load average (numero absoluto) em outro, porque as escalas nao sao
     * comparaveis.
     */
    function serverResources(canvasPercent, canvasLoad, payload) {
        const percentOptions = baseLineOptions('%');
        percentOptions.scales.y.max = 100;

        mount(canvasPercent, {
            type: 'line',
            data: {
                labels: payload.labels,
                datasets: [
                    lineDataset('CPU', payload.cpu, SERIES.cpu),
                    lineDataset('RAM', payload.ram, SERIES.ram),
                    lineDataset('Disco', payload.disk, SERIES.disk),
                ],
            },
            options: percentOptions,
        });

        if (canvasLoad) {
            const loadOptions = baseLineOptions('');
            mount(canvasLoad, {
                type: 'line',
                data: {
                    labels: payload.labels,
                    datasets: [lineDataset('Load (1 min)', payload.load, SERIES.load)],
                },
                options: loadOptions,
            });
        }
    }

    /** Tempo de resposta do site, em milissegundos. */
    function siteResponse(canvasId, payload) {
        const options = baseLineOptions(' ms');

        mount(canvasId, {
            type: 'line',
            data: {
                labels: payload.labels,
                datasets: [lineDataset('Tempo de resposta', payload.response, SERIES.response)],
            },
            options,
        });
    }

    /** Tendencia de alertas por dia, empilhada por severidade. */
    function alertTrend(canvasId, payload) {
        mount(canvasId, {
            type: 'bar',
            data: {
                labels: payload.labels,
                datasets: [
                    { label: 'Critico', data: payload.critical, backgroundColor: SEVERITY.critical, borderRadius: 3 },
                    { label: 'Atencao', data: payload.warning, backgroundColor: SEVERITY.warning, borderRadius: 3 },
                    { label: 'Informativo', data: payload.info, backgroundColor: SEVERITY.info, borderRadius: 3 },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        position: 'top',
                        align: 'end',
                        labels: { boxWidth: 10, boxHeight: 10, usePointStyle: true, pointStyle: 'circle', padding: 16 },
                    },
                    tooltip: { backgroundColor: '#111827', padding: 10, cornerRadius: 8 },
                },
                scales: {
                    x: { stacked: true, grid: { display: false } },
                    y: { stacked: true, beginAtZero: true, grid: { color: '#f3f4f6' }, ticks: { precision: 0 } },
                },
            },
        });
    }

    /** Distribuicao (rosca) - versoes de PHP, situacao dos certificados. */
    function distribution(canvasId, labels, values, colors) {
        mount(canvasId, {
            type: 'doughnut',
            data: {
                labels,
                datasets: [{
                    data: values,
                    backgroundColor: colors,
                    borderColor: '#ffffff',
                    borderWidth: 2,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '62%',
                plugins: {
                    legend: {
                        position: 'right',
                        labels: { boxWidth: 10, boxHeight: 10, usePointStyle: true, pointStyle: 'circle', padding: 12 },
                    },
                    tooltip: { backgroundColor: '#111827', padding: 10, cornerRadius: 8 },
                },
            },
        });
    }

    /**
     * Carrega a serie do servidor pela API e desenha. Usado pelo seletor de
     * periodo, que troca o grafico sem recarregar a pagina.
     */
    async function loadServerSeries(serverId, hours, canvasPercent, canvasLoad) {
        const data = await window.ControleVPS.api(`/api/v1/servers/${serverId}/metrics?horas=${hours}`);
        serverResources(canvasPercent, canvasLoad, data);
        return data;
    }

    async function loadSiteSeries(siteId, hours, canvasId) {
        const data = await window.ControleVPS.api(`/api/v1/sites/${siteId}/checks?horas=${hours}`);
        siteResponse(canvasId, data);
        return data;
    }

    window.ControleVPSCharts = {
        SERIES,
        SEVERITY,
        serverResources,
        siteResponse,
        alertTrend,
        distribution,
        loadServerSeries,
        loadSiteSeries,
    };
})();
