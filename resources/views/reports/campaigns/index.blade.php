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

    <section class="filters card">
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
    </section>

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
                    <a class="main-tab" id="exportCsv" href="/informes/campanas/export/campaigns.csv">Export CSV</a>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th data-sortable="true" data-key="platform">Plataforma</th>
                            <th data-sortable="true" data-key="account_id">Cuenta</th>
                            <th data-sortable="true" data-key="source_acquired">Fuente</th>
                            <th data-sortable="true" data-key="medium_acquired">Medio</th>
                            <th data-sortable="true" data-key="campaign_acquired">Campaña adquirida</th>
                            <th data-sortable="true" data-key="campaign_id">Campaign ID</th>
                            <th data-sortable="true" data-key="campaign_name">Campaign name</th>
                            <th class="num" data-sortable="true" data-key="spend">Inversion</th>
                            <th class="num" data-sortable="true" data-key="impressions">Impresiones</th>
                            <th class="num" data-sortable="true" data-key="clicks">Clicks</th>
                            <th class="num" data-sortable="true" data-key="ctr">CTR</th>
                            <th class="num" data-sortable="true" data-key="cpc">CPC</th>
                            <th class="num" data-sortable="true" data-key="platform_leads">Leads plataforma</th>
                            <th class="num" data-sortable="true" data-key="leads_salesforce">Leads Salesforce</th>
                            <th class="num" data-sortable="true" data-key="opportunities">Oportunidades</th>
                            <th class="num" data-sortable="true" data-key="reservations">Reservas</th>
                            <th class="num" data-sortable="true" data-key="live_reservations">Vivas</th>
                            <th class="num" data-sortable="true" data-key="fallen_reservations">Caidas</th>
                            <th class="num" data-sortable="true" data-key="sales">Ventas</th>
                            <th class="num" data-sortable="true" data-key="sale_amount">Importe vendido</th>
                            <th class="num" data-sortable="true" data-key="cost_per_lead">CPL</th>
                            <th class="num" data-sortable="true" data-key="cost_per_opportunity">CPO</th>
                            <th class="num" data-sortable="true" data-key="cost_per_reservation">CPR</th>
                            <th class="num" data-sortable="true" data-key="cost_per_sale">CPV</th>
                            <th class="num" data-sortable="true" data-key="roas">ROAS</th>
                            <th class="num" data-sortable="true" data-key="estimated_roi">ROI</th>
                            <th class="num" data-sortable="true" data-key="click_to_lead_salesforce">Click -> Lead SF</th>
                            <th class="num" data-sortable="true" data-key="click_to_lead_platform">Click -> Lead plataforma</th>
                            <th class="num" data-sortable="true" data-key="lead_to_opportunity">Lead -> Oportunidad</th>
                            <th class="num" data-sortable="true" data-key="opportunity_to_reservation">Oportunidad -> Reserva</th>
                            <th class="num" data-sortable="true" data-key="reservation_to_sale">Reserva -> Venta</th>
                            <th class="num" data-sortable="true" data-key="lead_to_sale">Lead -> Venta</th>
                            <th data-sortable="true" data-key="classification">Clasificacion</th>
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
