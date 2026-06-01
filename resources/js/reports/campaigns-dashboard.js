const numberFormatter = new Intl.NumberFormat('es-ES');
const moneyFormatter = new Intl.NumberFormat('es-ES', { style: 'currency', currency: 'EUR' });
const columnsStorageKey = 'hrmotor_campaign_columns_v3';

let campaignRows = [];
let tableSort = { key: 'spend', direction: 'desc' };
let searchTimer = null;
let syncingTableScroll = false;

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
    { key: 'campaign_id', label: 'Campaign ID', visible: false },
    { key: 'source_acquired', label: 'Fuente adquirida', visible: false },
    { key: 'medium_acquired', label: 'Medio adquirido', visible: false },
    { key: 'acquired_id', label: 'ID adquirido', visible: false },
    { key: 'content_acquired', label: 'Contenido adquirido', visible: false },
    { key: 'cost_per_lead', label: 'CPL', visible: false, numeric: true, formatter: formatMoney },
    { key: 'cost_per_opportunity', label: 'CPO', visible: false, numeric: true, formatter: formatMoney },
    { key: 'cost_per_reservation', label: 'CPR', visible: false, numeric: true, formatter: formatMoney },
    { key: 'cost_per_sale', label: 'CPV', visible: false, numeric: true, formatter: formatMoney },
    { key: 'roas', label: 'ROAS', visible: false, numeric: true, formatter: formatMultiplier },
    { key: 'estimated_roi', label: 'ROI estimado', visible: false, numeric: true, formatter: formatPercentRatio },
    { key: 'classification', label: 'Clasificacion', visible: false },
    { key: 'lead_to_opportunity', label: 'Lead -> oportunidad', visible: false, numeric: true, formatter: formatPercentRatio },
    { key: 'opportunity_to_reservation', label: 'Oportunidad -> reserva', visible: false, numeric: true, formatter: formatPercentRatio },
    { key: 'reservation_to_sale', label: 'Reserva -> venta', visible: false, numeric: true, formatter: formatPercentRatio },
    { key: 'lead_to_sale', label: 'Lead -> venta', visible: false, numeric: true, formatter: formatPercentRatio },
];

let visibleColumns = loadVisibleColumns();

document.addEventListener('DOMContentLoaded', async () => {
    setDefaultDates();
    bindTabs();
    bindFilters();
    bindResetFilters();
    bindSorting();
    bindDrawer();
    bindColumns();
    bindTableScroll();
    applyColumnVisibility();
    await reloadAllData();
});

function bindTabs() {
    document.querySelectorAll('.main-tab[data-panel]').forEach((button) => {
        button.addEventListener('click', () => {
            document.querySelectorAll('.main-tab[data-panel]').forEach((item) => item.classList.remove('active'));
            document.querySelectorAll('.tab-panel').forEach((panel) => panel.classList.remove('active'));

            button.classList.add('active');
            document.getElementById(button.dataset.panel)?.classList.add('active');
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

        document.getElementById('attributionWindow').value = '30';
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
        document.getElementById('exportCsv').href = `/informes/campanas/export/campaigns.csv?${query}`;
    } catch (error) {
        showLoadError(error);
    } finally {
        setLoadingState(false);
    }
}

function renderSummary(data) {
    document.getElementById('updatedBadge').textContent = data.datos_actualizados
        ? `Datos actualizados: ${formatDateTime(data.datos_actualizados)}`
        : 'Datos actualizados: pendiente';
    document.getElementById('periodLabel').textContent = periodText(data.periodo_actual);
    document.getElementById('windowLabel').textContent = `${data.attribution_window_days || 30} dias`;

    const empty = document.getElementById('emptyMessage');
    empty.classList.toggle('is-hidden', Boolean(data.ok));

    renderWarnings(data.warnings || []);
    renderKpis(data.kpis || {});
    renderCharts(data.charts || {});
    renderDiagnostics(data.diagnostics || {});
}

function renderWarnings(warnings) {
    const root = document.getElementById('warnings');
    root.innerHTML = '';

    warnings.forEach((warning) => {
        root.insertAdjacentHTML('beforeend', `<div class="notice">${escapeHtml(warning)}</div>`);
    });
}

function renderKpis(kpis) {
    const root = document.getElementById('summaryKpis');
    const cards = [
        ['Inversion total', formatMoney(kpis.spend), `CPC ${formatMoney(kpis.cpc)}`],
        ['Impresiones', formatNumber(kpis.impressions), `CTR ${formatPercentRatio(kpis.ctr)}`],
        ['Clicks', formatNumber(kpis.clicks), `Leads SF ${formatNumber(kpis.leads_salesforce)}`],
        ['Leads Salesforce', formatNumber(kpis.leads_salesforce), `CPL ${formatMoney(kpis.cost_per_lead)}`],
        ['Oportunidades', formatNumber(kpis.opportunities), `CPO ${formatMoney(kpis.cost_per_opportunity)}`],
        ['Reservas', formatNumber(kpis.reservations), `CPR ${formatMoney(kpis.cost_per_reservation)}`],
        ['Ventas', formatNumber(kpis.sales), `CPV ${formatMoney(kpis.cost_per_sale)}`],
        ['Importe vendido', formatMoney(kpis.sale_amount), `ROAS ${formatMultiplier(kpis.roas)} · ROI ${formatPercentRatio(kpis.estimated_roi)}`],
    ];

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

    root.innerHTML = `
        ${dailyEvolutionHtml(charts.daily_evolution || [])}
        ${funnelHtml(charts.funnel || [])}
        ${platformBarsHtml(charts.platforms || [])}
    `;
}

function dailyEvolutionHtml(rows) {
    const maxSpend = Math.max(...rows.map((row) => Number(row.spend || 0)), 0);
    const maxLeads = Math.max(...rows.map((row) => Number(row.leads_salesforce || 0)), 0);
    const maxSales = Math.max(...rows.map((row) => Number(row.sales || 0)), 0);
    const content = rows.length
        ? rows.map((row) => `
            <div class="evolution-day" title="${escapeHtml(formatDate(row.date))}">
                <span class="evolution-bar spend" style="height:${barHeight(row.spend, maxSpend)}%"></span>
                <span class="evolution-bar leads" style="height:${barHeight(row.leads_salesforce, maxLeads)}%"></span>
                <span class="evolution-bar sales" style="height:${barHeight(row.sales, maxSales)}%"></span>
            </div>
        `).join('')
        : '<div class="empty-state">Sin datos</div>';

    return `
        <article class="card panel campaign-chart-card campaign-chart-wide">
            <div class="panel-title compact">
                <div>
                    <h2>Evolucion diaria</h2>
                    <div class="small">Inversion, leads Salesforce y ventas</div>
                </div>
            </div>
            <div class="chart-legend">
                <span><i class="spend"></i>Inversion</span>
                <span><i class="leads"></i>Leads SF</span>
                <span><i class="sales"></i>Ventas</span>
            </div>
            <div class="campaign-evolution">${content}</div>
        </article>
    `;
}

function funnelHtml(rows) {
    const max = Math.max(...rows.map((row) => Number(row.value || 0)), 0);
    const content = rows.length
        ? rows.map((row) => metricBarHtml(row.label, row.value, max, formatNumber))
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
            <div class="platform-chart-group">
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

function metricBarHtml(label, value, max, formatter) {
    return `
        <div class="campaign-metric-bar">
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
    const groups = [
        ['Campanas con mas inversion', rankings.top_spend || [], 'spend', formatMoney],
        ['Mas leads Salesforce', rankings.top_leads_salesforce || [], 'leads_salesforce', formatNumber],
        ['Mas oportunidades', rankings.top_opportunities || [], 'opportunities', formatNumber],
        ['Mas reservas', rankings.top_reservations || [], 'reservations', formatNumber],
        ['Mas ventas', rankings.top_sales || [], 'sales', formatNumber],
        ['Mejor coste por venta', rankings.best_cost_per_sale || [], 'cost_per_sale', formatMoney],
        ['Peor coste por venta', rankings.worst_cost_per_sale || [], 'cost_per_sale', formatMoney],
        ['Mucho gasto y poca conversion', rankings.high_spend_low_conversion || [], 'spend', formatMoney],
        ['Muchos leads y pocas ventas', rankings.many_leads_few_sales || [], 'leads_salesforce', formatNumber],
        ['Revisar tracking', rankings.review_tracking || [], 'spend', formatMoney],
        ['Potenciar', rankings.boost || [], 'spend', formatMoney],
        ['Revisar', rankings.review || [], 'spend', formatMoney],
        ['Parar', rankings.stop || [], 'spend', formatMoney],
    ];

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
    setParam(params, 'attribution_window_days', document.getElementById('attributionWindow')?.value);
    setParam(params, 'platform', document.getElementById('platform')?.value);
    setParam(params, 'account_id', document.getElementById('accountId')?.value);
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

function activeColumns() {
    return columnDefinitions.filter((column) => visibleColumns.includes(column.key));
}

function applyColumnVisibility() {
    document.querySelectorAll('#panel-campaigns th[data-column]').forEach((header) => {
        header.classList.toggle('is-hidden', !visibleColumns.includes(header.dataset.column));
    });
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
    return row.display_campaign || row.campaign_name || row.campaign_acquired || row.campaign_id || '-';
}

function periodText(period) {
    if (!period) {
        return '-';
    }

    return `${formatDate(period.inicio)} a ${formatDate(period.fin)}`;
}

function toInputDate(date) {
    return date.toISOString().slice(0, 10);
}

function formatDate(value) {
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value || '-';
    }

    return new Intl.DateTimeFormat('es-ES', { day: '2-digit', month: '2-digit', year: 'numeric' }).format(date);
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

function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}
