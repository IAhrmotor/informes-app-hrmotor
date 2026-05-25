const fmt = new Intl.NumberFormat('es-ES');
const tableSortState = new Map();

document.addEventListener('DOMContentLoaded', async () => {
    bindTabs();
    bindFilters();
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
        'team',
        'direction',
        'status',
        'origin',
        'delegation',
        'zone',
        'portal',
        'user',
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

async function reloadAllData() {
    setLoadingState(true);

    try {
        const filters = currentFilters();
        const summary = await fetchJson(`/informes/llamadas/data/summary?${filters}`);
        renderSummary(summary);
        renderFilterOptions(summary.filters || {});

        const [agents, delegations, portals] = await Promise.all([
            fetchJson(`/informes/llamadas/data/agents?${currentFilters()}`),
            fetchJson(`/informes/llamadas/data/delegations?${currentFilters()}`),
            fetchJson(`/informes/llamadas/data/portals?${currentFilters()}`),
        ]);

        renderTeams(agents.teams || []);
        renderAgents(agents.agents || agents.items || []);
        renderZones(delegations.zones || []);
        renderDelegations(delegations.delegations || delegations.items || []);
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
    empty.textContent = data.message || 'No hay llamadas sincronizadas para el periodo seleccionado.';

    renderKpis(data.kpis || {});
    renderComparison(data.comparativa || []);
    renderInsights(data.insights || []);
}

function renderKpis(kpis) {
    const root = document.getElementById('summaryKpis');
    const cards = [
        ['Total llamadas', formatNumber(kpis.total_calls), 'Interacciones registradas'],
        ['Directas a comercial', formatNumber(kpis.commercial_direct_calls), 'Comercial directo'],
        ['Centralita', formatNumber(kpis.switchboard_calls), 'Llamada directa'],
        ['Portales', formatNumber(kpis.portal_calls), 'Procedencia clasificada'],
        ['Atendidas', formatNumber(kpis.answered), `${formatPercent(kpis.answered_pct)} sobre total`],
        ['No atendidas/perdidas', formatNumber(kpis.not_answered), `${formatPercent(kpis.not_answered_pct)} sobre total`],
        ['Tiempo medio', formatSeconds(kpis.average_talk_seconds), 'Solo llamadas atendidas'],
        ['Entrantes', formatNumber(kpis.inbound), `${formatPercent(kpis.inbound_pct)} sobre total`],
        ['Salientes', formatNumber(kpis.outbound), `${formatPercent(kpis.outbound_pct)} sobre total`],
        ['Atendidas comerciales', formatNumber(kpis.answered_commercial), 'Equipo comerciales'],
        ['Atendidas atencion', formatNumber(kpis.answered_customer_service), 'Atencion al Cliente'],
        ['Atendidas contact center', formatNumber(kpis.answered_contact_center), 'Contact Center'],
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
    renderRows('comparisonRows', rows, [
        [(row) => row.metrica],
        [(row) => formatComparisonValue(row.periodo_actual, row.is_seconds), true, (row) => row.periodo_actual, true],
        [(row) => formatComparisonValue(row.periodo_comparado, row.is_seconds), true, (row) => row.periodo_comparado, true],
        [(row) => formatComparisonDiff(row.diferencia, row.is_seconds), true, (row) => row.diferencia, true],
    ], 'No hay datos para comparar.');
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

function renderTeams(rows) {
    renderMetricRows('teamRows', rows, [[(row) => row.team_label]], 'No hay datos de equipos para los filtros seleccionados.');
}

function renderAgents(rows) {
    renderMetricRows('agentRows', rows, [
        [(row) => row.user_name || '-'],
        [(row) => row.team_label || '-'],
        [(row) => row.delegation || '-'],
        [(row) => row.zone || '-'],
    ], 'No hay datos de usuarios/agentes para los filtros seleccionados.');
}

function renderZones(rows) {
    renderMetricRows('zoneRows', rows, [[(row) => row.zone || '-']], 'No hay datos de zonas para los filtros seleccionados.');
}

function renderDelegations(rows) {
    renderMetricRows('delegationRows', rows, [
        [(row) => row.delegation || '-'],
        [(row) => row.zone || '-'],
    ], 'No hay datos de delegaciones para los filtros seleccionados.');
}

function renderPortals(rows) {
    renderMetricRows('portalRows', rows, [
        [(row) => row.portal || '-'],
        [(row) => row.call_origin_label || '-'],
    ], 'No hay datos de portales para los filtros seleccionados.');
}

function renderMetricRows(rootId, rows, leadingColumns, emptyMessage) {
    renderRows(rootId, rows, [
        ...leadingColumns,
        [(row) => formatNumber(row.total_calls), true],
        [(row) => formatCountPercent(row.answered, row.answered_pct), true, (row) => row.answered, true],
        [(row) => formatCountPercent(row.not_answered, row.not_answered_pct), true, (row) => row.not_answered, true],
        [(row) => formatCountPercent(row.inbound, row.inbound_pct), true, (row) => row.inbound, true],
        [(row) => formatCountPercent(row.outbound, row.outbound_pct), true, (row) => row.outbound, true],
        [(row) => formatSeconds(row.average_talk_seconds), true, (row) => row.average_talk_seconds, false],
    ], emptyMessage);
}

function renderRows(rootId, rows, columns, emptyMessage) {
    const root = document.getElementById(rootId);
    root.innerHTML = '';

    if (!rows.length) {
        root.innerHTML = `<tr><td colspan="${columns.length}">${escapeHtml(emptyMessage)}</td></tr>`;
        return;
    }

    rows.forEach((row) => {
        const cells = columns.map(([formatter, numeric, sortFormatter, html], index) => {
            const value = formatter(row) ?? '-';
            const className = numeric ? ' class="num"' : '';
            const content = html ? value : (index === 0 ? `<strong>${escapeHtml(value)}</strong>` : escapeHtml(value));
            const sortValue = sortFormatter ? ` data-sort-value="${escapeHtml(sortFormatter(row) ?? '')}"` : '';

            return `<td${className}${sortValue}>${content}</td>`;
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
        const aCell = a.children[columnIndex];
        const bCell = b.children[columnIndex];
        const aValue = parseSortableValue(aCell?.dataset.sortValue || aCell?.textContent);
        const bValue = parseSortableValue(bCell?.dataset.sortValue || bCell?.textContent);

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

    const primary = raw.split('(')[0].trim();
    const normalized = primary.replaceAll('%', '').replace(/\s+/g, '').replace(/^\+/, '');
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
    fillSelect('team', filters.teams || [], 'id', 'name');
    fillSelect('direction', filters.directions || [], 'id', 'name');
    fillSelect('status', filters.statuses || [], 'id', 'name');
    fillSelect('origin', filters.origins || [], 'id', 'name');
    fillSelect('delegation', (filters.delegations || []).map((item) => ({ id: item, name: item })), 'id', 'name');
    fillSelect('zone', (filters.zones || []).map((item) => ({ id: item, name: item })), 'id', 'name');
    fillSelect('portal', (filters.portals || []).map((item) => ({ id: item, name: item })), 'id', 'name');
    fillSelect('user', filters.users || [], 'id', 'name');
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
    setParam(params, 'team', document.getElementById('team')?.value);
    setParam(params, 'direction', document.getElementById('direction')?.value);
    setParam(params, 'status', document.getElementById('status')?.value);
    setParam(params, 'origin', document.getElementById('origin')?.value);
    setParam(params, 'delegation', document.getElementById('delegation')?.value);
    setParam(params, 'zone', document.getElementById('zone')?.value);
    setParam(params, 'portal', document.getElementById('portal')?.value);
    setParam(params, 'user', document.getElementById('user')?.value);

    if (document.getElementById('period')?.value === 'custom') {
        setParam(params, 'current_start', document.getElementById('currentStart')?.value);
        setParam(params, 'current_end', document.getElementById('currentEnd')?.value);
        setParam(params, 'comparison_start', document.getElementById('comparisonStart')?.value);
        setParam(params, 'comparison_end', document.getElementById('comparisonEnd')?.value);
    }

    return params.toString();
}

function setLoadingState(isLoading) {
    document.getElementById('loadingMessage')?.classList.toggle('is-hidden', !isLoading);

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
    document.getElementById('customPeriods')?.classList.toggle('is-hidden', document.getElementById('period')?.value !== 'custom');
}

async function fetchJson(url) {
    const response = await fetch(url, { headers: { Accept: 'application/json' } });

    if (!response.ok) {
        throw new Error(`Error cargando ${url}`);
    }

    return response.json();
}

function periodText(period) {
    return period ? `${formatDate(period.inicio) || '-'} a ${formatDate(period.fin) || '-'}` : '-';
}

function formatDate(value) {
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? value || '-' : new Intl.DateTimeFormat('es-ES', { day: '2-digit', month: '2-digit', year: 'numeric' }).format(date);
}

function formatDateTime(value) {
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? value || '-' : new Intl.DateTimeFormat('es-ES', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(date);
}

function formatNumber(value) {
    return value === null || value === undefined || Number.isNaN(Number(value)) ? '-' : fmt.format(Number(value));
}

function formatPercent(value) {
    return value === null || value === undefined || Number.isNaN(Number(value)) ? '-' : `${Number(value).toFixed(2)}%`;
}

function formatSeconds(value) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return '-';
    }

    const seconds = Math.max(0, Math.round(Number(value)));
    return `${String(Math.floor(seconds / 60)).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')}`;
}

function formatCountPercent(count, percent) {
    return `<span class="metric-value">${escapeHtml(formatNumber(count))}</span><span class="metric-percent">(${escapeHtml(formatPercent(percent))})</span>`;
}

function formatComparisonValue(value, seconds) {
    return escapeHtml(seconds ? formatSeconds(value) : formatNumber(value));
}

function formatComparisonDiff(value, seconds) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return '-';
    }

    const number = Number(value);
    const sign = number > 0 ? '+' : '';
    return escapeHtml(seconds ? `${sign}${formatSeconds(Math.abs(number))}` : `${sign}${fmt.format(number)}`);
}

function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}
