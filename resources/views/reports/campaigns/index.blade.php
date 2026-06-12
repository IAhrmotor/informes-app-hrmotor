<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Campanas | HR Motor - Informes comerciales</title>
    <link rel="icon" href="/brand/favicon.ico" sizes="any">
    <link rel="shortcut icon" href="/brand/favicon.ico">
    @include('partials.font-assets')

    @vite([
        'resources/css/reports/leads-dashboard.css',
        'resources/js/reports/campaigns-dashboard.js'
    ])
    <script>
        window.reportUserRole = @json($reportUserRole ?? 'viewer');
        window.reportUserCanExport = @json($reportUserCanExport ?? false);
    </script>
</head>
<body class="campaigns-report">
<div class="wrap">
    @include('reports.partials.report-header', ['currentReport' => 'campaigns', 'subtitle' => 'Rentabilidad digital'])

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
            <label for="campaignType">Tipo campana</label>
            <select id="campaignType">
                <option value="all" selected>Todos</option>
                <option value="venta">Venta</option>
                <option value="tasacion">Tasacion</option>
                <option value="exposicion">Exposicion</option>
                <option value="branding">Branding</option>
                <option value="otros">Otros</option>
            </select>
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
                    <label>Nombre de campana</label>
                    <div class="campaign-name-toolbar">
                        <button type="button" class="main-tab" id="campaignNamesSelectAll">Seleccionar todas</button>
                        <button type="button" class="filter-reset" id="campaignNamesClear">Limpiar</button>
                    </div>
                    <div class="campaign-name-checklist" id="campaignNameChecklist"></div>
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

    @if (\App\Support\ReportUserAccess::canSeeSyncDiagnostics(request()))
    <aside class="campaign-drawer campaign-diagnostics-drawer" id="campaignDiagnosticsModal" aria-hidden="true">
        <div class="campaign-drawer-backdrop" id="campaignDiagnosticsCloseBackdrop"></div>
        <section class="campaign-drawer-panel card" aria-label="Diagnostico de sincronizacion">
            <div class="panel-title">
                <div>
                    <h2>Diagnostico de sincronizacion</h2>
                    <div class="small">Estado de inversion, atribucion y calidad de tracking</div>
                </div>
                <button type="button" class="main-tab" id="campaignDiagnosticsClose">Cerrar</button>
            </div>
            <div class="campaign-diagnostics" id="campaignDiagnostics"></div>
        </section>
    </aside>
    @endif

    <main>
        <section class="tab-panel active">
            <div class="notice" id="loadingMessage">Cargando datos de campanas...</div>
            <div class="notice is-hidden" id="emptyMessage">No hay campanas de plataforma para el periodo seleccionado.</div>
            <div id="warnings"></div>
            <div class="campaign-legacy-context" aria-hidden="true">
                <span id="periodLabel"></span>
                <span id="pivotLabel"></span>
                <span id="selectedContextLabel"></span>
                <span id="updatedBadge"></span>
            </div>

            <section class="campaign-topbar">
                <nav class="tabs-main campaign-context-tabs" aria-label="Contexto de campanas">
                    <button type="button" class="main-tab active" data-context="all">Todos</button>
                    <button type="button" class="main-tab" data-context="venta">Venta</button>
                    <button type="button" class="main-tab" data-context="tasacion">Tasacion</button>
                    <button type="button" class="main-tab" data-context="exposicion">Exposicion</button>
                    <button type="button" class="main-tab" data-context="branding">Branding</button>
                    <button type="button" class="main-tab" data-context="otros">Otros</button>
                </nav>
                @if (\App\Support\ReportUserAccess::canSeeSyncDiagnostics(request()))
                <div class="campaign-topbar-actions">
                    <button type="button" class="main-tab" id="diagnosticsOpen">Ver diagnostico</button>
                </div>
                @endif
            </section>

            <section class="kpis dashboard-kpis" id="summaryKpis"></section>

            <section class="card panel is-hidden" id="platformComparisonPanel">
                <div class="panel-title">
                    <div>
                        <h2>Comparativa por plataforma</h2>
                        <div class="small">Google Ads frente a Meta Ads en el contexto seleccionado</div>
                    </div>
                </div>
                <div class="campaign-bar-list platform-comparison-grid" id="platformComparison"></div>
            </section>

            <section class="campaign-charts-grid" id="campaignCharts"></section>

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

            <section id="campaignTables"></section>
        </section>
    </main>
</div>
</body>
</html>
