const numberFormatter = new Intl.NumberFormat('es-ES');
const moneyFormatter = new Intl.NumberFormat('es-ES', { style: 'currency', currency: 'EUR' });
const columnsStorageKey = 'hrmotor_campaign_columns_v5';
const rankingsStorageKey = 'campaigns.visibleRankings.v5';
const campaignContextStorageKey = 'campaigns.selectedContext.v1';
const dailySeriesStorageKey = 'campaigns.dailyChart.visibleSeries';
const dailyTypeStorageKey = 'campaigns.dailyChart.chartType';
const monthlyMonthsStorageKey = 'campaigns.monthlyChart.months';
const monthlyMetricsStorageKey = 'campaigns.monthlyChart.metrics';
const monthlyCompareStorageKey = 'campaigns.monthlyChart.compare';
const campaignNameSelectionStorageKey = 'campaigns.campaignNames.selected';
const campaignPointIcon = '/brand/campaign-point.svg';

let campaignRows = [];
let tableSort = {
    all: { key: 'spend', direction: 'desc' },
    venta: { key: 'spend', direction: 'desc' },
    tasacion: { key: 'spend', direction: 'desc' },
    exposicion: { key: 'spend', direction: 'desc' },
    branding: { key: 'spend', direction: 'desc' },
    otros: { key: 'spend', direction: 'desc' },
};
let searchTimer = null;
let syncingTableScroll = false;
let dailyVisibleSeries = loadDailyVisibleSeries();
let dailyChartType = loadDailyChartType();
let currentCharts = {};
let currentRankings = {};
let currentContext = loadCampaignContext();
let campaignDetailsVisible = false;
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
    { key: 'purchases', label: 'Compras', formatter: formatNumber, className: 'purchases' },
    { key: 'cost_per_result', label: 'Coste por resultado', formatter: formatMoney, className: 'results' },
];

const monthlyTasacionSeriesDefinitions = [
    { key: 'spend', label: 'Inversion', formatter: formatMoney, className: 'spend' },
    { key: 'leads_salesforce', label: 'Leads', formatter: formatNumber, className: 'leads' },
    { key: 'opportunities', label: 'Oportunidades', formatter: formatNumber, className: 'opportunities' },
    { key: 'purchases', label: 'Compras', formatter: formatNumber, className: 'purchases' },
];

const rankingDefinitions = [
    { key: 'top_spend', title: 'Campanas con mas inversion', metric: 'spend', formatter: formatMoney, visible: true },
    { key: 'top_impressions', title: 'Campanas con mas impresiones', metric: 'impressions', formatter: formatNumber, visible: false },
    { key: 'top_leads_salesforce', title: 'Mas leads Salesforce', metric: 'leads_salesforce', formatter: formatNumber, visible: true },
    { key: 'top_opportunities', title: 'Mas oportunidades', metric: 'opportunities', formatter: formatNumber, visible: false },
    { key: 'top_reservations', title: 'Mas reservas', metric: 'reservations', formatter: formatNumber, visible: false },
    { key: 'top_sales', title: 'Mas ventas', metric: 'sales', formatter: formatNumber, visible: true },
    { key: 'top_purchases', title: 'Mas compras', metric: 'purchases', formatter: formatNumber, visible: false },
    { key: 'top_sale_amount', title: 'Mayor importe vendido', metric: 'sale_amount', formatter: formatMoney, visible: false },
    { key: 'best_roas', title: 'Mejor ROAS', metric: 'roas', formatter: formatMultiplier, visible: false },
    { key: 'best_ctr', title: 'Mejor CTR', metric: 'ctr', formatter: formatPercentRatio, visible: false },
    { key: 'best_cpc', title: 'Mejor CPC', metric: 'cpc', formatter: formatMoney, visible: false },
    { key: 'best_cost_per_sale', title: 'Mejor coste por venta', metric: 'cost_per_sale', formatter: formatMoney, visible: true },
    { key: 'best_cost_per_purchase', title: 'Mejor coste por compra', metric: 'cost_per_purchase', formatter: formatMoney, visible: false },
    { key: 'best_cost_per_lead', title: 'Mejor coste por lead', metric: 'cost_per_lead', formatter: formatMoney, visible: false },
    { key: 'best_cost_per_opportunity', title: 'Mejor coste por oportunidad', metric: 'cost_per_opportunity', formatter: formatMoney, visible: false },
    { key: 'best_cost_per_result', title: 'Mejor coste por resultado', metric: 'cost_per_result', formatter: formatMoney, visible: false },
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
    { key: 'campaign_status_label', label: 'Estado', visible: true },
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
    { key: 'purchases', label: 'Compras', visible: true, numeric: true, formatter: formatNumber },
    { key: 'cost_per_lead', label: 'Coste por lead', visible: true, numeric: true, formatter: formatMoney },
    { key: 'cost_per_opportunity', label: 'Coste por oportunidad', visible: true, numeric: true, formatter: formatMoney },
    { key: 'cost_per_reservation', label: 'Coste por reserva', visible: true, numeric: true, formatter: formatMoney },
    { key: 'cost_per_sale', label: 'Coste por venta', visible: true, numeric: true, formatter: formatMoney },
    { key: 'cost_per_purchase', label: 'Coste por compra', visible: true, numeric: true, formatter: formatMoney },
    { key: 'cost_per_result', label: 'Coste por resultado', visible: true, numeric: true, formatter: formatMoney },
    { key: 'result_count', label: 'Resultados', visible: true, numeric: true, formatter: formatNumber },
    { key: 'roas', label: 'ROAS', visible: true, numeric: true, formatter: formatMultiplier },
    { key: 'estimated_roi', label: 'ROI estimado', visible: true, numeric: true, formatter: formatPercentRatio },
    { key: 'account_id', label: 'Cuenta', visible: false },
    { key: 'campaign_id', label: 'Campaign ID', visible: false },
    { key: 'source_acquired', label: 'Fuente adquirida', visible: false },
    { key: 'medium_acquired', label: 'Medio adquirido', visible: false },
    { key: 'acquired_id', label: 'ID adquirido', visible: false },
    { key: 'content_acquired', label: 'Contenido adquirido', visible: false },
    { key: 'classification', label: 'Clasificacion', visible: false },
    { key: 'campaign_start_date', label: 'Fecha inicio campana', visible: false, formatter: formatDate },
    { key: 'campaign_end_date', label: 'Fecha fin campana', visible: false, formatter: formatDate },
    { key: 'last_spend_date', label: 'Ultima fecha con inversion', visible: false, formatter: formatDate },
    { key: 'appraisals_generated', label: 'Tasaciones generadas', visible: false, numeric: true, formatter: formatNumber },
    { key: 'cost_per_appraisal', label: 'Coste por tasacion', visible: false, numeric: true, formatter: formatMoney },
    { key: 'lead_to_opportunity', label: 'Lead -> oportunidad', visible: false, numeric: true, formatter: formatPercentRatio },
    { key: 'opportunity_to_reservation', label: 'Oportunidad -> reserva', visible: false, numeric: true, formatter: formatPercentRatio },
    { key: 'reservation_to_sale', label: 'Reserva -> venta', visible: false, numeric: true, formatter: formatPercentRatio },
    { key: 'lead_to_sale', label: 'Lead -> venta', visible: false, numeric: true, formatter: formatPercentRatio },
    { key: 'lead_to_purchase', label: 'Lead -> compra', visible: false, numeric: true, formatter: formatPercentRatio },
    { key: 'opportunity_to_purchase', label: 'Oportunidad -> compra', visible: false, numeric: true, formatter: formatPercentRatio },
];

let visibleColumns = loadVisibleColumns();
let visibleRankings = loadVisibleRankings(currentContext);

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
    bindCampaignTableActions();
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
                persistCampaignContext();
                syncCampaignTypeSelect();
                visibleRankings = loadVisibleRankings(currentContext);
                renderRankingsPopover();
                await reloadAllData();
            }

            syncCampaignTableScrollWidth();
        });
    });
}

function bindResetFilters() {
    document.getElementById('resetFilters')?.addEventListener('click', async () => {
        [
            'campaignType',
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
        currentContext = 'all';
        persistCampaignContext();
        syncCampaignTypeSelect();
        visibleRankings = loadVisibleRankings(currentContext);
        renderRankingsPopover();
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
        'campaignType',
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

    document.getElementById('campaignType')?.addEventListener('change', async (event) => {
        currentContext = event.target.value || 'all';
        persistCampaignContext();
        visibleRankings = loadVisibleRankings(currentContext);
        renderRankingsPopover();
        syncContextTabs();
        await reloadAllData();
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
    document.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) {
            return;
        }

        const toggle = target.closest('#columnsToggle');
        const popover = document.getElementById('columnsPopover');

        if (toggle && popover) {
            popover.classList.toggle('is-hidden');
            return;
        }

        if (popover && !target.closest('.columns-menu')) {
            popover.classList.add('is-hidden');
        }
    });

    document.addEventListener('change', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLInputElement)) {
            return;
        }

        if (!target.matches('[data-column-toggle]')) {
            return;
        }

        const column = target.dataset.columnToggle;
        if (!column) {
            return;
        }

        if (target.checked) {
            if (!visibleColumns.includes(column)) {
                visibleColumns = [...visibleColumns, column];
            }
        } else if (visibleColumns.length > 1) {
            visibleColumns = visibleColumns.filter((item) => item !== column);
        } else {
            target.checked = true;
            return;
        }

        persistVisibleColumns();
        applyColumnVisibility();
        syncCampaignTableScrollWidth();
    });
}

function bindRankingSettings() {
    renderRankingsPopover();
    document.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) {
            return;
        }

        const toggle = target.closest('#rankingsToggle');
        const popover = document.getElementById('rankingsPopover');

        if (toggle && popover) {
            popover.classList.toggle('is-hidden');
            return;
        }

        if (popover && !target.closest('.ranking-settings')) {
            popover.classList.add('is-hidden');
        }
    });

    document.addEventListener('change', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLInputElement) || !target.matches('[data-ranking-toggle]')) {
            return;
        }

        const ranking = target.dataset.rankingToggle;
        if (!ranking) {
            return;
        }

        if (target.checked) {
            if (!visibleRankings.includes(ranking)) {
                visibleRankings = [...visibleRankings, ranking];
            }
        } else if (visibleRankings.length > 1) {
            visibleRankings = visibleRankings.filter((item) => item !== ranking);
        } else {
            target.checked = true;
            return;
        }

        persistVisibleRankings();
        renderRankings(currentRankings || {});
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
        currentRankings = (rankings && rankings.rankings) || {};
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
            ? formatDateTime(data.datos_actualizados)
            : 'Pendiente';
    }
    document.getElementById('periodLabel').textContent = periodText(data.periodo_actual);
    const pivotLabel = document.getElementById('pivotLabel');
    if (pivotLabel) {
        pivotLabel.textContent = 'Lead.CreatedDate';
    }
    const contextLabel = document.getElementById('selectedContextLabel');
    if (contextLabel) {
        contextLabel.textContent = data.selected_context_label || contextLabel.textContent || '-';
    }

    const empty = document.getElementById('emptyMessage');
    empty.classList.toggle('is-hidden', Boolean(data.ok));

    renderWarnings(data.warnings || []);
    currentContext = data.selected_context || currentContext;
    syncCampaignTypeSelect();
    syncContextTabs();
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

function renderKpis(kpis, context = 'all') {
    const root = document.getElementById('summaryKpis');

    if (!root) {
        return;
    }

    root.innerHTML = '';
    kpiCardsForContext(context, kpis).forEach((card) => {
        root.insertAdjacentHTML('beforeend', `
            <div class="card kpi campaign-kpi">
                <div>
                    <div class="kpi-label">${escapeHtml(card.label)}</div>
                    <div class="kpi-value">${escapeHtml(card.value)}</div>
                    <div class="kpi-hint">${escapeHtml(card.hint || '')}</div>
                </div>
            </div>
        `);
    });
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

function normalizeCampaignContext(value) {
    const key = String(value || '').toLowerCase();
    return ['all', 'venta', 'tasacion', 'exposicion', 'branding', 'otros'].includes(key) ? key : 'all';
}

function persistCampaignContext() {
    localStorage.setItem(campaignContextStorageKey, currentContext);
}

function syncCampaignTypeSelect() {
    const select = document.getElementById('campaignType');
    if (!select) {
        return;
    }

    select.value = normalizeCampaignContext(currentContext);
}

function syncContextTabs() {
    document.querySelectorAll('.main-tab[data-context]').forEach((button) => {
        button.classList.toggle('active', normalizeCampaignContext(button.dataset.context) === normalizeCampaignContext(currentContext));
    });
}

function loadVisibleRankings(context = currentContext) {
    try {
        const saved = JSON.parse(localStorage.getItem(rankingsStorageKey) || 'null');
        if (Array.isArray(saved) && saved.length) {
            const known = saved.filter((key) => rankingDefinitions.some((ranking) => ranking.key === key));
            if (known.length) {
                return known.filter((key) => rankingAllowedInContext(key, context));
            }
        }
    } catch (error) {
        return defaultRankingKeys(context);
    }

    return defaultRankingKeys(context);
}

function defaultRankingKeys(context = currentContext) {
    switch (normalizeCampaignContext(context)) {
        case 'venta':
            return ['top_spend', 'top_sales', 'best_cost_per_sale', 'review_campaigns'];
        case 'tasacion':
            return ['top_spend', 'top_purchases', 'best_cost_per_purchase', 'review_campaigns'];
        case 'exposicion':
            return ['top_spend', 'top_leads_salesforce', 'best_cost_per_opportunity', 'review_campaigns'];
        case 'branding':
            return ['top_spend', 'top_impressions', 'best_cpc', 'best_ctr', 'review_campaigns'];
        case 'otros':
        case 'all':
        default:
            return ['top_spend', 'top_leads_salesforce', 'best_cost_per_result', 'review_campaigns'];
    }
}

function rankingAllowedInContext(key, context = currentContext) {
    const normalized = normalizeCampaignContext(context);

    const hidden = {
        venta: ['top_purchases', 'best_cost_per_purchase', 'best_lead_to_purchase', 'top_impressions', 'best_cost_per_result'],
        tasacion: ['top_sales', 'best_cost_per_sale', 'best_roas', 'worst_cost_per_sale', 'top_impressions', 'best_cost_per_result'],
        exposicion: ['top_sales', 'top_purchases', 'best_cost_per_sale', 'best_cost_per_purchase', 'best_roas', 'worst_cost_per_sale'],
        branding: ['top_sales', 'top_purchases', 'best_cost_per_sale', 'best_cost_per_purchase', 'best_cost_per_opportunity', 'best_lead_to_purchase', 'worst_cost_per_sale'],
        all: ['best_roas', 'worst_cost_per_sale', 'many_leads_few_sales', 'many_leads_few_purchases'],
        otros: ['top_sales', 'top_purchases', 'best_cost_per_sale', 'best_cost_per_purchase', 'best_lead_to_purchase', 'worst_cost_per_sale'],
    };

    return !(hidden[normalized] || []).includes(key);
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
                    <h2>Evolucion mensual</h2>
                    <div class="small">Ultimos meses seleccionados. La comparacion con el ano anterior esta desactivada por defecto.</div>
                </div>
            </div>
            <div class="monthly-chart-toolbar">
                <div class="monthly-chart-ranges">
                    <button type="button" class="monthly-pill" data-month-range="3">Ultimos 3 meses</button>
                    <button type="button" class="monthly-pill" data-month-range="6">Ultimos 6 meses</button>
                    <button type="button" class="monthly-pill" data-month-range="12">Ultimos 12 meses</button>
                    <button type="button" class="monthly-pill" data-month-range="all">Todo</button>
                </div>
                <div class="monthly-chart-compare">
                    <button type="button" class="monthly-pill ${monthlyCompareMode === 'none' ? 'is-active' : ''}" data-compare-mode="none">Sin comparacion</button>
                    <button type="button" class="monthly-pill ${monthlyCompareMode === 'prev_year' ? 'is-active' : ''}" data-compare-mode="prev_year">Ano anterior</button>
                </div>
            </div>
            <div class="monthly-chart-filters">
                <div>
                    <div class="monthly-filter-label">Metricas</div>
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

function visibleMonthlyRows(rows) {
    return rows || [];
}

function funnelHtml(rows) {
    const context = normalizeCampaignContext(currentContext);
    const steps = funnelStepsForContext(context, rows || []);
    const max = Math.max(...steps.map((row) => Number(row.value || 0)), 0);
    const content = steps.length
        ? steps.map((row, index) => {
            const previous = index > 0 ? Number(steps[index - 1].value || 0) : null;
            const rate = previous ? Number(row.value || 0) / previous : null;
            const tooltip = `${row.label}: ${formatNumber(Number(row.value || 0))}${rate === null ? '' : `\nConversion etapa anterior: ${formatPercentRatio(rate)}`}`;

            return metricBarHtml(row.label, row.value, max, formatNumber, tooltip, true);
        }).join('')
        : '<div class="empty-state">Sin datos</div>';

    return `
        <article class="card panel campaign-chart-card">
            <div class="panel-title compact">
                <div>
                    <h2>Embudo simple</h2>
                    <div class="small">${escapeHtml(funnelSubtitleForContext(context))}</div>
                </div>
            </div>
            <div class="campaign-bar-list campaign-funnel-list">${content}</div>
        </article>
    `;
}

function funnelStepsForContext(context, rows) {
    const totals = {
        impressions: rows.reduce((sum, row) => sum + Number(row.impressions || 0), 0),
        clicks: rows.reduce((sum, row) => sum + Number(row.clicks || 0), 0),
        leads_salesforce: rows.reduce((sum, row) => sum + Number(row.leads_salesforce || 0), 0),
        opportunities: rows.reduce((sum, row) => sum + Number(row.opportunities || 0), 0),
        reservations: rows.reduce((sum, row) => sum + Number(row.reservations || 0), 0),
        sales: rows.reduce((sum, row) => sum + Number(row.sales || 0), 0),
        purchases: rows.reduce((sum, row) => sum + Number(row.purchases || 0), 0),
    };

    switch (context) {
        case 'tasacion':
            return [
                { label: 'Clicks', value: totals.clicks },
                { label: 'Leads Salesforce', value: totals.leads_salesforce },
                { label: 'Oportunidades', value: totals.opportunities },
                { label: 'Compras', value: totals.purchases },
            ];
        case 'exposicion':
            return [
                { label: 'Clicks', value: totals.clicks },
                { label: 'Leads Salesforce', value: totals.leads_salesforce },
                { label: 'Oportunidades', value: totals.opportunities },
            ];
        case 'branding':
            return [
                { label: 'Clicks', value: totals.clicks },
                { label: 'Leads Salesforce', value: totals.leads_salesforce },
                { label: 'Oportunidades', value: totals.opportunities },
            ];
        case 'otros':
        case 'all':
        default:
            return [
                { label: 'Clicks', value: totals.clicks },
                { label: 'Leads Salesforce', value: totals.leads_salesforce },
                { label: 'Oportunidades', value: totals.opportunities },
                { label: 'Resultados', value: totals.sales + totals.purchases + totals.opportunities + totals.leads_salesforce },
            ];
    }
}

function funnelSubtitleForContext(context) {
    switch (context) {
        case 'tasacion':
            return 'Clicks, leads Salesforce, oportunidades y compras';
        case 'exposicion':
            return 'Clicks, leads Salesforce y oportunidades';
        case 'branding':
            return 'Clicks, leads Salesforce y oportunidades';
        case 'otros':
        case 'all':
        default:
            return 'Clicks, leads Salesforce, oportunidades y resultado agregado';
    }
}

function renderPlatformComparison(rows) {
    const panel = document.getElementById('platformComparisonPanel');
    const root = document.getElementById('platformComparison');

    if (!panel || !root) {
        return;
    }

    const ordered = ['google_ads', 'meta'].map((platform) => {
        const match = (rows || []).find((row) => row.platform === platform);
        return match || emptyPlatformComparisonRow(platform);
    });

    const maxSpend = Math.max(...ordered.map((row) => Number(row.spend || 0)), 0);
    const maxLeads = Math.max(...ordered.map((row) => Number(row.leads_salesforce || 0)), 0);
    const maxOpportunities = Math.max(...ordered.map((row) => Number(row.opportunities || 0)), 0);
    const maxResult = Math.max(...ordered.map((row) => Number(row.result_count || 0)), 0);

    panel.classList.remove('is-hidden');
    root.innerHTML = ordered.map((row) => platformComparisonCardHtml(row, maxSpend, maxLeads, maxOpportunities, maxResult)).join('');
}

function emptyPlatformComparisonRow(platform) {
    return {
        platform,
        spend: 0,
        leads_salesforce: 0,
        opportunities: 0,
        reservations: 0,
        sales: 0,
        purchases: 0,
        result_count: 0,
        cost_per_lead: null,
        cost_per_opportunity: null,
        cost_per_purchase: null,
        cost_per_sale: null,
        cost_per_result: null,
    };
}

function platformComparisonCardHtml(row, maxSpend, maxLeads, maxOpportunities, maxResult) {
    const context = normalizeCampaignContext(currentContext);
    const resultLabel = platformResultLabelForContext(context);
    const resultValue = platformResultValueForContext(row, context);
    const resultCost = platformResultCostForContext(row, context);
    const costLabel = {
        venta: 'Coste por venta',
        tasacion: 'Coste por compra',
        exposicion: 'Coste por oportunidad',
        branding: 'Coste por lead',
        otros: 'Coste por resultado',
        all: 'Coste por resultado',
    }[context] || 'Coste por resultado';

    return `
        <article class="card platform-comparison-card">
            <div class="platform-comparison-head">
                <strong>${escapeHtml(formatPlatform(row.platform))}</strong>
                <span>${escapeHtml(platformComparisonSubtitleForContext(context))}</span>
            </div>
            <div class="platform-comparison-metrics">
                ${metricBarHtml('Inversion', row.spend, maxSpend, formatMoney)}
                ${metricBarHtml('Leads Salesforce', row.leads_salesforce, maxLeads, formatNumber)}
                ${metricBarHtml('Oportunidades', row.opportunities, maxOpportunities, formatNumber)}
                ${metricBarHtml(resultLabel, resultValue, maxResult, formatNumber)}
                <div class="campaign-metric-bar campaign-metric-text">
                    <div>
                        <span>${escapeHtml(costLabel)}</span>
                        <strong>${escapeHtml(formatMoney(resultCost))}</strong>
                    </div>
                </div>
            </div>
        </article>
    `;
}

function platformComparisonSubtitleForContext(context) {
    switch (context) {
        case 'tasacion':
            return 'Google Ads y Meta Ads con foco en compras';
        case 'exposicion':
            return 'Google Ads y Meta Ads con foco en oportunidades';
        case 'branding':
            return 'Google Ads y Meta Ads con foco en leads';
        case 'otros':
        case 'all':
        default:
            return 'Google Ads y Meta Ads con resultados agregados';
    }
}

function platformResultLabelForContext(context) {
    switch (context) {
        case 'venta':
            return 'Ventas';
        case 'tasacion':
            return 'Compras';
        case 'exposicion':
            return 'Oportunidades';
        case 'branding':
            return 'Leads Salesforce';
        case 'otros':
        case 'all':
        default:
            return 'Resultados';
    }
}

function platformResultValueForContext(row, context) {
    switch (context) {
        case 'venta':
            return Number(row.sales || 0);
        case 'tasacion':
            return Number(row.purchases || 0);
        case 'exposicion':
            return Number(row.opportunities || 0);
        case 'branding':
            return Number(row.leads_salesforce || 0);
        case 'otros':
        case 'all':
        default:
            return Number(row.result_count || 0);
    }
}

function platformResultCostForContext(row, context) {
    switch (context) {
        case 'venta':
            return row.cost_per_sale;
        case 'tasacion':
            return row.cost_per_purchase;
        case 'exposicion':
            return row.cost_per_opportunity;
        case 'branding':
            return row.cost_per_lead;
        case 'otros':
        case 'all':
        default:
            return row.cost_per_result;
    }
}

function renderReviewCampaigns(rows) {
    const root = document.getElementById('reviewCampaigns');

    if (!root) {
        return;
    }

    root.innerHTML = rows.length
        ? rows.slice(0, 8).map((row) => `
            <article class="review-card">
                <div class="review-card-copy">
                    <strong>${escapeHtml(row.campaign || row.campaign_name || '-')}</strong>
                    <small>${escapeHtml(formatPlatform(row.platform))} · ${escapeHtml(row.detail || row.reason || '')}</small>
                </div>
                <div class="review-card-metric">
                    <span>${escapeHtml(row.metric || 'Resultado')}</span>
                    <strong>${escapeHtml(formatReviewValue(row))}</strong>
                </div>
            </article>
        `).join('')
        : '<div class="empty-state">Sin campañas a revisar</div>';
}

function loadCampaignContext() {
    try {
        return normalizeCampaignContext(localStorage.getItem(campaignContextStorageKey) || 'all');
    } catch (error) {
        return 'all';
    }
}

function monthlyResultCountForRow(row) {
    const context = normalizeCampaignContext(currentContext);

    switch (context) {
        case 'venta':
            return Number(row.sales || 0);
        case 'tasacion':
            return Number(row.purchases || 0);
        case 'exposicion':
            return Number(row.opportunities || 0);
        case 'branding':
            return Number(row.leads_salesforce || 0);
        case 'otros':
        case 'all':
        default:
            return Number(row.sales || 0)
                + Number(row.purchases || 0)
                + Number(row.opportunities || 0)
                + Number(row.leads_salesforce || 0);
    }
}

function monthlyCostPerResultForRow(row) {
    const resultCount = monthlyResultCountForRow(row);
    return resultCount > 0 ? Number(row.spend || 0) / resultCount : 0;
}

function bindCampaignTableActions() {
    document.getElementById('campaignTables')?.addEventListener('click', (event) => {
        const button = event.target.closest('[data-toggle-campaign-detail]');
        if (!button) {
            return;
        }

        campaignDetailsVisible = !campaignDetailsVisible;
        renderCampaignTables(campaignRows);
    });
}

function kpiCardsForContext(context, kpis) {
    const normalized = normalizeCampaignContext(context);
    const totalResults = formatNumber(kpis.result_count);

    switch (normalized) {
        case 'venta':
            return [
                { label: 'Inversion', value: formatMoney(kpis.spend), hint: `CPC ${formatMoney(kpis.cpc)}` },
                { label: 'Leads Salesforce', value: formatNumber(kpis.leads_salesforce), hint: `CPL ${formatMoney(kpis.cost_per_lead)}` },
                { label: 'Oportunidades', value: formatNumber(kpis.opportunities), hint: `CPO ${formatMoney(kpis.cost_per_opportunity)}` },
                { label: 'Reservas', value: formatNumber(kpis.reservations), hint: `CPR ${formatMoney(kpis.cost_per_reservation)}` },
                { label: 'Ventas', value: formatNumber(kpis.sales), hint: `CPV ${formatMoney(kpis.cost_per_sale)}` },
                { label: 'Importe vendido', value: formatMoney(kpis.sale_amount), hint: `ROAS ${formatMultiplier(kpis.roas)} · ROI ${formatPercentRatio(kpis.estimated_roi)}` },
                { label: 'Coste por venta', value: formatMoney(kpis.cost_per_sale), hint: `Resultados ${formatNumber(kpis.sales)}` },
                { label: 'ROAS / ROI', value: formatMultiplier(kpis.roas), hint: `ROI ${formatPercentRatio(kpis.estimated_roi)}` },
            ];
        case 'tasacion':
            return [
                { label: 'Inversion', value: formatMoney(kpis.spend), hint: `CPC ${formatMoney(kpis.cpc)}` },
                { label: 'Leads Salesforce', value: formatNumber(kpis.leads_salesforce), hint: `CPL ${formatMoney(kpis.cost_per_lead)}` },
                { label: 'Oportunidades', value: formatNumber(kpis.opportunities), hint: `CPO ${formatMoney(kpis.cost_per_opportunity)}` },
                { label: 'Compras', value: formatNumber(kpis.purchases), hint: `CP compra ${formatMoney(kpis.cost_per_purchase)}` },
                { label: 'Coste por lead', value: formatMoney(kpis.cost_per_lead), hint: `Leads ${formatNumber(kpis.leads_salesforce)}` },
                { label: 'Coste por oportunidad', value: formatMoney(kpis.cost_per_opportunity), hint: `Oportunidades ${formatNumber(kpis.opportunities)}` },
                { label: 'Coste por compra', value: formatMoney(kpis.cost_per_purchase), hint: `Compras ${formatNumber(kpis.purchases)}` },
                { label: 'Conversion lead -> compra', value: formatPercentRatio(kpis.lead_to_purchase), hint: `Oportunidad -> compra ${formatPercentRatio(kpis.opportunity_to_purchase)}` },
            ];
        case 'exposicion':
            return [
                { label: 'Inversion', value: formatMoney(kpis.spend), hint: `CPC ${formatMoney(kpis.cpc)}` },
                { label: 'Impresiones', value: formatNumber(kpis.impressions), hint: `CTR ${formatPercentRatio(kpis.ctr)}` },
                { label: 'Clicks', value: formatNumber(kpis.clicks), hint: `Leads SF ${formatNumber(kpis.leads_salesforce)}` },
                { label: 'Leads Salesforce', value: formatNumber(kpis.leads_salesforce), hint: `CPL ${formatMoney(kpis.cost_per_lead)}` },
                { label: 'Oportunidades', value: formatNumber(kpis.opportunities), hint: `CPO ${formatMoney(kpis.cost_per_opportunity)}` },
                { label: 'Coste por lead', value: formatMoney(kpis.cost_per_lead), hint: `Leads ${formatNumber(kpis.leads_salesforce)}` },
                { label: 'Coste por oportunidad', value: formatMoney(kpis.cost_per_opportunity), hint: `Oportunidades ${formatNumber(kpis.opportunities)}` },
                { label: 'CTR / CPC', value: formatPercentRatio(kpis.ctr), hint: `CPC ${formatMoney(kpis.cpc)}` },
            ];
        case 'branding':
            return [
                { label: 'Inversion', value: formatMoney(kpis.spend), hint: `CPC ${formatMoney(kpis.cpc)}` },
                { label: 'Impresiones', value: formatNumber(kpis.impressions), hint: `CTR ${formatPercentRatio(kpis.ctr)}` },
                { label: 'Clicks', value: formatNumber(kpis.clicks), hint: `Leads SF ${formatNumber(kpis.leads_salesforce)}` },
                { label: 'CTR', value: formatPercentRatio(kpis.ctr), hint: `CPC ${formatMoney(kpis.cpc)}` },
                { label: 'CPC', value: formatMoney(kpis.cpc), hint: `Clicks ${formatNumber(kpis.clicks)}` },
                { label: 'Leads Salesforce', value: formatNumber(kpis.leads_salesforce), hint: `CPL ${formatMoney(kpis.cost_per_lead)}` },
                { label: 'Coste por lead', value: formatMoney(kpis.cost_per_lead), hint: `Leads ${formatNumber(kpis.leads_salesforce)}` },
                { label: 'Oportunidades', value: formatNumber(kpis.opportunities), hint: `Total ${totalResults}` },
            ];
        case 'otros':
        case 'all':
        default:
            return [
                { label: 'Inversion', value: formatMoney(kpis.spend), hint: `CPC ${formatMoney(kpis.cpc)}` },
                { label: 'Impresiones', value: formatNumber(kpis.impressions), hint: `CTR ${formatPercentRatio(kpis.ctr)}` },
                { label: 'Clicks', value: formatNumber(kpis.clicks), hint: `Leads SF ${formatNumber(kpis.leads_salesforce)}` },
                { label: 'Leads Salesforce', value: formatNumber(kpis.leads_salesforce), hint: `CPL ${formatMoney(kpis.cost_per_lead)}` },
                { label: 'Oportunidades', value: formatNumber(kpis.opportunities), hint: `CPO ${formatMoney(kpis.cost_per_opportunity)}` },
                { label: 'Reservas', value: formatNumber(kpis.reservations), hint: `Ventas ${formatNumber(kpis.sales)} · Compras ${formatNumber(kpis.purchases)}` },
                { label: 'Ventas / Compras', value: totalResults, hint: `Ventas ${formatNumber(kpis.sales)} · Compras ${formatNumber(kpis.purchases)}` },
                { label: 'Coste por resultado', value: formatMoney(kpis.cost_per_result), hint: `Resultados ${totalResults}` },
            ];
    }
}

function renderFilterOptions(filters, rows = []) {
    populateCampaignTypeSelect(filters.campaign_types || []);
    populatePlatformSelect(filters.platforms || []);
    populateSelect('campaignStatus', filters.campaign_statuses || [], 'Todas');
    populateSelect('classification', filters.classifications || [], 'Todas');
    renderCampaignNameChecklist(rows);
    syncCampaignTypeSelect();
}

function populateCampaignTypeSelect(options) {
    const select = document.getElementById('campaignType');
    if (!select) {
        return;
    }

    const current = normalizeCampaignContext(select.value || currentContext);
    select.innerHTML = (options || []).map((option) => {
        const value = typeof option === 'object' ? option.value : option;
        const label = typeof option === 'object' ? option.label : option;

        return `<option value="${escapeHtml(value || 'all')}">${escapeHtml(label || 'Todos')}</option>`;
    }).join('');

    select.value = [...select.options].some((option) => option.value === current) ? current : 'all';
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
        select.insertAdjacentHTML('beforeend', `<option value="${escapeHtml(value)}">${escapeHtml(label || formatPlatform(value))}</option>`);
    });

    select.value = [...select.options].some((option) => option.value === current) ? current : '';
}

function populateSelect(id, options, emptyLabel) {
    const select = document.getElementById(id);
    if (!select) {
        return;
    }

    const current = select.value || '';
    select.innerHTML = `<option value="">${escapeHtml(emptyLabel)}</option>`;

    (options || []).forEach((option) => {
        const value = typeof option === 'object' ? option.value : option;
        const label = typeof option === 'object' ? option.label : option;
        if (!value) {
            return;
        }

        select.insertAdjacentHTML('beforeend', `<option value="${escapeHtml(value)}">${escapeHtml(label || value)}</option>`);
    });

    select.value = [...select.options].some((option) => option.value === current) ? current : '';
}

function renderCampaignNameChecklist(rows) {
    const root = document.getElementById('campaignNameChecklist');
    if (!root) {
        return;
    }

    const options = [...new Map((rows || [])
        .filter((row) => row.campaign_name)
        .map((row) => [row.campaign_name, row]))
        .values()];

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
        : '<div class="empty-state">No hay campanas disponibles</div>';

    persistCampaignNameSelections();

    root.querySelectorAll('input[type="checkbox"]').forEach((input) => {
        input.addEventListener('change', () => {
            campaignNameSelections = [...root.querySelectorAll('input[type="checkbox"]:checked')].map((item) => item.value);
            persistCampaignNameSelections();
            reloadAllData();
        });
    });
}

function loadCampaignNameSelections() {
    try {
        const saved = JSON.parse(localStorage.getItem(campaignNameSelectionStorageKey) || 'null');
        return Array.isArray(saved) ? saved.filter((value) => typeof value === 'string' && value.trim()) : [];
    } catch (error) {
        return [];
    }
}

function loadDailyVisibleSeries() {
    try {
        const saved = JSON.parse(localStorage.getItem(dailySeriesStorageKey) || 'null');
        const defaults = dailySeriesDefinitions.map((series) => series.key);
        if (!Array.isArray(saved) || !saved.length) {
            return defaults.slice(0, 1);
        }

        const known = saved.filter((key) => dailySeriesDefinitions.some((series) => series.key === key));
        return known.length ? known : defaults.slice(0, 1);
    } catch (error) {
        return dailySeriesDefinitions.slice(0, 1).map((series) => series.key);
    }
}

function loadDailyChartType() {
    try {
        const saved = localStorage.getItem(dailyTypeStorageKey) || 'lines';
        return saved === 'bars' ? 'bars' : 'lines';
    } catch (error) {
        return 'lines';
    }
}

function loadMonthlyVisibleMetrics() {
    try {
        const saved = JSON.parse(localStorage.getItem(monthlyMetricsStorageKey) || 'null');
        const defaults = ['spend', 'leads_salesforce', 'opportunities', 'reservations', 'sales', 'purchases', 'cost_per_result'];
        if (!Array.isArray(saved) || !saved.length) {
            return defaults;
        }

        const known = saved.filter((key) => monthlySeriesDefinitions.some((series) => series.key === key));
        return known.length ? known : defaults;
    } catch (error) {
        return ['spend', 'leads_salesforce', 'opportunities', 'reservations', 'sales', 'purchases', 'cost_per_result'];
    }
}

function loadMonthlyCompareMode() {
    try {
        const saved = localStorage.getItem(monthlyCompareStorageKey) || 'none';
        return saved === 'prev_year' ? 'prev_year' : 'none';
    } catch (error) {
        return 'none';
    }
}

function persistCampaignNameSelections() {
    localStorage.setItem(campaignNameSelectionStorageKey, JSON.stringify(campaignNameSelections));
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
    return currentFilters();
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

function loadSavedMonthlySelection() {
    try {
        const saved = JSON.parse(localStorage.getItem(monthlyMonthsStorageKey) || 'null');
        return Array.isArray(saved) ? saved.filter((value) => typeof value === 'string' && value.trim()) : [];
    } catch (error) {
        return [];
    }
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

function selectedMonthlySeries() {
    if (!monthlyVisibleMetrics.length) {
        monthlyVisibleMetrics = ['spend'];
    }

    return monthlyVisibleMetrics.filter((key) => monthlySeriesDefinitions.some((series) => series.key === key));
}

function buildMonthlyChartRows(allRows, selectedRows) {
    const monthMap = new Map((allRows || []).map((row) => [monthKeyFromDate(row.date), row]));
    const compareOffset = monthlyCompareMode === 'prev_year' ? 12 : 0;

    return (selectedRows || []).map((row) => {
        const monthKey = monthKeyFromDate(row.date);
        const compareKey = shiftMonthKey(monthKey, compareOffset);
        const compareRow = compareOffset ? monthMap.get(compareKey) : null;
        const chartRow = {
            date: row.date,
            month_key: monthKey,
            label: monthLabel(row),
            result_count: monthlyResultCountForRow(row),
        };

        selectedMonthlySeries().forEach((metric) => {
            if (metric === 'cost_per_result') {
                chartRow[metric] = monthlyCostPerResultForRow(row);
                chartRow[`${metric}_compare`] = compareRow ? monthlyCostPerResultForRow(compareRow) : 0;
                return;
            }

            chartRow[metric] = Number(row[metric] || 0);
            chartRow[`${metric}_compare`] = Number(compareRow?.[metric] || 0);
        });

        return chartRow;
    });
}

function buildMonthlySeriesDefinitions(metrics) {
    return (metrics || []).flatMap((metric) => {
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
                    label: `${definition.label} ano anterior`,
                    className: `${definition.className} is-compare`,
                    isCompare: true,
                }
                : null,
        ].filter(Boolean);
    });
}

function applyMonthlyRange(value, rows) {
    const availableMonths = (rows || []).map((row) => monthKeyFromDate(row.date));

    if (value === 'all') {
        monthlySelectedMonths = availableMonths;
        return;
    }

    const months = Number(value);
    monthlySelectedMonths = availableMonths.slice(-months);
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
    const hiddenByContext = {
        venta: ['purchases', 'cost_per_lead', 'cost_per_opportunity', 'cost_per_purchase', 'cost_per_result', 'result_count', 'lead_to_purchase', 'opportunity_to_purchase', 'appraisals_generated', 'cost_per_appraisal', 'appraisal_amount'],
        tasacion: ['reservations', 'sales', 'sale_amount', 'cost_per_reservation', 'cost_per_sale', 'cost_per_result', 'result_count', 'roas', 'estimated_roi', 'opportunity_to_reservation', 'reservation_to_sale', 'lead_to_sale'],
        exposicion: ['reservations', 'sales', 'sale_amount', 'purchases', 'cost_per_reservation', 'cost_per_sale', 'cost_per_purchase', 'cost_per_result', 'result_count', 'roas', 'estimated_roi', 'opportunity_to_reservation', 'reservation_to_sale', 'lead_to_sale', 'lead_to_purchase', 'opportunity_to_purchase', 'appraisals_generated', 'cost_per_appraisal', 'appraisal_amount'],
        branding: ['reservations', 'sales', 'sale_amount', 'purchases', 'cost_per_reservation', 'cost_per_sale', 'cost_per_purchase', 'cost_per_opportunity', 'cost_per_result', 'result_count', 'roas', 'estimated_roi', 'opportunity_to_reservation', 'reservation_to_sale', 'lead_to_sale', 'lead_to_purchase', 'opportunity_to_purchase', 'appraisals_generated', 'cost_per_appraisal', 'appraisal_amount'],
        otros: ['appraisals_generated', 'cost_per_appraisal', 'appraisal_amount', 'cost_per_purchase'],
        all: ['appraisals_generated', 'cost_per_appraisal', 'appraisal_amount', 'cost_per_purchase'],
    };

    return !(hiddenByContext[normalizeCampaignContext(context)] || []).includes(key);
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

    const selected = [...document.querySelectorAll('#periodPreset option')].find((option) => option.value === value);
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
    if (!empty) {
        return;
    }

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

function monthKeyFromDate(value) {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
}

function shiftMonthKey(monthKey, offset) {
    if (!monthKey || !offset) {
        return monthKey;
    }

    const [year, month] = monthKey.split('-').map(Number);
    const date = new Date(year, month - 1 + offset, 1);
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
}

function monthLabel(row) {
    const date = new Date(row.date || row.metric_date || row.month_key || row.metric_month || row.month || row.date_from || '');
    if (Number.isNaN(date.getTime())) {
        return row.label || '-';
    }

    return new Intl.DateTimeFormat('es-ES', { month: 'short', year: 'numeric' }).format(date);
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

    return Number(value).toFixed(2);
}

function formatPlatform(value) {
    return {
        google_ads: 'Google Ads',
        meta: 'Meta Ads',
        salesforce: 'Salesforce',
    }[value] || value || '-';
}

function formatReviewValue(row) {
    return row.value !== null && row.value !== undefined ? (Number.isFinite(Number(row.value)) ? formatNumber(row.value) : String(row.value)) : '-';
}

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function cellHtml([value, numeric, rawValue, columnKey]) {
    const classes = ['table-cell'];
    if (numeric) {
        classes.push('num');
    }

    return `<td class="${classes.join(' ')}" data-column="${escapeHtml(columnKey)}" title="${escapeHtml(rawValue ?? '')}">${escapeHtml(value)}</td>`;
}

function sortedCampaignRows(rows, tableType) {
    const sortState = tableSort[tableType] || tableSort.all;
    const direction = sortState.direction === 'asc' ? 1 : -1;

    return [...(rows || [])].sort((a, b) => {
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

function metricBarHtml(label, value, max, formatter, tooltip = null, useLogScale = false) {
    const numericValue = Number(value || 0);
    const denominator = max > 0 ? max : 1;
    const width = useLogScale && numericValue > 0
        ? Math.max(4, (Math.log10(numericValue + 1) / Math.log10(denominator + 1)) * 100)
        : (numericValue / denominator) * 100;

    return `
        <div class="campaign-metric-bar" ${tooltip ? `title="${escapeHtml(tooltip)}" data-tooltip="${escapeHtml(tooltip)}"` : ''}>
            <div>
                <span>${escapeHtml(label)}</span>
                <strong>${escapeHtml(formatter(numericValue))}</strong>
            </div>
            <i><b style="width:${Math.min(100, Math.max(0, width))}%"></b></i>
        </div>
    `;
}

function renderColumnsPopover() {
    const root = document.getElementById('columnsPopover');
    if (!root) {
        return;
    }

    root.innerHTML = columnDefinitions.map((column) => `
        <label class="column-option switch-option">
            <input type="checkbox" data-column-toggle="${escapeHtml(column.key)}" ${visibleColumns.includes(column.key) ? 'checked' : ''}>
            <span>${escapeHtml(column.label)}</span>
        </label>
    `).join('');
}

function renderRankingsPopover() {
    const root = document.getElementById('rankingsPopover');
    if (!root) {
        return;
    }

    root.innerHTML = rankingDefinitions.map((ranking) => `
        <label class="column-option switch-option">
            <input type="checkbox" data-ranking-toggle="${escapeHtml(ranking.key)}" ${visibleRankings.includes(ranking.key) ? 'checked' : ''}>
            <span>${escapeHtml(ranking.title)}</span>
        </label>
    `).join('');
}

function renderRankings(rankings) {
    const root = document.getElementById('rankingsGrid');
    if (!root) {
        return;
    }

    currentRankings = rankings || {};
    const context = normalizeCampaignContext(currentContext);
    const visible = rankingDefinitions.filter((ranking) => visibleRankings.includes(ranking.key) && rankingAllowedInContext(ranking.key, context));

    root.innerHTML = visible.length
        ? visible.map((ranking) => {
            const rows = currentRankings[ranking.key] || [];
            const max = Math.max(...rows.map((row) => Number(row[ranking.metric] || 0)), 0);
            const body = rows.length
                ? rows.map((row) => metricBarHtml(campaignLabel(row), row[ranking.metric], max, ranking.formatter)).join('')
                : '<div class="empty-state">Sin datos</div>';

            return `
                <article class="card panel">
                    <div class="panel-title compact">
                        <div>
                            <h2>${escapeHtml(ranking.title)}</h2>
                        </div>
                    </div>
                    <div class="campaign-bar-list">${body}</div>
                </article>
            `;
        }).join('')
        : '<div class="empty-state">Sin rankings visibles</div>';
}

function renderDiagnostics(diagnostics) {
    const root = document.getElementById('campaignDiagnostics');
    if (!root) {
        return;
    }

    const entries = [
        ['Ultima sync Meta', formatDateTime(diagnostics.last_meta_sync)],
        ['Ultima sync Google Ads', formatDateTime(diagnostics.last_google_sync)],
        ['Filas Meta', formatNumber(diagnostics.meta_metric_rows)],
        ['Filas Google Ads', formatNumber(diagnostics.google_metric_rows)],
    ];

    root.innerHTML = entries.map(([label, value]) => `
        <div class="diagnostic-item">
            <span>${escapeHtml(label)}</span>
            <strong>${escapeHtml(value)}</strong>
        </div>
    `).join('');
}

function renderCampaignTables(rows) {
    const root = document.getElementById('campaignTables');
    if (!root) {
        return;
    }

    const context = normalizeCampaignContext(currentContext);
    const titleMap = {
        venta: ['Detalle de campanas de venta', 'Vista detallada de campanas orientadas a venta'],
        tasacion: ['Detalle de campanas de tasacion', 'Vista detallada de campanas orientadas a tasacion'],
        exposicion: ['Detalle de campanas de exposicion', 'Vista detallada de campanas orientadas a exposicion'],
        branding: ['Detalle de campanas de branding', 'Vista detallada de campanas orientadas a branding'],
        otros: ['Detalle de campanas', 'Vista general de campanas no clasificadas en un tipo principal'],
        all: ['Detalle de campanas', 'Vista general por tipos de campana'],
    };
    const [title, subtitle] = titleMap[context] || titleMap.all;

    root.innerHTML = renderCampaignTable(context, title, subtitle, rows || []);
    renderColumnsPopover();
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
        : `<tr><td colspan="${emptyColspan}">No hay campanas para los filtros seleccionados.</td></tr>`;

    return `
        <article class="card panel campaign-table-card">
            <div class="panel-title compact">
                <div>
                    <h2>${escapeHtml(title)}</h2>
                    <div class="small">${escapeHtml(subtitle)}</div>
                </div>
                <div class="campaign-table-actions">
                    <button type="button" class="main-tab campaign-detail-toggle" data-toggle-campaign-detail>
                        ${campaignDetailsVisible ? 'Ocultar detalle de campanas' : 'Mostrar detalle de campanas'}
                    </button>
                    <div class="columns-menu">
                        <button type="button" class="main-tab" id="columnsToggle">Columnas</button>
                        <div class="columns-popover card is-hidden" id="columnsPopover"></div>
                    </div>
                    ${window.reportUserCanExport ? '<a class="main-tab" id="exportCsv" href="/informes/campanas/export/campaigns.csv">Export CSV</a>' : ''}
                </div>
            </div>
            <div class="${campaignDetailsVisible ? '' : 'is-hidden'}" id="campaignDetailBody">
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
            </div>
        </article>
    `;
}

function loadVisibleColumns() {
    try {
        const saved = JSON.parse(localStorage.getItem(columnsStorageKey) || 'null');
        const defaults = columnDefinitions.filter((column) => column.visible).map((column) => column.key);

        if (!Array.isArray(saved) || !saved.length) {
            return defaults;
        }

        const known = saved.filter((key) => columnDefinitions.some((column) => column.key === key));
        return known.length ? known : defaults;
    } catch (error) {
        return columnDefinitions.filter((column) => column.visible).map((column) => column.key);
    }
}

function persistVisibleColumns() {
    localStorage.setItem(columnsStorageKey, JSON.stringify(visibleColumns));
}

function persistVisibleRankings() {
    localStorage.setItem(rankingsStorageKey, JSON.stringify(visibleRankings));
}
