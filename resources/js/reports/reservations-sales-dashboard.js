const fmt = new Intl.NumberFormat('es-ES');
const tableSortState = new Map();
let reservationsReloadController = null;
let latestReservationsReloadRequestId = 0;
let performanceReloadController = null;
let latestPerformanceReloadRequestId = 0;
const performanceColumnsStorageKey = 'reservationsSalesCommercialPerformanceColumnsV1';
const performanceColumnDefinitions = [
    { key: 'ranking', label: 'Ranking' },
    { key: 'traffic_light', label: 'Semáforo', alwaysVisible: true },
    { key: 'commercial', label: 'Comercial', alwaysVisible: true },
    { key: 'delegation', label: 'Delegación', defaultVisible: true },
    { key: 'zone', label: 'Zona' },
    { key: 'leads', label: 'Leads', defaultVisible: true },
    { key: 'opportunities', label: 'Oportunidades', defaultVisible: true },
    { key: 'reservations_total', label: 'Reservas', defaultVisible: true },
    { key: 'reservations_active', label: 'Activas', defaultVisible: true },
    { key: 'objective', label: 'Objetivo' },
    { key: 'fulfillment_pct', label: 'Cumplimiento', defaultVisible: true },
    { key: 'lead_to_reservation_pct', label: 'Lead → Reserva' },
    { key: 'opportunity_to_reservation_pct', label: 'Oportunidad → Reserva' },
    { key: 'sales', label: 'Ventas', defaultVisible: true },
    { key: 'reservation_to_sale_pct', label: 'Reserva → Venta' },
    { key: 'cancellations', label: 'Cancelaciones' },
    { key: 'cancellation_pct', label: '% cancelación' },
    { key: 'margin_total', label: 'Margen total', defaultVisible: true },
    { key: 'average_margin_per_sale', label: 'Margen medio', defaultVisible: true },
    { key: 'margin_coverage_pct', label: 'Cobertura margen' },
];
let performanceVisibleColumns = loadVisibleColumns(performanceColumnsStorageKey, performanceColumnDefinitions);
const reservationsCommercialColumnsStorageKey = 'reservationsCommercialColumns';
const reservationsCommercialColumnDefinitions = [
    { key: 'comercial', label: 'Comercial', alwaysVisible: true },
    { key: 'commercial_delegation', label: 'Delegacion comercial', alwaysVisible: true },
    { key: 'zone', label: 'Zona', alwaysVisible: true },
    { key: 'oportunidades_totales', label: 'Oportunidades totales', alwaysVisible: true },
    { key: 'reservas_vivas', label: 'Reservas vivas', alwaysVisible: true },
    { key: 'reservas_vivas_pct', label: '% reservas vivas' },
    { key: 'reservas_vivas_participation_pct', label: '% participacion reservas' },
    { key: 'oportunidades_caidas', label: 'Oportunidades caidas', alwaysVisible: true },
    { key: 'oportunidades_caidas_pct', label: '% oportunidades caidas' },
    { key: 'oportunidades_caidas_participation_pct', label: '% participacion caidas' },
    { key: 'cv_firmados', label: 'Contratos CV firmados', alwaysVisible: true },
    { key: 'cv_firmados_pct', label: '% contratos CV firmados' },
    { key: 'cv_firmados_participation_pct', label: '% participacion CV' },
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
    bindCommercialPerformance();
    setFilterMode('panel-resumen');
    toggleCustomPeriods();
    await reloadAllData();
});

function bindTabs() {
    document.querySelectorAll('.main-tab').forEach((button) => {
        button.addEventListener('click', async () => {
            const panelId = button.dataset.panel;

            document.querySelectorAll('.main-tab').forEach((item) => item.classList.remove('active'));
            document.querySelectorAll('.tab-panel').forEach((panel) => panel.classList.remove('active'));

            button.classList.add('active');
            document.getElementById(panelId)?.classList.add('active');
            setFilterMode(panelId);

            if (panelId === 'panel-rendimiento-comercial') {
                reservationsReloadController?.abort();
                await reloadCommercialPerformance();
            } else {
                performanceReloadController?.abort();
                await reloadAllData();
            }
        });
    });
}

function setFilterMode(panelId) {
    const performanceMode = panelId === 'panel-rendimiento-comercial';
    const filters = document.getElementById('reportFilters');
    if (!filters) return;

    filters.dataset.mode = performanceMode ? 'performance' : 'legacy';
    filters.querySelectorAll('.legacy-filter-control').forEach((control) => {
        control.classList.toggle('is-hidden', performanceMode);
    });
    filters.querySelectorAll('.performance-filter-control').forEach((control) => {
        control.classList.toggle('is-hidden', !performanceMode);
    });

    const delegationLabel = document.getElementById('commercialDelegationLabel');
    if (delegationLabel) delegationLabel.textContent = performanceMode ? 'Delegación' : 'Delegacion comercial';
    toggleCustomPeriods();
    if (performanceMode) refreshPerformanceScrolls();
}

function isCommercialPerformanceMode() {
    return document.getElementById('reportFilters')?.dataset.mode === 'performance';
}

function bindCommercialPerformance() {
    if (!window.reportUserCanViewCommercialPerformance) return;

    initPerformanceColumns();
    initPerformanceScrolls();
    window.addEventListener('resize', refreshPerformanceScrolls);
    document.getElementById('performanceMonth')?.addEventListener('change', reloadCommercialPerformance);

    document.getElementById('savePerformanceTarget')?.addEventListener('click', saveCommercialPerformanceTarget);
    document.getElementById('loadPerformanceAudit')?.addEventListener('click', reloadCommercialPerformanceAudit);
}

function commercialPerformanceQuery() {
    const params = new URLSearchParams({ month: document.getElementById('performanceMonth').value });
    setParam(params, 'zone', document.getElementById('zone').value);
    setParam(params, 'delegation', document.getElementById('commercialDelegation').value);
    setParam(params, 'commercial', document.getElementById('commercial').value);
    return params.toString();
}

async function reloadCommercialPerformance() {
    if (!window.reportUserCanViewCommercialPerformance) return;

    performanceReloadController?.abort();
    const controller = new AbortController();
    performanceReloadController = controller;
    const requestId = ++latestPerformanceReloadRequestId;
    invalidatePerformanceAudit();
    document.getElementById('performanceLoading')?.classList.remove('is-hidden');
    document.getElementById('performanceLoadError')?.classList.add('is-hidden');

    try {
        const data = await fetchJson(`/informes/reservas-ventas/data/commercial-performance?${commercialPerformanceQuery()}`, {
            signal: controller.signal,
        });
        if (requestId !== latestPerformanceReloadRequestId) return;

        if (renderPerformanceFilters(data.filters || {})) {
            await reloadCommercialPerformance();
            return;
        }

        renderCommercialPerformance(data);
    } catch (error) {
        if (error?.name !== 'AbortError') {
            const loadError = document.getElementById('performanceLoadError');
            loadError.textContent = error?.message || 'No se pudo cargar el rendimiento comercial.';
            loadError.classList.remove('is-hidden');
        }
    } finally {
        if (requestId === latestPerformanceReloadRequestId) {
            document.getElementById('performanceLoading')?.classList.add('is-hidden');
        }
    }
}

async function saveCommercialPerformanceTarget() {
    const button = document.getElementById('savePerformanceTarget');
    const target = document.getElementById('performanceTarget');
    button.disabled = true;

    try {
        await fetchJson('/informes/reservas-ventas/data/commercial-performance/target', {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': window.reportCsrfToken,
            },
            body: JSON.stringify({
                month: document.getElementById('performanceMonth').value,
                reservations_target: Number(target.value),
            }),
        });
        await reloadCommercialPerformance();
    } catch (error) {
        window.alert('No se pudo guardar el objetivo. Debe ser un entero mayor que cero.');
    } finally {
        button.disabled = false;
    }
}

function renderCommercialPerformance(data) {
    document.getElementById('performanceTarget').value = data.objective?.reservations_target ?? 18;
    renderPerformanceKpis(data.summary || {});
    renderPerformanceRows(data.items || []);
    renderPerformanceEvolution(data.evolution || []);
    applyPerformanceColumnVisibility();
    refreshPerformanceScrolls();

    const quality = data.data_quality || {};
    const coverageNotice = document.getElementById('performanceCancellationCoverage');
    const certifiedCutoff = quality.cancellation_certified_until;
    const sourceCutoff = quality.cancellation_source_cutoff_at;
    if (quality.cancellations_available && certifiedCutoff) {
        coverageNotice.textContent = `Cancelaciones disponibles hasta el corte certificado ${formatDateTime(certifiedCutoff)}. No se afirma cobertura posterior a ese instante.`;
    } else if (sourceCutoff) {
        coverageNotice.textContent = `Cancelaciones no evaluables (${quality.cancellation_coverage_status || 'no certificada'}). Último corte consultado: ${formatDateTime(sourceCutoff)}${certifiedCutoff ? `; continuidad certificada hasta ${formatDateTime(certifiedCutoff)}` : ''}.`;
    } else {
        coverageNotice.textContent = 'Cancelaciones no evaluables: no existe un corte OpportunityHistory certificado para el período.';
    }
    const warning = document.getElementById('performanceQualityWarning');
    const uncertified = Number(quality.uncertified_historical_events || 0);
    const conflicts = Number(quality.duplicate_conflict_groups || 0) + Number(quality.unresolved_attribution_events || 0);
    const messages = [];
    if (!quality.cancellations_available) messages.push(`Cancelaciones no evaluables: cobertura OpportunityHistory ${quality.cancellation_coverage_status || 'no certificada'}.`);
    if (Number(quality.cancellation_unresolved_dependencies || 0) > 0) messages.push(`${formatNumber(quality.cancellation_unresolved_dependencies)} dependencias de Opportunity no resueltas impiden certificar el KPI.`);
    if (Number(quality.invalid_cancellation_chronology || 0) > 0) messages.push(`${formatNumber(quality.invalid_cancellation_chronology)} transiciones tienen una reserva posterior y se excluyen como incidencia.`);
    if (uncertified > 0) messages.push(`${formatNumber(uncertified)} eventos sin asignación histórica evaluable; conservan su actividad individual y quedan fuera del ranking de equipo.`);
    if (Number(quality.organisation_changes_within_month || 0) > 0) messages.push(`${formatNumber(quality.organisation_changes_within_month)} comerciales cambiaron de delegación o zona durante el mes; no se ha elegido una asignación mensual arbitraria.`);
    if (conflicts > 0) messages.push(`${formatNumber(conflicts)} incidencias de atribución permanecen fuera del ranking individual.`);
    warning.textContent = messages.join(' ');
    warning.classList.toggle('is-hidden', messages.length === 0);
}

async function reloadCommercialPerformanceAudit() {
    const button = document.getElementById('loadPerformanceAudit');
    const status = document.getElementById('performanceAuditStatus');
    button.disabled = true;
    status.textContent = 'Cargando auditoría local...';
    status.classList.remove('performance-note--error');
    status.classList.add('performance-note--info');
    status.classList.remove('is-hidden');

    try {
        const params = new URLSearchParams({
            month: document.getElementById('performanceMonth').value,
            per_page: '200',
        });
        setParam(params, 'commercial', document.getElementById('commercial').value);
        const data = await fetchJson(`/informes/reservas-ventas/data/commercial-performance/audit?${params}`);
        renderCommercialPerformanceAudit(data);
        status.textContent = `${formatNumber(data.pagination?.total || 0)} eventos auditables. Cobertura cancelaciones: ${data.coverage_status || '-'}.`;
    } catch (error) {
        status.textContent = error?.message || 'No se pudo cargar la auditoría.';
        status.classList.remove('performance-note--info');
        status.classList.add('performance-note--error');
    } finally {
        button.disabled = false;
    }
}

function renderCommercialPerformanceAudit(data) {
    const result = document.getElementById('performanceAuditResult');
    result.innerHTML = `
        <div class="table-scroll-top is-hidden" data-scroll-target="performanceAuditWrap" aria-hidden="true"><div></div></div>
        <div class="table-wrap performance-audit-wrap" id="performanceAuditWrap">
            <table class="performance-audit-table">
                <thead><tr>
                    <th>Evento</th><th>Fecha</th><th>ID Lead</th><th>ID oportunidad</th>
                    <th>Responsable</th><th>Delegación / cobertura</th><th>Contado</th><th>Incidencia / exclusión</th>
                </tr></thead>
                <tbody id="performanceAuditRows"></tbody>
            </table>
        </div>`;
    result.classList.remove('is-hidden');
    const root = document.getElementById('performanceAuditRows');
    const rows = data.items || [];
    if (!rows.length) {
        root.innerHTML = '<tr><td colspan="8">No hay eventos auditables para el filtro.</td></tr>';
        initPerformanceScrolls();
        refreshPerformanceScrolls();
        return;
    }

    root.innerHTML = rows.map((row) => `<tr>
        <td>${escapeHtml(row.event_type || '-')}</td><td>${escapeHtml(formatDate(row.event_at))}</td>
        <td>${escapeHtml(row.lead_id || '-')}</td><td>${escapeHtml(row.opportunity_id || '-')}</td>
        <td><strong>${escapeHtml(row.commercial || '-')}</strong><br><small>${escapeHtml(row.commercial_id || '-')}</small></td>
        <td>${escapeHtml(row.delegation || '-')}<br><small>${escapeHtml(formatDelegationStatus(row.delegation_status, row.delegation_issue))}</small></td>
        <td>${row.counted_in_metric ? 'Sí' : 'No'}</td><td>${escapeHtml(row.exclusion_reason || row.deduplication_status || '-')}<br><small>${escapeHtml(row.metric_attribution || '-')}</small></td>
    </tr>`).join('');
    initPerformanceScrolls();
    refreshPerformanceScrolls();
}

function formatDelegationStatus(status, issue) {
    const labels = {
        observed: 'Observada',
        bootstrap_approved: 'Bootstrap aprobado',
        not_certifiable: 'No certificable',
    };
    const issues = {
        incomplete_history: 'Cobertura incompleta',
        organisation_change_within_month: 'Cambio intramensual',
        missing_commercial_identity: 'Identidad no disponible',
        future_period: 'Periodo futuro',
    };
    const label = labels[status] || 'No certificable';
    const detail = issues[issue];

    return detail ? `${label} · ${detail}` : label;
}

function invalidatePerformanceAudit() {
    const result = document.getElementById('performanceAuditResult');
    if (result) {
        result.innerHTML = '';
        result.classList.add('is-hidden');
    }
    document.getElementById('performanceAuditStatus')?.classList.add('is-hidden');
}

function renderPerformanceFilters(filters) {
    const changedSelections = [
        fillPerformanceSelect('zone', filters.zones || [], (value) => ({ value, label: value }), 'Todas'),
        fillPerformanceSelect('commercialDelegation', filters.delegations || [], (value) => ({ value, label: value }), 'Todas'),
        fillPerformanceSelect('commercial', filters.commercials || [], (value) => ({ value: value.id, label: value.name }), 'Todos'),
    ];

    return changedSelections.some(Boolean);
}

function fillPerformanceSelect(id, values, mapper, allLabel) {
    const select = document.getElementById(id);
    const selected = select.value;
    select.innerHTML = `<option value="">${allLabel}</option>`;
    values.forEach((value) => {
        const option = mapper(value);
        select.insertAdjacentHTML('beforeend', `<option value="${escapeHtml(option.value)}">${escapeHtml(option.label)}</option>`);
    });
    const selectionIsValid = [...select.options].some((option) => option.value === selected);
    if (selectionIsValid) select.value = selected;

    return selected !== '' && !selectionIsValid;
}

function renderPerformanceKpis(summary) {
    const cards = [
        ['Leads', formatNumber(summary.leads)],
        ['Oportunidades', formatNumber(summary.opportunities)],
        ['Reservas totales', formatNumber(summary.reservations_total)],
        ['Ventas', formatNumber(summary.sales)],
        ['Cumplimiento', formatPercent(summary.fulfillment_pct)],
        ['Margen total', formatCurrency(summary.margin_total)],
    ];
    document.getElementById('performanceKpis').innerHTML = cards.map(([label, value]) => `
        <div class="card kpi"><div class="kpi-copy"><div class="kpi-label">${escapeHtml(label)}</div><div class="kpi-value">${escapeHtml(value)}</div></div></div>
    `).join('');
}

function renderPerformanceRows(rows) {
    const root = document.getElementById('performanceRows');
    if (!rows.length) {
        root.innerHTML = `<tr><td colspan="${performanceVisibleColumns.length}">No hay actividad comercial para los filtros seleccionados.</td></tr>`;
        return;
    }

    root.innerHTML = rows.map((row) => `<tr>
        <td class="num" data-column="ranking">${escapeHtml(row.ranking ?? '-')}</td>
        <td data-column="traffic_light">${performanceLight(row.traffic_light)}</td>
        <td data-column="commercial"><strong>${escapeHtml(row.commercial || '-')}</strong></td><td data-column="delegation">${escapeHtml(row.delegation || '-')}</td><td data-column="zone">${escapeHtml(row.zone || '-')}</td>
        <td class="num" data-column="leads">${formatNumber(row.leads)}</td><td class="num" data-column="opportunities">${formatNumber(row.opportunities)}</td><td class="num" data-column="reservations_total">${formatNumber(row.reservations_total)}</td><td class="num" data-column="reservations_active">${formatNumber(row.reservations_active)}</td>
        <td class="num" data-column="objective">${formatNumber(row.objective)}</td><td class="num" data-column="fulfillment_pct">${formatPercent(row.fulfillment_pct)}</td>
        <td class="num" data-column="lead_to_reservation_pct">${formatPercent(row.lead_to_reservation_pct)}</td><td class="num" data-column="opportunity_to_reservation_pct">${formatPercent(row.opportunity_to_reservation_pct)}</td>
        <td class="num" data-column="sales">${formatNumber(row.sales)}</td><td class="num" data-column="reservation_to_sale_pct">${formatPercent(row.reservation_to_sale_pct)}</td>
        <td class="num" data-column="cancellations">${formatAvailableNumber(row.cancellations)}</td><td class="num" data-column="cancellation_pct">${formatAvailablePercent(row.cancellation_pct)}</td>
        <td class="num" data-column="margin_total" title="Rentabilidad acumulada de las ventas con margen informado.">${formatCurrency(row.margin_total)}</td>
        <td class="num" data-column="average_margin_per_sale" title="Media calculada únicamente sobre ventas con margen informado.">${formatCurrency(row.average_margin_per_sale)}</td>
        <td class="num" data-column="margin_coverage_pct">${formatPercent(row.margin_coverage_pct)}</td>
    </tr>`).join('');
}

function renderPerformanceEvolution(rows) {
    const root = document.getElementById('performanceEvolutionRows');
    root.innerHTML = rows.map((row) => `<tr>
        <td title="${escapeHtml(formatPerformanceMonth(row.month, true))}"><strong>${escapeHtml(formatPerformanceMonth(row.month))}</strong></td><td class="num">${formatNumber(row.leads)}</td><td class="num">${formatNumber(row.opportunities)}</td>
        <td class="num">${formatNumber(row.reservations_total)}</td><td class="num">${formatNumber(row.reservations_active)}</td><td class="num">${formatNumber(row.sales)}</td><td class="num">${formatAvailableNumber(row.cancellations)}</td>
        <td class="num">${formatPercent(row.fulfillment_pct)}</td><td class="num">${formatPercent(row.lead_to_reservation_pct)}</td><td class="num">${formatPercent(row.opportunity_to_reservation_pct)}</td>
        <td class="num">${formatPercent(row.reservation_to_sale_pct)}</td><td class="num">${formatAvailablePercent(row.cancellation_pct)}</td><td class="num">${formatCurrency(row.margin_total)}</td><td class="num">${formatCurrency(row.average_margin_per_sale)}</td>
    </tr>`).join('');
}

function performanceLight(value) {
    const labels = { green: 'Verde', yellow: 'Amarillo', orange: 'Naranja', red: 'Rojo' };
    if (!Object.hasOwn(labels, value)) return '-';
    return `<span class="performance-light performance-light--${value}">${labels[value]}</span>`;
}

function initPerformanceColumns() {
    const button = document.getElementById('performanceColumnsButton');
    const popover = document.getElementById('performanceColumnsPopover');
    if (!button || !popover) return;

    popover.innerHTML = performanceColumnDefinitions
        .filter((column) => !column.alwaysVisible)
        .map((column) => `
            <label class="column-option switch-option">
                <input type="checkbox" data-performance-column-toggle="${escapeHtml(column.key)}" ${performanceVisibleColumns.includes(column.key) ? 'checked' : ''}>
                <span>${escapeHtml(column.label)}</span>
            </label>`)
        .join('');
    applyPerformanceColumnVisibility();

    button.addEventListener('click', () => {
        const hidden = popover.classList.toggle('is-hidden');
        button.setAttribute('aria-expanded', String(!hidden));
    });
    popover.addEventListener('change', (event) => {
        const input = event.target.closest('[data-performance-column-toggle]');
        if (!input) return;

        const visible = new Set(performanceVisibleColumns);
        input.checked ? visible.add(input.dataset.performanceColumnToggle) : visible.delete(input.dataset.performanceColumnToggle);
        performanceVisibleColumns = performanceColumnDefinitions
            .filter((column) => column.alwaysVisible || visible.has(column.key))
            .map((column) => column.key);
        localStorage.setItem(performanceColumnsStorageKey, JSON.stringify(performanceVisibleColumns));
        applyPerformanceColumnVisibility();
    });
    document.addEventListener('click', (event) => {
        if (!event.target.closest('.columns-menu')) {
            popover.classList.add('is-hidden');
            button.setAttribute('aria-expanded', 'false');
        }
    });
}

function applyPerformanceColumnVisibility() {
    document.querySelectorAll('#performanceTable [data-column]').forEach((cell) => {
        cell.classList.toggle('is-hidden', !performanceVisibleColumns.includes(cell.dataset.column));
    });
    refreshPerformanceScrolls();
}

function initPerformanceScrolls() {
    document.querySelectorAll('.table-scroll-top[data-scroll-target]').forEach((top) => {
        if (top.dataset.scrollBound === 'true') return;
        const bottom = document.getElementById(top.dataset.scrollTarget);
        if (!bottom) return;

        let syncing = false;
        top.addEventListener('scroll', () => {
            if (syncing) return;
            syncing = true;
            bottom.scrollLeft = top.scrollLeft;
            syncing = false;
        });
        bottom.addEventListener('scroll', () => {
            if (syncing) return;
            syncing = true;
            top.scrollLeft = bottom.scrollLeft;
            syncing = false;
        });
        top.dataset.scrollBound = 'true';
    });
}

function refreshPerformanceScrolls() {
    window.requestAnimationFrame(() => {
        document.querySelectorAll('.table-scroll-top[data-scroll-target]').forEach((top) => {
            const bottom = document.getElementById(top.dataset.scrollTarget);
            const spacer = top.firstElementChild;
            if (!bottom || !spacer) return;

            spacer.style.width = `${bottom.scrollWidth}px`;
            top.classList.toggle('is-hidden', bottom.scrollWidth <= bottom.clientWidth + 1);
            top.scrollLeft = bottom.scrollLeft;
        });
    });
}

function formatPerformanceMonth(value, includeYear = false) {
    const [year, month] = String(value || '').split('-').map(Number);
    if (!year || !month) return String(value || '-');

    const date = new Date(Date.UTC(year, month - 1, 1));
    return new Intl.DateTimeFormat('es-ES', includeYear
        ? { month: 'long', year: 'numeric', timeZone: 'UTC' }
        : { month: 'long', timeZone: 'UTC' }).format(date);
}

function bindResetFilters() {
    document.getElementById('resetFilters')?.addEventListener('click', async () => {
        if (isCommercialPerformanceMode()) {
            document.getElementById('performanceMonth').value = document.getElementById('performanceMonth').dataset.defaultMonth;
            document.getElementById('zone').value = '';
            document.getElementById('commercialDelegation').value = '';
            document.getElementById('commercial').value = '';
            await reloadCommercialPerformance();
            return;
        }

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
    ['zone', 'commercialDelegation', 'commercial'].forEach((id) => {
        document.getElementById(id)?.addEventListener('change', async () => {
            if (id === 'zone') {
                document.getElementById('commercialDelegation').value = '';
                document.getElementById('commercial').value = '';
            } else if (id === 'commercialDelegation') {
                document.getElementById('commercial').value = '';
            }

            if (isCommercialPerformanceMode()) {
                await reloadCommercialPerformance();
            } else {
                await reloadAllData();
            }
        });
    });

    [
        'period',
        'dateCriterion',
        'opportunityType',
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
    reservationsReloadController?.abort();
    reservationsReloadController = new AbortController();
    const requestId = ++latestReservationsReloadRequestId;
    const requestOptions = { signal: reservationsReloadController.signal };
    setLoadingState(true);

    try {
        const filters = currentFilters();
        const summary = await fetchJson(`/informes/reservas-ventas/data/summary?${filters}`, requestOptions);
        if (requestId !== latestReservationsReloadRequestId) return;
        if (renderFilterOptions(summary.filters || {})) {
            await reloadAllData();
            return;
        }
        renderSummary(summary);

        const [commercials, portals] = await Promise.all([
            fetchJson(`/informes/reservas-ventas/data/commercials?${filters}`, requestOptions),
            fetchJson(`/informes/reservas-ventas/data/portals?${filters}`, requestOptions),
        ]);
        if (requestId !== latestReservationsReloadRequestId) return;

        renderCommercialZones(commercials.zones || []);
        renderCommercialDelegations(commercials.delegations || []);
        renderCommercials(commercials.commercials || commercials.items || []);
        renderPortals(portals.items || []);
    } catch (error) {
        if (error?.name !== 'AbortError' && requestId === latestReservationsReloadRequestId) {
            showLoadError(error);
        }
    } finally {
        if (requestId === latestReservationsReloadRequestId) {
            setLoadingState(false);
        }
    }
}

function renderSummary(data) {
    document.getElementById('updatedBadge').textContent = data.datos_actualizados
        ? `Datos actualizados: ${formatDateTime(data.datos_actualizados)}`
        : 'Datos actualizados: pendiente';
    document.getElementById('currentPeriodLabel').textContent = periodText(data.periodo_actual);
    document.getElementById('comparisonPeriodLabel').textContent = periodText(data.periodo_comparado);
    document.getElementById('universeDateLabel').textContent = data.universe_date_label || '-';

    const empty = document.getElementById('emptyMessage');
    empty.classList.toggle('is-hidden', Boolean(data.ok));
    empty.textContent = data.message || 'No hay oportunidades sincronizadas para el periodo seleccionado.';

    renderKpis(data.kpis || {});
    renderComparison(data.comparativa || []);
    renderDataQuality(data.data_quality || {});
}

function renderDataQuality(quality) {
    const panel = document.getElementById('reservationsDataQualityPanel');
    const root = document.getElementById('reservationsDataQualityIncidents');
    const incidents = quality.incidents || [];

    panel?.classList.toggle('is-hidden', incidents.length === 0);
    if (!root) return;
    document.getElementById('reservationsDataQualityCount').textContent = formatNumber(quality.duplicate_event_groups || incidents.length);
    root.innerHTML = incidents.map((incident) => `
        <article class="data-quality-incident">
            <div>
                <strong>${incident.type === 'sale' ? 'Venta duplicada' : 'Reserva duplicada'} · ${escapeHtml(incident.vehicle_plate || incident.vehicle_id || 'Vehículo sin referencia visible')}</strong>
                <span>${escapeHtml(incident.event_date || 'Sin fecha')} · ${formatNumber((incident.opportunity_ids || []).length)} oportunidades</span>
            </div>
            <code>${escapeHtml((incident.opportunity_ids || []).join(', '))}</code>
            ${incident.conflicting_fields?.length ? `<small>Desglose en incidencia por conflicto en: ${escapeHtml(incident.conflicting_fields.join(', '))}</small>` : '<small>Atribución común; se contabiliza una sola vez.</small>'}
        </article>
    `).join('');
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

function renderCommercialZones(rows) {
    renderRows('commercialZoneRows', rows, [
        [(row) => row.zone || '-'],
        [(row) => formatNumber(row.oportunidades_totales), true],
        [(row) => formatCountConversionParticipation(row.reservas_vivas, row.reservas_vivas_pct, row.reservas_vivas_participation_pct), true, (row) => row.reservas_vivas, true],
        [(row) => formatCountConversionParticipation(row.oportunidades_caidas, row.oportunidades_caidas_pct, row.oportunidades_caidas_participation_pct), true, (row) => row.oportunidades_caidas, true],
        [(row) => formatCountConversionParticipation(row.cv_firmados, row.cv_firmados_pct, row.cv_firmados_participation_pct), true, (row) => row.cv_firmados, true],
    ], 'No hay datos de zonas para los filtros seleccionados.');
}

function renderCommercialDelegations(rows) {
    renderRows('commercialDelegationRows', rows, [
        [(row) => row.commercial_delegation || '-'],
        [(row) => row.zone || '-'],
        [(row) => formatNumber(row.oportunidades_totales), true],
        [(row) => formatCountConversionParticipation(row.reservas_vivas, row.reservas_vivas_pct, row.reservas_vivas_participation_pct), true, (row) => row.reservas_vivas, true],
        [(row) => formatCountConversionParticipation(row.oportunidades_caidas, row.oportunidades_caidas_pct, row.oportunidades_caidas_participation_pct), true, (row) => row.oportunidades_caidas, true],
        [(row) => formatCountConversionParticipation(row.cv_firmados, row.cv_firmados_pct, row.cv_firmados_participation_pct), true, (row) => row.cv_firmados, true],
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
        [(row) => formatPercent(row.reservas_vivas_participation_pct), true, (row) => row.reservas_vivas_participation_pct, false, 'reservas_vivas_participation_pct'],
        [(row) => formatCountPercent(row.oportunidades_caidas, row.oportunidades_caidas_pct), true, (row) => row.oportunidades_caidas, true, 'oportunidades_caidas'],
        [(row) => formatPercent(row.oportunidades_caidas_pct), true, (row) => row.oportunidades_caidas_pct, false, 'oportunidades_caidas_pct'],
        [(row) => formatPercent(row.oportunidades_caidas_participation_pct), true, (row) => row.oportunidades_caidas_participation_pct, false, 'oportunidades_caidas_participation_pct'],
        [(row) => formatCountPercent(row.cv_firmados, row.cv_firmados_pct), true, (row) => row.cv_firmados, true, 'cv_firmados'],
        [(row) => formatPercent(row.cv_firmados_pct), true, (row) => row.cv_firmados_pct, false, 'cv_firmados_pct'],
        [(row) => formatPercent(row.cv_firmados_participation_pct), true, (row) => row.cv_firmados_participation_pct, false, 'cv_firmados_participation_pct'],
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
        [(row) => formatCountConversionParticipation(row.reservas_vivas, row.reservas_vivas_pct, row.reservas_vivas_participation_pct), true, (row) => row.reservas_vivas, true],
        [(row) => formatCountConversionParticipation(row.oportunidades_caidas, row.oportunidades_caidas_pct, row.oportunidades_caidas_participation_pct), true, (row) => row.oportunidades_caidas, true],
        [(row) => formatCountConversionParticipation(row.cv_firmados, row.cv_firmados_pct, row.cv_firmados_participation_pct), true, (row) => row.cv_firmados, true],
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
    const changedSelections = [
        fillSelect('commercial', filters.commercials || [], 'id', 'name'),
        fillSelect('commercialDelegation', (filters.commercial_delegations || []).map((item) => ({ id: item, name: item })), 'id', 'name'),
        fillSelect('zone', (filters.zones || []).map((item) => ({ id: item, name: item })), 'id', 'name'),
    ];

    return changedSelections.some(Boolean);
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

    const selectionIsValid = [...select.options].some((option) => option.value === current);
    select.value = selectionIsValid ? current : '';

    return current !== '' && !selectionIsValid;
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
        document.getElementById('updatedBadge').textContent = 'Cargando fotografía local...';
        document.getElementById('emptyMessage')?.classList.add('is-hidden');
        [
            'summaryKpis',
            'comparisonRows',
            'insights',
            'commercialZoneRows',
            'commercialDelegationRows',
            'commercialRows',
            'portalRows',
        ].forEach((id) => {
            const element = document.getElementById(id);
            if (element) element.innerHTML = '';
        });
        document.getElementById('reservationsDataQualityPanel')?.classList.add('is-hidden');
    }

    document.querySelector('main')?.classList.toggle('dashboard-is-loading', isLoading);
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
        isCommercialPerformanceMode() || document.getElementById('period')?.value !== 'custom'
    );
}

async function fetchJson(url, options = {}) {
    const response = await fetch(url, {
        ...options,
        headers: { Accept: 'application/json', ...(options.headers || {}) },
    });

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

function formatAvailableNumber(value) {
    return value === null || value === undefined ? 'N/D' : formatNumber(value);
}

function formatAvailablePercent(value) {
    return value === null || value === undefined ? 'N/D' : formatPercent(value);
}

function formatCurrency(value) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return '-';
    }

    return new Intl.NumberFormat('es-ES', { style: 'currency', currency: 'EUR' }).format(Number(value));
}

function formatSignedNumber(value) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return '-';
    }

    const number = Number(value);
    return `${number > 0 ? '+' : ''}${fmt.format(number)}`;
}

function formatCountPercent(count, percent) {
    return `<span class="metric-value">${escapeHtml(formatNumber(count))}</span><span class="metric-percent">(${escapeHtml(formatPercent(percent))})</span>`;
}

function formatCountConversionParticipation(count, ratioOverOpportunities, participation) {
    return `<span class="metric-value">${escapeHtml(formatNumber(count))}</span>`
        + `<span class="metric-percent">Sobre oportunidades ${escapeHtml(formatPercent(ratioOverOpportunities))}</span>`
        + `<span class="metric-percent">Participación ${escapeHtml(formatPercent(participation))}</span>`;
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
    const defaultColumns = definitions
        .filter((column) => column.alwaysVisible || column.defaultVisible)
        .map((column) => column.key);

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
