const fmt = new Intl.NumberFormat('es-ES');

const state = {
    selectedPortal: 'Web',
    portals: [],
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

function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}