const fmt = new Intl.NumberFormat('es-ES');

document.addEventListener('DOMContentLoaded', async () => {
    bindTabs();
    bindFilters();
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
        'leadDelegation',
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

        renderCommercials(commercials.items || []);
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

function renderDelegations(rows) {
    renderRows('delegationRows', rows, [
        [(row) => row.zone || '-'],
        [(row) => row.delegacion],
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
}

function renderFilterOptions(filters) {
    fillSelect('commercial', filters.commercials || [], 'id', 'name');
    fillSelect('leadDelegation', (filters.lead_delegations || []).map((item) => ({ id: item, name: item })), 'id', 'name');
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
    setParam(params, 'lead_delegation', document.getElementById('leadDelegation')?.value);
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
