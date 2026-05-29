<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Campañas | HR Motor - Informes comerciales</title>
    <link rel="icon" href="/brand/favicon.ico" sizes="any">
    <link rel="shortcut icon" href="/brand/favicon.ico">

    @vite([
        'resources/css/reports/leads-dashboard.css',
        'resources/js/reports/campaigns-dashboard.js'
    ])
</head>
<body>
<div class="wrap">
    @include('reports.partials.report-header', ['currentReport' => 'campaigns', 'subtitle' => 'Rentabilidad de campañas digitales'])

    <section class="filters card campaigns-filter-bar">
        <div class="filter-group">
            <label for="startDate">Inicio</label>
            <input type="date" id="startDate">
        </div>
        <div class="filter-group">
            <label for="endDate">Fin</label>
            <input type="date" id="endDate">
        </div>
        <div class="filter-group">
            <label for="attributionWindow">Ventana</label>
            <select id="attributionWindow">
                <option value="7">7 dias</option>
                <option value="15">15 dias</option>
                <option value="30" selected>30 dias</option>
                <option value="60">60 dias</option>
            </select>
        </div>
        <div class="filter-group">
            <label for="platform">Plataforma</label>
            <select id="platform"><option value="">Todas</option></select>
        </div>
        <div class="filter-group">
            <label for="accountId">Cuenta</label>
            <select id="accountId"><option value="">Todas</option></select>
        </div>
        <div class="filter-group campaign-search">
            <label for="campaignSearch">Buscar campaÃ±a</label>
            <input type="search" id="campaignSearch" placeholder="Nombre, ID, fuente o medio">
        </div>
        <div class="filter-actions campaign-filter-actions">
            <button type="button" class="main-tab" id="advancedFiltersOpen">Filtros avanzados</button>
            <button type="button" class="filter-reset" id="resetFilters">Limpiar filtros</button>
        </div>
    </section>

    <aside class="campaign-drawer" id="advancedFiltersDrawer" aria-hidden="true">
        <div class="campaign-drawer-backdrop" id="advancedFiltersCloseBackdrop"></div>
        <section class="campaign-drawer-panel card" aria-label="Filtros avanzados">
            <div class="panel-title">
                <div>
                    <h2>Filtros avanzados</h2>
                    <div class="small">Segmenta por origen, delegacion y resultado Salesforce</div>
                </div>
                <button type="button" class="main-tab" id="advancedFiltersClose">Cerrar</button>
            </div>
            <div class="campaign-advanced-grid">
        <div class="filter-group">
            <label for="sourceAcquired">Fuente adquirida</label>
            <select id="sourceAcquired"><option value="">Todas</option></select>
        </div>
        <div class="filter-group">
            <label for="mediumAcquired">Medio adquirido</label>
            <select id="mediumAcquired"><option value="">Todos</option></select>
        </div>
        <div class="filter-group">
            <label for="campaignAcquired">Campaña adquirida</label>
            <select id="campaignAcquired"><option value="">Todas</option></select>
        </div>
        <div class="filter-group">
            <label for="campaignId">Campaign ID</label>
            <select id="campaignId"><option value="">Todos</option></select>
        </div>
        <div class="filter-group">
            <label for="campaignName">Campaign name</label>
            <select id="campaignName"><option value="">Todas</option></select>
        </div>
        <div class="filter-group">
            <label for="delegation">Delegacion</label>
            <select id="delegation"><option value="">Todas</option></select>
        </div>
        <div class="filter-group">
            <label for="zone">Zona</label>
            <select id="zone"><option value="">Todas</option></select>
        </div>
        <div class="filter-group">
            <label for="leadStatus">Estado lead</label>
            <select id="leadStatus"><option value="">Todos</option></select>
        </div>
        <div class="filter-group">
            <label for="hasOpportunity">Oportunidad</label>
            <select id="hasOpportunity">
                <option value="">Todas</option>
                <option value="yes">Si</option>
                <option value="no">No</option>
            </select>
        </div>
        <div class="filter-group">
            <label for="hasReservation">Reserva</label>
            <select id="hasReservation">
                <option value="">Todas</option>
                <option value="yes">Si</option>
                <option value="no">No</option>
            </select>
        </div>
        <div class="filter-group">
            <label for="hasSale">Venta</label>
            <select id="hasSale">
                <option value="">Todas</option>
                <option value="yes">Si</option>
                <option value="no">No</option>
            </select>
        </div>
        <div class="filter-group">
            <label for="commercialUser">Comercial</label>
            <select id="commercialUser"><option value="">Todos</option></select>
        </div>
        <div class="filter-group">
            <label for="vehicleInterest">Vehiculo/anuncio</label>
            <select id="vehicleInterest"><option value="">Todos</option></select>
        </div>
            </div>
        </section>
    </aside>

    <nav class="tabs-main" aria-label="Pestanas del informe">
        <button class="main-tab active" data-panel="panel-resumen">Resumen</button>
        <button class="main-tab" data-panel="panel-campaigns">Campañas</button>
    </nav>

    <main>
        <section id="panel-resumen" class="tab-panel active">
            <div class="notice" id="loadingMessage">Cargando datos de campañas...</div>
            <div class="notice is-hidden" id="emptyMessage">No hay campañas atribuidas para el periodo seleccionado.</div>
            <div id="warnings"></div>

            <section class="period-strip">
                <div class="card period-card">
                    <span>Periodo</span>
                    <strong id="periodLabel">-</strong>
                </div>
                <div class="card period-card">
                    <span>Ventana atribucion</span>
                    <strong id="windowLabel">-</strong>
                </div>
            </section>

            <section class="kpis dashboard-kpis" id="summaryKpis"></section>

            <section class="card panel">
                <div class="panel-title">
                    <div>
                        <h2>Diagnostico de sincronizacion</h2>
                        <div class="small">Estado de inversion, atribucion y calidad de tracking</div>
                    </div>
                    <span class="badge" id="updatedBadge">Datos actualizados: pendiente</span>
                </div>
                <div class="campaign-diagnostics" id="campaignDiagnostics"></div>
            </section>

            <section class="card panel">
                <div class="panel-title">
                    <div>
                        <h2>Rankings</h2>
                        <div class="small">Campañas agregadas por rendimiento</div>
                    </div>
                </div>
                <div class="priority-grid" id="rankingsGrid"></div>
            </section>
        </section>

        <section id="panel-campaigns" class="tab-panel">
            <section class="card panel">
                <div class="panel-title">
                    <div>
                        <h2>Tabla principal</h2>
                        <div class="small">Agregado por campaña, sin datos personales</div>
                    </div>
                    <div class="campaign-table-actions">
                        <div class="columns-menu">
                            <button type="button" class="main-tab" id="columnsToggle">Columnas</button>
                            <div class="columns-popover card is-hidden" id="columnsPopover"></div>
                        </div>
                        <a class="main-tab" id="exportCsv" href="/informes/campanas/export/campaigns.csv">Export CSV</a>
                    </div>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th data-column="campaign" data-sortable="true" data-key="campaign_name">Campaña</th>
                            <th data-column="platform" data-sortable="true" data-key="platform">Plataforma</th>
                            <th data-column="match_status" data-sortable="true" data-key="match_status">Estado de cruce</th>
                            <th data-column="classification" data-sortable="true" data-key="classification">Clasificacion</th>
                            <th data-column="spend" class="num" data-sortable="true" data-key="spend">Inversion</th>
                            <th data-column="impressions" class="num" data-sortable="true" data-key="impressions">Impresiones</th>
                            <th data-column="clicks" class="num" data-sortable="true" data-key="clicks">Clicks</th>
                            <th data-column="ctr" class="num" data-sortable="true" data-key="ctr">CTR</th>
                            <th data-column="cpc" class="num" data-sortable="true" data-key="cpc">CPC</th>
                            <th data-column="platform_leads" class="num" data-sortable="true" data-key="platform_leads">Leads plataforma</th>
                            <th data-column="leads_salesforce" class="num" data-sortable="true" data-key="leads_salesforce">Leads Salesforce</th>
                            <th data-column="opportunities" class="num" data-sortable="true" data-key="opportunities">Oportunidades</th>
                            <th data-column="reservations" class="num" data-sortable="true" data-key="reservations">Reservas</th>
                            <th data-column="sales" class="num" data-sortable="true" data-key="sales">Ventas</th>
                            <th data-column="sale_amount" class="num" data-sortable="true" data-key="sale_amount">Importe vendido</th>
                            <th data-column="cost_per_lead" class="num" data-sortable="true" data-key="cost_per_lead">CPL</th>
                            <th data-column="cost_per_sale" class="num" data-sortable="true" data-key="cost_per_sale">CPV</th>
                            <th data-column="roas" class="num" data-sortable="true" data-key="roas">ROAS</th>
                            <th data-column="account_id" data-sortable="true" data-key="account_id">Cuenta</th>
                            <th data-column="campaign_id" data-sortable="true" data-key="campaign_id">Campaign ID</th>
                            <th data-column="campaign_name" data-sortable="true" data-key="campaign_name">Campaign name</th>
                            <th data-column="source_acquired" data-sortable="true" data-key="source_acquired">Fuente</th>
                            <th data-column="medium_acquired" data-sortable="true" data-key="medium_acquired">Medio</th>
                            <th data-column="campaign_acquired" data-sortable="true" data-key="campaign_acquired">Campaña adquirida</th>
                            <th data-column="cost_per_opportunity" class="num" data-sortable="true" data-key="cost_per_opportunity">CPO</th>
                            <th data-column="cost_per_reservation" class="num" data-sortable="true" data-key="cost_per_reservation">CPR</th>
                            <th data-column="estimated_roi" class="num" data-sortable="true" data-key="estimated_roi">ROI</th>
                            <th data-column="lead_to_opportunity" class="num" data-sortable="true" data-key="lead_to_opportunity">Lead -> Oportunidad</th>
                            <th data-column="lead_to_sale" class="num" data-sortable="true" data-key="lead_to_sale">Lead -> Venta</th>
                        </tr>
                        </thead>
                        <tbody id="campaignRows"></tbody>
                    </table>
                </div>
            </section>
        </section>
    </main>
</div>
</body>
</html>
