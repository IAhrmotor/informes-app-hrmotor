<x-reports.app-shell title="Reservas / Ventas" current-report="reservations-sales" :updated-badge-text="'Cargando fotograf'.mb_chr(237).'a local...'">
    <x-slot:head>
        @vite([
            'resources/css/reports/leads-dashboard.css',
            'resources/css/reports/reservations-sales-dashboard.css',
            'resources/js/reports/reservations-sales-dashboard.js'
        ])
    </x-slot:head>
<div class="wrap">
    <script>
        window.reportUserCanExport = @json($reportUserCanExport ?? false);
        window.reportUserCanViewCommercialPerformance = @json($reportUserCanViewCommercialPerformance ?? false);
        window.reportCsrfToken = @json(csrf_token());
    </script>
    <section class="filters card report-filters" id="reportFilters" data-mode="legacy">
        <div class="filter-group legacy-filter-control">
            <label for="period">Periodo</label>
            <select id="period">
                <option value="last_30_days">Ultimos 30 dias</option>
                <option value="current_month">Mes actual</option>
                <option value="previous_month">Mes anterior</option>
                <option value="custom">Personalizado</option>
            </select>
        </div>

        <div class="filter-group legacy-filter-control">
            <label for="dateCriterion">Criterio de fecha</label>
            <select id="dateCriterion">
                <option value="created_date">Fecha de creacion</option>
                <option value="reservation_date">Fecha de reserva</option>
                <option value="cv_signed_date">Fecha de firma contrato</option>
            </select>
        </div>

        <div class="filter-group legacy-filter-control">
            <label for="opportunityType">Tipo de oportunidad</label>
            <select id="opportunityType">
                <option value="all">Todos</option>
                <option value="Tasacion">Tasación</option>
                <option value="Venta">Venta</option>
            </select>
        </div>

        @if ($reportUserCanViewCommercialPerformance ?? false)
        <div class="filter-group performance-filter-control performance-month-control is-hidden">
            <label for="performanceMonth">Mes natural</label>
            <input id="performanceMonth" type="month" value="{{ now('Europe/Madrid')->format('Y-m') }}" data-default-month="{{ now('Europe/Madrid')->format('Y-m') }}">
        </div>
        @endif

        <div class="filter-group shared-delegation-control">
            <label for="commercialDelegation" id="commercialDelegationLabel">Delegacion comercial</label>
            <select id="commercialDelegation">
                <option value="">Todas</option>
            </select>
        </div>

        <div class="filter-group shared-zone-control">
            <label for="zone">Zona</label>
            <select id="zone">
                <option value="">Todas</option>
            </select>
        </div>

        <div class="filter-group shared-commercial-control">
            <label for="commercial">Comercial</label>
            <select id="commercial">
                <option value="">Todos</option>
            </select>
        </div>
        @if ($reportUserCanViewCommercialPerformance ?? false)
        <div class="filter-group performance-filter-control performance-target-field is-hidden">
            <label for="performanceTarget">Objetivo reservas</label>
            <div class="performance-target-control">
                <input id="performanceTarget" type="number" min="1" step="1" inputmode="numeric">
                <button type="button" class="filter-reset" id="savePerformanceTarget">Guardar</button>
            </div>
        </div>
        @endif

        <div class="filter-actions shared-reset-control">
            <button type="button" class="filter-reset" id="resetFilters">Limpiar filtros</button>
        </div>
    </section>

    <section class="filters card custom-periods is-hidden" id="customPeriods">
        <div class="filter-group">
            <label for="currentStart">Inicio actual</label>
            <input type="date" id="currentStart">
        </div>
        <div class="filter-group">
            <label for="currentEnd">Fin actual</label>
            <input type="date" id="currentEnd">
        </div>
        <div class="filter-group">
            <label for="comparisonStart">Inicio comparado</label>
            <input type="date" id="comparisonStart">
        </div>
        <div class="filter-group">
            <label for="comparisonEnd">Fin comparado</label>
            <input type="date" id="comparisonEnd">
        </div>
    </section>

    <nav class="tabs-main" aria-label="Pestanas del informe">
        <button class="main-tab active" data-panel="panel-resumen">Resumen direccion</button>
        <button class="main-tab" data-panel="panel-comerciales">Comerciales / delegaciones / zonas</button>
        <button class="main-tab" data-panel="panel-portales">Portales / procedencia</button>
        @if ($reportUserCanViewCommercialPerformance ?? false)
            <button class="main-tab" data-panel="panel-rendimiento-comercial">Rendimiento comercial</button>
        @endif
    </nav>

    <main>
        <section id="panel-resumen" class="tab-panel active">
            <div class="notice" id="loadingMessage">Cargando fotografía local...</div>
            <div class="notice is-hidden" id="emptyMessage">No hay oportunidades sincronizadas para el periodo seleccionado.</div>

            <section class="period-strip">
                <div class="card period-card">
                    <span>Periodo actual</span>
                    <strong id="currentPeriodLabel">-</strong>
                </div>
                <div class="card period-card">
                    <span>Periodo comparado</span>
                    <strong id="comparisonPeriodLabel">-</strong>
                </div>
                <div class="card period-card universe-definition-card">
                    <span>Fecha que define el universo</span>
                    <strong id="universeDateLabel">-</strong>
                    <small>Los resultados posteriores se miden sobre esta misma cohorte.</small>
                </div>
            </section>

            <section class="card panel data-quality-panel is-hidden" id="reservationsDataQualityPanel">
                <div class="panel-title">
                    <div>
                        <h2>Alertas de calidad del dato</h2>
                        <div class="small">Eventos repetidos por vehículo y fecha. Cada grupo cuenta una sola vez en el KPI.</div>
                    </div>
                    <span class="quality-count" id="reservationsDataQualityCount">0</span>
                </div>
                <div class="data-quality-incidents" id="reservationsDataQualityIncidents"></div>
            </section>

            <section class="kpis dashboard-kpis" id="summaryKpis"></section>

            <section class="card panel">
                <div class="panel-title">
                    <div>
                        <h2>Comparativa basica</h2>
                        <div class="small">Periodo actual frente al periodo comparado</div>
                    </div>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>Metrica</th>
                            <th class="num">Periodo actual</th>
                            <th class="num">Periodo comparado</th>
                            <th class="num">Diferencia</th>
                        </tr>
                        </thead>
                        <tbody id="comparisonRows"></tbody>
                    </table>
                </div>
            </section>

        </section>

        <section id="panel-comerciales" class="tab-panel">
            <section class="card panel">
                <div class="panel-title">
                    <div>
                        <h2>Zonas</h2>
                        <div class="small">Agrupado por zona comercial del owner</div>
                    </div>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>Zona</th>
                            <th class="num">Oportunidades totales</th>
                            <th class="num">Reservas vivas</th>
                            <th class="num">Oportunidades caidas</th>
                            <th class="num">Contratos CV firmados</th>
                        </tr>
                        </thead>
                        <tbody id="commercialZoneRows"></tbody>
                    </table>
                </div>
            </section>

            <section class="card panel">
                <div class="panel-title">
                    <div>
                        <h2>Delegaciones</h2>
                        <div class="small">Agrupado por delegacion comercial del owner</div>
                    </div>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>Delegacion comercial</th>
                            <th>Zona</th>
                            <th class="num">Oportunidades totales</th>
                            <th class="num">Reservas vivas</th>
                            <th class="num">Oportunidades caidas</th>
                            <th class="num">Contratos CV firmados</th>
                        </tr>
                        </thead>
                        <tbody id="commercialDelegationRows"></tbody>
                    </table>
                </div>
            </section>

            <section class="card panel">
                <div class="panel-title">
                    <div>
                        <h2>Comerciales</h2>
                        <div class="small">Agrupado por responsable de la oportunidad</div>
                    </div>
                    <div class="columns-menu">
                        <button type="button" class="filter-reset" id="reservationsCommercialColumnsButton">Columnas</button>
                        <div class="columns-popover card is-hidden" id="reservationsCommercialColumnsPopover"></div>
                    </div>
                </div>
                <div class="filters card compact-filters">
                    <div class="filter-group">
                        <label for="reservationsCommercialSearch">Buscar comercial</label>
                        <input id="reservationsCommercialSearch" type="search" placeholder="Filtrar por nombre o ID Salesforce">
                    </div>
                </div>
                <div class="table-wrap">
                    <table id="reservationsCommercialTable">
                        <thead>
                        <tr>
                            <th data-column="comercial">Comercial</th>
                            <th data-column="commercial_delegation">Delegacion comercial</th>
                            <th data-column="zone">Zona</th>
                            <th class="num" data-column="oportunidades_totales">Oportunidades totales</th>
                            <th class="num" data-column="reservas_vivas">Reservas vivas</th>
                            <th class="num is-hidden" data-column="reservas_vivas_pct">% reservas vivas</th>
                            <th class="num is-hidden" data-column="reservas_vivas_participation_pct">% participacion reservas</th>
                            <th class="num" data-column="oportunidades_caidas">Oportunidades caidas</th>
                            <th class="num is-hidden" data-column="oportunidades_caidas_pct">% oportunidades caidas</th>
                            <th class="num is-hidden" data-column="oportunidades_caidas_participation_pct">% participacion caidas</th>
                            <th class="num" data-column="cv_firmados">Contratos CV firmados</th>
                            <th class="num is-hidden" data-column="cv_firmados_pct">% contratos CV firmados</th>
                            <th class="num is-hidden" data-column="cv_firmados_participation_pct">% participacion CV</th>
                        </tr>
                        </thead>
                        <tbody id="commercialRows"></tbody>
                    </table>
                </div>
            </section>
        </section>

        <section id="panel-portales" class="tab-panel">
            <section class="card panel">
                <div class="panel-title">
                    <div>
                        <h2>Portales / Procedencia</h2>
                        <div class="small">Procedencia reconstruida desde oportunidad o lead relacionado</div>
                    </div>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>Portal / Procedencia</th>
                            <th class="num">Oportunidades totales</th>
                            <th class="num">Reservas vivas</th>
                            <th class="num">Oportunidades caidas</th>
                            <th class="num">Contratos CV firmados</th>
                        </tr>
                        </thead>
                        <tbody id="portalRows"></tbody>
                    </table>
                </div>
            </section>
        </section>

        @if ($reportUserCanViewCommercialPerformance ?? false)
        <section id="panel-rendimiento-comercial" class="tab-panel">
            <div class="notice is-hidden" id="performanceLoading">Cargando rendimiento comercial local...</div>
            <div class="performance-note performance-note--info" id="performanceSemantics">
                Actividad mensual, no cohorte. Cada hito se asigna al mes en que ocurre; por ello, algunos ratios pueden superar el 100 %.
            </div>
            <div class="performance-note performance-note--info" id="performanceCancellationCoverage">Cobertura de cancelaciones pendiente de cargar.</div>
            <div class="performance-note performance-note--quality is-hidden" id="performanceQualityWarning"></div>
            <div class="performance-note performance-note--error is-hidden" id="performanceLoadError"></div>
            <section class="kpis dashboard-kpis" id="performanceKpis"></section>

            <section class="card panel">
                <div class="panel-title">
                    <div>
                        <h2>Rendimiento por comercial</h2>
                        <div class="small">Ranking exclusivo por cumplimiento para asignaciones observadas o con bootstrap aprobado.</div>
                    </div>
                    <div class="columns-menu">
                        <button type="button" class="filter-reset" id="performanceColumnsButton" aria-expanded="false" aria-controls="performanceColumnsPopover">Añadir o quitar columnas</button>
                        <div class="columns-popover card is-hidden" id="performanceColumnsPopover"></div>
                    </div>
                </div>
                <div class="table-scroll-top is-hidden" data-scroll-target="performanceTableWrap" aria-hidden="true"><div></div></div>
                <div class="table-wrap performance-table-wrap" id="performanceTableWrap">
                    <table class="performance-table" id="performanceTable">
                        <thead><tr>
                            <th data-column="ranking">Ranking</th><th data-column="traffic_light">Semáforo</th><th data-column="commercial">Comercial</th><th data-column="delegation">Delegación</th><th data-column="zone">Zona</th>
                            <th class="num" data-column="leads">Leads</th><th class="num" data-column="opportunities">Oportunidades</th><th class="num" data-column="reservations_total">Reservas</th><th class="num" data-column="reservations_active">Activas</th>
                            <th class="num" data-column="objective">Objetivo</th><th class="num" data-column="fulfillment_pct">Cumplimiento</th>
                            <th class="num" data-column="lead_to_reservation_pct">Lead → Reserva</th><th class="num" data-column="opportunity_to_reservation_pct">Oportunidad → Reserva</th>
                            <th class="num" data-column="sales">Ventas</th><th class="num" data-column="reservation_to_sale_pct">Reserva → Venta</th>
                            <th class="num" data-column="cancellations">Cancelaciones</th><th class="num" data-column="cancellation_pct">% cancelación</th>
                            <th class="num" data-column="margin_total" title="Rentabilidad acumulada de las ventas con margen informado.">Margen total</th>
                            <th class="num" data-column="average_margin_per_sale" title="Media calculada únicamente sobre ventas con margen informado.">Margen medio</th>
                            <th class="num" data-column="margin_coverage_pct">Cobertura margen</th>
                        </tr></thead>
                        <tbody id="performanceRows"></tbody>
                    </table>
                </div>
            </section>

            <section class="card panel">
                <div class="panel-title">
                    <div><h2>Evolución mensual</h2><div class="small">Mes seleccionado y tres meses anteriores</div></div>
                </div>
                <div class="table-scroll-top is-hidden" data-scroll-target="performanceEvolutionWrap" aria-hidden="true"><div></div></div>
                <div class="table-wrap" id="performanceEvolutionWrap">
                    <table class="performance-evolution-table">
                        <thead><tr>
                            <th>Mes</th><th class="num">Leads</th><th class="num">Oportunidades</th><th class="num">Reservas</th>
                            <th class="num">Activas</th><th class="num">Ventas</th><th class="num">Cancelaciones</th><th class="num">Cumplimiento</th>
                            <th class="num">Lead → Reserva</th><th class="num">Oport. → Reserva</th><th class="num">Reserva → Venta</th>
                            <th class="num">% cancelación</th><th class="num">Margen total</th><th class="num">Margen medio</th>
                        </tr></thead>
                        <tbody id="performanceEvolutionRows"></tbody>
                    </table>
                </div>
            </section>

            <section class="card panel" aria-labelledby="performanceAuditTitle">
                <div class="panel-title">
                    <div>
                        <h2 id="performanceAuditTitle">Auditoría de Rendimiento comercial</h2>
                        <div class="small">Trazabilidad local sin PII: IDs, hitos, atribución, cobertura e incidencias.</div>
                    </div>
                    <button type="button" class="filter-reset" id="loadPerformanceAudit">Cargar auditoría</button>
                </div>
                <div class="performance-note performance-note--info is-hidden" id="performanceAuditStatus"></div>
                <div id="performanceAuditResult" class="is-hidden"></div>
            </section>
        </section>
        @endif
    </main>
</div>
</x-reports.app-shell>
