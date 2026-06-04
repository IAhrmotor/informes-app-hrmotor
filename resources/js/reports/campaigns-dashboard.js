const numberFormatter = new Intl.NumberFormat('es-ES');
const moneyFormatter = new Intl.NumberFormat('es-ES', { style: 'currency', currency: 'EUR' });
const columnsStorageKey = 'hrmotor_campaign_columns_v4';
const rankingsStorageKey = 'campaigns.visibleRankings';
const dailySeriesStorageKey = 'campaigns.dailyChart.visibleSeries';
const dailyTypeStorageKey = 'campaigns.dailyChart.chartType';
const monthlyMonthsStorageKey = 'campaigns.monthlyChart.months';
const monthlyMetricsStorageKey = 'campaigns.monthlyChart.metrics';
const monthlyCompareStorageKey = 'campaigns.monthlyChart.compare';
const campaignNameSelectionStorageKey = 'campaigns.campaignNames.selected';
const campaignPointIcon = '/brand/campaign-point.svg';

let campaignRows = [];
let tableSort = {
    venta: { key: 'spend', direction: 'desc' },
    tasacion: { key: 'spend', direction: 'desc' },
};
let searchTimer = null;
let syncingTableScroll = false;
let dailyVisibleSeries = loadDailyVisibleSeries();
let dailyChartType = loadDailyChartType();
let currentCharts = {};
let currentContext = 'venta';
let campaignNameSelections = loadCampaignNameSelections();
let monthlySelectedMonths = [];
let monthlyVisibleMetrics = loadMonthlyVisibleMetrics();
let monthlyCompareMode = loadMonthlyCompareMode();

const dailySeriesDefinitions = [
    { key: 'spend', label: 'Inversion', formatter: formatMoney, className: 'spend' },
    { key: 'leads_salesforce', label: 'Leads SF', formatter: formatNumber, className: 'leads' },
];

const conversionSeriesDefinitions = [
    { key: 'reservations', label: 'Reservas', formatter: formatNumber, className: 'reservations' },
    { key: 'sales', label: 'Ventas', formatter: formatNumber, className: 'sales' },
];

const monthlySeriesDefinitions = [
    { key: 'spend', label: 'Inversion', formatter: formatMoney, className: 'spend' },
    { key: 'impressions', label: 'Impresiones', formatter: formatNumber, className: 'impressions' },
    { key: 'clicks', label: 'Clicks', formatter: formatNumber, className: 'clicks' },
    { key: 'leads_salesforce', label: 'Leads', formatter: formatNumber, className: 'leads' },
    { key: 'opportunities', label: 'Oportunidades', formatter: formatNumber, className: 'opportunities' },
    { key: 'reservations', label: 'Reservas', formatter: formatNumber, className: 'reservations' },
    { key: 'sales', label: 'Ventas', formatter: formatNumber, className: 'sales' },
    { key: 'appraisals_generated', label: 'Tasaciones', formatter: formatNumber, className: 'appraisals' },
    { key: 'purchases', label: 'Compras', formatter: formatNumber, className: 'purchases' },
];

const monthlyTasacionSeriesDefinitions = [
    { key: 'spend', label: 'Inversion', formatter: formatMoney, className: 'spend' },
    { key: 'leads_salesforce', label: 'Leads', formatter: formatNumber, className: 'leads' },
    { key: 'opportunities', label: 'Oportunidades', formatter: formatNumber, className: 'opportunities' },
    { key: 'purchases', label: 'Compras', formatter: formatNumber, className: 'purchases' },
];

const rankingDefinitions = [
    { key: 'top_spend', title: 'Campanas con mas inversion', metric: 'spend', formatter: formatMoney, visible: true },
    { key: 'top_leads_salesforce', title: 'Mas leads Salesforce', metric: 'leads_salesforce', formatter: formatNumber, visible: true },
    { key: 'top_opportunities', title: 'Mas oportunidades', metric: 'opportunities', formatter: formatNumber, visible: false },
    { key: 'top_reservations', title: 'Mas reservas', metric: 'reservations', formatter: formatNumber, visible: false },
    { key: 'top_sales', title: 'Mas ventas', metric: 'sales', formatter: formatNumber, visible: true },
    { key: 'top_purchases', title: 'Mas compras', metric: 'purchases', formatter: formatNumber, visible: false },
    { key: 'top_sale_amount', title: 'Mayor importe vendido', metric: 'sale_amount', formatter: formatMoney, visible: false },
    { key: 'best_roas', title: 'Mejor ROAS', metric: 'roas', formatter: formatMultiplier, visible: false },
    { key: 'best_cost_per_sale', title: 'Mejor coste por venta', metric: 'cost_per_sale', formatter: formatMoney, visible: true },
    { key: 'best_cost_per_purchase', title: 'Mejor coste por compra', metric: 'cost_per_purchase', formatter: formatMoney, visible: false },
    { key: 'best_lead_to_purchase', title: 'Mejor conversion lead -> compra', metric: 'lead_to_purchase', formatter: formatPercentRatio, visible: false },
    { key: 'worst_cost_per_sale', title: 'Peor coste por venta', metric: 'cost_per_sale', formatter: formatMoney, visible: false },
    { key: 'high_spend_low_conversion', title: 'Mucho gasto y poca conversion', metric: 'spend', formatter: formatMoney, visible: false },
    { key: 'many_leads_few_sales', title: 'Muchos leads y pocas ventas', metric: 'leads_salesforce', formatter: formatNumber, visible: false },
    { key: 'many_leads_few_purchases', title: 'Muchos leads y pocas compras', metric: 'leads_salesforce', formatter: formatNumber, visible: false },
    { key: 'review_campaigns', title: 'Campanas a revisar', metric: 'value', formatter: formatNumber, visible: true },
    { key: 'review_tracking', title: 'Revisar tracking', metric: 'spend', formatter: formatMoney, visible: true },
    { key: 'boost', title: 'Potenciar', metric: 'spend', formatter: formatMoney, visible: true },
    { key: 'review', title: 'Revisar', metric: 'spend', formatter: formatMoney, visible: false },
    { key: 'stop', title: 'Parar', metric: 'spend', formatter: formatMoney, visible: false },
];

const columnDefinitions = [
    { key: 'campaign', label: 'Campana', visible: true, formatter: (_value, row) => campaignLabel(row) },
    { key: 'platform', label: 'Plataforma', visible: true, formatter: formatPlatform },
    { key: 'spend', label: 'Inversion', visible: true, numeric: true, formatter: formatMoney },
    { key: 'impressions', label: 'Impresiones', visible: true, numeric: true, formatter: formatNumber },
    { key: 'clicks', label: 'Clicks', visible: true, numeric: true, formatter: formatNumber },
    { key: 'ctr', label: 'CTR', visible: true, numeric: true, formatter: formatPercentRatio },
    { key: 'cpc', label: 'CPC', visible: true, numeric: true, formatter: formatMoney },
    { key: 'leads_salesforce', label: 'Leads Salesforce', visible: true, numeric: true, formatter: formatNumber },
    { key: 'opportunities', label: 'Oportunidades', visible: true, numeric: true, formatter: formatNumber },
    { key: 'reservations', label: 'Reservas', visible: true, numeric: true, formatter: formatNumber },
    { key: 'sales', label: 'Ventas', visible: true, numeric: true, formatter: formatNumber },
    { key: 'sale_amount', label: 'Importe vendido', visible: true, numeric: true, formatter: formatMoney },
    { key: 'account_id', label: 'Cuenta', visible: false },
    { key: 'campaign_id', label: 'Campaign ID', visible: true },
    { key: 'source_acquired', label: 'Fuente adquirida', visible: false },
    { key: 'medium_acquired', label: 'Medio adquirido', visible: false },
    { key: 'acquired_id', label: 'ID adquirido', visible: false },
    { key: 'content_acquired', label: 'Contenido adquirido', visible: false },
    { key: 'cost_per_lead', label: 'CPL', visible: true, numeric: true, formatter: formatMoney },
    { key: 'cost_per_opportunity', label: 'CPO', visible: true, numeric: true, formatter: formatMoney },
    { key: 'cost_per_reservation', label: 'CPR', visible: true, numeric: true, formatter: formatMoney },
    { key: 'cost_per_sale', label: 'CPV', visible: true, numeric: true, formatter: formatMoney },
    { key: 'roas', label: 'ROAS', visible: true, numeric: true, formatter: formatMultiplier },
    { key: 'estimated_roi', label: 'ROI estimado', visible: true, numeric: true, formatter: formatPercentRatio },
    { key: 'classification', label: 'Clasificacion', visible: false },
    { key: 'campaign_status_label', label: 'Estado campana', visible: true },
    { key: 'campaign_start_date', label: 'Fecha inicio campana', visible: false, formatter: formatDate },
    { key: 'campaign_end_date', label: 'Fecha fin campana', visible: false, formatter: formatDate },
    { key: 'last_spend_date', label: 'Ultima fecha con inversion', visible: false, formatter: formatDate },
    { key: 'appraisals_generated', label: 'Tasaciones generadas', visible: false, numeric: true, formatter: formatNumber },
    { key: 'purchases', label: 'Compras firmadas', visible: false, numeric: true, formatter: formatNumber },
    { key: 'cost_per_appraisal', label: 'Coste por tasacion', visible: false, numeric: true, formatter: formatMoney },
    { key: 'cost_per_purchase', label: 'Coste por compra', visible: false, numeric: true, formatter: formatMoney },
    { key: 'lead_to_opportunity', label: 'Lead -> oportunidad', visible: false, numeric: true, formatter: formatPercentRatio },
    { key: 'opportunity_to_reservation', label: 'Oportunidad -> reserva', visible: false, numeric: true, formatter: formatPercentRatio },
    { key: 'reservation_to_sale', label: 'Reserva -> venta', visible: false, numeric: true, formatter: formatPercentRatio },
    { key: 'lead_to_sale', label: 'Lead -> venta', visible: false, numeric: true, formatter: formatPercentRatio },
];

let visibleColumns = loadVisibleColumns();
let visibleRankings = loadVisibleRankings();

document.addEventListener('DOMContentLoaded', async () => {
    populatePeriodPreset();
    setDefaultDates();
    bindTabs();
    bindFilters();
    bindResetFilters();
    bindSorting();
    bindDrawer();
    bindCampaignNameActions();
    bindColumns();
    bindRankingSettings();
    bindMonthlyChartControls();
    bindTableScroll();
    applyColumnVisibility();
    await reloadAllData();
});

function bindTabs() {
    document.querySelectorAll('.main-tab[data-panel]').forEach((button) => {
        button.addEventListener('click', async () => {
            document.querySelectorAll('.main-tab[data-panel]').forEach((item) => item.classList.remove('active'));
            document.querySelectorAll('.tab-panel').forEach((panel) => panel.classList.remove('active'));

            button.classList.add('active');
            document.getElementById(button.dataset.panel)?.classList.add('active');

            if (button.dataset.context && button.dataset.context !== currentContext) {
                currentContext = button.dataset.context;
                await reloadAllData();
            }

            syncCampaignTableScrollWidth();
        });
    });
}

function bindResetFilters() {
    document.getElementById('resetFilters')?.addEventListener('click', async () => {
        [
            'platform',
            'campaignSearch',
            'campaignStatus',
            'hasOpportunity',
            'hasReservation',
            'hasSale',
            'classification',
        ].forEach((id) => {
            const element = document.getElementById(id);

            if (element) {
                element.value = '';
            }
        });

        document.getElementById('periodPreset').value = 'last_30_days';
        document.getElementById('campaignStatus').value = 'active';
        campaignNameSelections = [];
        persistCampaignNameSelections();
        renderCampaignNameChecklist(campaignRows);
        setDefaultDates();
        await reloadAllData();
    });
}

function bindFilters() {
    [
        'startDate',
        'endDate',
        'platform',
        'campaignStatus',
        'hasOpportunity',
        'hasReservation',
        'hasSale',
        'classification',
    ].forEach((id) => {
        document.getElementById(id)?.addEventListener('change', reloadAllData);
    });

    document.getElementById('periodPreset')?.addEventListener('change', async (event) => {
        applyPeriodPreset(event.target.value);
        await reloadAllData();
    });

    ['startDate', 'endDate'].forEach((id) => {
        document.getElementById(id)?.addEventListener('change', () => {
            const preset = document.getElementById('periodPreset');
            if (preset) {
                preset.value = 'custom';
            }
        });
    });

    document.getElementById('campaignSearch')?.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(reloadAllData, 350);
    });
}

function bindSorting() {
    const root = document.getElementById('campaignTables');

    root?.addEventListener('click', (event) => {
        const header = event.target.closest('th[data-sortable="true"]');
        if (!header) {
            return;
        }

        const tableType = header.closest('table')?.dataset.tableType;
        const key = header.dataset.key;

        if (!tableType || !key) {
            return;
        }

        const current = tableSort[tableType] || { key: 'spend', direction: 'desc' };
        tableSort = {
            ...tableSort,
            [tableType]: {
                key,
                direction: current.key === key && current.direction === 'desc' ? 'asc' : 'desc',
            },
        };

        renderCampaignTables(campaignRows);
    });
}

function bindDrawer() {
    const drawer = document.getElementById('advancedFiltersDrawer');
    const open = () => {
        drawer?.classList.add('is-open');
        drawer?.setAttribute('aria-hidden', 'false');
    };
    const close = () => {
        drawer?.classList.remove('is-open');
        drawer?.setAttribute('aria-hidden', 'true');
    };

    document.getElementById('advancedFiltersOpen')?.addEventListener('click', open);
    document.getElementById('advancedFiltersClose')?.addEventListener('click', close);
    document.getElementById('advancedFiltersCloseBackdrop')?.addEventListener('click', close);
}

function bindCampaignNameActions() {
    document.getElementById('campaignNamesSelectAll')?.addEventListener('click', () => {
        campaignNameSelections = [...new Set(campaignRows.map((row) => row.campaign_name).filter(Boolean))];
        persistCampaignNameSelections();
        renderCampaignNameChecklist(campaignRows);
        reloadAllData();
    });

    document.getElementById('campaignNamesClear')?.addEventListener('click', () => {
        campaignNameSelections = [];
        persistCampaignNameSelections();
        renderCampaignNameChecklist(campaignRows);
        reloadAllData();
    });
}

function bindColumns() {
    renderColumnsPopover();
    document.getElementById('columnsToggle')?.addEventListener('click', () => {
        document.getElementById('columnsPopover')?.classList.toggle('is-hidden');
    });
}

function bindRankingSettings() {
    renderRankingsPopover();
    document.getElementById('rankingsToggle')?.addEventListener('click', () => {
        document.getElementById('rankingsPopover')?.classList.toggle('is-hidden');
    });
}

function bindDailyChartControls() {
    document.querySelectorAll('[data-series]').forEach((button) => {
        button.addEventListener('click', () => {
            const series = button.dataset.series;

            if (!series) {
                return;
            }

            if (dailyVisibleSeries.includes(series)) {
                if (dailyVisibleSeries.length === 1) {
                    return;
                }

                dailyVisibleSeries = dailyVisibleSeries.filter((item) => item !== series);
            } else {
                dailyVisibleSeries = [...dailyVisibleSeries, series];
            }

            localStorage.setItem(dailySeriesStorageKey, JSON.stringify(dailyVisibleSeries));
            renderCharts(currentCharts);
        });
    });

    document.querySelectorAll('[data-chart-type]').forEach((button) => {
        button.addEventListener('click', () => {
            const type = button.dataset.chartType === 'lines' ? 'lines' : 'bars';
            dailyChartType = type;
            localStorage.setItem(dailyTypeStorageKey, type);
            renderCharts(currentCharts);
        });
    });
}

function bindTableScroll() {
    const root = document.getElementById('campaignTables');
    if (!root) {
        return;
    }

    root.addEventListener('scroll', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) {
            return;
        }

        const proxy = target.matches('[data-table-scroll-proxy]');
        const wrap = target.matches('[data-table-wrap]');

        if (!proxy && !wrap) {
            return;
        }

        if (syncingTableScroll) {
            return;
        }

        syncingTableScroll = true;

        const tableType = target.dataset.tableScrollProxy || target.dataset.tableWrap;
        const counterpart = [...root.querySelectorAll(proxy ? '[data-table-wrap]' : '[data-table-scroll-proxy]')]
            .find((element) => element.dataset.tableWrap === tableType || element.dataset.tableScrollProxy === tableType);

        if (counterpart) {
            counterpart.scrollLeft = target.scrollLeft;
        }

        syncingTableScroll = false;
    }, true);

    window.addEventListener('resize', syncCampaignTableScrollWidth);
}

function bindMonthlyChartControls() {
    const root = document.getElementById('campaignCharts');

    root?.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) {
            return;
        }

        const monthButton = target.closest('[data-month-key]');
        const rangeButton = target.closest('[data-month-range]');
        const metricButton = target.closest('[data-month-metric]');
        const compareButton = target.closest('[data-compare-mode]');

        if (monthButton) {
            const monthKey = monthButton.dataset.monthKey;
            if (!monthKey) {
                return;
            }

            if (monthlySelectedMonths.includes(monthKey)) {
                if (monthlySelectedMonths.length === 1) {
                    return;
                }
                monthlySelectedMonths = monthlySelectedMonths.filter((item) => item !== monthKey);
            } else {
                monthlySelectedMonths = [...monthlySelectedMonths, monthKey];
            }

            persistMonthlySelection();
            renderCharts(currentCharts);
            return;
        }

        if (rangeButton) {
            const value = rangeButton.dataset.monthRange;
            if (!value) {
                return;
            }

            applyMonthlyRange(value, currentCharts.monthly_evolution || []);
            persistMonthlySelection();
            renderCharts(currentCharts);
            return;
        }

        if (metricButton) {
            const metric = metricButton.dataset.monthMetric;
            if (!metric) {
                return;
            }

            if (monthlyVisibleMetrics.includes(metric)) {
                if (monthlyVisibleMetrics.length === 1) {
                    return;
                }
                monthlyVisibleMetrics = monthlyVisibleMetrics.filter((item) => item !== metric);
            } else {
                monthlyVisibleMetrics = [...monthlyVisibleMetrics, metric];
            }

            persistMonthlyMetrics();
            renderCharts(currentCharts);
            return;
        }

        if (compareButton) {
            const mode = compareButton.dataset.compareMode || 'none';
            monthlyCompareMode = mode === 'prev_year' ? 'prev_year' : 'none';
            persistMonthlyCompareMode();
            renderCharts(currentCharts);
        }
    });
}

async function reloadAllData() {
    setLoadingState(true);

    try {
        const query = currentFilters();
        const campaignsQuery = currentCampaignFilters();
        const [summary, campaigns, rankings] = await Promise.all([
            fetchJson(`/informes/campanas/data/summary?${query}`),
            fetchJson(`/informes/campanas/data/campaigns?${campaignsQuery}`),
            fetchJson(`/informes/campanas/data/rankings?${query}`),
        ]);

        renderSummary(summary || {});
        renderFilterOptions(summary.filters || {}, campaigns.items || []);
        campaignRows = campaigns.items || [];
        renderCampaignTables(campaignRows);
        renderRankings((rankings && rankings.rankings) || {});
        const exportLink = document.getElementById('exportCsv');
        if (exportLink) {
            exportLink.href = `/informes/campanas/export/campaigns.csv?${campaignsQuery}`;
        }
    } catch (error) {
        showLoadError(error);
    } finally {
        setLoadingState(false);
    }
}

function renderSummary(data) {
    const updatedBadge = document.getElementById('updatedBadge');
    if (updatedBadge) {
        updatedBadge.textContent = data.datos_actualizados
            ? `Datos actualizados: ${formatDateTime(data.datos_actualizados)}`
            : 'Datos actualizados: pendiente';
    }
    document.getElementById('periodLabel').textContent = periodText(data.periodo_actual);
    const pivotLabel = document.getElementById('pivotLabel');
    if (pivotLabel) {
        pivotLabel.textContent = 'Lead.CreatedDate';
    }

    const empty = document.getElementById('emptyMessage');
    empty.classList.toggle('is-hidden', Boolean(data.ok));

    renderWarnings(data.warnings || []);
    currentContext = data.selected_context || currentContext;
    renderKpis(data.kpis || {}, currentContext);
    renderCharts(data.charts || {});
    renderPlatformComparison(data.platform_comparison || []);
    renderReviewCampaigns(data.review_campaigns || []);
    renderDiagnostics(data.diagnostics || {});
}

function renderWarnings(warnings) {
    const root = document.getElementById('warnings');

    root.innerHTML = '';

    warnings.forEach((warning) => {
        root.insertAdjacentHTML('beforeend', `<div class="notice">${escapeHtml(warning)}</div>`);
    });
}

function renderKpis(kpis, context = 'venta') {
    const root = document.getElementById('summaryKpis');
    let cards = [
        ['Inversion total', formatMoney(kpis.spend), `CPC ${formatMoney(kpis.cpc)}`],
        ['Impresiones', formatNumber(kpis.impressions), `CTR ${formatPercentRatio(kpis.ctr)}`],
        ['Clicks', formatNumber(kpis.clicks), `Leads SF ${formatNumber(kpis.leads_salesforce)}`],
        ['Leads Salesforce', formatNumber(kpis.leads_salesforce), `CPL ${formatMoney(kpis.cost_per_lead)}`],
        ['Oportunidades', formatNumber(kpis.opportunities), `CPO ${formatMoney(kpis.cost_per_opportunity)}`],
        ['Reservas', formatNumber(kpis.reservations), `CPR ${formatMoney(kpis.cost_per_reservation)}`],
        ['Ventas', formatNumber(kpis.sales), `CPV ${formatMoney(kpis.cost_per_sale)}`],
        ['Importe vendido', formatMoney(kpis.sale_amount), `ROAS ${formatMultiplier(kpis.roas)} · ROI ${formatPercentRatio(kpis.estimated_roi)}`],
    ];

    if (context === 'tasacion') {
        cards = [
            ['Inversion total', formatMoney(kpis.spend), `CPC ${formatMoney(kpis.cpc)}`],
            ['Impresiones', formatNumber(kpis.impressions), `CTR ${formatPercentRatio(kpis.ctr)}`],
            ['Clicks', formatNumber(kpis.clicks), `Leads SF ${formatNumber(kpis.leads_salesforce)}`],
            ['Leads Salesforce', formatNumber(kpis.leads_salesforce), `CPL ${formatMoney(kpis.cost_per_lead)}`],
            ['Oportunidades / citas', formatNumber(kpis.opportunities), `CPO ${formatMoney(kpis.cost_per_opportunity)}`],
            ['Tasaciones generadas', formatNumber(kpis.appraisals_generated), `Coste ${formatMoney(kpis.cost_per_appraisal)}`],
            ['Compras / contratos', formatNumber(kpis.purchases), `Coste ${formatMoney(kpis.cost_per_purchase)}`],
            ['Conversion lead -> compra', formatPercentRatio(kpis.lead_to_purchase), `Cita -> compra ${formatPercentRatio(kpis.opportunity_to_purchase)}`],
        ];
    }

    root.innerHTML = '';
    cards.forEach(([label, value, hint]) => {
        root.insertAdjacentHTML('beforeend', `
            <div class="card kpi campaign-kpi">
                <div>
                    <div class="kpi-label">${escapeHtml(label)}</div>
                    <div class="kpi-value">${escapeHtml(value)}</div>
                    <div class="kpi-hint">${escapeHtml(hint)}</div>
                </div>
            </div>
        `);
    });
}

function renderCharts(charts) {
    const root = document.getElementById('campaignCharts');

    if (!root) {
        return;
    }

    currentCharts = charts || {};
    root.innerHTML = `
        ${monthlyEvolutionHtml(charts.monthly_evolution || charts.daily_evolution || [])}
        ${funnelHtml(charts.funnel || [])}
        ${platformBarsHtml(charts.platforms || [])}
    `;
}

function monthlyEvolutionHtml(rows) {
    const visibleRows = visibleMonthlyRows(rows);
    const selectedRows = selectedMonthlyRows(visibleRows);
    const definitions = selectedMonthlySeries();
    const content = selectedRows.length
        ? genericLineChartHtml(buildMonthlyChartRows(rows, selectedRows), buildMonthlySeriesDefinitions(definitions), monthlyTooltip, true)
        : '<div class="empty-state">Sin datos</div>';

    return `
        <article class="card panel campaign-chart-card campaign-chart-wide monthly-chart-card">
            <div class="panel-title compact">
                <div>
                    <h2>Evolucion historica</h2>
                    <div class="small">Media mensual de los meses seleccionados. Puedes comparar con el mismo periodo del año anterior.</div>
                </div>
            </div>
            <div class="monthly-chart-toolbar">
                <div class="monthly-chart-ranges">
                    <button type="button" class="monthly-pill" data-month-range="3">Últimos 3 meses</button>
                    <button type="button" class="monthly-pill" data-month-range="6">Últimos 6 meses</button>
                    <button type="button" class="monthly-pill" data-month-range="12">Últimos 12 meses</button>
                    <button type="button" class="monthly-pill" data-month-range="all">Todo</button>
                </div>
                <div class="monthly-chart-compare">
                    <button type="button" class="monthly-pill ${monthlyCompareMode === 'none' ? 'is-active' : ''}" data-compare-mode="none">Sin comparación</button>
                    <button type="button" class="monthly-pill ${monthlyCompareMode === 'prev_year' ? 'is-active' : ''}" data-compare-mode="prev_year">Año anterior</button>
                </div>
            </div>
            <div class="monthly-chart-filters">
                <div>
                    <div class="monthly-filter-label">Meses</div>
                    <div class="monthly-pill-group" id="monthlyMonthPills">
                        ${visibleRows.map((row) => {
                            const monthKey = monthKeyFromDate(row.date);
                            const active = monthlySelectedMonths.includes(monthKey);
                            return `
                                <button type="button" class="monthly-pill ${active ? 'is-active' : ''}" data-month-key="${escapeHtml(monthKey)}">
                                    ${escapeHtml(monthLabel(row))}
                                </button>
                            `;
                        }).join('')}
                    </div>
                </div>
                <div>
                    <div class="monthly-filter-label">Métricas</div>
                    <div class="monthly-pill-group" id="monthlyMetricPills">
                        ${monthlySeriesDefinitions.map((series) => `
                            <button type="button" class="monthly-pill ${monthlyVisibleMetrics.includes(series.key) ? 'is-active' : ''}" data-month-metric="${escapeHtml(series.key)}">
                                ${escapeHtml(series.label)}
                            </button>
                        `).join('')}
                    </div>
                </div>
            </div>
            <div class="campaign-evolution campaign-evolution-lines monthly-chart-figure">${content}</div>
        </article>
    `;
}

function dailyEvolutionHtml(rows) {
    const chartClass = dailyChartType === 'lines' ? 'campaign-evolution-lines' : 'campaign-evolution-bars';
    const content = rows.length ? (
        dailyChartType === 'lines'
            ? dailyLineChartHtml(rows)
            : dailyBarsChartHtml(rows)
    ) : '<div class="empty-state">Sin datos</div>';

    return `
        <article class="card panel campaign-chart-card campaign-chart-wide">
            <div class="panel-title compact">
                <div>
                    <h2>Evolucion diaria</h2>
                    <div class="small">Inversion diaria y leads creados</div>
                </div>
            </div>
            <div class="daily-chart-toolbar">
                <div class="daily-series-toggles">
                    ${dailySeriesDefinitions.map((series) => `
                        <button type="button" class="daily-chip ${dailyVisibleSeries.includes(series.key) ? 'is-active' : ''}" data-series="${escapeHtml(series.key)}">
                            ${dailyVisibleSeries.includes(series.key) ? '✓ ' : ''}${escapeHtml(series.label)}
                        </button>
                    `).join('')}
                </div>
                <div class="daily-type-toggle">
                    <button type="button" class="${dailyChartType === 'bars' ? 'is-active' : ''}" data-chart-type="bars">Barras</button>
                    <button type="button" class="${dailyChartType === 'lines' ? 'is-active' : ''}" data-chart-type="lines">Lineas</button>
                </div>
            </div>
            <div class="campaign-evolution ${chartClass}" style="--chart-days:${Math.max(rows.length, 1)}">${content}</div>
        </article>
    `;
}

function dailyBarsChartHtml(rows) {
    return groupedBarsChartHtml(rows, activeDailySeries(), dailyTooltip);
}

function groupedBarsChartHtml(rows, seriesDefinitions, tooltipFactory) {
    const labelEvery = axisLabelEvery(rows.length);

    return rows.map((row, index) => {
        const tooltip = tooltipFactory(row);
        const edgeClass = index === 0 ? ' is-first' : (index === rows.length - 1 ? ' is-last' : '');

        return `
            <div class="evolution-day${edgeClass}" title="${escapeHtml(tooltip)}" data-tooltip="${escapeHtml(tooltip)}">
                <div class="evolution-bars-group">
                    ${seriesDefinitions
                        .map((series) => `
                            <span class="evolution-bar ${escapeHtml(series.className)}" style="height:${barHeight(row[series.key], dailyMax(rows, series.key))}%"></span>
                        `).join('')}
                </div>
                <small>${showDateLabel(index, rows.length, labelEvery) ? escapeHtml(formatShortDate(row.date)) : ''}</small>
            </div>
        `;
    }).join('');
}

function dailyLineChartHtml(rows) {
    return genericLineChartHtml(rows, activeDailySeries(), dailyTooltip);
}

function reservationsSalesHtml(rows) {
    const content = rows.length
        ? groupedBarsChartHtml(rows, conversionSeriesDefinitions, conversionTooltip)
        : '<div class="empty-state">Sin datos</div>';
    const title = currentContext === 'tasacion' ? 'Evolucion de tasaciones y compras' : 'Evolucion de reservas y ventas';
    const subtitle = currentContext === 'tasacion'
        ? 'Tasaciones/citas y compras de leads del periodo'
        : 'Reservas y ventas de leads del periodo';

    return `
        <article class="card panel campaign-chart-card campaign-chart-wide">
            <div class="panel-title compact">
                <div>
                    <h2>${escapeHtml(title)}</h2>
                    <div class="small">${escapeHtml(subtitle)}</div>
                </div>
            </div>
            <div class="campaign-evolution campaign-evolution-bars campaign-evolution-results" style="--chart-days:${Math.max(rows.length, 1)}">${content}</div>
        </article>
    `;
}

function genericLineChartHtml(rows, seriesDefinitions, tooltipFactory, showPoints = false) {
    const width = 100;
    const chartHeight = 96;
    const labelEvery = axisLabelEvery(rows.length);
    const seriesSvg = seriesDefinitions
        .map((series) => {
            const max = dailyMax(rows, series.key);
            const coordinates = rows.map((row, index) => ({
                x: lineX(index, rows.length),
                y: lineY(row[series.key], max, chartHeight),
            }));
            const points = coordinates.map((point) => `${point.x.toFixed(2)},${point.y.toFixed(2)}`).join(' ');

            return `<polyline class="line-series ${escapeHtml(series.className)}" points="${points}" />`;
        }).join('');
    const hoverPoints = rows.map((row, index) => {
        const x = lineX(index, rows.length);
        const tooltip = tooltipFactory(row);

        return `<span class="line-hover-point" style="left:${x.toFixed(2)}%" title="${escapeHtml(tooltip)}" data-tooltip="${escapeHtml(tooltip)}"></span>`;
    }).join('');
    const labels = rows.map((row, index) => `
        ${showDateLabel(index, rows.length, labelEvery)
            ? `<span class="line-axis-label ${index === 0 ? 'is-first' : (index === rows.length - 1 ? 'is-last' : '')}" style="left:${lineX(index, rows.length).toFixed(2)}%">${escapeHtml(formatShortDate(row.date))}</span>`
            : ''}
    `).join('');

    return `
        <div class="campaign-line-chart">
            <svg viewBox="-0.75 0 ${width + 1.5} ${chartHeight}" preserveAspectRatio="none" aria-hidden="true">
                ${seriesSvg}
            </svg>
            <div class="line-point-layer">${pointLayerHtml(rows, seriesDefinitions, tooltipFactory, showPoints, chartHeight)}</div>
            <div class="line-hover-layer">${hoverPoints}</div>
            <div class="line-label-layer">${labels}</div>
        </div>
    `;
}

function pointLayerHtml(rows, seriesDefinitions, tooltipFactory, showPoints, chartHeight) {
    if (!showPoints) {
        return '';
    }

    return seriesDefinitions
        .filter((series) => !series.isCompare)
        .flatMap((series) => {
            const max = dailyMax(rows, series.key);
            return rows.map((row, index) => {
                const x = lineX(index, rows.length);
                const y = lineY(row[series.key], max, chartHeight);
                const yPercent = (y / chartHeight) * 100;
                const tooltip = tooltipFactory(row);

                return `<span class="line-point-dot ${escapeHtml(series.className)}" style="left:${x.toFixed(2)}%;top:${yPercent.toFixed(2)}%;background-image:url('${campaignPointIcon}')" aria-hidden="true"></span>`;
            });
        })
        .join('');
}

function funnelHtml(rows) {
    const max = Math.max(...rows.map((row) => Number(row.value || 0)), 0);
    const content = rows.length
        ? rows.map((row, index) => {
            const previous = index > 0 ? Number(rows[index - 1].value || 0) : null;
            const rate = previous ? Number(row.value || 0) / previous : null;
            const tooltip = `${row.label}: ${formatNumber(Number(row.value || 0))}${rate === null ? '' : `\nConversion etapa anterior: ${formatPercentRatio(rate)}`}`;

            return metricBarHtml(row.label, row.value, max, formatNumber, tooltip, true);
        })
            .join('')
        : '<div class="empty-state">Sin datos</div>';

    return `
        <article class="card panel campaign-chart-card">
            <div class="panel-title compact">
                <div>
                    <h2>Embudo</h2>
                    <div class="small">Clicks a ventas</div>
                </div>
            </div>
            <div class="campaign-bar-list campaign-funnel-list">${content}</div>
        </article>
    `;
}

function platformBarsHtml(rows) {
    const maxSpend = Math.max(...rows.map((row) => Number(row.spend || 0)), 0);
    const maxLeads = Math.max(...rows.map((row) => Number(row.leads_salesforce || 0)), 0);
    const maxSales = Math.max(...rows.map((row) => Number(row.sales || 0)), 0);
    const content = rows.length
        ? rows.map((row) => `
            <div class="platform-chart-group" title="${escapeHtml(platformTooltip(row))}" data-tooltip="${escapeHtml(platformTooltip(row))}">
                <strong>${escapeHtml(formatPlatform(row.platform))}</strong>
                ${metricBarHtml('Inversion', row.spend, maxSpend, formatMoney)}
                ${metricBarHtml('Leads SF', row.leads_salesforce, maxLeads, formatNumber)}
                ${metricBarHtml('Ventas', row.sales, maxSales, formatNumber)}
            </div>
        `).join('')
        : '<div class="empty-state">Sin datos</div>';

    return `
        <article class="card panel campaign-chart-card">
            <div class="panel-title compact">
                <div>
                    <h2>Por plataforma</h2>
                    <div class="small">Inversion, leads y ventas</div>
                </div>
            </div>
            <div class="campaign-bar-list">${content}</div>
        </article>
    `;
}

function renderPlatformComparison(rows) {
    const panel = document.getElementById('platformComparisonPanel');
    const root = document.getElementById('platformComparison');

    if (!panel || !root) {
        return;
    }

    panel.classList.toggle('is-hidden', currentContext !== 'tasacion');

    const maxSpend = Math.max(...rows.map((row) => Number(row.spend || 0)), 0);
    const maxLeads = Math.max(...rows.map((row) => Number(row.leads_salesforce || 0)), 0);
    const maxPurchases = Math.max(...rows.map((row) => Number(row.purchases || 0)), 0);

    root.innerHTML = rows.length
        ? rows.map((row) => `
            <div class="platform-chart-group" title="${escapeHtml(platformComparisonTooltip(row))}" data-tooltip="${escapeHtml(platformComparisonTooltip(row))}">
                <strong>${escapeHtml(formatPlatform(row.platform))}</strong>
                ${metricBarHtml('Inversion', row.spend, maxSpend, formatMoney)}
                ${metricBarHtml('Leads', row.leads_salesforce, maxLeads, formatNumber)}
                ${metricBarHtml('Compras', row.purchases, maxPurchases, formatNumber)}
                ${metricBarHtml('Coste por compra', row.cost_per_purchase, Math.max(...rows.map((item) => Number(item.cost_per_purchase || 0)), 0), formatMoney)}
            </div>
        `).join('')
        : '<div class="empty-state">Sin datos</div>';
}

function renderReviewCampaigns(rows) {
    const root = document.getElementById('reviewCampaigns');

    if (!root) {
        return;
    }

    root.innerHTML = rows.length
        ? rows.map((row) => `
            <div class="portal-row">
                <span>
                    ${escapeHtml(row.campaign || row.campaign_name || '-')}
                    <small>${escapeHtml(formatPlatform(row.platform))} · ${escapeHtml(row.detail || row.reason || '')}</small>
                </span>
                <strong>${escapeHtml(row.metric || '')}: ${escapeHtml(formatReviewValue(row))}</strong>
            </div>
        `).join('')
        : '<div class="empty-state">Sin datos</div>';
}

function metricBarHtml(label, value, max, formatter, tooltip = null, useLogScale = false) {
    return `
        <div class="campaign-metric-bar" ${tooltip ? `title="${escapeHtml(tooltip)}" data-tooltip="${escapeHtml(tooltip)}"` : ''}>
            <div>
                <span>${escapeHtml(label)}</span>
                <strong>${escapeHtml(formatter(value))}</strong>
            </div>
            <i><b style="width:${useLogScale ? logBarWidth(value, max) : barWidth(value, max)}%"></b></i>
        </div>
    `;
}

function renderDiagnostics(diagnostics) {
    const root = document.getElementById('campaignDiagnostics');
    if (!root) {
        return;
    }

    const items = [
        ['Ultima sync Meta', formatDateTime(diagnostics.last_meta_sync)],
        ['Ultima sync Google Ads', formatDateTime(diagnostics.last_google_sync)],
        ['Ultima atribucion', formatDateTime(diagnostics.last_attribution_build)],
        ['Filas Meta', formatNumber(diagnostics.meta_metric_rows)],
        ['Filas Google Ads', formatNumber(diagnostics.google_metric_rows)],
        ['Campanas plataforma', formatNumber(diagnostics.platform_campaigns)],
        ['Procedencias excluidas', formatNumber(diagnostics.salesforce_origins)],
        ['Salesforce sin inversion / procedencia sin coste', formatNumber(diagnostics.campaigns_salesforce_without_spend)],
        ['Cruzadas por ID', formatNumber(diagnostics.campaigns_matched_by_id)],
        ['Cruzadas por nombre', formatNumber(diagnostics.campaigns_matched_by_name)],
        ['Leads atribuibles Salesforce', formatNumber(diagnostics.salesforce_leads_with_campaign_period)],
        ['Candidatos validos', formatNumber(diagnostics.valid_candidate_leads)],
        ['Atribuciones', formatNumber(diagnostics.built_attributions)],
        ['Inversion sin leads', formatNumber(diagnostics.campaigns_spend_without_salesforce_leads)],
        ['Leads en campanas plataforma', formatNumber(diagnostics.leads_platform_campaigns)],
        ['Leads en procedencias Salesforce', formatNumber(diagnostics.leads_salesforce_origins)],
        ['Ventas atribuidas', formatNumber(diagnostics.attributed_sales)],
        ['Ventas con importe disponible', formatNumber(diagnostics.sales_with_amount_available)],
        ['Oportunidades CV firmado', formatNumber(diagnostics.opportunities_cv_signed)],
        ['Ventas atribuidas con amount > 0', formatNumber(diagnostics.attributed_sales_with_amount)],
        ['Suma amount ventas atribuidas', formatMoney(diagnostics.attributed_sales_amount_sum)],
        ['Ventas con importe total > 0', formatNumber(diagnostics.sales_with_opo_for_importe_total)],
        ['Suma importe total ventas', formatMoney(diagnostics.sum_opo_for_importe_total_sales)],
        ['Ventas con Amount > 0', formatNumber(diagnostics.sales_with_amount)],
        ['Suma Amount ventas', formatMoney(diagnostics.sum_amount_sales)],
        ['Estado importe total', amountFieldStatusLabel(diagnostics.opo_for_importe_total_field_status || diagnostics.amount_field_status)],
        ['Estado Amount fallback', amountFieldStatusLabel(diagnostics.amount_fallback_field_status)],
        ['Campo importe usado', saleAmountFieldUsedLabel(diagnostics.sale_amount_field_used)],
        ['Candidatos con campana adquirida', formatNumber(diagnostics.candidates_with_campaign_acquired)],
        ['Candidatos solo fuente/medio', formatNumber(diagnostics.candidates_only_source_medium)],
        ['Candidatos con acquired_id', formatNumber(diagnostics.candidates_with_acquired_id)],
        ['Candidatos con content_acquired', formatNumber(diagnostics.candidates_with_content_acquired)],
        ['Match ad_id', formatNumber(diagnostics.match_ad_id)],
        ['Match adset/adgroup', formatNumber(diagnostics.match_adset_or_adgroup)],
        ['Match campaign_id', formatNumber(diagnostics.match_campaign_id)],
        ['Match nombre exacto', formatNumber(diagnostics.match_campaign_name_exact)],
        ['Match nombre flexible', formatNumber(diagnostics.match_campaign_name_flexible)],
        ['Salesforce-only por campana', formatNumber(diagnostics.salesforce_only_by_campaign)],
        ['Salesforce-only por procedencia', formatNumber(diagnostics.salesforce_only_by_origin)],
    ];

    root.innerHTML = `
        <div class="diagnostic-note">
            Las procedencias Salesforce se recogen para analisis de tracking, pero no forman parte de la tabla principal de campanas reales.
        </div>
        ${items.map(([label, value]) => `
            <div class="diagnostic-item">
                <span>${escapeHtml(label)}</span>
                <strong>${escapeHtml(value)}</strong>
            </div>
        `).join('')}
    `;
}

function renderRankings(rankings) {
    const root = document.getElementById('rankingsGrid');
    const groups = rankingDefinitions
        .filter((definition) => visibleRankings.includes(definition.key) && rankingAllowedInContext(definition.key))
        .map((definition) => [
            definition.title,
            rankings[definition.key] || [],
            definition.metric,
            definition.formatter,
        ]);

    root.innerHTML = '';
    groups.forEach(([title, rows, key, formatter]) => {
        const items = rows.length
            ? rows.map((row) => `
                <div class="portal-row">
                    <span>${escapeHtml(campaignLabel(row))}<small>${escapeHtml(formatPlatform(row.platform))}</small></span>
                    <strong>${escapeHtml(formatter(row[key]))}</strong>
                </div>
            `).join('')
            : '<div class="empty-state">Sin datos</div>';

        root.insertAdjacentHTML('beforeend', `
            <article class="priority-item">
                <div class="priority-head"><b>${escapeHtml(title)}</b></div>
                <div class="portal-list">${items}</div>
            </article>
        `);
    });
}

function rankingAllowedInContext(key) {
    const tasacionHidden = ['top_reservations', 'top_sales', 'top_sale_amount', 'best_roas', 'best_cost_per_sale', 'worst_cost_per_sale', 'many_leads_few_sales'];
    const ventaHidden = ['top_purchases', 'best_cost_per_purchase', 'best_lead_to_purchase', 'many_leads_few_purchases'];

    return currentContext === 'tasacion'
        ? !tasacionHidden.includes(key)
        : !ventaHidden.includes(key);
}

function renderCampaignTables(rows) {
    const root = document.getElementById('campaignTables');
    if (!root) {
        return;
    }

    const ventaRows = rows.filter((row) => row.campaign_type === 'venta');
    const tasacionRows = rows.filter((row) => row.campaign_type === 'tasacion');

    root.innerHTML = [
        renderCampaignTable('venta', 'Campañas de venta', 'Inversión y resultados de campañas orientadas a venta', ventaRows),
        renderCampaignTable('tasacion', 'Campañas de tasación', 'Inversión y resultados de campañas orientadas a tasación', tasacionRows),
    ].join('');

    applyColumnVisibility();
    syncCampaignTableScrollWidth();
}

function renderCampaignTable(tableType, title, subtitle, rows) {
    const columns = activeColumns(tableType);
    const sortedRows = sortedCampaignRows(rows, tableType);
    const emptyColspan = Math.max(columns.length, 1);
    const headers = columns.map((column) => `
        <th data-column="${escapeHtml(column.key)}" ${column.numeric ? 'class="num"' : ''} data-sortable="true" data-key="${escapeHtml(column.key)}">
            ${escapeHtml(column.label)}
        </th>
    `).join('');
    const body = sortedRows.length
        ? sortedRows.map((row) => `
            <tr>
                ${columns.map((column) => {
                    const formatter = column.formatter || ((value) => value);
                    return cellHtml([
                        formatter(row[column.key], row),
                        column.numeric,
                        row[column.key],
                        column.key,
                    ]);
                }).join('')}
            </tr>
        `).join('')
        : `<tr><td colspan="${emptyColspan}">No hay campañas de ${tableType === 'venta' ? 'venta' : 'tasación'} para los filtros seleccionados.</td></tr>`;

    return `
        <article class="card panel campaign-table-card">
            <div class="panel-title compact">
                <div>
                    <h2>${escapeHtml(title)}</h2>
                    <div class="small">${escapeHtml(subtitle)}</div>
                </div>
            </div>
            <div class="table-scroll-proxy" data-table-scroll-proxy="${escapeHtml(tableType)}"><div></div></div>
            <div class="table-wrap" data-table-wrap="${escapeHtml(tableType)}">
                <table data-table-type="${escapeHtml(tableType)}">
                    <thead>
                        <tr>${headers}</tr>
                    </thead>
                    <tbody>
                        ${body}
                    </tbody>
                </table>
            </div>
        </article>
    `;
}

function cellHtml([value, numeric, sortValue, columnKey]) {
    const className = numeric ? ' class="num"' : '';
    const sortAttr = sortValue === undefined ? '' : ` data-sort-value="${escapeHtml(sortValue ?? '')}"`;
    const columnAttr = columnKey ? ` data-column="${escapeHtml(columnKey)}"` : '';

    return `<td${className}${columnAttr}${sortAttr}>${escapeHtml(value ?? '-')}</td>`;
}

function sortedCampaignRows(rows, tableType) {
    const sortState = tableSort[tableType] || { key: 'spend', direction: 'desc' };
    const direction = sortState.direction === 'asc' ? 1 : -1;

    return [...rows].sort((a, b) => {
        const left = a[sortState.key];
        const right = b[sortState.key];

        if (left === null || left === undefined || left === '') {
            return 1;
        }

        if (right === null || right === undefined || right === '') {
            return -1;
        }

        if (typeof left === 'number' || typeof right === 'number') {
            return (Number(left) - Number(right)) * direction;
        }

        return String(left).localeCompare(String(right), 'es', { sensitivity: 'base' }) * direction;
    });
}

function renderFilterOptions(filters, rows = []) {
    populatePlatformSelect(filters.platforms || []);
    populateSelect('campaignStatus', filters.campaign_statuses || [], 'Todas');
    populateSelect('classification', filters.classifications || [], 'Todas');
    renderCampaignNameChecklist(rows);
}

function populatePlatformSelect(options) {
    const select = document.getElementById('platform');

    if (!select) {
        return;
    }

    const current = select.value || '';
    const filtered = (options || []).filter((option) => {
        const value = typeof option === 'object' ? option.value : option;
        return ['google_ads', 'meta'].includes(value);
    });

    select.innerHTML = '<option value="">Todas</option>';
    filtered.forEach((option) => {
        const value = typeof option === 'object' ? option.value : option;
        const label = typeof option === 'object' ? option.label : option;
        const platformLabel = formatPlatform(value);
        const display = label ? platformLabel : platformLabel;

        select.insertAdjacentHTML('beforeend', `<option value="${escapeHtml(value)}">${escapeHtml(display)}</option>`);
    });

    select.value = [...select.options].some((option) => option.value === current) ? current : '';
}

function populateSelect(id, options, emptyLabel) {
    const select = document.getElementById(id);
    const current = select?.value || '';

    if (!select) {
        return;
    }

    select.innerHTML = `<option value="">${escapeHtml(emptyLabel)}</option>`;
    options.forEach((option) => {
        const value = typeof option === 'object' ? option.value : option;
        const label = typeof option === 'object' ? option.label : option;

        if (!value) {
            return;
        }

        select.insertAdjacentHTML('beforeend', `<option value="${escapeHtml(value)}">${escapeHtml(label)}</option>`);
    });

    select.value = [...select.options].some((option) => option.value === current) ? current : '';
}

function renderCampaignNameChecklist(rows) {
    const root = document.getElementById('campaignNameChecklist');
    if (!root) {
        return;
    }

    const options = [...new Map(rows
        .filter((row) => row.campaign_name)
        .map((row) => [row.campaign_name, row]))
        .values()];

    const availableNames = options.map((row) => row.campaign_name);

    root.innerHTML = options.length
        ? options.map((row) => {
            const name = row.campaign_name;
            const checked = campaignNameSelections.includes(name);
            return `
                <label class="campaign-name-option">
                    <input type="checkbox" value="${escapeHtml(name)}" ${checked ? 'checked' : ''}>
                    <span>
                        <strong>${escapeHtml(name)}</strong>
                        <small>${escapeHtml(formatPlatform(row.platform))}</small>
                    </span>
                </label>
            `;
        }).join('')
        : '<div class="empty-state">No hay campañas disponibles</div>';

    persistCampaignNameSelections();

    root.querySelectorAll('input[type="checkbox"]').forEach((input) => {
        input.addEventListener('change', () => {
            campaignNameSelections = [...root.querySelectorAll('input[type="checkbox"]:checked')].map((item) => item.value);
            persistCampaignNameSelections();
            reloadAllData();
        });
    });
}

function currentFilters() {
    const params = new URLSearchParams();

    setParam(params, 'start_date', document.getElementById('startDate')?.value);
    setParam(params, 'end_date', document.getElementById('endDate')?.value);
    setParam(params, 'platform', document.getElementById('platform')?.value);
    setParam(params, 'context', currentContext);
    setParam(params, 'campaign_status', document.getElementById('campaignStatus')?.value);
    setParam(params, 'search', document.getElementById('campaignSearch')?.value);
    appendArrayParam(params, 'campaign_name', campaignNameSelections);
    setParam(params, 'has_opportunity', document.getElementById('hasOpportunity')?.value);
    setParam(params, 'has_reservation', document.getElementById('hasReservation')?.value);
    setParam(params, 'has_sale', document.getElementById('hasSale')?.value);
    setParam(params, 'classification', document.getElementById('classification')?.value);

    return params.toString();
}

function currentCampaignFilters() {
    const params = new URLSearchParams(currentFilters());
    params.set('context', 'all');

    return params.toString();
}

function persistCampaignNameSelections() {
    localStorage.setItem(campaignNameSelectionStorageKey, JSON.stringify(campaignNameSelections));
}

function persistMonthlySelection() {
    localStorage.setItem(monthlyMonthsStorageKey, JSON.stringify(monthlySelectedMonths));
}

function persistMonthlyMetrics() {
    localStorage.setItem(monthlyMetricsStorageKey, JSON.stringify(monthlyVisibleMetrics));
}

function persistMonthlyCompareMode() {
    localStorage.setItem(monthlyCompareStorageKey, monthlyCompareMode);
}

function selectedMonthlyRows(rows) {
    if (!rows.length) {
        monthlySelectedMonths = [];
        return rows;
    }

    const availableMonths = rows.map((row) => monthKeyFromDate(row.date));
    const savedMonths = loadSavedMonthlySelection().filter((month) => availableMonths.includes(month));

    if (!monthlySelectedMonths.length) {
        monthlySelectedMonths = savedMonths.length ? savedMonths : availableMonths.slice(-6);
        persistMonthlySelection();
    } else {
        monthlySelectedMonths = monthlySelectedMonths.filter((month) => availableMonths.includes(month));
        if (!monthlySelectedMonths.length) {
            monthlySelectedMonths = availableMonths.slice(-6);
            persistMonthlySelection();
        }
    }

    const selectedRows = rows.filter((row) => monthlySelectedMonths.includes(monthKeyFromDate(row.date)));

    if (!selectedRows.length && availableMonths.length) {
        monthlySelectedMonths = availableMonths.slice(-6);
        persistMonthlySelection();
        return rows.filter((row) => monthlySelectedMonths.includes(monthKeyFromDate(row.date)));
    }

    return selectedRows;
}

function loadSavedMonthlySelection() {
    try {
        const saved = JSON.parse(localStorage.getItem(monthlyMonthsStorageKey) || 'null');
        return Array.isArray(saved) ? saved.filter((value) => typeof value === 'string' && value.trim()) : [];
    } catch (error) {
        return [];
    }
}

function selectedMonthlySeries() {
    if (!monthlyVisibleMetrics.length) {
        monthlyVisibleMetrics = ['spend'];
    }

    return monthlyVisibleMetrics.filter((key) => monthlySeriesDefinitions.some((series) => series.key === key));
}

function buildMonthlyChartRows(allRows, selectedRows) {
    const monthMap = new Map(allRows.map((row) => [monthKeyFromDate(row.date), row]));
    const compareOffset = monthlyCompareMode === 'prev_year' ? 12 : 0;

    return selectedRows.map((row) => {
        const monthKey = monthKeyFromDate(row.date);
        const compareKey = shiftMonthKey(monthKey, compareOffset);
        const compareRow = compareOffset ? monthMap.get(compareKey) : null;
        const chartRow = {
            date: row.date,
            month_key: monthKey,
            label: monthLabel(row),
        };

        selectedMonthlySeries().forEach((metric) => {
            chartRow[metric] = Number(row[metric] || 0);
            chartRow[`${metric}_compare`] = Number(compareRow?.[metric] || 0);
        });

        return chartRow;
    });
}

function buildMonthlySeriesDefinitions(metrics) {
    return metrics.flatMap((metric) => {
        const definition = monthlySeriesDefinitions.find((series) => series.key === metric);
        if (!definition) {
            return [];
        }

        return [
            definition,
            monthlyCompareMode === 'prev_year'
                ? {
                    ...definition,
                    key: `${definition.key}_compare`,
                    label: `${definition.label} año anterior`,
                    className: `${definition.className} is-compare`,
                    isCompare: true,
                }
                : null,
        ].filter(Boolean);
    });
}

function applyMonthlyRange(value, rows) {
    const availableMonths = rows.map((row) => monthKeyFromDate(row.date));

    if (value === 'all') {
        monthlySelectedMonths = availableMonths;
        return;
    }

    const count = Number(value);
    if (!Number.isFinite(count) || count <= 0) {
        return;
    }

    monthlySelectedMonths = availableMonths.slice(-count);
}

function monthKeyFromDate(value) {
    return String(value || '').slice(0, 7);
}

function monthLabel(row) {
    const date = new Date(row.date);
    if (Number.isNaN(date.getTime())) {
        return row.label || '-';
    }

    return new Intl.DateTimeFormat('es-ES', { month: 'short', year: 'numeric' })
        .format(date)
        .replace('.', '')
        .replace(/^./, (letter) => letter.toUpperCase());
}

function shiftMonthKey(monthKey, months) {
    const [year, month] = monthKey.split('-').map((part) => Number(part));
    if (!year || !month) {
        return monthKey;
    }

    const date = new Date(year, month - 1 - months, 1);
    return [
        date.getFullYear(),
        String(date.getMonth() + 1).padStart(2, '0'),
    ].join('-');
}

function renderColumnsPopover() {
    const root = document.getElementById('columnsPopover');
    if (!root) {
        return;
    }

    root.innerHTML = columnDefinitions.map((column) => `
        <label class="column-option">
            <input type="checkbox" value="${escapeHtml(column.key)}" ${visibleColumns.includes(column.key) ? 'checked' : ''}>
            <span>${escapeHtml(column.label)}</span>
        </label>
    `).join('');

    root.querySelectorAll('input[type="checkbox"]').forEach((input) => {
        input.addEventListener('change', () => {
            visibleColumns = [...root.querySelectorAll('input[type="checkbox"]:checked')].map((item) => item.value);
            if (!visibleColumns.length) {
                visibleColumns = ['campaign'];
            }
            localStorage.setItem(columnsStorageKey, JSON.stringify(visibleColumns));
            renderCampaignTables(campaignRows);
        });
    });
}

function renderRankingsPopover() {
    const root = document.getElementById('rankingsPopover');
    if (!root) {
        return;
    }

    root.innerHTML = rankingDefinitions.map((ranking) => `
        <label class="column-option switch-option">
            <input type="checkbox" value="${escapeHtml(ranking.key)}" ${visibleRankings.includes(ranking.key) ? 'checked' : ''}>
            <span>${escapeHtml(ranking.title)}</span>
        </label>
    `).join('');

    root.querySelectorAll('input[type="checkbox"]').forEach((input) => {
        input.addEventListener('change', () => {
            visibleRankings = [...root.querySelectorAll('input[type="checkbox"]:checked')].map((item) => item.value);
            if (!visibleRankings.length) {
                visibleRankings = defaultRankingKeys();
                renderRankingsPopover();
            }
            localStorage.setItem(rankingsStorageKey, JSON.stringify(visibleRankings));
            fetchJson(`/informes/campanas/data/rankings?${currentFilters()}`)
                .then((payload) => renderRankings((payload && payload.rankings) || {}))
                .catch(showLoadError);
        });
    });
}

function loadVisibleColumns() {
    try {
        const saved = JSON.parse(localStorage.getItem(columnsStorageKey) || 'null');
        if (Array.isArray(saved) && saved.length) {
            const known = saved.filter((key) => columnDefinitions.some((column) => column.key === key));

            if (known.length) {
                return known;
            }
        }
    } catch (error) {
        return columnDefinitions.filter((column) => column.visible).map((column) => column.key);
    }

    return columnDefinitions.filter((column) => column.visible).map((column) => column.key);
}

function loadVisibleRankings() {
    try {
        const saved = JSON.parse(localStorage.getItem(rankingsStorageKey) || 'null');
        if (Array.isArray(saved) && saved.length) {
            const known = saved.filter((key) => rankingDefinitions.some((ranking) => ranking.key === key));

            if (known.length) {
                return known;
            }
        }
    } catch (error) {
        return defaultRankingKeys();
    }

    return defaultRankingKeys();
}

function loadDailyVisibleSeries() {
    const defaults = ['spend', 'leads_salesforce'];

    try {
        const saved = JSON.parse(localStorage.getItem(dailySeriesStorageKey) || 'null');
        if (Array.isArray(saved) && saved.length) {
            const known = saved.filter((key) => defaults.includes(key));

            if (known.length) {
                return known;
            }
        }
    } catch (error) {
        return defaults;
    }

    return defaults;
}

function loadDailyChartType() {
    const saved = localStorage.getItem(dailyTypeStorageKey);

    return saved === 'lines' ? 'lines' : 'bars';
}

function loadCampaignNameSelections() {
    try {
        const saved = JSON.parse(localStorage.getItem(campaignNameSelectionStorageKey) || 'null');
        return Array.isArray(saved) ? saved.filter((value) => typeof value === 'string' && value.trim()) : [];
    } catch (error) {
        return [];
    }
}

function loadMonthlyVisibleMetrics() {
    const defaults = ['spend', 'leads_salesforce'];

    try {
        const saved = JSON.parse(localStorage.getItem(monthlyMetricsStorageKey) || 'null');
        if (Array.isArray(saved) && saved.length) {
            const known = saved.filter((key) => monthlySeriesDefinitions.some((series) => series.key === key));

            if (known.length) {
                return known;
            }
        }
    } catch (error) {
        return defaults;
    }

    return defaults;
}

function loadMonthlyCompareMode() {
    const saved = localStorage.getItem(monthlyCompareStorageKey);

    return saved === 'prev_year' ? 'prev_year' : 'none';
}

function visibleMonthlyRows(rows) {
    const now = new Date();
    const year = now.getFullYear();
    const month = now.getMonth();
    const filtered = rows.filter((row) => {
        const date = new Date(row.date);
        if (Number.isNaN(date.getTime())) {
            return false;
        }

        return date.getFullYear() === year && date.getMonth() <= month;
    });

    return filtered.length ? filtered : rows;
}

function defaultRankingKeys() {
    return rankingDefinitions.filter((ranking) => ranking.visible).map((ranking) => ranking.key);
}

function activeColumns(context = currentContext) {
    return columnDefinitions.filter((column) => visibleColumns.includes(column.key) && columnAllowedInContext(column.key, context));
}

function applyColumnVisibility() {
    document.querySelectorAll('#campaignTables table[data-table-type] [data-column]').forEach((cell) => {
        const tableType = cell.closest('table')?.dataset.tableType || currentContext;
        cell.classList.toggle('is-hidden', !visibleColumns.includes(cell.dataset.column) || !columnAllowedInContext(cell.dataset.column, tableType));
    });
}

function columnAllowedInContext(key, context = currentContext) {
    const tasacionHidden = ['reservations', 'sales', 'sale_amount', 'cost_per_reservation', 'cost_per_sale', 'roas', 'estimated_roi', 'opportunity_to_reservation', 'reservation_to_sale', 'lead_to_sale'];
    const ventaHidden = ['appraisals_generated', 'purchases', 'cost_per_appraisal', 'cost_per_purchase', 'lead_to_purchase', 'opportunity_to_purchase', 'appraisal_amount'];

    return context === 'tasacion'
        ? !tasacionHidden.includes(key)
        : !ventaHidden.includes(key);
}

function syncCampaignTableScrollWidth() {
    document.querySelectorAll('#campaignTables [data-table-scroll-proxy]').forEach((proxy) => {
        const tableType = proxy.dataset.tableScrollProxy;
        const wrap = [...document.querySelectorAll('#campaignTables [data-table-wrap]')]
            .find((element) => element.dataset.tableWrap === tableType);
        const table = wrap?.querySelector('table');
        const spacer = proxy.firstElementChild;

        if (spacer && table) {
            spacer.style.width = `${table.scrollWidth}px`;
        }
    });
}

function setDefaultDates() {
    const end = new Date();
    const start = new Date();
    start.setDate(end.getDate() - 30);

    document.getElementById('startDate').value = toInputDate(start);
    document.getElementById('endDate').value = toInputDate(end);
}

function populatePeriodPreset() {
    const select = document.getElementById('periodPreset');

    if (!select) {
        return;
    }

    const options = [
        { value: 'last_30_days', label: 'Ultimos 30 dias' },
        { value: 'current_month', label: 'Mes actual' },
        { value: 'current_year', label: 'Ano actual' },
        ...monthPresetOptions(),
        { value: 'custom', label: 'Personalizado' },
    ];

    select.innerHTML = options.map((option) => `
        <option value="${escapeHtml(option.value)}" data-start="${escapeHtml(option.start || '')}" data-end="${escapeHtml(option.end || '')}">
            ${escapeHtml(option.label)}
        </option>
    `).join('');
    select.value = 'last_30_days';
}

function monthPresetOptions() {
    const formatter = new Intl.DateTimeFormat('es-ES', { month: 'long', year: 'numeric' });
    const today = new Date();
    const startOfThisMonth = new Date(today.getFullYear(), today.getMonth(), 1);
    const months = [];

    for (let index = 0; index < 12; index += 1) {
        const month = new Date(startOfThisMonth.getFullYear(), startOfThisMonth.getMonth() - index, 1);
        const end = new Date(month.getFullYear(), month.getMonth() + 1, 0);
        const label = formatter.format(month).replace(/^./, (letter) => letter.toUpperCase());

        months.push({
            value: `month_${month.getFullYear()}_${String(month.getMonth() + 1).padStart(2, '0')}`,
            label,
            start: toInputDate(month),
            end: toInputDate(end),
        });
    }

    return months;
}

function applyPeriodPreset(value) {
    const startDate = document.getElementById('startDate');
    const endDate = document.getElementById('endDate');

    if (!startDate || !endDate || value === 'custom') {
        return;
    }

    if (value === 'last_30_days') {
        setDefaultDates();
        return;
    }

    if (value === 'current_month') {
        const today = new Date();
        startDate.value = toInputDate(new Date(today.getFullYear(), today.getMonth(), 1));
        endDate.value = toInputDate(new Date(today.getFullYear(), today.getMonth() + 1, 0));
        return;
    }

    if (value === 'current_year') {
        const today = new Date();
        startDate.value = toInputDate(new Date(today.getFullYear(), 0, 1));
        endDate.value = toInputDate(today);
        return;
    }

    const selected = [...document.querySelectorAll('#periodPreset option')]
        .find((option) => option.value === value);
    if (selected?.dataset.start && selected?.dataset.end) {
        startDate.value = selected.dataset.start;
        endDate.value = selected.dataset.end;
    }
}

function setLoadingState(isLoading) {
    document.getElementById('loadingMessage')?.classList.toggle('is-hidden', !isLoading);

    if (isLoading) {
        document.getElementById('emptyMessage')?.classList.add('is-hidden');
    }
}

function showLoadError(error) {
    const empty = document.getElementById('emptyMessage');
    empty.classList.remove('is-hidden');
    empty.textContent = error?.message || 'No se han podido cargar los datos de campanas.';
}

async function fetchJson(url) {
    const response = await fetch(url, { headers: { Accept: 'application/json' } });

    if (!response.ok) {
        throw new Error(`Error cargando ${url}`);
    }

    return response.json();
}

function setParam(params, key, value) {
    if (value) {
        params.set(key, value);
    }
}

function appendArrayParam(params, key, values) {
    (values || []).forEach((value) => {
        if (value) {
            params.append(`${key}[]`, value);
        }
    });
}

function campaignLabel(row) {
    return row.display_campaign || row.campaign || row.campaign_name || row.campaign_acquired || row.campaign_id || '-';
}

function periodText(period) {
    if (!period) {
        return '-';
    }

    return `${formatDate(period.inicio)} a ${formatDate(period.fin)}`;
}

function toInputDate(date) {
    return [
        date.getFullYear(),
        String(date.getMonth() + 1).padStart(2, '0'),
        String(date.getDate()).padStart(2, '0'),
    ].join('-');
}

function formatDate(value) {
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value || '-';
    }

    return new Intl.DateTimeFormat('es-ES', { day: '2-digit', month: '2-digit', year: 'numeric' }).format(date);
}

function formatShortDate(value) {
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value || '-';
    }

    return new Intl.DateTimeFormat('es-ES', { day: '2-digit', month: '2-digit' }).format(date);
}

function formatDateTime(value) {
    const date = new Date(value);

    if (!value || Number.isNaN(date.getTime())) {
        return value || 'Sin datos';
    }

    return new Intl.DateTimeFormat('es-ES', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(date);
}

function formatNumber(value) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return '-';
    }

    return numberFormatter.format(Number(value));
}

function formatMoney(value) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return '-';
    }

    return moneyFormatter.format(Number(value));
}

function formatPercentRatio(value) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return '-';
    }

    return `${(Number(value) * 100).toFixed(1)}%`;
}

function formatMultiplier(value) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return '-';
    }

    return `${Number(value).toFixed(2)}x`;
}

function formatPlatform(value) {
    return {
        meta: 'Meta Ads',
        google_ads: 'Google Ads',
        salesforce: 'Salesforce',
    }[value] || value || '-';
}

function amountFieldStatusLabel(value) {
    return {
        missing: 'No existe columna local',
        exists_with_values: 'Existe con importes',
        exists_but_zero: 'Existe, pero ventas firmadas estan a 0',
        exists_but_no_attributed_values: 'Existe, pero sin importes atribuidos',
    }[value] || value || 'Sin datos';
}

function saleAmountFieldUsedLabel(value) {
    return {
        opo_for_importe_total: 'opo_for_importe_total',
        amount: 'amount',
        none: 'Sin importe disponible',
    }[value] || value || 'Sin datos';
}

function dailyTooltip(row) {
    return [
        `Fecha: ${formatDate(row.date)}`,
        ...activeDailySeries().map((series) => {
            const label = series.key === 'leads_salesforce' ? 'Leads Salesforce' : series.label;

            return `${label}: ${series.formatter(Number(row[series.key] || 0))}`;
        }),
    ].join('\n');
}

function conversionTooltip(row) {
    const firstLabel = currentContext === 'tasacion' ? 'Tasaciones / citas' : 'Reservas';
    const secondLabel = currentContext === 'tasacion' ? 'Compras' : 'Ventas';

    return [
        `Fecha: ${formatDate(row.date)}`,
        `${firstLabel}: ${formatNumber(Number(row.reservations || 0))}`,
        `${secondLabel}: ${formatNumber(Number(row.sales || 0))}`,
    ].join('\n');
}

function monthlyTooltip(row) {
    const definitions = selectedMonthlySeries()
        .map((key) => monthlySeriesDefinitions.find((series) => series.key === key))
        .filter(Boolean);

    const lines = [`Mes: ${row.label || formatShortDate(row.date)}`];
    definitions.forEach((series) => {
        lines.push(`${series.label}: ${series.formatter(Number(row[series.key] || 0))}`);

        if (monthlyCompareMode === 'prev_year') {
            lines.push(`${series.label} año anterior: ${series.formatter(Number(row[`${series.key}_compare`] || 0))}`);
        }
    });

    return lines.join('\n');
}

function dailyMax(rows, key) {
    return Math.max(...rows.map((row) => Number(row[key] || 0)), 0);
}

function activeDailySeries() {
    return dailySeriesDefinitions.filter((series) => dailyVisibleSeries.includes(series.key));
}

function lineX(index, total) {
    return total <= 1 ? 50 : (index / (total - 1)) * 100;
}

function lineY(value, max, chartHeight) {
    return chartHeight - pointBottom(value, max);
}

function showDateLabel(index, total, labelEvery) {
    return index === 0 || index === total - 1 || index % labelEvery === 0;
}

function axisLabelEvery(total) {
    if (total <= 14) {
        return 1;
    }

    return Math.max(2, Math.ceil(total / 10));
}

function platformTooltip(row) {
    return [
        `Plataforma: ${formatPlatform(row.platform)}`,
        `Inversion: ${formatMoney(Number(row.spend || 0))}`,
        `Leads Salesforce: ${formatNumber(Number(row.leads_salesforce || 0))}`,
        `Ventas: ${formatNumber(Number(row.sales || 0))}`,
    ].join('\n');
}

function platformComparisonTooltip(row) {
    return [
        `Plataforma: ${formatPlatform(row.platform)}`,
        `Inversion: ${formatMoney(Number(row.spend || 0))}`,
        `Leads: ${formatNumber(Number(row.leads_salesforce || 0))}`,
        `Oportunidades / citas: ${formatNumber(Number(row.opportunities || 0))}`,
        `Compras: ${formatNumber(Number(row.purchases || 0))}`,
        `Coste por lead: ${formatMoney(row.cost_per_lead)}`,
        `Coste por compra: ${formatMoney(row.cost_per_purchase)}`,
        `Conversion lead -> compra: ${formatPercentRatio(row.lead_to_purchase)}`,
    ].join('\n');
}

function formatReviewValue(row) {
    const value = Number(row.value || 0);
    return String(row.metric || '').toLowerCase().includes('inversion')
        ? formatMoney(value)
        : formatNumber(value);
}

function barWidth(value, max) {
    if (!max || Number.isNaN(Number(value))) {
        return 0;
    }

    return Math.max(4, Math.round((Number(value) / max) * 100));
}

function logBarWidth(value, max) {
    const safeValue = Math.max(Number(value) || 0, 0);
    const safeMax = Math.max(Number(max) || 0, 0);

    if (!safeMax) {
        return 0;
    }

    const scaled = Math.log10(safeValue + 1) / Math.log10(safeMax + 1);
    return Math.max(12, Math.round(scaled * 100));
}

function barHeight(value, max) {
    if (!max || Number.isNaN(Number(value))) {
        return 0;
    }

    return Math.max(6, Math.round((Number(value) / max) * 100));
}

function pointBottom(value, max) {
    if (!max || Number.isNaN(Number(value))) {
        return 4;
    }

    return Math.max(4, Math.round((Number(value) / max) * 90));
}

function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}
