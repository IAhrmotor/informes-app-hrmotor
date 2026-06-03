const numberFormatter = new Intl.NumberFormat('es-ES');
const moneyFormatter = new Intl.NumberFormat('es-ES', { style: 'currency', currency: 'EUR' });
const columnsStorageKey = 'hrmotor_campaign_columns_v4';
const rankingsStorageKey = 'campaigns.visibleRankings';
const dailySeriesStorageKey = 'campaigns.dailyChart.visibleSeries';
const dailyTypeStorageKey = 'campaigns.dailyChart.chartType';

let campaignRows = [];
let tableSort = { key: 'spend', direction: 'desc' };
let searchTimer = null;
let syncingTableScroll = false;
let dailyVisibleSeries = loadDailyVisibleSeries();
let dailyChartType = loadDailyChartType();
let currentCharts = {};
let currentContext = 'venta';

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
    { key: 'leads_salesforce', label: 'Leads', formatter: formatNumber, className: 'leads' },
    { key: 'opportunities', label: 'Oportunidades', formatter: formatNumber, className: 'opportunities' },
    { key: 'reservations', label: 'Reservas', formatter: formatNumber, className: 'reservations' },
    { key: 'sales', label: 'Ventas', formatter: formatNumber, className: 'sales' },
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
    bindColumns();
    bindRankingSettings();
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

            syncTableScrollWidth();
        });
    });
}

function bindResetFilters() {
    document.getElementById('resetFilters')?.addEventListener('click', async () => {
        [
            'platform',
            'accountId',
            'campaignSearch',
            'campaignStatus',
            'campaignSourceType',
            'mediumAcquired',
            'campaignAcquired',
            'campaignId',
            'campaignName',
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
        setDefaultDates();
        await reloadAllData();
    });
}

function bindFilters() {
    [
        'startDate',
        'endDate',
        'attributionWindow',
        'platform',
        'accountId',
        'campaignStatus',
        'campaignSourceType',
        'mediumAcquired',
        'campaignAcquired',
        'campaignId',
        'campaignName',
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
    document.querySelectorAll('#panel-campaigns th[data-sortable="true"]').forEach((header) => {
        header.addEventListener('click', () => {
            const key = header.dataset.key;
            tableSort = {
                key,
                direction: tableSort.key === key && tableSort.direction === 'desc' ? 'asc' : 'desc',
            };
            renderCampaignRows(campaignRows);
        });
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
    const top = document.getElementById('campaignTableTopScroll');
    const wrap = document.getElementById('campaignTableWrap');

    if (!top || !wrap) {
        return;
    }

    top.addEventListener('scroll', () => {
        if (syncingTableScroll) {
            return;
        }

        syncingTableScroll = true;
        wrap.scrollLeft = top.scrollLeft;
        syncingTableScroll = false;
    });

    wrap.addEventListener('scroll', () => {
        if (syncingTableScroll) {
            return;
        }

        syncingTableScroll = true;
        top.scrollLeft = wrap.scrollLeft;
        syncingTableScroll = false;
    });

    window.addEventListener('resize', syncTableScrollWidth);
}

async function reloadAllData() {
    setLoadingState(true);

    try {
        const query = currentFilters();
        const [summary, campaigns, rankings] = await Promise.all([
            fetchJson(`/informes/campanas/data/summary?${query}`),
            fetchJson(`/informes/campanas/data/campaigns?${query}`),
            fetchJson(`/informes/campanas/data/rankings?${query}`),
        ]);

        renderSummary(summary || {});
        renderFilterOptions(summary.filters || {});
        campaignRows = campaigns.items || [];
        renderCampaignRows(campaignRows);
        renderRankings((rankings && rankings.rankings) || {});
        const exportLink = document.getElementById('exportCsv');
        if (exportLink) {
            exportLink.href = `/informes/campanas/export/campaigns.csv?${query}`;
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
    document.getElementById('windowLabel').textContent = 'Pivot Lead.CreatedDate';

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
    const definitions = currentContext === 'tasacion'
        ? monthlyTasacionSeriesDefinitions
        : monthlySeriesDefinitions;
    const content = rows.length
        ? genericLineChartHtml(rows, definitions, monthlyTooltip, true)
        : '<div class="empty-state">Sin datos</div>';

    return `
        <article class="card panel campaign-chart-card campaign-chart-wide">
            <div class="panel-title compact">
                <div>
                    <h2>Evolucion mensual de campanas</h2>
                    <div class="small">Inversion, leads y resultados atribuidos por mes de creacion del lead</div>
                </div>
            </div>
            <div class="campaign-evolution campaign-evolution-lines">${content}</div>
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

            const pointsSvg = showPoints
                ? coordinates.map((point) => `<circle class="line-point is-visible ${escapeHtml(series.className)}" cx="${point.x.toFixed(2)}" cy="${point.y.toFixed(2)}" r="1.8"></circle>`).join('')
                : '';

            return `<polyline class="line-series ${escapeHtml(series.className)}" points="${points}" />${pointsSvg}`;
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
            <div class="line-hover-layer">${hoverPoints}</div>
            <div class="line-label-layer">${labels}</div>
        </div>
    `;
}

function funnelHtml(rows) {
    const max = Math.max(...rows.map((row) => Number(row.value || 0)), 0);
    const content = rows.length
        ? rows.map((row, index) => {
            const previous = index > 0 ? Number(rows[index - 1].value || 0) : null;
            const rate = previous ? Number(row.value || 0) / previous : null;
            const tooltip = `${row.label}: ${formatNumber(Number(row.value || 0))}${rate === null ? '' : `\nConversion etapa anterior: ${formatPercentRatio(rate)}`}`;

            return metricBarHtml(row.label, row.value, max, formatNumber, tooltip);
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
            <div class="campaign-bar-list">${content}</div>
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

function metricBarHtml(label, value, max, formatter, tooltip = null) {
    return `
        <div class="campaign-metric-bar" ${tooltip ? `title="${escapeHtml(tooltip)}" data-tooltip="${escapeHtml(tooltip)}"` : ''}>
            <div>
                <span>${escapeHtml(label)}</span>
                <strong>${escapeHtml(formatter(value))}</strong>
            </div>
            <i><b style="width:${barWidth(value, max)}%"></b></i>
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

function renderCampaignRows(rows) {
    const root = document.getElementById('campaignRows');
    const sortedRows = sortedCampaignRows(rows);
    const columns = activeColumns();
    root.innerHTML = '';
    applyColumnVisibility();

    if (!sortedRows.length) {
        root.innerHTML = `<tr><td colspan="${columns.length}">No hay campanas de plataforma para los filtros seleccionados.</td></tr>`;
        syncTableScrollWidth();
        return;
    }

    sortedRows.forEach((row) => {
        const cells = columns.map((column) => {
            const formatter = column.formatter || ((value) => value);
            return [
                formatter(row[column.key], row),
                column.numeric,
                row[column.key],
            ];
        });

        root.insertAdjacentHTML('beforeend', `<tr>${cells.map(cellHtml).join('')}</tr>`);
    });

    syncTableScrollWidth();
}

function cellHtml([value, numeric, sortValue]) {
    const className = numeric ? ' class="num"' : '';
    const sortAttr = sortValue === undefined ? '' : ` data-sort-value="${escapeHtml(sortValue ?? '')}"`;

    return `<td${className}${sortAttr}>${escapeHtml(value ?? '-')}</td>`;
}

function sortedCampaignRows(rows) {
    const direction = tableSort.direction === 'asc' ? 1 : -1;

    return [...rows].sort((a, b) => {
        const left = a[tableSort.key];
        const right = b[tableSort.key];

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

function renderFilterOptions(filters) {
    populateSelect('platform', filters.platforms || [], 'Todas');
    populateSelect('campaignSourceType', filters.source_types || [], 'Todos');
    populateSelect('campaignStatus', filters.campaign_statuses || [], 'Todas');
    populateSelect('accountId', (filters.accounts || []).map((account) => ({
        value: account.id,
        label: account.name ? `${account.name} (${account.id})` : account.id,
    })), 'Todas');
    populateSelect('mediumAcquired', filters.mediums || [], 'Todos');
    populateSelect('campaignAcquired', filters.campaigns_acquired || [], 'Todas');
    populateSelect('campaignId', filters.campaign_ids || [], 'Todos');
    populateSelect('campaignName', filters.campaign_names || [], 'Todas');
    populateSelect('classification', filters.classifications || [], 'Todas');
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

function currentFilters() {
    const params = new URLSearchParams();

    setParam(params, 'start_date', document.getElementById('startDate')?.value);
    setParam(params, 'end_date', document.getElementById('endDate')?.value);
    setParam(params, 'platform', document.getElementById('platform')?.value);
    setParam(params, 'account_id', document.getElementById('accountId')?.value);
    setParam(params, 'context', currentContext);
    setParam(params, 'campaign_status', document.getElementById('campaignStatus')?.value);
    setParam(params, 'search', document.getElementById('campaignSearch')?.value);
    setParam(params, 'campaign_source_type', document.getElementById('campaignSourceType')?.value);
    setParam(params, 'medium_acquired', document.getElementById('mediumAcquired')?.value);
    setParam(params, 'campaign_acquired', document.getElementById('campaignAcquired')?.value);
    setParam(params, 'campaign_id', document.getElementById('campaignId')?.value);
    setParam(params, 'campaign_name', document.getElementById('campaignName')?.value);
    setParam(params, 'has_opportunity', document.getElementById('hasOpportunity')?.value);
    setParam(params, 'has_reservation', document.getElementById('hasReservation')?.value);
    setParam(params, 'has_sale', document.getElementById('hasSale')?.value);
    setParam(params, 'classification', document.getElementById('classification')?.value);

    return params.toString();
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
            renderCampaignRows(campaignRows);
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

function defaultRankingKeys() {
    return rankingDefinitions.filter((ranking) => ranking.visible).map((ranking) => ranking.key);
}

function activeColumns() {
    return columnDefinitions.filter((column) => visibleColumns.includes(column.key) && columnAllowedInContext(column.key));
}

function applyColumnVisibility() {
    document.querySelectorAll('#panel-campaigns th[data-column]').forEach((header) => {
        header.classList.toggle('is-hidden', !visibleColumns.includes(header.dataset.column) || !columnAllowedInContext(header.dataset.column));
    });
}

function columnAllowedInContext(key) {
    const tasacionHidden = ['reservations', 'sales', 'sale_amount', 'cost_per_reservation', 'cost_per_sale', 'roas', 'estimated_roi', 'opportunity_to_reservation', 'reservation_to_sale', 'lead_to_sale'];
    const ventaHidden = ['appraisals_generated', 'purchases', 'cost_per_appraisal', 'cost_per_purchase', 'lead_to_purchase', 'opportunity_to_purchase', 'appraisal_amount'];

    return currentContext === 'tasacion'
        ? !tasacionHidden.includes(key)
        : !ventaHidden.includes(key);
}

function syncTableScrollWidth() {
    const top = document.getElementById('campaignTableTopScroll');
    const wrap = document.getElementById('campaignTableWrap');
    const table = wrap?.querySelector('table');

    if (!top || !wrap || !table) {
        return;
    }

    const spacer = top.firstElementChild;
    if (spacer) {
        spacer.style.width = `${table.scrollWidth}px`;
    }

    top.scrollLeft = wrap.scrollLeft;
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
    const definitions = currentContext === 'tasacion'
        ? monthlyTasacionSeriesDefinitions
        : monthlySeriesDefinitions;

    return [
        `Mes: ${row.label || formatShortDate(row.date)}`,
        ...definitions.map((series) => `${series.label}: ${series.formatter(Number(row[series.key] || 0))}`),
    ].join('\n');
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
