<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Leads | HR Motor - Informes comerciales</title>
    <link rel="icon" href="/brand/favicon.ico" sizes="any">
    <link rel="shortcut icon" href="/brand/favicon.ico">
    @include('partials.font-assets')

    @vite([
        'resources/css/reports/leads-dashboard.css',
        'resources/js/reports/leads-dashboard.js'
    ])
</head>
<body>
<div class="wrap">
    <script>
        window.reportUserCanExport = @json($reportUserCanExport ?? false);
    </script>
    @include('reports.partials.report-header', ['currentReport' => 'leads', 'subtitle' => 'Leads'])

    <section class="filters card">
        <div class="filter-group">
            <label for="period">Periodo</label>
            <select id="period">
                <option value="last_30_days">Ultimos 30 dias</option>
                <option value="current_month">Mes actual</option>
                <option value="previous_month">Mes anterior</option>
                <option value="custom">Personalizado</option>
            </select>
        </div>

        <div class="filter-group">
            <label for="leadType">Tipo de Lead</label>
            <select id="leadType">
                <option value="all">Todos</option>
                <option value="Tasacion">Tasacion</option>
                <option value="Venta">Venta</option>
            </select>
        </div>

        <div class="filter-group">
            <label for="leadDelegation">Delegacion del lead</label>
            <select id="leadDelegation">
                <option value="">Todas</option>
            </select>
        </div>

        <div class="filter-group">
            <label for="commercialDelegation">Delegacion comercial</label>
            <select id="commercialDelegation">
                <option value="">Todas</option>
            </select>
        </div>

        <div class="filter-group">
            <label for="zone">Zona</label>
            <select id="zone">
                <option value="">Todas</option>
            </select>
        </div>

        <div class="filter-group">
            <label for="portal">Portal</label>
            <select id="portal">
                <option value="">Todos</option>
            </select>
        </div>

        <div class="filter-group">
            <label for="commercial">Comercial</label>
            <select id="commercial">
                <option value="">Todos</option>
            </select>
        </div>

        <div class="filter-group">
            <label for="expositionMode">Exposicion</label>
            <select id="expositionMode">
                <option value="with">Incluir</option>
                <option value="without">Excluir</option>
            </select>
        </div>
        <div class="filter-actions">
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
        <button class="main-tab active" data-panel="panel-resumen">Resumen Direccion</button>
        <button class="main-tab" data-panel="panel-comerciales">Comerciales/Delegaciones/Zonas</button>
        <button class="main-tab" data-panel="panel-delegaciones">Delegaciones por reparto de leads</button>
        <button class="main-tab" data-panel="panel-portales">Portales / Procedencia</button>
    </nav>

    <main>
        <section id="panel-resumen" class="tab-panel active">
            <div class="notice" id="loadingMessage">Cargando datos de Salesforce...</div>
            <div class="notice is-hidden" id="emptyMessage">No hay datos sincronizados para el periodo seleccionado.</div>

            <section class="card panel">
                <div class="panel-title">
                    <div>
                        <h2>Resumen ejecutivo</h2>
                        <div class="small">Conclusiones generadas con KPIs reales del periodo</div>
                    </div>
                </div>
                <div class="insights" id="insights"></div>
            </section>

            <section class="period-strip">
                <div class="card period-card">
                    <span>Periodo actual</span>
                    <strong id="currentPeriodLabel">-</strong>
                </div>
                <div class="card period-card">
                    <span>Periodo comparado</span>
                    <strong id="comparisonPeriodLabel">-</strong>
                </div>
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
            <section class="filters card">
                <div class="filter-group">
                    <label for="commercialPanelsOrder">Orden de cuadros</label>
                    <select id="commercialPanelsOrder">
                        <option value="zones,delegations,commercials">Zonas, Delegaciones, Comerciales</option>
                        <option value="commercials,delegations,zones">Comerciales, Delegaciones, Zonas</option>
                        <option value="delegations,zones,commercials">Delegaciones, Zonas, Comerciales</option>
                    </select>
                </div>
            </section>

            <div id="commercialPanels">
            <section class="card panel" data-commercial-section="zones">
                <div class="panel-title">
                    <div>
                        <h2>Zonas</h2>
                        <div class="small">Agrupado por zona comercial del usuario atribuido</div>
                    </div>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>Zona</th>
                            <th class="num">Leads totales</th>
                            <th class="num">Convertidos</th>
                            <th class="num">Descartados</th>
                            <th class="num">Potenciales</th>
                            <th class="num">Potenciales sin trabajar</th>
                            <th class="num">Gestionados</th>
                        </tr>
                        </thead>
                        <tbody id="commercialZoneRows"></tbody>
                    </table>
                </div>
            </section>

            <section class="card panel" data-commercial-section="delegations">
                <div class="panel-title">
                    <div>
                        <h2>Delegaciones</h2>
                        <div class="small">Agrupado por delegacion comercial del usuario atribuido</div>
                    </div>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>Delegacion comercial</th>
                            <th>Zona</th>
                            <th class="num">Leads totales</th>
                            <th class="num">Convertidos</th>
                            <th class="num">Descartados</th>
                            <th class="num">Potenciales</th>
                            <th class="num">Potenciales sin trabajar</th>
                            <th class="num">Gestionados</th>
                        </tr>
                        </thead>
                        <tbody id="commercialDelegationRows"></tbody>
                    </table>
                </div>
            </section>

            <section class="card panel" data-commercial-section="commercials">
                <div class="panel-title">
                    <div>
                        <h2>Comerciales</h2>
                        <div class="small">Solo usuarios activos con perfiles comerciales permitidos</div>
                    </div>
                    <div class="columns-menu">
                        <button type="button" class="filter-reset" id="leadCommercialColumnsButton">Columnas</button>
                        <div class="columns-popover card is-hidden" id="leadCommercialColumnsPopover"></div>
                    </div>
                </div>
                <div class="table-wrap">
                    <table id="leadCommercialTable">
                        <thead>
                        <tr>
                            <th data-column="comercial">Comercial</th>
                            <th data-column="commercial_delegation">Delegacion comercial</th>
                            <th data-column="zone">Zona</th>
                            <th class="num" data-column="leads_totales">Leads totales</th>
                            <th class="num" data-column="convertidos">Convertidos</th>
                            <th class="num is-hidden" data-column="conversion_pct">% convertidos</th>
                            <th class="num" data-column="descartados">Descartados</th>
                            <th class="num is-hidden" data-column="descarte_pct">% descartados</th>
                            <th class="num" data-column="potenciales">Potenciales</th>
                            <th class="num is-hidden" data-column="potenciales_pct">% potenciales</th>
                            <th class="num" data-column="potenciales_sin_trabajar">Potenciales sin trabajar</th>
                            <th class="num is-hidden" data-column="potenciales_sin_trabajar_pct">% potenciales sin trabajar</th>
                            <th class="num" data-column="gestionados">Gestionados</th>
                            <th class="num is-hidden" data-column="gestionados_pct">% gestionados</th>
                        </tr>
                        </thead>
                        <tbody id="commercialRows"></tbody>
                    </table>
                </div>
            </section>
            </div>
        </section>

        <section id="panel-delegaciones" class="tab-panel">
            <section class="card panel">
                <div class="panel-title">
                    <div>
                        <h2>Delegaciones por reparto de leads</h2>
                        <div class="small">Total entrado frente a leads en Potencial con owner generico</div>
                    </div>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>Delegacion del lead</th>
                            <th class="num">Leads totales</th>
                            <th class="num">Potencial con owner generico</th>
                            <th class="num">% pendiente</th>
                        </tr>
                        </thead>
                        <tbody id="delegationRows"></tbody>
                    </table>
                </div>
            </section>
        </section>

        <section id="panel-portales" class="tab-panel">
            <section class="card panel">
                <div class="panel-title">
                    <div>
                        <h2>Portales / Procedencia</h2>
                        <div class="small">Procedencia comercial desde Salesforce</div>
                    </div>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>Portal / Procedencia</th>
                            <th class="num">Leads totales</th>
                            <th class="num">Convertidos</th>
                            <th class="num">Descartados</th>
                            <th class="num">Potenciales</th>
                            <th class="num">Potenciales sin trabajar</th>
                            <th class="num">Gestionados</th>
                            <th class="num">Llamadas</th>
                            <th class="num">Formularios</th>
                        </tr>
                        </thead>
                        <tbody id="portalRows"></tbody>
                    </table>
                </div>
            </section>
        </section>
    </main>
</div>
</body>
</html>
