const numberFormatter = new Intl.NumberFormat('es-ES');
const moneyFormatter = new Intl.NumberFormat('es-ES', { style: 'currency', currency: 'EUR' });
const columnsStorageKey = 'hrmotor_campaign_columns_v1';

let campaignRows = [];
let tableSort = { key: 'spend', direction: 'desc' };
let searchTimer = null;

const columnDefinitions = [
    { key: 'campaign', label: 'Campaña', visible: true, formatter: (_value, row) => campaignLabel(row) },
    { key: 'platform', label: 'Plataforma', visible: true },
    { key: 'match_status', label: 'Estado de cruce', visible: true },
    { key: 'classification', label: 'Clasificacion', visible: true },
    { key: 'spend', label: 'Inversion', visible: true, numeric: true, formatter: formatMoney },
    { key: 'impressions', label: 'Impresiones', visible: true, numeric: true, formatter: formatNumber },
    { key: 'clicks', label: 'Clicks', visible: true, numeric: true, formatter: formatNumber },
    { key: 'ctr', label: 'CTR', visible: true, numeric: true, formatter: formatPercentRatio },
    { key: 'cpc', label: 'CPC', visible: true, numeric: true, formatter: formatMoney },
    { key: 'platform_leads', label: 'Leads plataforma', visible: true, numeric: true, formatter: formatNullableNumber },
    { key: 'leads_salesforce', label: 'Leads Salesforce', visible: true, numeric: true, formatter: formatNumber },
    { key: 'opportunities', label: 'Oportunidades', visible: true, numeric: true, formatter: formatNumber },
    { key: 'reservations', label: 'Reservas', visible: true, numeric: true, formatter: formatNumber },
    { key: 'sales', label: 'Ventas', visible: true, numeric: true, formatter: formatNumber },
    { key: 'sale_amount', label: 'Importe vendido', visible: true, numeric: true, formatter: formatMoney },
    { key: 'cost_per_lead', label: 'CPL', visible: true, numeric: true, formatter: formatMoney },
    { key: 'cost_per_sale', label: 'CPV', visible: true, numeric: true, formatter: formatMoney },
    { key: 'roas', label: 'ROAS', visible: true, numeric: true, formatter: formatMultiplier },
    { key: 'account_id', label: 'Cuenta', visible: false },
    { key: 'campaign_id', label: 'Campaign ID', visible: false },
    { key: 'campaign_name', label: 'Campaign name', visible: false },
    { key: 'source_acquired', label: 'Fuente adquirida', visible: false },
    { key: 'medium_acquired', label: 'Medio adquirido', visible: false },
    { key: 'campaign_acquired', label: 'Campaña adquirida', visible: false },
    { key: 'cost_per_opportunity', label: 'CPO', visible: false, numeric: true, formatter: formatMoney },
    { key: 'cost_per_reservation', label: 'CPR', visible: false, numeric: true, formatter: formatMoney },
    { key: 'estimated_roi', label: 'ROI estimado', visible: false, numeric: true, formatter: formatPercentRatio },
    { key: 'lead_to_opportunity', label: 'Lead -> oportunidad', visible: false, numeric: true, formatter: formatPercentRatio },
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
        });
    });
}

function bindResetFilters() {
    document.getElementById('resetFilters')?.addEventListener('click', async () => {
        [
            'platform',
            'accountId',
            'campaignSearch',
            'sourceAcquired',
            'mediumAcquired',
            'campaignAcquired',
            'campaignId',
            'campaignName',
            'delegation',
            'zone',
            'leadStatus',
            'hasOpportunity',
            'hasReservation',
            'hasSale',
            'commercialUser',
            'vehicleInterest',
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
        'sourceAcquired',
        'mediumAcquired',
        'campaignAcquired',
        'campaignId',
        'campaignName',
        'delegation',
        'zone',
        'leadStatus',
        'hasOpportunity',
        'hasReservation',
        'hasSale',
        'commercialUser',
        'vehicleInterest',
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
        ['Clicks', formatNumber(kpis.clicks), `Leads plataforma ${formatNullableNumber(kpis.platform_leads)}`],
        ['Leads Salesforce', formatNumber(kpis.leads_salesforce), `CPL ${formatMoney(kpis.cost_per_lead)}`],
        ['Oportunidades', formatNumber(kpis.opportunities), `CPO ${formatMoney(kpis.cost_per_opportunity)}`],
        ['Reservas', formatNumber(kpis.reservations), `CPR ${formatMoney(kpis.cost_per_reservation)}`],
        ['Ventas', formatNumber(kpis.sales), `CPV ${formatMoney(kpis.cost_per_sale)}`],
        ['Importe vendido', formatMoney(kpis.sale_amount), `ROAS ${formatMultiplier(kpis.roas)}`],
        ['ROI estimado', formatPercentRatio(kpis.estimated_roi), `Lead -> venta ${formatPercentRatio(kpis.lead_to_sale)}`],
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

function renderDiagnostics(diagnostics) {
    const root = document.getElementById('campaignDiagnostics');
    const items = [
        ['Ultima sync Meta', formatDateTime(diagnostics.last_meta_sync)],
        ['Ultima sync Google Ads', formatDateTime(diagnostics.last_google_sync)],
        ['Ultima atribucion', formatDateTime(diagnostics.last_attribution_build)],
        ['Filas Meta', formatNumber(diagnostics.meta_metric_rows)],
        ['Filas Google Ads', formatNumber(diagnostics.google_metric_rows)],
        ['Leads con campana', formatNumber(diagnostics.salesforce_leads_with_campaign_period)],
        ['Candidatos validos', formatNumber(diagnostics.valid_candidate_leads)],
        ['Atribuciones', formatNumber(diagnostics.built_attributions)],
        ['Inversion sin leads', formatNumber(diagnostics.campaigns_spend_without_salesforce_leads)],
        ['Salesforce sin inversion', formatNumber(diagnostics.campaigns_salesforce_without_spend)],
        ['Cruzadas por ID', formatNumber(diagnostics.campaigns_matched_by_id)],
        ['Cruzadas por nombre', formatNumber(diagnostics.campaigns_matched_by_name)],
    ];

    root.innerHTML = items.map(([label, value]) => `
        <div class="diagnostic-item">
            <span>${escapeHtml(label)}</span>
            <strong>${escapeHtml(value)}</strong>
        </div>
    `).join('');
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
        ['Revisar inversion/tracking', rankings.review_investment_tracking || [], 'leads_salesforce', formatNumber],
        ['Potenciar', rankings.boost || [], 'spend', formatMoney],
        ['Revisar', rankings.review || [], 'spend', formatMoney],
        ['Parar', rankings.stop || [], 'spend', formatMoney],
    ];

    root.innerHTML = '';
    groups.forEach(([title, rows, key, formatter]) => {
        const items = rows.length
            ? rows.map((row) => `
                <div class="portal-row">
                    <span>${escapeHtml(campaignLabel(row))}</span>
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
        root.innerHTML = `<tr><td colspan="${columns.length}">No hay campanas agregadas para los filtros seleccionados.</td></tr>`;
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
    populateSelect('accountId', (filters.accounts || []).map((account) => ({
        value: account.id,
        label: account.name ? `${account.name} (${account.id})` : account.id,
    })), 'Todas');
    populateSelect('sourceAcquired', filters.sources || [], 'Todas');
    populateSelect('mediumAcquired', filters.mediums || [], 'Todos');
    populateSelect('campaignAcquired', filters.campaigns_acquired || [], 'Todas');
    populateSelect('campaignId', filters.campaign_ids || [], 'Todos');
    populateSelect('campaignName', filters.campaign_names || [], 'Todas');
    populateSelect('delegation', filters.delegations || [], 'Todas');
    populateSelect('zone', filters.zones || [], 'Todas');
    populateSelect('leadStatus', filters.lead_statuses || [], 'Todos');
    populateSelect('commercialUser', (filters.commercials || []).map((item) => ({ value: item.id, label: item.name })), 'Todos');
    populateSelect('vehicleInterest', filters.vehicles || [], 'Todos');
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
    setParam(params, 'source_acquired', document.getElementById('sourceAcquired')?.value);
    setParam(params, 'medium_acquired', document.getElementById('mediumAcquired')?.value);
    setParam(params, 'campaign_acquired', document.getElementById('campaignAcquired')?.value);
    setParam(params, 'campaign_id', document.getElementById('campaignId')?.value);
    setParam(params, 'campaign_name', document.getElementById('campaignName')?.value);
    setParam(params, 'delegation', document.getElementById('delegation')?.value);
    setParam(params, 'zone', document.getElementById('zone')?.value);
    setParam(params, 'lead_status', document.getElementById('leadStatus')?.value);
    setParam(params, 'has_opportunity', document.getElementById('hasOpportunity')?.value);
    setParam(params, 'has_reservation', document.getElementById('hasReservation')?.value);
    setParam(params, 'has_sale', document.getElementById('hasSale')?.value);
    setParam(params, 'commercial_user', document.getElementById('commercialUser')?.value);
    setParam(params, 'vehicle_interest', document.getElementById('vehicleInterest')?.value);

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
            return saved.filter((key) => columnDefinitions.some((column) => column.key === key));
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
    return row.campaign_name || row.campaign_acquired || row.campaign_id || '-';
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

function formatNullableNumber(value) {
    return value === null || value === undefined ? '-' : formatNumber(value);
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

function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}
