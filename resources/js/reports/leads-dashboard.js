const fmt = new Intl.NumberFormat('es-ES');
const tableSortState = new Map();

document.addEventListener('DOMContentLoaded', async () => {
    bindTabs();
    bindFilters();
    bindCommercialPanelOrder();
    bindTableSorting();
    toggleCustomPeriods();
    await reloadAllData();
});

function bindTabs() {
    document.querySelectorAll('.main-tab').forEach((button) => {
        button.addEventListener('click', () => {
            const panelId = button.dataset.panel;

            document.querySelectorAll('.main-tab').forEach((item) => item.classList.remove('active'));
            document.querySelectorAll('.tab-panel').forEach((panel) => panel.classList.remove('active'));

            button.classList.add('active');
            document.getElementById(panelId)?.classList.add('active');
        });
    });
}

function bindFilters() {
    [
        'period',
        'channel',
        'portal',
        'status',
        'leadType',
        'leadDelegation',
        'leadGroup',
        'commercialDelegation',
        'zone',
        'commercial',
        'expositionMode',
        'currentStart',
        'currentEnd',
        'comparisonStart',
        'comparisonEnd',
    ].forEach((id) => {
        document.getElementById(id)?.addEventListener('change', async () => {
            if (id === 'period') {
                toggleCustomPeriods();
            }

            await reloadAllData();
        });
    });
}

function bindCommercialPanelOrder() {
    const select = document.getElementById('commercialPanelsOrder');
    const storedOrder = localStorage.getItem('commercialPanelsOrder');

    if (select && storedOrder && [...select.options].some((option) => option.value === storedOrder)) {
        select.value = storedOrder;
    }

    applyCommercialPanelOrder();

    select?.addEventListener('change', () => {
        localStorage.setItem('commercialPanelsOrder', select.value);
        applyCommercialPanelOrder();
    });
}

function applyCommercialPanelOrder() {
    const container = document.getElementById('commercialPanels');
    const order = document.getElementById('commercialPanelsOrder')?.value || 'zones,delegations,commercials';

    order.split(',').forEach((key) => {
        const panel = container?.querySelector(`[data-commercial-section="${key}"]`);

        if (panel) {
            container.appendChild(panel);
        }
    });
}

async function reloadAllData() {
    setLoadingState(true);

    try {
        const summary = await fetchJson(`/informes/leads/data/summary?${currentFilters()}`);
        renderSummary(summary);
        renderFilterOptions(summary.filters || {});

        const [commercials, delegations, portals] = await Promise.all([
            fetchJson(`/informes/leads/data/commercials?${currentFilters()}`),
            fetchJson(`/informes/leads/data/delegations?${currentFilters()}`),
            fetchJson(`/informes/leads/data/portals?${currentFilters()}`),
        ]);

        renderCommercialZones(commercials.zones || []);
        renderCommercialDelegations(commercials.delegations || []);
        renderCommercials(commercials.commercials || commercials.items || []);
        renderDelegations(delegations.items || []);
        renderPortals(portals.items || []);
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
    document.getElementById('currentPeriodLabel').textContent = periodText(data.periodo_actual);
    document.getElementById('comparisonPeriodLabel').textContent = periodText(data.periodo_comparado);

    const empty = document.getElementById('emptyMessage');
    empty.classList.toggle('is-hidden', Boolean(data.ok));
    empty.textContent = data.message || 'No hay datos sincronizados para el periodo seleccionado.';

    renderKpis(data.kpis || {});
    renderComparison(data.comparativa || []);
    renderInsights(data.insights || []);
}

function renderKpis(kpis) {
    const root = document.getElementById('summaryKpis');
    const cards = [
        ['Leads totales', formatNumber(kpis.leads_totales), 'Muestra del periodo'],
        ['Convertidos', formatNumber(kpis.convertidos), `${formatPercent(kpis.conversion_pct)} sobre total`],
        ['Descartados', formatNumber(kpis.descartados), `${formatPercent(kpis.descarte_pct)} sobre total`],
        ['Potenciales', formatNumber(kpis.potenciales), 'Bolsa viva'],
        ['Potenciales sin trabajar', formatNumber(kpis.potenciales_sin_trabajar), 'Solo Status Potencial'],
        ['Gestionados', formatNumber(kpis.gestionados), `${formatPercent(kpis.gestionados_pct)} sobre total`],
        ['Llamadas', formatNumber(kpis.llamadas), `${formatPercent(kpis.llamadas_pct)} del total`],
        ['Formularios', formatNumber(kpis.formularios), `${formatPercent(kpis.formularios_pct)} del total`],
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

function renderComparison(rows) {
    const root = document.getElementById('comparisonRows');
    root.innerHTML = '';

    if (!rows.length) {
        root.innerHTML = '<tr><td colspan="5">No hay datos para comparar.</td></tr>';
        return;
    }

    rows.forEach((row) => {
        root.insertAdjacentHTML('beforeend', `
            <tr>
                <td><strong>${escapeHtml(row.metrica)}</strong></td>
                <td class="num">${formatMetric(row.periodo_actual, row.is_percentage)}</td>
                <td class="num">${formatMetric(row.periodo_comparado, row.is_percentage)}</td>
                <td class="num">${formatDiff(row.diferencia, row.is_percentage)}</td>
                <td class="num">${row.variacion_pct === null || row.variacion_pct === undefined ? '-' : `${formatSigned(row.variacion_pct)}%`}</td>
            </tr>
        `);
    });

    applyStoredSort(root);
}

function renderInsights(items) {
    const root = document.getElementById('insights');
    root.innerHTML = '';

    if (!items.length) {
        root.innerHTML = '<div class="priority-item">No hay datos suficientes para generar conclusiones.</div>';
        return;
    }

    items.forEach((item) => {
        root.insertAdjacentHTML('beforeend', `<div class="priority-item">${escapeHtml(item)}</div>`);
    });
}

function renderCommercials(rows) {
    renderRows('commercialRows', rows, [
        [(row) => row.comercial],
        [(row) => row.commercial_delegation || '-'],
        [(row) => row.zone || '-'],
        [(row) => formatNumber(row.leads_totales), true],
        [(row) => formatNumber(row.convertidos), true],
        [(row) => formatPercent(row.conversion_pct), true],
        [(row) => formatNumber(row.descartados), true],
        [(row) => formatPercent(row.descarte_pct), true],
        [(row) => formatNumber(row.potenciales), true],
        [(row) => formatNumber(row.potenciales_sin_trabajar), true],
        [(row) => formatNumber(row.gestionados), true],
        [(row) => formatPercent(row.gestionados_pct), true],
        [(row) => formatNumber(row.llamadas), true],
        [(row) => formatNumber(row.formularios), true],
    ], 'No hay datos de comerciales para los filtros seleccionados.');
}

function renderCommercialZones(rows) {
    renderRows('commercialZoneRows', rows, [
        [(row) => row.zone || '-'],
        [(row) => formatNumber(row.leads_totales), true],
        [(row) => formatNumber(row.convertidos), true],
        [(row) => formatPercent(row.conversion_pct), true],
        [(row) => formatNumber(row.descartados), true],
        [(row) => formatPercent(row.descarte_pct), true],
        [(row) => formatNumber(row.potenciales), true],
        [(row) => formatNumber(row.potenciales_sin_trabajar), true],
        [(row) => formatNumber(row.gestionados), true],
        [(row) => formatPercent(row.gestionados_pct), true],
        [(row) => formatNumber(row.llamadas), true],
        [(row) => formatNumber(row.formularios), true],
    ], 'No hay datos de zonas para los filtros seleccionados.');
}

function renderCommercialDelegations(rows) {
    renderRows('commercialDelegationRows', rows, [
        [(row) => row.commercial_delegation],
        [(row) => row.zone || '-'],
        [(row) => formatNumber(row.leads_totales), true],
        [(row) => formatNumber(row.convertidos), true],
        [(row) => formatPercent(row.conversion_pct), true],
        [(row) => formatNumber(row.descartados), true],
        [(row) => formatPercent(row.descarte_pct), true],
        [(row) => formatNumber(row.potenciales), true],
        [(row) => formatNumber(row.potenciales_sin_trabajar), true],
        [(row) => formatNumber(row.gestionados), true],
        [(row) => formatPercent(row.gestionados_pct), true],
        [(row) => formatNumber(row.llamadas), true],
        [(row) => formatNumber(row.formularios), true],
    ], 'No hay datos de delegaciones comerciales para los filtros seleccionados.');
}

function renderDelegations(rows) {
    renderRows('delegationRows', rows, [
        [(row) => row.delegacion],
        [(row) => row.lead_group || '-'],
        [(row) => row.zone || '-'],
        [(row) => formatNumber(row.leads_totales), true],
        [(row) => formatNumber(row.convertidos), true],
        [(row) => formatPercent(row.conversion_pct), true],
        [(row) => formatNumber(row.descartados), true],
        [(row) => formatPercent(row.descarte_pct), true],
        [(row) => formatNumber(row.potenciales), true],
        [(row) => formatNumber(row.potenciales_sin_trabajar), true],
        [(row) => formatNumber(row.gestionados), true],
        [(row) => formatPercent(row.gestionados_pct), true],
        [(row) => formatNumber(row.llamadas), true],
        [(row) => formatNumber(row.formularios), true],
    ], 'No hay datos de delegaciones para los filtros seleccionados.');
}

function renderPortals(rows) {
    renderRows('portalRows', rows, [
        [(row) => row.portal],
        [(row) => formatNumber(row.leads_totales), true],
        [(row) => formatNumber(row.llamadas), true],
        [(row) => formatNumber(row.formularios), true],
        [(row) => formatPercent(row.llamadas_pct), true],
        [(row) => formatPercent(row.formularios_pct), true],
        [(row) => formatNumber(row.convertidos), true],
        [(row) => formatPercent(row.conversion_pct), true],
        [(row) => formatNumber(row.descartados), true],
        [(row) => formatPercent(row.descarte_pct), true],
        [(row) => formatNumber(row.potenciales), true],
        [(row) => formatNumber(row.potenciales_sin_trabajar), true],
        [(row) => formatNumber(row.gestionados), true],
        [(row) => formatPercent(row.gestionados_pct), true],
    ], 'No hay datos de portales para los filtros seleccionados.');
}

function renderRows(rootId, rows, columns, emptyMessage) {
    const root = document.getElementById(rootId);
    root.innerHTML = '';

    if (!rows.length) {
        root.innerHTML = `<tr><td colspan="${columns.length}">${escapeHtml(emptyMessage)}</td></tr>`;
        return;
    }

    rows.forEach((row) => {
        const cells = columns.map(([formatter, numeric], index) => {
            const value = formatter(row) ?? '-';
            const className = numeric ? ' class="num"' : '';
            const content = index === 0 ? `<strong>${escapeHtml(value)}</strong>` : escapeHtml(value);

            return `<td${className}>${content}</td>`;
        }).join('');

        root.insertAdjacentHTML('beforeend', `<tr>${cells}</tr>`);
    });

    applyStoredSort(root);
}

function bindTableSorting() {
    document.querySelectorAll('table').forEach((table) => makeTableSortable(table));
}

function makeTableSortable(table) {
    table.querySelectorAll('thead th').forEach((header, index) => {
        header.dataset.sortable = 'true';
        header.addEventListener('click', () => {
            const tbody = table.querySelector('tbody');

            if (!tbody) {
                return;
            }

            const current = tableSortState.get(tbody.id);
            const direction = current?.columnIndex === index && current.direction === 'asc' ? 'desc' : 'asc';
            const state = { columnIndex: index, direction };

            tableSortState.set(tbody.id, state);
            sortRowsByColumn(table, index, direction);
            updateSortIndicators(table, state);
        });
    });
}

function applyStoredSort(tbody) {
    const table = tbody.closest('table');
    const state = tableSortState.get(tbody.id);

    if (!table || !state || state.columnIndex >= table.querySelectorAll('thead th').length) {
        return;
    }

    sortRowsByColumn(table, state.columnIndex, state.direction);
    updateSortIndicators(table, state);
}

function sortRowsByColumn(table, columnIndex, direction) {
    const tbody = table.querySelector('tbody');
    const multiplier = direction === 'asc' ? 1 : -1;
    const rows = [...tbody.querySelectorAll('tr')];

    rows.sort((a, b) => {
        const aValue = parseSortableValue(a.children[columnIndex]?.textContent);
        const bValue = parseSortableValue(b.children[columnIndex]?.textContent);

        if (aValue.empty && bValue.empty) {
            return 0;
        }

        if (aValue.empty) {
            return 1;
        }

        if (bValue.empty) {
            return -1;
        }

        if (aValue.type === 'number' && bValue.type === 'number') {
            return (aValue.value - bValue.value) * multiplier;
        }

        return aValue.value.localeCompare(bValue.value, 'es', { sensitivity: 'base' }) * multiplier;
    });

    rows.forEach((row) => tbody.appendChild(row));
}

function parseSortableValue(value) {
    const raw = String(value || '').trim();

    if (raw === '' || raw === '-') {
        return { empty: true, type: 'text', value: '' };
    }

    const normalized = raw
        .replaceAll('%', '')
        .replace(/\s+/g, '')
        .replace(/^\+/, '');
    const numericCandidate = normalized.includes(',')
        ? normalized.replaceAll('.', '').replace(',', '.')
        : (/^-?\d{1,3}(\.\d{3})+(\.\d+)?$/.test(normalized) ? normalized.replaceAll('.', '') : normalized);
    const number = Number(numericCandidate);

    if (!Number.isNaN(number) && /^-?\d+(\.\d+)?$/.test(numericCandidate)) {
        return { empty: false, type: 'number', value: number };
    }

    return { empty: false, type: 'text', value: raw.toLocaleLowerCase('es') };
}

function updateSortIndicators(table, state) {
    table.querySelectorAll('thead th').forEach((header, index) => {
        header.querySelector('.sort-indicator')?.remove();

        if (index === state.columnIndex) {
            header.insertAdjacentHTML('beforeend', ` <span class="sort-indicator">${state.direction === 'asc' ? '▲' : '▼'}</span>`);
        }
    });
}

function renderFilterOptions(filters) {
    fillSelect('commercial', filters.commercials || [], 'id', 'name');
    fillSelect('leadDelegation', (filters.lead_delegations || []).map((item) => ({ id: item, name: item })), 'id', 'name');
    fillSelect('leadGroup', (filters.lead_groups || []).map((item) => ({ id: item, name: item })), 'id', 'name');
    fillSelect('commercialDelegation', (filters.commercial_delegations || []).map((item) => ({ id: item, name: item })), 'id', 'name');
    fillSelect('zone', (filters.zones || []).map((item) => ({ id: item, name: item })), 'id', 'name');
    fillSelect('portal', (filters.portals || []).map((item) => ({ id: item, name: item })), 'id', 'name');
}

function fillSelect(id, items, valueKey, labelKey) {
    const select = document.getElementById(id);
    const current = select.value;
    const first = select.querySelector('option')?.outerHTML || '<option value="">Todos</option>';

    select.innerHTML = first;

    items.forEach((item) => {
        const option = document.createElement('option');
        option.value = item[valueKey];
        option.textContent = item[labelKey];
        select.appendChild(option);
    });

    select.value = [...select.options].some((option) => option.value === current) ? current : '';
}

function currentFilters() {
    const params = new URLSearchParams();

    setParam(params, 'period', document.getElementById('period')?.value);
    setParam(params, 'channel', document.getElementById('channel')?.value);
    setParam(params, 'portal', document.getElementById('portal')?.value);
    setParam(params, 'status', document.getElementById('status')?.value);
    setParam(params, 'lead_type', document.getElementById('leadType')?.value);
    setParam(params, 'lead_delegation', document.getElementById('leadDelegation')?.value);
    setParam(params, 'lead_group', document.getElementById('leadGroup')?.value);
    setParam(params, 'commercial_delegation', document.getElementById('commercialDelegation')?.value);
    setParam(params, 'zone', document.getElementById('zone')?.value);
    setParam(params, 'commercial', document.getElementById('commercial')?.value);
    setParam(params, 'exposition_mode', document.getElementById('expositionMode')?.value);

    if (document.getElementById('period')?.value === 'custom') {
        setParam(params, 'current_start', document.getElementById('currentStart')?.value);
        setParam(params, 'current_end', document.getElementById('currentEnd')?.value);
        setParam(params, 'comparison_start', document.getElementById('comparisonStart')?.value);
        setParam(params, 'comparison_end', document.getElementById('comparisonEnd')?.value);
    }

    return params.toString();
}

function setLoadingState(isLoading) {
    const loading = document.getElementById('loadingMessage');

    loading?.classList.toggle('is-hidden', !isLoading);

    if (isLoading) {
        document.getElementById('updatedBadge').textContent = 'Cargando datos de Salesforce...';
        document.getElementById('emptyMessage')?.classList.add('is-hidden');
    }
}

function showLoadError(error) {
    const empty = document.getElementById('emptyMessage');
    empty.classList.remove('is-hidden');
    empty.textContent = error?.message || 'No se han podido cargar los datos de Salesforce.';
}

function setParam(params, key, value) {
    if (value) {
        params.set(key, value);
    }
}

function toggleCustomPeriods() {
    document.getElementById('customPeriods')?.classList.toggle(
        'is-hidden',
        document.getElementById('period')?.value !== 'custom'
    );
}

async function fetchJson(url) {
    const response = await fetch(url, { headers: { Accept: 'application/json' } });

    if (!response.ok) {
        throw new Error(`Error cargando ${url}`);
    }

    return response.json();
}

function periodText(period) {
    if (!period) {
        return '-';
    }

    return `${period.inicio || '-'} a ${period.fin || '-'}`;
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

    return fmt.format(Number(value));
}

function formatPercent(value) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return '-';
    }

    return `${Number(value).toFixed(1)}%`;
}

function formatMetric(value, isPercentage) {
    return isPercentage ? formatPercent(value) : formatNumber(value);
}

function formatDiff(value, isPercentage) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return '-';
    }

    const number = Number(value);
    const sign = number > 0 ? '+' : '';

    return isPercentage
        ? `${sign}${number.toFixed(1)} pp`
        : `${sign}${fmt.format(number)}`;
}

function formatSigned(value) {
    const number = Number(value);
    const sign = number > 0 ? '+' : '';

    return `${sign}${number.toFixed(1)}`;
}

function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}
