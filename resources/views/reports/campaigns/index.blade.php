<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Campanas | HR Motor - Informes comerciales</title>
    <link rel="icon" href="/brand/favicon.ico" sizes="any">
    <link rel="shortcut icon" href="/brand/favicon.ico">

    @vite([
        'resources/css/reports/leads-dashboard.css',
        'resources/js/reports/campaigns-dashboard.js'
    ])
    <script>
        window.reportUserRole = @json($reportUserRole ?? 'viewer');
        window.reportUserCanExport = @json($reportUserCanExport ?? false);
    </script>
</head>
<body>
<div class="wrap">
    @include('reports.partials.report-header', ['currentReport' => 'campaigns', 'subtitle' => 'Rentabilidad de campanas digitales'])

    <section class="filters card campaigns-filter-bar">
        <div class="filter-group">
            <label for="periodPreset">Periodo</label>
            <select id="periodPreset"></select>
        </div>
        <div class="filter-group">
            <label for="startDate">Inicio</label>
            <input type="date" id="startDate">
        </div>
        <div class="filter-group">
            <label for="endDate">Fin</label>
            <input type="date" id="endDate">
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
            <label for="campaignStatus">Estado campana</label>
            <select id="campaignStatus">
                <option value="">Todas</option>
                <option value="active" selected>Activas</option>
                <option value="inactive">Inactivas</option>
            </select>
        </div>
        <div class="filter-group campaign-search">
            <label for="campaignSearch">Buscar campana</label>
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
                    <div class="small">Segmenta campanas reales por campana, resultado y clasificacion</div>
                </div>
                <button type="button" class="main-tab" id="advancedFiltersClose">Cerrar</button>
            </div>
            <div class="campaign-advanced-grid">
                <div class="filter-group">
                    <label for="campaignSourceType">Tipo campana</label>
                    <select id="campaignSourceType"><option value="">Todos</option></select>
                </div>
                <div class="filter-group">
                    <label for="mediumAcquired">Medio adquirido</label>
                    <select id="mediumAcquired"><option value="">Todos</option></select>
                </div>
                <div class="filter-group">
                    <label for="campaignAcquired">Campana adquirida</label>
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
                    <label for="classification">Clasificacion</label>
                    <select id="classification"><option value="">Todas</option></select>
                </div>
            </div>
        </section>
    </aside>

    <nav class="tabs-main" aria-label="Pestanas del informe">
        <button class="main-tab active" data-context="venta" data-panel="panel-resumen">Venta</button>
        <button class="main-tab" data-context="tasacion" data-panel="panel-resumen">Tasacion</button>
        <button class="main-tab" data-panel="panel-campaigns">Campanas</button>
    </nav>

    <main>
        <section id="panel-resumen" class="tab-panel active">
            <div class="notice" id="loadingMessage">Cargando datos de campanas...</div>
            <div class="notice is-hidden" id="emptyMessage">No hay campanas de plataforma para el periodo seleccionado.</div>
            <div id="warnings"></div>

            <section class="period-strip">
                <div class="card period-card">
                    <span>Periodo</span>
                    <strong id="periodLabel">-</strong>
                </div>
                <div class="card period-card">
                    <span>Base informe</span>
                    <strong id="windowLabel">-</strong>
                </div>
            </section>

            <section class="kpis dashboard-kpis" id="summaryKpis"></section>
            <section class="campaign-charts-grid" id="campaignCharts"></section>
            <section class="card panel is-hidden" id="platformComparisonPanel">
                <div class="panel-title">
                    <div>
                        <h2>Comparativa por plataforma</h2>
                        <div class="small">Google Ads frente a Meta Ads en el contexto seleccionado</div>
                    </div>
                </div>
                <div class="campaign-bar-list" id="platformComparison"></div>
            </section>
            @if (($reportUserRole ?? 'viewer') === 'admin')
            <section class="card panel" id="campaignDiagnosticsPanel">
                <div class="panel-title">
                    <div>
                        <h2>Diagnostico de sincronizacion</h2>
                        <div class="small">Estado de inversion, atribucion y calidad de tracking</div>
                    </div>
                    <span class="badge" id="updatedBadge">Datos actualizados: pendiente</span>
                </div>
                <div class="campaign-diagnostics" id="campaignDiagnostics"></div>
            </section>
            @endif

            <section class="card panel">
                <div class="panel-title">
                    <div>
                        <h2>Rankings</h2>
                        <div class="small">Campanas reales de Google Ads y Meta Ads</div>
                    </div>
                    <div class="ranking-settings">
                        <button type="button" class="main-tab" id="rankingsToggle">Configurar rankings</button>
                        <div class="ranking-popover card is-hidden" id="rankingsPopover"></div>
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
                        <div class="small">Solo campanas reales de Google Ads y Meta Ads, sin datos personales</div>
                    </div>
                    <div class="campaign-table-actions">
                        <div class="columns-menu">
                            <button type="button" class="main-tab" id="columnsToggle">Columnas</button>
                            <div class="columns-popover card is-hidden" id="columnsPopover"></div>
                        </div>
                        @if ($reportUserCanExport ?? false)
                            <a class="main-tab" id="exportCsv" href="/informes/campanas/export/campaigns.csv">Export CSV</a>
                        @endif
                    </div>
                </div>
                <div class="table-scroll-proxy" id="campaignTableTopScroll"><div></div></div>
                <div class="table-wrap" id="campaignTableWrap">
                    <table>
                        <thead>
                        <tr>
                            <th data-column="campaign" data-sortable="true" data-key="campaign_name">Campana</th>
                            <th data-column="platform" data-sortable="true" data-key="platform">Plataforma</th>
                            <th data-column="spend" class="num" data-sortable="true" data-key="spend">Inversion</th>
                            <th data-column="impressions" class="num" data-sortable="true" data-key="impressions">Impresiones</th>
                            <th data-column="clicks" class="num" data-sortable="true" data-key="clicks">Clicks</th>
                            <th data-column="ctr" class="num" data-sortable="true" data-key="ctr">CTR</th>
                            <th data-column="cpc" class="num" data-sortable="true" data-key="cpc">CPC</th>
                            <th data-column="leads_salesforce" class="num" data-sortable="true" data-key="leads_salesforce">Leads Salesforce</th>
                            <th data-column="opportunities" class="num" data-sortable="true" data-key="opportunities">Oportunidades</th>
                            <th data-column="reservations" class="num" data-sortable="true" data-key="reservations">Reservas</th>
                            <th data-column="sales" class="num" data-sortable="true" data-key="sales">Ventas</th>
                            <th data-column="sale_amount" class="num" data-sortable="true" data-key="sale_amount">Importe vendido</th>
                            <th data-column="account_id" data-sortable="true" data-key="account_id">Cuenta</th>
                            <th data-column="campaign_id" data-sortable="true" data-key="campaign_id">Campaign ID</th>
                            <th data-column="source_acquired" data-sortable="true" data-key="source_acquired">Fuente adquirida</th>
                            <th data-column="medium_acquired" data-sortable="true" data-key="medium_acquired">Medio adquirido</th>
                            <th data-column="acquired_id" data-sortable="true" data-key="acquired_id">ID adquirido</th>
                            <th data-column="content_acquired" data-sortable="true" data-key="content_acquired">Contenido adquirido</th>
                            <th data-column="cost_per_lead" class="num" data-sortable="true" data-key="cost_per_lead">CPL</th>
                            <th data-column="cost_per_opportunity" class="num" data-sortable="true" data-key="cost_per_opportunity">CPO</th>
                            <th data-column="cost_per_reservation" class="num" data-sortable="true" data-key="cost_per_reservation">CPR</th>
                            <th data-column="cost_per_sale" class="num" data-sortable="true" data-key="cost_per_sale">CPV</th>
                            <th data-column="roas" class="num" data-sortable="true" data-key="roas">ROAS</th>
                            <th data-column="estimated_roi" class="num" data-sortable="true" data-key="estimated_roi">ROI estimado</th>
                            <th data-column="classification" data-sortable="true" data-key="classification">Clasificacion</th>
                            <th data-column="campaign_status_label" data-sortable="true" data-key="campaign_status_label">Estado campana</th>
                            <th data-column="campaign_start_date" data-sortable="true" data-key="campaign_start_date">Fecha inicio campana</th>
                            <th data-column="campaign_end_date" data-sortable="true" data-key="campaign_end_date">Fecha fin campana</th>
                            <th data-column="last_spend_date" data-sortable="true" data-key="last_spend_date">Ultima fecha con inversion</th>
                            <th data-column="appraisals_generated" class="num" data-sortable="true" data-key="appraisals_generated">Tasaciones generadas</th>
                            <th data-column="purchases" class="num" data-sortable="true" data-key="purchases">Compras firmadas</th>
                            <th data-column="cost_per_appraisal" class="num" data-sortable="true" data-key="cost_per_appraisal">Coste por tasacion</th>
                            <th data-column="cost_per_purchase" class="num" data-sortable="true" data-key="cost_per_purchase">Coste por compra</th>
                            <th data-column="lead_to_opportunity" class="num" data-sortable="true" data-key="lead_to_opportunity">Lead -> Oportunidad</th>
                            <th data-column="opportunity_to_reservation" class="num" data-sortable="true" data-key="opportunity_to_reservation">Oportunidad -> Reserva</th>
                            <th data-column="reservation_to_sale" class="num" data-sortable="true" data-key="reservation_to_sale">Reserva -> Venta</th>
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
