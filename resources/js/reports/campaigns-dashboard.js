const numberFormatter = new Intl.NumberFormat('es-ES');
const moneyFormatter = new Intl.NumberFormat('es-ES', { style: 'currency', currency: 'EUR' });

let campaignRows = [];
let tableSort = { key: 'spend', direction: 'desc' };

document.addEventListener('DOMContentLoaded', async () => {
    setDefaultDates();
    bindTabs();
    bindFilters();
    bindResetFilters();
    bindSorting();
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

async function reloadAllData() {
    setLoadingState(true);

    try {
        const query = currentFilters();
        const [summary, campaigns, rankings] = await Promise.all([
            fetchJson(`/informes/campanas/data/summary?${query}`),
            fetchJson(`/informes/campanas/data/campaigns?${query}`),
            fetchJson(`/informes/campanas/data/rankings?${query}`),
        ]);

        renderSummary(summary);
        renderFilterOptions(summary.filters || {});
        campaignRows = campaigns.items || [];
        renderCampaignRows(campaignRows);
        renderRankings(rankings.rankings || {});
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
        ['Reservas generadas', formatNumber(kpis.reservations), `CPR ${formatMoney(kpis.cost_per_reservation)}`],
        ['Reservas vivas', formatNumber(kpis.live_reservations), `Caidas ${formatNumber(kpis.fallen_reservations)}`],
        ['Ventas', formatNumber(kpis.sales), `CPV ${formatMoney(kpis.cost_per_sale)}`],
        ['Importe vendido', formatMoney(kpis.sale_amount), `ROAS ${formatMultiplier(kpis.roas)}`],
        ['ROI estimado', formatPercentRatio(kpis.estimated_roi), `Lead -> venta ${formatPercentRatio(kpis.lead_to_sale)}`],
    ];

    root.innerHTML = '';
    cards.forEach(([label, value, hint]) => {
        root.insertAdjacentHTML('beforeend', `
            <div class="card kpi">
                <div>
                    <div class="kpi-label">${escapeHtml(label)}</div>
                    <div class="kpi-value">${escapeHtml(value)}</div>
                    <div class="kpi-hint">${escapeHtml(hint)}</div>
                </div>
            </div>
        `);
    });
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
    root.innerHTML = '';

    if (!sortedRows.length) {
        root.innerHTML = '<tr><td colspan="33">No hay campanas agregadas para los filtros seleccionados.</td></tr>';
        return;
    }

    sortedRows.forEach((row) => {
        const cells = [
            [row.platform],
            [row.account_id],
            [row.source_acquired],
            [row.medium_acquired],
            [row.campaign_acquired],
            [row.campaign_id],
            [row.campaign_name],
            [formatMoney(row.spend), true, row.spend],
            [formatNumber(row.impressions), true, row.impressions],
            [formatNumber(row.clicks), true, row.clicks],
            [formatPercentRatio(row.ctr), true, row.ctr],
            [formatMoney(row.cpc), true, row.cpc],
            [formatNullableNumber(row.platform_leads), true, row.platform_leads],
            [formatNumber(row.leads_salesforce), true, row.leads_salesforce],
            [formatNumber(row.opportunities), true, row.opportunities],
            [formatNumber(row.reservations), true, row.reservations],
            [formatNumber(row.live_reservations), true, row.live_reservations],
            [formatNumber(row.fallen_reservations), true, row.fallen_reservations],
            [formatNumber(row.sales), true, row.sales],
            [formatMoney(row.sale_amount), true, row.sale_amount],
            [formatMoney(row.cost_per_lead), true, row.cost_per_lead],
            [formatMoney(row.cost_per_opportunity), true, row.cost_per_opportunity],
            [formatMoney(row.cost_per_reservation), true, row.cost_per_reservation],
            [formatMoney(row.cost_per_sale), true, row.cost_per_sale],
            [formatMultiplier(row.roas), true, row.roas],
            [formatPercentRatio(row.estimated_roi), true, row.estimated_roi],
            [formatPercentRatio(row.click_to_lead_salesforce), true, row.click_to_lead_salesforce],
            [formatPercentRatio(row.click_to_lead_platform), true, row.click_to_lead_platform],
            [formatPercentRatio(row.lead_to_opportunity), true, row.lead_to_opportunity],
            [formatPercentRatio(row.opportunity_to_reservation), true, row.opportunity_to_reservation],
            [formatPercentRatio(row.reservation_to_sale), true, row.reservation_to_sale],
            [formatPercentRatio(row.lead_to_sale), true, row.lead_to_sale],
            [row.classification],
        ];

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

    if (Number.isNaN(date.getTime())) {
        return value || '-';
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
