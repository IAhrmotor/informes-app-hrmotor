const fmt = new Intl.NumberFormat('es-ES');

const state = {
    selectedPortal: 'Web',
    portals: [],
    monthlyReportLoaded: false,
};

document.addEventListener('DOMContentLoaded', async () => {
    bindTabs();
    bindFilters();

    await loadResumen();
    await loadKpis();
    await loadPortales();
    await loadPortalDetalle(state.selectedPortal);
    await loadDelegaciones();
    await loadComerciales();
    await loadComparativa();
    await loadCalidadDato();
});

function bindTabs() {
    document.querySelectorAll('.main-tab').forEach((button) => {
        button.addEventListener('click', () => {
            const panelId = button.dataset.panel;

            document.querySelectorAll('.main-tab').forEach((item) => item.classList.remove('active'));
            document.querySelectorAll('.tab-panel').forEach((panel) => panel.classList.remove('active'));

            button.classList.add('active');
            document.getElementById(panelId)?.classList.add('active');

            if (panelId === 'panel-informe-mensual' && !state.monthlyReportLoaded) {
                loadMonthlyCommercialReport();
            }
        });
    });
}

function bindFilters() {
    ['period', 'expositionMode', 'channel', 'portal'].forEach((id) => {
        const element = document.getElementById(id);

        if (!element) {
            return;
        }

        element.addEventListener('change', async () => {
            await reloadAllData();
        });
    });
}

async function loadResumen() {
    const data = await fetchJson('/informes/leads/data/resumen');

    document.getElementById('kpiTotal').textContent = fmt.format(data.total_leads ?? 0);
    document.getElementById('kpiCalls').textContent = fmt.format(data.llamadas ?? 0);
    document.getElementById('kpiForms').textContent = fmt.format(data.formularios ?? 0);
    document.getElementById('kpiPending').textContent = fmt.format(data.pendientes_clasificar ?? 0);
}

async function loadKpis() {
    const data = await fetchJson('/informes/leads/data/kpis');
    const root = document.getElementById('kpiRows');

    root.innerHTML = '';

    data.items.forEach((row) => {
        root.insertAdjacentHTML('beforeend', `
            <tr>
                <td><strong>${escapeHtml(row.metrica)}</strong></td>
                <td class="num">${formatValue(row.con_exposicion)}</td>
                <td class="num">${formatValue(row.sin_exposicion)}</td>
                <td class="num">${formatValue(row.diferencia)}</td>
            </tr>
        `);
    });
}

async function loadPortales() {
    const data = await fetchJson('/informes/leads/data/portales');
    state.portals = data.items;

    renderPortalSelect();
    renderPortalBars();
    renderPortalList();
    renderPortalTable();
}

function renderPortalSelect() {
    const select = document.getElementById('portal');

    if (!select) {
        return;
    }

    const currentValue = select.value;

    select.innerHTML = '<option value="">Todos</option>';

    state.portals.forEach((row) => {
        const option = document.createElement('option');
        option.value = row.portal;
        option.textContent = row.portal;
        select.appendChild(option);
    });

    select.value = currentValue;
}

function renderPortalBars() {
    const root = document.getElementById('portalBars');
    const maxTotal = Math.max(...state.portals.map((row) => row.total), 1);

    root.innerHTML = '';

    state.portals.forEach((row) => {
        const callPct = row.total ? (row.llamadas / row.total) * 100 : 0;
        const formPct = row.total ? (row.formularios / row.total) * 100 : 0;
        const scalePct = (row.total / maxTotal) * 100;

        root.insertAdjacentHTML('beforeend', `
            <div class="bar-row">
                <div class="portal-name" title="${escapeHtml(row.portal)}">${escapeHtml(row.portal)}</div>
                <div class="stack" title="${fmt.format(row.llamadas)} llamadas · ${fmt.format(row.formularios)} formularios" style="width:${Math.max(scalePct, 4)}%">
                    <div class="seg-call" style="width:${callPct}%"></div>
                    <div class="seg-form" style="width:${formPct}%"></div>
                </div>
                <div class="total">${fmt.format(row.total)}</div>
            </div>
        `);
    });
}

function renderPortalList() {
    const root = document.getElementById('portalList');
    root.innerHTML = '';

    state.portals.forEach((row) => {
        const button = document.createElement('button');

        button.type = 'button';
        button.className = `portal-btn${row.portal === state.selectedPortal ? ' active' : ''}`;
        button.addEventListener('click', () => selectPortal(row.portal));

        button.innerHTML = `
            <div class="portal-row">
                <span>${escapeHtml(row.portal)}</span>
                <span>${fmt.format(row.total)}</span>
            </div>
            <div class="portal-sub">
                <span>${fmt.format(row.llamadas)} llamadas</span>
                <span>${fmt.format(row.formularios)} formularios</span>
            </div>
        `;

        root.appendChild(button);
    });
}

function renderPortalTable() {
    const root = document.getElementById('portalRows');
    root.innerHTML = '';

    state.portals.forEach((row) => {
        const callPct = percentage(row.llamadas, row.total);
        const formPct = percentage(row.formularios, row.total);

        root.insertAdjacentHTML('beforeend', `
            <tr>
                <td><strong>${escapeHtml(row.portal)}</strong></td>
                <td class="num">${fmt.format(row.llamadas)}</td>
                <td class="num">${fmt.format(row.formularios)}</td>
                <td class="num"><strong>${fmt.format(row.total)}</strong></td>
                <td class="num">${callPct}</td>
                <td class="num">${formPct}</td>
                <td class="num">${fmt.format(row.convertidos ?? 0)}</td>
                <td class="num">${row.conversion_pct ?? '-'}</td>
            </tr>
        `);
    });
}

async function selectPortal(portal) {
    state.selectedPortal = portal;
    renderPortalList();
    await loadPortalDetalle(portal);
}

async function loadPortalDetalle(portal) {
    const params = new URLSearchParams({ portal });
    const data = await fetchJson(`/informes/leads/data/portal-detalle?${params.toString()}`);

    document.getElementById('selectedPortalBadge').textContent = data.portal;

    const root = document.getElementById('detailRows');
    root.innerHTML = '';

    data.items.forEach((row) => {
        const typeClass = row.tipo === 'Delegación'
            ? 'type-pill'
            : row.tipo === 'Grupo'
                ? 'type-pill group'
                : 'type-pill pending';

        root.insertAdjacentHTML('beforeend', `
            <tr>
                <td><strong>${escapeHtml(row.delegacion)}</strong></td>
                <td><span class="${typeClass}">${escapeHtml(row.tipo)}</span></td>
                <td>${escapeHtml(row.grupo_comercial ?? '-')}</td>
                <td class="num">${fmt.format(row.llamadas)}</td>
                <td class="num">${fmt.format(row.formularios)}</td>
                <td class="num"><strong>${fmt.format(row.total)}</strong></td>
            </tr>
        `);
    });
}

async function loadDelegaciones() {
    const data = await fetchJson(`/informes/leads/data/delegaciones?${currentFilters().toString()}`);
    const root = document.getElementById('delegationRows');

    if (!root) {
        return;
    }

    root.innerHTML = '';

    if (!data.items.length) {
        root.insertAdjacentHTML('beforeend', `
            <tr>
                <td colspan="11">No hay datos de delegaciones para los filtros seleccionados.</td>
            </tr>
        `);
        return;
    }

    data.items.forEach((row) => {
        const typeClass = row.tipo === 'Delegación'
            ? 'type-pill'
            : row.tipo === 'Grupo'
                ? 'type-pill group'
                : 'type-pill pending';

        root.insertAdjacentHTML('beforeend', `
            <tr>
                <td><strong>${escapeHtml(row.delegacion)}</strong></td>
                <td><span class="${typeClass}">${escapeHtml(row.tipo)}</span></td>
                <td>${escapeHtml(row.grupo_comercial ?? '-')}</td>
                <td class="num"><strong>${fmt.format(row.total)}</strong></td>
                <td class="num">${fmt.format(row.llamadas)}</td>
                <td class="num">${fmt.format(row.formularios)}</td>
                <td class="num">${fmt.format(row.convertidos)}</td>
                <td class="num">${formatPercent(row.conversion_pct)}</td>
                <td class="num">${fmt.format(row.descartados)}</td>
                <td class="num">${formatPercent(row.descarte_pct)}</td>
                <td class="num">${fmt.format(row.incidencias)}</td>
            </tr>
        `);
    });
}

async function loadComerciales() {
    const data = await fetchJson(`/informes/leads/data/comerciales?${currentFilters().toString()}`);
    const root = document.getElementById('commercialRows');

    if (!root) {
        return;
    }

    root.innerHTML = '';

    if (!data.items.length) {
        root.insertAdjacentHTML('beforeend', `
            <tr>
                <td colspan="12">No hay datos de comerciales para los filtros seleccionados.</td>
            </tr>
        `);
        return;
    }

    data.items.forEach((row) => {
        root.insertAdjacentHTML('beforeend', `
            <tr>
                <td><strong>${escapeHtml(row.comercial)}</strong></td>
                <td class="num"><strong>${fmt.format(row.total)}</strong></td>
                <td class="num">${fmt.format(row.llamadas)}</td>
                <td class="num">${fmt.format(row.formularios)}</td>
                <td class="num">${fmt.format(row.convertidos)}</td>
                <td class="num">${formatPercent(row.conversion_pct)}</td>
                <td class="num">${fmt.format(row.descartados)}</td>
                <td class="num">${formatPercent(row.descarte_pct)}</td>
                <td class="num">${fmt.format(row.potenciales)}</td>
                <td class="num">${fmt.format(row.sin_task_event)}</td>
                <td class="num">${formatPercent(row.cobertura_task_event_pct)}</td>
                <td class="num">${fmt.format(row.sin_seguimiento_reciente)}</td>
            </tr>
        `);
    });
}

async function loadComparativa() {
    const data = await fetchJson(`/informes/leads/data/comparativa?${currentFilters().toString()}`);
    const root = document.getElementById('comparisonRows');
    const label = document.getElementById('comparisonPeriodLabel');

    if (!root) {
        return;
    }

    root.innerHTML = '';

    if (label && data.periodo_actual && data.periodo_comparado) {
        label.textContent = `Periodo actual ${data.periodo_actual.desde} a ${data.periodo_actual.hasta} · Comparado con ${data.periodo_comparado.desde} a ${data.periodo_comparado.hasta}`;
    }

    if (!data.items.length) {
        root.insertAdjacentHTML('beforeend', `
            <tr>
                <td colspan="4">No hay datos de comparativa para los filtros seleccionados.</td>
            </tr>
        `);
        return;
    }

    data.items.forEach((row) => {
        root.insertAdjacentHTML('beforeend', `
            <tr>
                <td><strong>${escapeHtml(row.metrica)}</strong></td>
                <td class="num">${formatMetric(row.periodo_actual, row.is_percentage)}</td>
                <td class="num">${formatMetric(row.periodo_comparado, row.is_percentage)}</td>
                <td class="num">${formatDiff(row.diferencia, row.is_percentage)}</td>
            </tr>
        `);
    });
}

async function loadCalidadDato() {
    const data = await fetchJson('/informes/leads/data/calidad-dato');
    const root = document.getElementById('qualityRows');

    root.innerHTML = '';

    data.items.forEach((row) => {
        root.insertAdjacentHTML('beforeend', `
            <tr>
                <td><strong>${escapeHtml(row.incidencia)}</strong></td>
                <td class="num">${fmt.format(row.registros)}</td>
                <td>${escapeHtml(row.accion)}</td>
            </tr>
        `);
    });
}

async function fetchJson(url) {
    const response = await fetch(url, {
        headers: {
            Accept: 'application/json',
        },
    });

    if (!response.ok) {
        throw new Error(`Error cargando ${url}`);
    }

    return response.json();
}

function percentage(value, total) {
    if (!total) {
        return '-';
    }

    return `${((value / total) * 100).toFixed(1)}%`;
}

function formatValue(value) {
    if (value === null || value === undefined) {
        return '-';
    }

    if (typeof value === 'number') {
        return fmt.format(value);
    }

    return escapeHtml(String(value));
}

function formatPercent(value) {
    if (value === null || value === undefined) {
        return '-';
    }

    return `${Number(value).toFixed(2)}%`;
}

function formatMetric(value, isPercentage = false) {
    if (value === null || value === undefined) {
        return '-';
    }

    if (isPercentage) {
        return `${Number(value).toFixed(2)}%`;
    }

    return fmt.format(Number(value));
}

function formatDiff(value, isPercentage = false) {
    if (value === null || value === undefined) {
        return '-';
    }

    const number = Number(value);
    const sign = number > 0 ? '+' : '';

    if (isPercentage) {
        return `${sign}${number.toFixed(2)} pp`;
    }

    return `${sign}${fmt.format(number)}`;
}

function currentFilters() {
    const params = new URLSearchParams();

    const channel = document.getElementById('channel')?.value;
    const portal = document.getElementById('portal')?.value;
    const expositionMode = document.getElementById('expositionMode')?.value;

    if (channel) {
        params.set('channel', channel);
    }

    if (portal) {
        params.set('portal', portal);
    }

    if (expositionMode) {
        params.set('exposition_mode', expositionMode);
    }

    return params;
}

async function reloadAllData() {
    await loadResumen();
    await loadKpis();
    await loadPortales();
    await loadPortalDetalle(state.selectedPortal);
    await loadDelegaciones();
    await loadComerciales();
    await loadComparativa();
    await loadCalidadDato();
}

async function loadMonthlyCommercialReport() {
    const message = document.getElementById('monthlyReportMessage');
    const content = document.getElementById('monthlyReportContent');

    if (!message || !content) {
        return;
    }

    message.textContent = 'Cargando informe mensual...';
    message.classList.remove('is-hidden');
    content.classList.add('is-hidden');

    try {
        const summary = await fetchMonthlyJson('/informes/leads/data/monthly-commercial/summary');

        if (!summary.ok) {
            message.textContent = summary.message || 'No hay informe mensual generado todavia.';
            state.monthlyReportLoaded = true;
            return;
        }

        const [
            kpis,
            evolution,
            commercialPending,
            commercialPerformance,
            portals,
            delegations,
            delegationPending,
        ] = await Promise.all([
            fetchMonthlyJson('/informes/leads/data/monthly-commercial/kpis'),
            fetchMonthlyJson('/informes/leads/data/monthly-commercial/evolution'),
            fetchMonthlyJson('/informes/leads/data/monthly-commercial/commercial-pending'),
            fetchMonthlyJson('/informes/leads/data/monthly-commercial/commercial-performance'),
            fetchMonthlyJson('/informes/leads/data/monthly-commercial/portals'),
            fetchMonthlyJson('/informes/leads/data/monthly-commercial/delegations'),
            fetchMonthlyJson('/informes/leads/data/monthly-commercial/delegation-pending'),
        ]);

        renderMonthlySummary(summary);
        renderMonthlyKpis(kpis.data || {});
        renderMonthlyEvolution(evolution.data || {});
        renderMonthlyCommercialPending((commercialPending.data || {}).items || []);
        renderMonthlyCommercialPerformance((commercialPerformance.data || {}).items || []);
        renderMonthlyPortals((portals.data || {}).items || []);
        renderMonthlyDelegations((delegations.data || {}).items || []);
        renderMonthlyDelegationPending((delegationPending.data || {}).items || []);

        message.classList.add('is-hidden');
        content.classList.remove('is-hidden');
        state.monthlyReportLoaded = true;
    } catch (error) {
        message.textContent = 'No se pudo cargar el informe mensual.';
        throw error;
    }
}

async function fetchMonthlyJson(url) {
    return fetchJson(url);
}

function renderMonthlySummary(response) {
    const data = response.data || {};
    const generated = document.getElementById('monthlyGeneratedAt');
    const period = document.getElementById('monthlyPeriodLabel');
    const prioritiesRoot = document.getElementById('monthlyPriorityList');

    if (generated) {
        generated.textContent = response.generated_at ? `Generado ${response.generated_at.slice(0, 10)}` : '-';
    }

    const current = data.periodos_estandar?.periodo_actual;
    const previous = data.periodos_estandar?.periodo_anterior;

    if (period && current && previous) {
        period.textContent = `${dateOnly(current.inicio)} a ${dateOnly(current.fin)} frente a ${dateOnly(previous.inicio)} a ${dateOnly(previous.fin)}`;
    }

    if (!prioritiesRoot) {
        return;
    }

    const priorities = data.resumen_ejecutivo?.prioridades || [];
    prioritiesRoot.innerHTML = '';

    if (!priorities.length) {
        prioritiesRoot.innerHTML = '<div class="priority-item">No hay prioridades generadas para este snapshot.</div>';
        return;
    }

    priorities.slice(0, 3).forEach((priority) => {
        prioritiesRoot.insertAdjacentHTML('beforeend', `
            <article class="priority-item">
                <strong>${escapeHtml(priority.titulo || '-')}</strong>
                <p>${escapeHtml(priority.sugerencia || '-')}</p>
            </article>
        `);
    });
}

function renderMonthlyKpis(data) {
    const root = document.getElementById('monthlyKpiCards');

    if (!root) {
        return;
    }

    const cards = [
        ['L', 'Leads en analisis', formatMonthlyNumber(data.leads_en_analisis), 'Muestra del periodo'],
        ['%', 'Conversion sobre total', formatMonthlyPercent(data.conversion_sobre_total), 'Convertidos / total'],
        ['D', 'Descarte sobre total', formatMonthlyPercent(data.descarte_sobre_total), 'Descartados / total'],
        ['T', 'Potenciales sin Task/Event', formatMonthlyNumber(data.potenciales_sin_task_event_registrada), 'Falta trazabilidad registrada'],
        ['H', 'Tiempo medio 1a Task/Event', formatMonthlyHours(data.tiempo_medio_hasta_primera_task_event_horas), 'Desde asignacion'],
        ['P90', 'P90 1a Task/Event', formatMonthlyHours(data.tiempo_p90_primera_task_event_horas), 'Desde asignacion'],
        ['TE', 'Con primera Task/Event', formatMonthlyNumber(data.con_primera_task_event_registrada), 'Leads asignados'],
        ['1h', '1a gestion <1h con Task/Event', formatMonthlyPercent(data.primera_gestion_menos_1h_entre_leads_con_task_event), 'Sobre leads con actividad'],
        ['A', '1a gestion <1h asignados', formatMonthlyPercent(data.primera_gestion_menos_1h_sobre_leads_asignados), 'Sobre leads asignados'],
    ];

    root.innerHTML = '';

    cards.forEach(([icon, label, value, hint]) => {
        root.insertAdjacentHTML('beforeend', `
            <div class="card kpi monthly-kpi">
                <div class="ico">${escapeHtml(icon)}</div>
                <div>
                    <div class="kpi-label">${escapeHtml(label)}</div>
                    <div class="kpi-value">${escapeHtml(value)}</div>
                    <div class="kpi-hint">${escapeHtml(hint)}</div>
                </div>
            </div>
        `);
    });
}

function renderMonthlyEvolution(data) {
    const rows = data.items || [];
    const root = document.getElementById('monthlyEvolutionRows');

    if (!root) {
        return;
    }

    root.innerHTML = '';

    if (!rows.length) {
        root.innerHTML = '<tr><td colspan="4">No hay datos de evolucion en el snapshot.</td></tr>';
        return;
    }

    rows.forEach((row) => {
        root.insertAdjacentHTML('beforeend', `
            <tr>
                <td><strong>${escapeHtml(row.metrica)}</strong></td>
                <td class="num">${formatMonthlyMetric(row.periodo_actual, row)}</td>
                <td class="num">${formatMonthlyMetric(row.periodo_anterior, row)}</td>
                <td class="num">${formatMonthlyDiff(row.diferencia, row)}</td>
            </tr>
        `);
    });
}

function renderMonthlyCommercialPending(rows) {
    renderMonthlyRows('monthlyCommercialPendingRows', rows, [
        ['comercial', (row) => row.comercial || '-'],
        ['potenciales', (row) => formatMonthlyNumber(row.leads_potenciales), true],
        ['sin_task', (row) => formatMonthlyNumber(row.potenciales_sin_ninguna_task_event), true],
        ['ultima', (row) => formatMonthlyNumber(row.potenciales_con_ultima_task_mayor_3_dias), true],
        ['seguimiento', (row) => formatMonthlyNumber(row.potenciales_sin_seguimiento_mayor_3_dias), true],
    ], 'No hay potenciales pendientes por comercial.');
}

function renderMonthlyCommercialPerformance(rows) {
    renderMonthlyRows('monthlyCommercialPerformanceRows', rows, [
        ['comercial', (row) => row.comercial || '-'],
        ['leads', (row) => formatMonthlyNumber(row.leads_totales), true],
        ['convertidos', (row) => formatMonthlyNumber(row.leads_convertidos), true],
        ['conversion', (row) => formatMonthlyPercent(row.conversion_sobre_total), true],
        ['descartados', (row) => formatMonthlyNumber(row.leads_descartados), true],
        ['descarte', (row) => formatMonthlyPercent(row.descarte_sobre_total), true],
        ['gestionados', (row) => formatMonthlyNumber(row.leads_gestionados), true],
        ['primera', (row) => formatMonthlyNumber(row.leads_con_primera_actividad), true],
        ['menos1h', (row) => formatMonthlyPercent(row.ratio_respondidos_menos_1h_sobre_asignados), true],
    ], 'No hay rendimiento por comercial en el snapshot.');
}

function renderMonthlyPortals(rows) {
    renderMonthlyRows('monthlyPortalRows', rows, [
        ['portal', (row) => row.portal || '-'],
        ['leads', (row) => formatMonthlyNumber(row.leads_totales), true],
        ['convertidos', (row) => formatMonthlyNumber(row.leads_convertidos), true],
        ['conversion', (row) => formatMonthlyPercent(row.conversion_sobre_total), true],
        ['potenciales', (row) => formatMonthlyNumber(row.leads_potenciales), true],
        ['seguimiento', (row) => formatMonthlyNumber(row.potenciales_sin_seguimiento_mayor_3_dias), true],
        ['tiempo', (row) => formatMonthlyHours(row.tiempo_medio_respuesta_horas), true],
    ], 'No hay datos de portales en el snapshot.');
}

function renderMonthlyDelegations(rows) {
    renderMonthlyRows('monthlyDelegationRows', rows, [
        ['delegacion', (row) => row.delegacion || '-'],
        ['leads', (row) => formatMonthlyNumber(row.leads_totales), true],
        ['convertidos', (row) => formatMonthlyNumber(row.leads_convertidos), true],
        ['conversion', (row) => formatMonthlyPercent(row.conversion_sobre_total), true],
        ['descartados', (row) => formatMonthlyNumber(row.leads_descartados), true],
        ['descarte', (row) => formatMonthlyPercent(row.descarte_sobre_total), true],
        ['p90', (row) => formatMonthlyHours(row.tiempo_p90_respuesta_horas), true],
    ], 'No hay datos de delegaciones en el snapshot.');
}

function renderMonthlyDelegationPending(rows) {
    renderMonthlyRows('monthlyDelegationPendingRows', rows, [
        ['delegacion', (row) => row.delegacion || '-'],
        ['potenciales', (row) => formatMonthlyNumber(row.leads_potenciales), true],
        ['sin_task', (row) => formatMonthlyNumber(row.potenciales_sin_ninguna_task_event), true],
        ['ultima', (row) => formatMonthlyNumber(row.potenciales_con_ultima_task_mayor_3_dias), true],
        ['seguimiento', (row) => formatMonthlyNumber(row.potenciales_sin_seguimiento_mayor_3_dias), true],
    ], 'No hay potenciales pendientes por delegacion.');
}

function renderMonthlyRows(rootId, rows, columns, emptyMessage) {
    const root = document.getElementById(rootId);

    if (!root) {
        return;
    }

    root.innerHTML = '';

    if (!rows.length) {
        root.innerHTML = `<tr><td colspan="${columns.length}">${escapeHtml(emptyMessage)}</td></tr>`;
        return;
    }

    rows.forEach((row) => {
        const cells = columns.map(([key, formatter, numeric], index) => {
            const value = formatter(row);
            const tag = index === 0 ? 'strong' : 'span';
            const className = numeric ? ' class="num"' : '';

            return `<td${className}><${tag}>${escapeHtml(value)}</${tag}></td>`;
        }).join('');

        root.insertAdjacentHTML('beforeend', `<tr>${cells}</tr>`);
    });
}

function formatMonthlyNumber(value) {
    if (value === null || value === undefined || value === '') {
        return '-';
    }

    return fmt.format(Number(value));
}

function formatMonthlyPercent(value) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return '-';
    }

    return `${Math.round(Number(value) * 100)}%`;
}

function formatMonthlyHours(value) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return '-';
    }

    const minutes = Number(value) * 60;

    if (minutes < 1) {
        return 'Inmediata';
    }

    if (minutes < 60) {
        return `${Math.round(minutes)} min`;
    }

    return `${Math.round(Number(value))} h`;
}

function formatMonthlyMetric(value, row) {
    if (row.is_ratio) {
        return formatMonthlyPercent(value);
    }

    if ((row.key || '').includes('tiempo_')) {
        return formatMonthlyHours(value);
    }

    return formatMonthlyNumber(value);
}

function formatMonthlyDiff(value, row) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return '-';
    }

    const number = Number(value);
    const sign = number > 0 ? '+' : number < 0 ? '-' : '';

    if (row.is_ratio) {
        return `${sign}${Math.round(number * 100)} pp`;
    }

    if ((row.key || '').includes('tiempo_')) {
        return `${sign}${formatMonthlyHours(Math.abs(number))}`;
    }

    return `${sign}${fmt.format(number)}`;
}

function dateOnly(value) {
    if (!value) {
        return '-';
    }

    return String(value).slice(0, 10);
}

function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}
