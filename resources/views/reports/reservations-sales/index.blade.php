<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Reservas / Ventas | HR Motor - Informes comerciales</title>
    <link rel="icon" href="/brand/favicon.ico" sizes="any">
    <link rel="shortcut icon" href="/brand/favicon.ico">

    @vite([
        'resources/css/reports/leads-dashboard.css',
        'resources/js/reports/reservations-sales-dashboard.js'
    ])
</head>
<body>
<div class="wrap">
    @include('reports.partials.report-header', ['currentReport' => 'reservations-sales', 'subtitle' => 'Reservas / Ventas'])

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
            <label for="dateCriterion">Criterio de fecha</label>
            <select id="dateCriterion">
                <option value="created_date">Fecha de creacion</option>
                <option value="reservation_date">Fecha de reserva</option>
                <option value="cv_signed_date">Fecha de firma contrato</option>
            </select>
        </div>

        <div class="filter-group">
            <label for="opportunityType">Tipo de oportunidad</label>
            <select id="opportunityType">
                <option value="all">Todos</option>
                <option value="Tasacion">Tasación</option>
                <option value="Venta">Venta</option>
            </select>
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
    </nav>

    <main>
        <section id="panel-resumen" class="tab-panel active">
            <div class="notice" id="loadingMessage">Cargando datos de Salesforce...</div>
            <div class="notice is-hidden" id="emptyMessage">No hay oportunidades sincronizadas para el periodo seleccionado.</div>

            <section class="card panel">
                <div class="panel-title">
                    <div>
                        <h2>Resumen ejecutivo</h2>
                        <div class="small">Conclusiones sobre reservas, caidas y contratos CV</div>
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
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>Comercial</th>
                            <th>Delegacion comercial</th>
                            <th>Zona</th>
                            <th class="num">Oportunidades totales</th>
                            <th class="num">Reservas vivas</th>
                            <th class="num">Oportunidades caidas</th>
                            <th class="num">Contratos CV firmados</th>
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
    </main>
</div>
</body>
</html>
