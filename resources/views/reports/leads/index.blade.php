<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">

    <title>Procedencia de Leads - Dirección</title>

    @vite([
        'resources/css/reports/leads-dashboard.css',
        'resources/js/reports/leads-dashboard.js'
    ])
</head>
<body>
<div class="wrap">
    <header class="header">
        <div>
            <div class="eyebrow">Dirección</div>
            <h1>Procedencia de leads</h1>
            <p class="sub">Últimos 30 días · Portales, llamadas, formularios y delegaciones</p>
        </div>

        <div class="badge">Vista limpia para seguimiento comercial</div>
    </header>

    <section class="filters card">
        <div class="filter-group">
            <label for="period">Periodo</label>
            <select id="period">
                <option value="last_30_days">Últimos 30 días</option>
                <option value="current_month">Mes actual</option>
                <option value="previous_month">Mes anterior</option>
                <option value="custom">Personalizado</option>
            </select>
        </div>

        <div class="filter-group">
            <label for="expositionMode">Exposición</label>
            <select id="expositionMode">
                <option value="with">Con Exposición</option>
                <option value="without">Sin Exposición</option>
                <option value="only">Solo Exposición</option>
            </select>
        </div>

        <div class="filter-group">
            <label for="channel">Canal</label>
            <select id="channel">
                <option value="">Todos</option>
                <option value="Llamada">Llamada</option>
                <option value="Formulario">Formulario</option>
            </select>
        </div>

        <div class="filter-group">
            <label for="portal">Portal</label>
            <select id="portal">
                <option value="">Todos</option>
            </select>
        </div>
    </section>

    <nav class="tabs-main" aria-label="Pestañas del informe">
        <button class="main-tab active" data-panel="panel-resumen">Resumen Dirección</button>
        <button class="main-tab" data-panel="panel-kpis">KPIs clave</button>
        <button class="main-tab" data-panel="panel-portales">Procedencia por portal</button>
        <button class="main-tab" data-panel="panel-portal-detalle">Detalle de portal</button>
        <button class="main-tab" data-panel="panel-delegaciones">Delegaciones</button>
        <button class="main-tab" data-panel="panel-comerciales">Comerciales</button>
        <button class="main-tab" data-panel="panel-comparativa">Comparativa</button>
        <button class="main-tab" data-panel="panel-calidad">Calidad de dato</button>
    </nav>

    <main>
        <section id="panel-resumen" class="tab-panel active">
            <section class="kpis">
                <div class="card kpi">
                    <div class="ico">📊</div>
                    <div>
                        <div class="kpi-label">Total leads</div>
                        <div class="kpi-value" id="kpiTotal">-</div>
                        <div class="kpi-hint">Muestra analizada</div>
                    </div>
                </div>

                <div class="card kpi">
                    <div class="ico">☎️</div>
                    <div>
                        <div class="kpi-label">Llamadas</div>
                        <div class="kpi-value" id="kpiCalls">-</div>
                        <div class="kpi-hint">Canal llamada</div>
                    </div>
                </div>

                <div class="card kpi">
                    <div class="ico">📝</div>
                    <div>
                        <div class="kpi-label">Formularios</div>
                        <div class="kpi-value" id="kpiForms">-</div>
                        <div class="kpi-hint">Resto de leads</div>
                    </div>
                </div>

                <div class="card kpi">
                    <div class="ico">📍</div>
                    <div>
                        <div class="kpi-label">Pendiente clasificar</div>
                        <div class="kpi-value" id="kpiPending">-</div>
                        <div class="kpi-hint">Para cerrar clasificación</div>
                    </div>
                </div>
            </section>

            <section class="grid">
                <div class="card panel">
                    <div class="panel-title">
                        <div>
                            <h2>Leads por portal</h2>
                            <div class="small">Reparto de llamadas y formularios</div>
                        </div>

                        <div class="legend">
                            <span><i class="dot dot-call"></i>Llamadas</span>
                            <span><i class="dot dot-form"></i>Formularios</span>
                        </div>
                    </div>

                    <div class="portal-bars" id="portalBars"></div>
                </div>

                <div class="card panel">
                    <div class="panel-title">
                        <div>
                            <h2>Resumen por portal</h2>
                            <div class="small">Selecciona un portal para ver su detalle</div>
                        </div>
                    </div>

                    <div class="portal-list" id="portalList"></div>
                </div>
            </section>

            <div class="notice">
                <strong>Nota:</strong> la vista sin Exposición permite analizar captación real sin leads recreados manualmente por comerciales.
            </div>
        </section>

        <section id="panel-kpis" class="tab-panel">
            <section class="card panel">
                <div class="panel-title">
                    <div>
                        <h2>KPIs clave</h2>
                        <div class="small">Lectura con Exposición, sin Exposición y diferencia</div>
                    </div>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>Métrica</th>
                            <th class="num">Con Exposición</th>
                            <th class="num">Sin Exposición</th>
                            <th class="num">Diferencia</th>
                        </tr>
                        </thead>
                        <tbody id="kpiRows"></tbody>
                    </table>
                </div>
            </section>
        </section>

        <section id="panel-portales" class="tab-panel">
            <section class="card panel">
                <div class="panel-title">
                    <div>
                        <h2>Procedencia por portal</h2>
                        <div class="small">Llamadas, formularios, total y conversión</div>
                    </div>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>Portal</th>
                            <th class="num">Llamadas</th>
                            <th class="num">Formularios</th>
                            <th class="num">Total</th>
                            <th class="num">% llamadas</th>
                            <th class="num">% formularios</th>
                            <th class="num">Convertidos</th>
                            <th class="num">% conversión</th>
                        </tr>
                        </thead>
                        <tbody id="portalRows"></tbody>
                    </table>
                </div>
            </section>
        </section>

        <section id="panel-portal-detalle" class="tab-panel">
            <section class="card panel">
                <div class="panel-title">
                    <div>
                        <h2>Detalle por delegación y grupo</h2>
                        <div class="small">Detalle del portal seleccionado</div>
                    </div>

                    <div class="badge" id="selectedPortalBadge">-</div>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>Delegación / grupo</th>
                            <th>Tipo</th>
                            <th>Grupo comercial</th>
                            <th class="num">Llamadas</th>
                            <th class="num">Formularios</th>
                            <th class="num">Total</th>
                        </tr>
                        </thead>
                        <tbody id="detailRows"></tbody>
                    </table>
                </div>
            </section>
        </section>

        <section id="panel-delegaciones" class="tab-panel">
            <section class="card panel">
                <div class="panel-title">
                    <div>
                        <h2>Delegaciones</h2>
                        <div class="small">Vista por delegación real o grupo comercial</div>
                    </div>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>Delegación / grupo</th>
                            <th>Tipo</th>
                            <th>Grupo comercial</th>
                            <th class="num">Total leads</th>
                            <th class="num">Llamadas</th>
                            <th class="num">Formularios</th>
                            <th class="num">Convertidos</th>
                            <th class="num">% conversión</th>
                            <th class="num">Descartados</th>
                            <th class="num">% descarte</th>
                            <th class="num">Incidencias</th>
                        </tr>
                        </thead>
                        <tbody id="delegationRows"></tbody>
                    </table>
                </div>
            </section>
        </section>

        <section id="panel-comerciales" class="tab-panel">
            <section class="card panel">
                <div class="panel-title">
                    <div>
                        <h2>Comerciales</h2>
                        <div class="small">Análisis por comercial operativo, conversión y seguimiento</div>
                    </div>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>Comercial</th>
                            <th class="num">Total leads</th>
                            <th class="num">Llamadas</th>
                            <th class="num">Formularios</th>
                            <th class="num">Convertidos</th>
                            <th class="num">% conversión</th>
                            <th class="num">Descartados</th>
                            <th class="num">% descarte</th>
                            <th class="num">Potenciales</th>
                            <th class="num">Sin Task/Event</th>
                            <th class="num">Cobertura Task/Event</th>
                            <th class="num">Sin seguimiento reciente</th>
                        </tr>
                        </thead>
                        <tbody id="commercialRows"></tbody>
                    </table>
                </div>
            </section>
        </section>

        <section id="panel-comparativa" class="tab-panel">
            <section class="card panel">
                <div class="panel-title">
                    <div>
                        <h2>Comparativa entre periodos</h2>
                        <div class="small" id="comparisonPeriodLabel">Periodo actual frente a periodo comparado</div>
                    </div>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>Métrica</th>
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

        <section id="panel-calidad" class="tab-panel">
            <section class="card panel">
                <div class="panel-title">
                    <div>
                        <h2>Calidad de dato</h2>
                        <div class="small">Incidencias pendientes de normalización</div>
                    </div>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>Incidencia</th>
                            <th class="num">Registros</th>
                            <th>Acción</th>
                        </tr>
                        </thead>
                        <tbody id="qualityRows"></tbody>
                    </table>
                </div>
            </section>
        </section>
    </main>
</div>
</body>
</html>