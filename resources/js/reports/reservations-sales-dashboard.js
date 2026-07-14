const fmt = new Intl.NumberFormat('es-ES');
const tableSortState = new Map();
const reservationsCommercialColumnsStorageKey = 'reservationsCommercialColumns';
const reservationsCommercialColumnDefinitions = [
    { key: 'comercial', label: 'Comercial', alwaysVisible: true },
    { key: 'commercial_delegation', label: 'Delegacion comercial', alwaysVisible: true },
    { key: 'zone', label: 'Zona', alwaysVisible: true },
    { key: 'oportunidades_totales', label: 'Oportunidades totales', alwaysVisible: true },
    { key: 'reservas_vivas', label: 'Reservas vivas', alwaysVisible: true },
    { key: 'reservas_vivas_pct', label: '% reservas vivas' },
    { key: 'oportunidades_caidas', label: 'Oportunidades caidas', alwaysVisible: true },
    { key: 'oportunidades_caidas_pct', label: '% oportunidades caidas' },
    { key: 'cv_firmados', label: 'Contratos CV firmados', alwaysVisible: true },
    { key: 'cv_firmados_pct', label: '% contratos CV firmados' },
];
let reservationsCommercialVisibleColumns = loadVisibleColumns(
    reservationsCommercialColumnsStorageKey,
    reservationsCommercialColumnDefinitions
);

document.addEventListener('DOMContentLoaded', async () => {
    bindTabs();
    bindFilters();
    bindResetFilters();
    bindTableSorting();
    initReservationsCommercialColumns();
    bindReservationsCommercialSearch();
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

function bindResetFilters() {
    document.getElementById('resetFilters')?.addEventListener('click', async () => {
        [
            'commercialDelegation',
            'zone',
            'commercial',
            'currentStart',
            'currentEnd',
            'comparisonStart',
            'comparisonEnd',
        ].forEach((id) => {
            const element = document.getElementById(id);

            if (element) {
                element.value = '';
            }
        });

        document.getElementById('period').value = 'last_30_days';
        document.getElementById('dateCriterion').value = 'created_date';
        document.getElementById('opportunityType').value = 'all';
        toggleCustomPeriods();
        await reloadAllData();
    });
}

function bindFilters() {
    [
        'period',
        'dateCriterion',
        'opportunityType',
        'commercialDelegation',
        'zone',
        'commercial',
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
        const summary = await fetchJson(`/informes/reservas-ventas/data/summary?${filters}`);
        renderSummary(summary);
        renderFilterOptions(summary.filters || {});

        const [commercials, portals] = await Promise.all([
            fetchJson(`/informes/reservas-ventas/data/commercials?${filters}`),
            fetchJson(`/informes/reservas-ventas/data/portals?${filters}`),
        ]);

        renderCommercialZones(commercials.zones || []);
        renderCommercialDelegations(commercials.delegations || []);
        renderCommercials(commercials.commercials || commercials.items || []);
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
    empty.textContent = data.message || 'No hay oportunidades sincronizadas para el periodo seleccionado.';

    renderKpis(data.kpis || {});
    renderComparison(data.comparativa || []);
    renderInsights(data.executive_insights || data.insights || []);
}

function renderKpis(kpis) {
    const root = document.getElementById('summaryKpis');
    const cards = [
        { label: 'Oportunidades totales', value: formatNumber(kpis.oportunidades_totales), hint: 'Muestra del periodo', metric: 'oportunidades_totales' },
        { label: 'Reservas vivas', value: formatNumber(kpis.reservas_vivas), hint: `${formatPercent(kpis.reservas_vivas_pct)} sobre total`, metric: 'reservas_vivas' },
        { label: 'Reservas vivas actuales Salesforce', value: formatNumber(kpis.reservas_vivas_actuales_salesforce), hint: 'Sin filtro de fecha', metric: 'reservas_vivas_actuales_salesforce' },
        { label: 'Oportunidades caidas', value: formatNumber(kpis.oportunidades_caidas), hint: `${formatPercent(kpis.oportunidades_caidas_pct)} sobre total`, metric: 'oportunidades_caidas' },
        { label: 'Contratos CV firmados', value: formatNumber(kpis.cv_firmados), hint: `${formatPercent(kpis.cv_firmados_pct)} sobre total`, metric: 'cv_firmados' },
    ];

    root.innerHTML = '';

    cards.forEach((card) => {
        root.insertAdjacentHTML('beforeend', `
            <div class="card kpi">
                <div class="kpi-copy">
                    <div class="kpi-label">${escapeHtml(card.label)}</div>
                    <div class="kpi-value">${escapeHtml(card.value)}</div>
                    <div class="kpi-hint">${escapeHtml(card.hint)}</div>
                    ${kpiAuditLinkHtml(card.metric, card.label)}
                </div>
            </div>
        `);
    });
}

function renderComparison(rows) {
    const root = document.getElementById('comparisonRows');
    root.innerHTML = '';

    if (!rows.length) {
        root.innerHTML = '<tr><td colspan="4">No hay datos para comparar.</td></tr>';
        return;
    }

    rows.forEach((row) => {
        root.insertAdjacentHTML('beforeend', `
            <tr>
                <td><strong>${escapeHtml(row.metrica)}</strong></td>
                <td class="num" data-sort-value="${escapeHtml(row.periodo_actual ?? '')}">${formatComparisonValue(row, 'periodo_actual')}</td>
                <td class="num" data-sort-value="${escapeHtml(row.periodo_comparado ?? '')}">${formatComparisonValue(row, 'periodo_comparado')}</td>
                <td class="num" data-sort-value="${escapeHtml(row.diferencia ?? '')}">${formatComparisonDiff(row)}</td>
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
        if (typeof item === 'string') {
            root.insertAdjacentHTML('beforeend', `<div class="priority-item">${escapeHtml(item)}</div>`);
            return;
        }

        const priority = normalizePriority(item.prioridad || 'media');

        root.insertAdjacentHTML('beforeend', `
            <article class="priority-item priority-${escapeHtml(priority)}">
                <div class="priority-head">
                    <b>${escapeHtml(item.titulo || 'Conclusión')}</b>
                    <span class="priority-badge">${escapeHtml(priority)}</span>
                </div>
                <p>${escapeHtml(item.problema_detectado || '')}</p>
                <p><b>Evidencia:</b> ${escapeHtml(item.evidencia || '')}</p>
                <p><b>Recomendación:</b> ${escapeHtml(item.recomendacion || '')}</p>
            </article>
        `);
    });
}

function renderCommercialZones(rows) {
    renderRows('commercialZoneRows', rows, [
        [(row) => row.zone || '-'],
        [(row) => formatNumber(row.oportunidades_totales), true],
        [(row) => formatCountPercent(row.reservas_vivas, row.reservas_vivas_pct), true, (row) => row.reservas_vivas, true],
        [(row) => formatCountPercent(row.oportunidades_caidas, row.oportunidades_caidas_pct), true, (row) => row.oportunidades_caidas, true],
        [(row) => formatCountPercent(row.cv_firmados, row.cv_firmados_pct), true, (row) => row.cv_firmados, true],
    ], 'No hay datos de zonas para los filtros seleccionados.');
}

function renderCommercialDelegations(rows) {
    renderRows('commercialDelegationRows', rows, [
        [(row) => row.commercial_delegation || '-'],
        [(row) => row.zone || '-'],
        [(row) => formatNumber(row.oportunidades_totales), true],
        [(row) => formatCountPercent(row.reservas_vivas, row.reservas_vivas_pct), true, (row) => row.reservas_vivas, true],
        [(row) => formatCountPercent(row.oportunidades_caidas, row.oportunidades_caidas_pct), true, (row) => row.oportunidades_caidas, true],
        [(row) => formatCountPercent(row.cv_firmados, row.cv_firmados_pct), true, (row) => row.cv_firmados, true],
    ], 'No hay datos de delegaciones para los filtros seleccionados.');
}

function renderCommercials(rows) {
    renderRows('commercialRows', rows, [
        [(row) => row.comercial || '-', false, null, false, 'comercial'],
        [(row) => row.commercial_delegation || '-', false, null, false, 'commercial_delegation'],
        [(row) => row.zone || '-', false, null, false, 'zone'],
        [(row) => formatNumber(row.oportunidades_totales), true, (row) => row.oportunidades_totales, false, 'oportunidades_totales'],
        [(row) => formatCountPercent(row.reservas_vivas, row.reservas_vivas_pct), true, (row) => row.reservas_vivas, true, 'reservas_vivas'],
        [(row) => formatPercent(row.reservas_vivas_pct), true, (row) => row.reservas_vivas_pct, false, 'reservas_vivas_pct'],
        [(row) => formatCountPercent(row.oportunidades_caidas, row.oportunidades_caidas_pct), true, (row) => row.oportunidades_caidas, true, 'oportunidades_caidas'],
        [(row) => formatPercent(row.oportunidades_caidas_pct), true, (row) => row.oportunidades_caidas_pct, false, 'oportunidades_caidas_pct'],
        [(row) => formatCountPercent(row.cv_firmados, row.cv_firmados_pct), true, (row) => row.cv_firmados, true, 'cv_firmados'],
        [(row) => formatPercent(row.cv_firmados_pct), true, (row) => row.cv_firmados_pct, false, 'cv_firmados_pct'],
    ], 'No hay datos de comerciales para los filtros seleccionados.', (row) => ({
        'data-search': `${row.comercial || ''} ${row.commercial_id || row.group_key || ''}`.trim(),
    }));

    applyReservationsCommercialColumnVisibility();
    applyReservationsCommercialSearchFilter();
}

function renderPortals(rows) {
    renderRows('portalRows', rows, [
        [(row) => row.portal || '-'],
        [(row) => formatNumber(row.oportunidades_totales), true],
        [(row) => formatCountPercent(row.reservas_vivas, row.reservas_vivas_pct), true, (row) => row.reservas_vivas, true],
        [(row) => formatCountPercent(row.oportunidades_caidas, row.oportunidades_caidas_pct), true, (row) => row.oportunidades_caidas, true],
        [(row) => formatCountPercent(row.cv_firmados, row.cv_firmados_pct), true, (row) => row.cv_firmados, true],
    ], 'No hay datos de portales para los filtros seleccionados.');
}

function renderRows(rootId, rows, columns, emptyMessage, rowMeta = null) {
    const root = document.getElementById(rootId);
    root.innerHTML = '';

    if (!rows.length) {
        root.innerHTML = `<tr><td colspan="${columns.length}">${escapeHtml(emptyMessage)}</td></tr>`;
        return;
    }

    rows.forEach((row) => {
        const cells = columns.map(([formatter, numeric, sortFormatter, html, columnKey], index) => {
            const value = formatter(row) ?? '-';
            const className = numeric ? ' class="num"' : '';
            const content = html ? value : (index === 0 ? `<strong>${escapeHtml(value)}</strong>` : escapeHtml(value));
            const sortValue = sortFormatter ? ` data-sort-value="${escapeHtml(sortFormatter(row) ?? '')}"` : '';
            const columnAttr = columnKey ? ` data-column="${escapeHtml(columnKey)}"` : '';

            return `<td${className}${sortValue}${columnAttr}>${content}</td>`;
        }).join('');

        const attrs = rowMeta ? rowMeta(row) : {};
        const attrString = Object.entries(attrs)
            .filter(([, value]) => value !== null && value !== undefined && value !== '')
            .map(([key, value]) => ` ${escapeHtml(key)}="${escapeHtml(value)}"`)
            .join('');

        root.insertAdjacentHTML('beforeend', `<tr${attrString}>${cells}</tr>`);
    });

    applyStoredSort(root);
}

function initReservationsCommercialColumns() {
    const button = document.getElementById('reservationsCommercialColumnsButton');
    const popover = document.getElementById('reservationsCommercialColumnsPopover');

    if (!button || !popover) {
        return;
    }

    renderReservationsCommercialColumnsPopover();
    applyReservationsCommercialColumnVisibility();

    button.addEventListener('click', () => {
        popover.classList.toggle('is-hidden');
    });

    popover.addEventListener('change', (event) => {
        const input = event.target.closest('[data-column-toggle]');

        if (!input) {
            return;
        }

        const visible = new Set(reservationsCommercialVisibleColumns);
        const key = input.dataset.columnToggle;

        if (input.checked) {
            visible.add(key);
        } else {
            visible.delete(key);
        }

        reservationsCommercialVisibleColumns = reservationsCommercialColumnDefinitions
            .filter((column) => column.alwaysVisible || visible.has(column.key))
            .map((column) => column.key);

        localStorage.setItem(
            reservationsCommercialColumnsStorageKey,
            JSON.stringify(reservationsCommercialVisibleColumns)
        );
        applyReservationsCommercialColumnVisibility();
    });

    document.addEventListener('click', (event) => {
        if (!event.target.closest('.columns-menu')) {
            popover.classList.add('is-hidden');
        }
    });
}

function renderReservationsCommercialColumnsPopover() {
    const root = document.getElementById('reservationsCommercialColumnsPopover');

    if (!root) {
        return;
    }

    root.innerHTML = reservationsCommercialColumnDefinitions
        .filter((column) => !column.alwaysVisible)
        .map((column) => `
            <label class="column-option switch-option">
                <input type="checkbox" data-column-toggle="${escapeHtml(column.key)}" ${reservationsCommercialVisibleColumns.includes(column.key) ? 'checked' : ''}>
                <span>${escapeHtml(column.label)}</span>
            </label>
        `)
        .join('');
}

function applyReservationsCommercialColumnVisibility() {
    document.querySelectorAll('#reservationsCommercialTable [data-column]').forEach((cell) => {
        cell.classList.toggle('is-hidden', !reservationsCommercialVisibleColumns.includes(cell.dataset.column));
    });
}

function bindReservationsCommercialSearch() {
    document.getElementById('reservationsCommercialSearch')?.addEventListener('input', applyReservationsCommercialSearchFilter);
}

function applyReservationsCommercialSearchFilter() {
    const term = String(document.getElementById('reservationsCommercialSearch')?.value || '')
        .trim()
        .toLocaleLowerCase('es');

    document.querySelectorAll('#commercialRows tr').forEach((row) => {
        const haystack = String(row.dataset.search || '').toLocaleLowerCase('es');
        row.classList.toggle('is-hidden', term !== '' && !haystack.includes(term));
    });
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
    const normalized = primary
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

function currentFilters() {
    const params = new URLSearchParams();

    setParam(params, 'period', document.getElementById('period')?.value);
    setParam(params, 'date_criterion', document.getElementById('dateCriterion')?.value);
    setParam(params, 'opportunity_type', document.getElementById('opportunityType')?.value);
    setParam(params, 'commercial_delegation', document.getElementById('commercialDelegation')?.value);
    setParam(params, 'zone', document.getElementById('zone')?.value);
    setParam(params, 'commercial', document.getElementById('commercial')?.value);

    if (document.getElementById('period')?.value === 'custom') {
        setParam(params, 'current_start', document.getElementById('currentStart')?.value);
        setParam(params, 'current_end', document.getElementById('currentEnd')?.value);
        setParam(params, 'comparison_start', document.getElementById('comparisonStart')?.value);
        setParam(params, 'comparison_end', document.getElementById('comparisonEnd')?.value);
    }

    return params.toString();
}

function renderFilterOptions(filters) {
    fillSelect('commercial', filters.commercials || [], 'id', 'name');
    fillSelect('commercialDelegation', (filters.commercial_delegations || []).map((item) => ({ id: item, name: item })), 'id', 'name');
    fillSelect('zone', (filters.zones || []).map((item) => ({ id: item, name: item })), 'id', 'name');
}

function fillSelect(id, items, valueKey, labelKey) {
    const select = document.getElementById(id);

    if (!select) {
        return;
    }

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

function buildKpiAuditUrl(metric) {
    const params = new URLSearchParams(currentFilters());
    params.set('metric', metric);

    return `/informes/reservas-ventas/export/kpi-audit.csv?${params.toString()}`;
}

function kpiAuditLinkHtml(metric, label) {
    if (!window.reportUserCanExport || !metric) {
        return '';
    }

    return `<div class="kpi-actions"><a class="kpi-audit-link" href="${escapeHtml(buildKpiAuditUrl(metric))}" title="Auditar ${escapeHtml(label)}">Auditar KPI</a></div>`;
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

    return `${formatDate(period.inicio) || '-'} a ${formatDate(period.fin) || '-'}`;
}

function formatDate(value) {
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value || '-';
    }

    return new Intl.DateTimeFormat('es-ES', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    }).format(date);
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

function formatCountPercent(count, percent) {
    return `<span class="metric-value">${escapeHtml(formatNumber(count))}</span><span class="metric-percent">(${escapeHtml(formatPercent(percent))})</span>`;
}

function formatComparisonValue(row, key) {
    const value = row[key];
    const percent = row[`${key}_pct`];

    return row.is_compact ? formatCountPercent(value, percent) : escapeHtml(formatNumber(value));
}

function formatComparisonDiff(row) {
    const count = formatDiff(row.diferencia, false);

    if (!row.is_compact || row.diferencia_pct_puntos === null || row.diferencia_pct_puntos === undefined) {
        return escapeHtml(count);
    }

    return `<span class="metric-value">${escapeHtml(count)}</span><span class="metric-percent">(${escapeHtml(formatDiff(row.diferencia_pct_puntos, true))})</span>`;
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

function normalizePriority(value) {
    const priority = String(value || 'media')
        .trim()
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '');

    if (priority === 'alta') {
        return 'alta';
    }

    if (priority === 'baja') {
        return 'baja';
    }

    return 'media';
}

function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function loadVisibleColumns(storageKey, definitions) {
    const defaultColumns = definitions.filter((column) => column.alwaysVisible).map((column) => column.key);

    try {
        const stored = JSON.parse(localStorage.getItem(storageKey) || '[]');

        if (!Array.isArray(stored) || !stored.length) {
            return defaultColumns;
        }

        const valid = stored.filter((key) => definitions.some((column) => column.key === key));

        return definitions
            .filter((column) => column.alwaysVisible || valid.includes(column.key))
            .map((column) => column.key);
    } catch (error) {
        return defaultColumns;
    }
}
