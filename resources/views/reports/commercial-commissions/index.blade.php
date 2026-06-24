<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Comisiones Comerciales | HR Motor - Informes comerciales</title>
    <link rel="icon" href="/brand/favicon.ico" sizes="any">
    <link rel="shortcut icon" href="/brand/favicon.ico">
    @include('partials.font-assets')

    @vite([
        'resources/css/reports/leads-dashboard.css',
        'resources/js/reports/commercial-commissions-dashboard.js',
    ])
    <script>
        window.reportUserRole = @json($reportUserRole ?? 'viewer');
    </script>
</head>
@php
    $summaryRows = collect($dashboard['summary_rows'] ?? []);
    $defaultCommercialId = $summaryRows->first()['commercial_id'] ?? null;
    $totalFinalCommission = (float) $summaryRows->sum('final_commission');
    $totalPrimaAdjusted = (float) $summaryRows->sum('prima_adjusted');
    $totalPenalties = (float) $summaryRows->sum('total_penalties');
    $totalDeliveries = (int) $summaryRows->sum('deliveries_count');
    $kpiTooltips = [
        'Mes analizado' => 'Mes cerrado usado por el informe. Pivota por cv_signed_date, que viene de Fecha_firma_contrato__c.',
        'Oportunidades' => 'Conteo de salesforce_opportunities con cv_signed=true, stage_name distinto de Cerrada perdida, record_type Venta/Cambio/Tasacion y gestion_de_venta false o null.',
        'Resenas' => 'Conteo de salesforce_reviews creadas dentro del mes. Fuente Salesforce: objeto Resena__c.',
        'Estado' => 'Indica si existen bloqueos de datos o estructura que impiden calcular el informe.',
        'Comerciales' => 'Numero de usuarios activos cargados desde salesforce_users y mostrados en el resumen, aunque tengan 0 actividad en el mes.',
        'Comision final' => 'Formula: max(prima ajustada - penalizaciones, 0) + producto financiacion + producto garantias.',
        'Prima ajustada' => 'Formula: prima total x tramo de entregas.',
        'Penalizaciones' => 'Suma de penalizacion por garantias, penalizacion por resenas y penalizacion por financiacion.',
        'Entregas' => 'Conteo de oportunidades de tipo Venta y Cambio del mes. Cada entrega suma 60 EUR.',
        'Operaciones' => 'Conteo de oportunidades de tipo Venta, Cambio y Tasacion del mes.',
        'Compras liquidadas' => 'Compras historicas enlazadas a una venta del mes. Formula por compra: rentabilidad_compra x 1.8%. Fuente principal: informe_rentabilidad.',
        'Compartidas' => 'Suma de 30 EUR por cada oportunidad con Entrega_Compartida__c.',
        'Ventas' => 'Importe calculado como entregas x 60 EUR.',
        'Stock +150' => 'Suma de 10 EUR por cada entrega con Dias_en_stock__c >= 150.',
        'Bonus +15' => 'Suma de 30 EUR por cada entrega a partir de la numero 16.',
        'Prima total' => 'Formula: ventas + compras + compartidas - descuento 5% + stock +150 + bonus +15.',
        'Prima neta' => 'Formula: max(prima ajustada - penalizaciones, 0).',
        'Prod. financiacion' => 'Producto calculado por tramos sobre Beneficio_financiacion_comercial__c.',
        'Prod. garantias' => 'Producto calculado por tramos sobre Garant_a_Total__c.',
    ];
    $tooltip = static fn (string $label): string => $kpiTooltips[$label] ?? '';
@endphp
<body class="campaigns-report commercial-commissions-report">
<div class="wrap">
    @include('reports.partials.report-header', ['currentReport' => 'commercial-commissions', 'subtitle' => 'Comisiones mensuales'])

    <main>
        <section class="tab-panel active">
            <section class="filters card commission-filters">
                <form method="GET" class="commission-filter-form">
                    <div class="filter-group">
                        <label for="month">Mes cerrado</label>
                        <input type="month" id="month" name="month" value="{{ $dashboard['month'] }}">
                    </div>
                    <div class="filter-actions commission-filter-actions">
                        <button type="submit" class="main-tab">Cargar informe</button>
                    </div>
                </form>
            </section>

            @if (! $dashboard['ready'])
                <div class="notice">
                    El informe no esta listo para calculo final. Hay bloqueos de configuracion o logica que impiden sacar comisiones definitivas.
                </div>
            @endif

            @foreach ($dashboard['issues'] as $issue)
                <div class="notice">{{ $issue }}</div>
            @endforeach

            @foreach ($dashboard['warnings'] ?? [] as $warning)
                <div class="notice commission-warning">{{ $warning }}</div>
            @endforeach

            <section class="kpis dashboard-kpis">
                <article class="card campaign-kpi">
                    <div class="kpi-label"><span class="kpi-tooltip" data-kpi-tooltip="{{ $tooltip('Mes analizado') }}">Mes analizado</span></div>
                    <div class="kpi-value">{{ $dashboard['month_label'] }}</div>
                    <div class="kpi-hint">Periodo cerrado seleccionado para contraste.</div>
                </article>
                <article class="card campaign-kpi">
                    <div class="kpi-label"><span class="kpi-tooltip" data-kpi-tooltip="{{ $tooltip('Oportunidades') }}">Oportunidades</span></div>
                    <div class="kpi-value">{{ number_format($dashboard['diagnostics']['opportunities_total'], 0, ',', '.') }}</div>
                    <div class="kpi-hint">CV firmados de Venta, Cambio y Tasacion en el mes.</div>
                </article>
                <article class="card campaign-kpi">
                    <div class="kpi-label"><span class="kpi-tooltip" data-kpi-tooltip="{{ $tooltip('Resenas') }}">Resenas</span></div>
                    <div class="kpi-value">{{ number_format($dashboard['diagnostics']['reviews_count'], 0, ',', '.') }}</div>
                    <div class="kpi-hint">Objeto `Resena__c` creado dentro del mes.</div>
                </article>
                <article class="card campaign-kpi">
                    <div class="kpi-label"><span class="kpi-tooltip" data-kpi-tooltip="{{ $tooltip('Estado') }}">Estado</span></div>
                    <div class="kpi-value">{{ $dashboard['ready'] ? 'Listo para validar' : 'Pendiente de cierre' }}</div>
                    <div class="kpi-hint">Solo administradores pueden verlo por ahora.</div>
                </article>
            </section>

            <section class="card panel">
                <div class="panel-title">
                    <div>
                        <h2>Diagnostico de datos base</h2>
                        <div class="small">Volumen real sincronizado para el mes seleccionado.</div>
                    </div>
                </div>
                <div class="campaign-diagnostics commission-diagnostics-grid">
                    <div class="diagnostic-item">
                        <span>Ventas / Cambios</span>
                        <strong>{{ number_format($dashboard['diagnostics']['sales_count'], 0, ',', '.') }}</strong>
                    </div>
                    <div class="diagnostic-item">
                        <span>Compras base</span>
                        <strong>{{ number_format($dashboard['diagnostics']['purchases_count'], 0, ',', '.') }}</strong>
                    </div>
                    <div class="diagnostic-item">
                        <span>Operaciones</span>
                        <strong>{{ number_format($dashboard['diagnostics']['operations_count'], 0, ',', '.') }}</strong>
                    </div>
                    <div class="diagnostic-item">
                        <span>Compartidas</span>
                        <strong>{{ number_format($dashboard['diagnostics']['shared_sales_count'], 0, ',', '.') }}</strong>
                    </div>
                    <div class="diagnostic-item">
                        <span>Stock +150</span>
                        <strong>{{ number_format($dashboard['diagnostics']['stock_150_count'], 0, ',', '.') }}</strong>
                    </div>
                    <div class="diagnostic-item">
                        <span>Comerciales detectados</span>
                        <strong>{{ number_format($dashboard['diagnostics']['commercials_count'], 0, ',', '.') }}</strong>
                    </div>
                    <div class="diagnostic-item">
                        <span>Usuarios sincronizados</span>
                        <strong>{{ number_format($dashboard['diagnostics']['synced_users_count'] ?? 0, 0, ',', '.') }}</strong>
                    </div>
                    <div class="diagnostic-item">
                        <span>Filtro Gestion de venta</span>
                        <strong>{{ $dashboard['diagnostics']['sale_management_filter_applied'] ? 'Aplicado' : 'No aplicado' }}</strong>
                    </div>
                    <div class="diagnostic-item">
                        <span>Vehiculos vendibles enlazados</span>
                        <strong>{{ number_format($dashboard['diagnostics']['sold_vehicle_links_count'] ?? 0, 0, ',', '.') }}</strong>
                    </div>
                    <div class="diagnostic-item">
                        <span>Compras historicas candidatas</span>
                        <strong>{{ number_format($dashboard['diagnostics']['historical_purchase_candidates_count'] ?? 0, 0, ',', '.') }}</strong>
                    </div>
                    <div class="diagnostic-item">
                        <span>Compras liquidadas</span>
                        <strong>{{ number_format($dashboard['diagnostics']['matched_purchase_commissions_count'] ?? 0, 0, ',', '.') }}</strong>
                    </div>
                </div>
            </section>

            <section class="card panel">
                <div class="panel-title">
                    <div>
                        <h2>Campos candidatos de rentabilidad</h2>
                        <div class="small">Se sincronizan ambos para contrastarlos antes de fijar el calculo final de compras.</div>
                    </div>
                </div>
                <div class="table-shell">
                    <table>
                        <thead>
                        <tr>
                            <th>Campo local</th>
                            <th class="num">Filas con dato</th>
                            <th class="num">Filas positivas</th>
                            <th class="num">Suma</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($dashboard['diagnostics']['candidate_rentability_fields'] as $candidate)
                            <tr>
                                <td>{{ $candidate['field'] }}</td>
                                <td class="num">{{ number_format($candidate['non_null_rows'], 0, ',', '.') }}</td>
                                <td class="num">{{ number_format($candidate['positive_rows'], 0, ',', '.') }}</td>
                                <td class="num">{{ number_format($candidate['sum'], 2, ',', '.') }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            @if ($summaryRows->isNotEmpty())
                <section class="card panel">
                    <div class="panel-title">
                        <div>
                            <h2>Resumen de comisiones</h2>
                            <div class="small">La financiacion se calcula sobre Venta + Cambio + Tasacion y las compras solo se liquidan al propietario de la compra cuando ese vehiculo se vende.</div>
                        </div>
                    </div>

                    <nav class="tabs-main commission-inner-tabs" aria-label="Vista de comisiones">
                        <button type="button" class="main-tab active" data-commission-tab-trigger="summary">Resumen</button>
                        <button type="button" class="main-tab" data-commission-tab-trigger="detail">Detalle auditable</button>
                    </nav>

                    <div data-commission-tab-panel="summary">
                        <section class="campaign-context-grid commission-context-grid">
                            <article class="card campaign-context-card">
                                <span class="kpi-tooltip" data-kpi-tooltip="{{ $tooltip('Comerciales') }}">Comerciales</span>
                                <strong>{{ number_format($summaryRows->count(), 0, ',', '.') }}</strong>
                            </article>
                            <article class="card campaign-context-card">
                                <span class="kpi-tooltip" data-kpi-tooltip="{{ $tooltip('Comision final') }}">Comision final</span>
                                <strong>{{ number_format($totalFinalCommission, 2, ',', '.') }} €</strong>
                            </article>
                            <article class="card campaign-context-card">
                                <span class="kpi-tooltip" data-kpi-tooltip="{{ $tooltip('Prima ajustada') }}">Prima ajustada</span>
                                <strong>{{ number_format($totalPrimaAdjusted, 2, ',', '.') }} €</strong>
                            </article>
                            <article class="card campaign-context-card">
                                <span class="kpi-tooltip" data-kpi-tooltip="{{ $tooltip('Penalizaciones') }}">Penalizaciones</span>
                                <strong>{{ number_format($totalPenalties, 2, ',', '.') }} €</strong>
                            </article>
                        </section>

                        <section class="platform-comparison-grid commission-overview-grid">
                            <article class="card platform-comparison-card">
                                <div class="platform-comparison-head">
                                    <strong>Volumen comercial</strong>
                                    <span>Conteos agregados del mes seleccionado.</span>
                                </div>
                                <div class="platform-comparison-metrics">
                                    <div class="platform-metric-item"><span>Entregas</span><strong>{{ number_format($totalDeliveries, 0, ',', '.') }}</strong></div>
                                    <div class="platform-metric-item"><span>Operaciones</span><strong>{{ number_format($summaryRows->sum('operations_count'), 0, ',', '.') }}</strong></div>
                                    <div class="platform-metric-item"><span>Compras liquidadas</span><strong>{{ number_format($summaryRows->sum('purchases_amount'), 2, ',', '.') }} €</strong></div>
                                    <div class="platform-metric-item"><span>Compartidas</span><strong>{{ number_format($summaryRows->sum('shared_amount'), 2, ',', '.') }} €</strong></div>
                                </div>
                            </article>
                            <article class="card platform-comparison-card">
                                <div class="platform-comparison-head">
                                    <strong>Base variable</strong>
                                    <span>Conceptos antes de ajustar por tramos y penalizaciones.</span>
                                </div>
                                <div class="platform-comparison-metrics">
                                    <div class="platform-metric-item"><span>Ventas</span><strong>{{ number_format($summaryRows->sum('sales_amount'), 2, ',', '.') }} €</strong></div>
                                    <div class="platform-metric-item"><span>Stock +150</span><strong>{{ number_format($summaryRows->sum('stock_150_amount'), 2, ',', '.') }} €</strong></div>
                                    <div class="platform-metric-item"><span>Bonus +15</span><strong>{{ number_format($summaryRows->sum('bonus_15_amount'), 2, ',', '.') }} €</strong></div>
                                    <div class="platform-metric-item"><span>Prima total</span><strong>{{ number_format($summaryRows->sum('prima_total'), 2, ',', '.') }} €</strong></div>
                                </div>
                            </article>
                            <article class="card platform-comparison-card">
                                <div class="platform-comparison-head">
                                    <strong>Productos y ajustes</strong>
                                    <span>Impacto final despues de penalizaciones.</span>
                                </div>
                                <div class="platform-comparison-metrics">
                                    <div class="platform-metric-item"><span>Penalizaciones</span><strong>{{ number_format($totalPenalties, 2, ',', '.') }} €</strong></div>
                                    <div class="platform-metric-item"><span>Prima neta</span><strong>{{ number_format($summaryRows->sum('prima_after_penalties'), 2, ',', '.') }} €</strong></div>
                                    <div class="platform-metric-item"><span>Prod. financiacion</span><strong>{{ number_format($summaryRows->sum('financing_product_amount'), 2, ',', '.') }} €</strong></div>
                                    <div class="platform-metric-item"><span>Prod. garantias</span><strong>{{ number_format($summaryRows->sum('guarantee_product_amount'), 2, ',', '.') }} €</strong></div>
                                </div>
                            </article>
                        </section>

                        <div class="table-shell" style="height: 700px;">
                            <table data-sortable-table="commission-summary">
                                <thead>
                                <tr>
                                    <th data-sortable="true">Comercial</th>
                                    <th class="num" data-sortable="true">Entregas</th>
                                    <th class="num" data-sortable="true">Operaciones</th>
                                    <th class="num" data-sortable="true">Ventas</th>
                                    <th class="num" data-sortable="true">Compras</th>
                                    <th class="num" data-sortable="true">Compartidas</th>
                                    <th class="num" data-sortable="true">Descuento 5%</th>
                                    <th class="num" data-sortable="true">Stock +150</th>
                                    <th class="num" data-sortable="true">Bonus +15</th>
                                    <th class="num" data-sortable="true">Prima total</th>
                                    <th class="num" data-sortable="true">Tramo</th>
                                    <th class="num" data-sortable="true">Prima ajustada</th>
                                    <th class="num" data-sortable="true">Resenas</th>
                                    <th class="num" data-sortable="true">% resenas</th>
                                    <th class="num" data-sortable="true">% financiacion</th>
                                    <th class="num" data-sortable="true">Penalizaciones</th>
                                    <th class="num" data-sortable="true">Prod. financiacion</th>
                                    <th class="num" data-sortable="true">Prod. garantias</th>
                                    <th class="num" data-sortable="true">Comision final</th>
                                </tr>
                                </thead>
                                <tbody data-sort-body="commission-summary">
                                @foreach ($summaryRows as $row)
                                    <tr>
                                        <td>{{ $row['commercial_name'] }}</td>
                                        <td class="num">{{ number_format($row['deliveries_count'], 0, ',', '.') }}</td>
                                        <td class="num">{{ number_format($row['operations_count'], 0, ',', '.') }}</td>
                                        <td class="num">{{ number_format($row['sales_amount'], 2, ',', '.') }}</td>
                                        <td class="num">{{ number_format($row['purchases_amount'], 2, ',', '.') }}</td>
                                        <td class="num">{{ number_format($row['shared_amount'], 2, ',', '.') }}</td>
                                        <td class="num">{{ number_format($row['discount_penalty_amount'], 2, ',', '.') }}</td>
                                        <td class="num">{{ number_format($row['stock_150_amount'], 2, ',', '.') }}</td>
                                        <td class="num">{{ number_format($row['bonus_15_amount'], 2, ',', '.') }}</td>
                                        <td class="num">{{ number_format($row['prima_total'], 2, ',', '.') }}</td>
                                        <td class="num">{{ $row['delivery_bracket_label'] }} · {{ number_format($row['delivery_bracket_percent'], 0, ',', '.') }}%</td>
                                        <td class="num">{{ number_format($row['prima_adjusted'], 2, ',', '.') }}</td>
                                        <td class="num">{{ number_format($row['reviews_count'], 0, ',', '.') }}</td>
                                        <td class="num">{{ number_format($row['reviews_percentage'], 2, ',', '.') }}%</td>
                                        <td class="num">{{ number_format($row['financing_percentage'], 2, ',', '.') }}%</td>
                                        <td class="num">{{ number_format($row['total_penalties'], 2, ',', '.') }}</td>
                                        <td class="num">{{ number_format($row['financing_product_amount'], 2, ',', '.') }}</td>
                                        <td class="num">{{ number_format($row['guarantee_product_amount'], 2, ',', '.') }}</td>
                                        <td class="num"><strong>{{ number_format($row['final_commission'], 2, ',', '.') }}</strong></td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="is-hidden" data-commission-tab-panel="detail">
                        <div class="commission-detail-shell">
                            <aside class="card commission-commercial-picker">
                                <div class="filter-group">
                                    <label for="commissionCommercialSearch">Buscar comercial</label>
                                    <input
                                        type="search"
                                        id="commissionCommercialSearch"
                                        placeholder="Nombre o ID de Salesforce"
                                        autocomplete="off"
                                    >
                                </div>
                                <div class="commission-commercial-list" id="commissionCommercialList">
                                    @foreach ($summaryRows as $row)
                                        <button
                                            type="button"
                                            class="commission-commercial-option{{ $row['commercial_id'] === $defaultCommercialId ? ' is-active' : '' }}"
                                            data-commercial-option
                                            data-commercial-id="{{ $row['commercial_id'] }}"
                                            data-commercial-search="{{ \Illuminate\Support\Str::lower($row['commercial_name'].' '.$row['commercial_id']) }}"
                                        >
                                            <strong>{{ $row['commercial_name'] }}</strong>
                                            <span>{{ $row['commercial_id'] }}</span>
                                            <small>
                                                Entregas {{ number_format($row['deliveries_count'], 0, ',', '.') }}
                                                · Comision {{ number_format($row['final_commission'], 2, ',', '.') }} €
                                            </small>
                                        </button>
                                    @endforeach
                                </div>
                            </aside>

                            <section class="commission-detail-stage">
                                <div class="empty-state is-hidden" id="commissionCommercialEmpty">
                                    No hay comerciales que coincidan con la busqueda.
                                </div>

                                @foreach ($summaryRows as $row)
                                    @php
                                        $deliveriesDetails = $row['details']['deliveries'] ?? [];
                                        $purchasesDetails = $row['details']['purchases'] ?? [];
                                        $sharedDetails = $row['details']['shared'] ?? [];
                                        $stockDetails = $row['details']['stock_150'] ?? [];
                                        $reviewsDetails = $row['details']['reviews'] ?? [];
                                    @endphp
                                    <article
                                        class="commission-commercial-panel{{ $row['commercial_id'] === $defaultCommercialId ? ' is-active' : ' is-hidden' }}"
                                        data-commercial-panel
                                        data-commercial-id="{{ $row['commercial_id'] }}"
                                    >
                                        <section class="card panel commission-commercial-hero">
                                            <div class="panel-title compact">
                                                <div>
                                                    <h2>{{ $row['commercial_name'] }}</h2>
                                                    <div class="small">ID {{ $row['commercial_id'] }} · Comision final {{ number_format($row['final_commission'], 2, ',', '.') }} €</div>
                                                </div>
                                            </div>
                                            <div class="campaign-context-grid commission-commercial-metrics">
                                                <article class="card campaign-context-card">
                                                    <span>Entregas</span>
                                                    <strong>{{ number_format($row['deliveries_count'], 0, ',', '.') }}</strong>
                                                </article>
                                                <article class="card campaign-context-card">
                                                    <span>Prima ajustada</span>
                                                    <strong>{{ number_format($row['prima_adjusted'], 2, ',', '.') }} €</strong>
                                                </article>
                                                <article class="card campaign-context-card">
                                                    <span>Penalizaciones</span>
                                                    <strong>{{ number_format($row['total_penalties'], 2, ',', '.') }} €</strong>
                                                </article>
                                                <article class="card campaign-context-card">
                                                    <span>Prod. financiacion</span>
                                                    <strong>{{ number_format($row['financing_product_amount'], 2, ',', '.') }} €</strong>
                                                </article>
                                            </div>
                                            <nav class="tabs-main commission-detail-tabs" aria-label="Detalle del comercial">
                                                <button type="button" class="main-tab active" data-commercial-detail-tab-trigger="deliveries">Entregas</button>
                                                <button type="button" class="main-tab" data-commercial-detail-tab-trigger="purchases">Compras cobradas</button>
                                                <button type="button" class="main-tab" data-commercial-detail-tab-trigger="shared">Compartidas</button>
                                                <button type="button" class="main-tab" data-commercial-detail-tab-trigger="stock">Stock +150</button>
                                                <button type="button" class="main-tab" data-commercial-detail-tab-trigger="reviews">Resenas</button>
                                            </nav>
                                        </section>

                                        <section class="commission-detail-grid">
                                            <div class="table-shell" data-commercial-detail-tab-panel="deliveries">
                                                <table data-sortable-table="deliveries-{{ $row['commercial_id'] }}">
                                                    <thead>
                                                    <tr><th colspan="6">Entregas · {{ number_format(count($deliveriesDetails), 0, ',', '.') }}</th></tr>
                                                    <tr>
                                                        <th data-sortable="true">ID</th>
                                                        <th data-sortable="true">Oportunidad</th>
                                                        <th data-sortable="true">Tipo</th>
                                                        <th data-sortable="true">Fecha</th>
                                                        <th data-sortable="true">Matricula</th>
                                                        <th class="num" data-sortable="true">Importe</th>
                                                    </tr>
                                                    </thead>
                                                    <tbody data-sort-body="deliveries-{{ $row['commercial_id'] }}">
                                                    @forelse ($deliveriesDetails as $detail)
                                                        <tr>
                                                            <td>{{ $detail['opportunity_id'] }}</td>
                                                            <td>{{ $detail['opportunity_name'] }}</td>
                                                            <td>{{ $detail['record_type_name'] }}</td>
                                                            <td>{{ $detail['cv_signed_date'] }}</td>
                                                            <td>{{ $detail['vehicle_plate'] ?: '-' }}</td>
                                                            <td class="num">{{ number_format($detail['amount'], 2, ',', '.') }}</td>
                                                        </tr>
                                                    @empty
                                                        <tr><td colspan="6">Sin entregas.</td></tr>
                                                    @endforelse
                                                    </tbody>
                                                </table>
                                            </div>

                                            <div class="table-shell is-hidden" data-commercial-detail-tab-panel="purchases">
                                                <table data-sortable-table="purchases-{{ $row['commercial_id'] }}">
                                                    <thead>
                                                    <tr><th colspan="7">Compras cobradas este mes · {{ number_format(count($purchasesDetails), 0, ',', '.') }}</th></tr>
                                                    <tr>
                                                        <th data-sortable="true">Matricula</th>
                                                        <th data-sortable="true">Compra</th>
                                                        <th data-sortable="true">Tipo compra</th>
                                                        <th data-sortable="true">Fecha compra</th>
                                                        <th data-sortable="true">Venta posterior</th>
                                                        <th class="num" data-sortable="true">Rentabilidad</th>
                                                        <th class="num" data-sortable="true">Comision</th>
                                                    </tr>
                                                    </thead>
                                                    <tbody data-sort-body="purchases-{{ $row['commercial_id'] }}">
                                                    @forelse ($purchasesDetails as $detail)
                                                        <tr>
                                                            <td>{{ $detail['vehicle_plate'] }}</td>
                                                            <td>{{ $detail['purchase_opportunity_name'] }}</td>
                                                            <td>{{ $detail['purchase_record_type_name'] }}</td>
                                                            <td>{{ $detail['purchase_date'] }}</td>
                                                            <td>{{ $detail['sale_opportunity_name'] }}</td>
                                                            <td class="num">{{ number_format($detail['rentability_amount'], 2, ',', '.') }}</td>
                                                            <td class="num">{{ number_format($detail['commission_amount'], 2, ',', '.') }}</td>
                                                        </tr>
                                                    @empty
                                                        <tr><td colspan="7">Sin compras liquidadas en el mes.</td></tr>
                                                    @endforelse
                                                    </tbody>
                                                </table>
                                            </div>

                                            <div class="table-shell is-hidden" data-commercial-detail-tab-panel="shared">
                                                <table data-sortable-table="shared-{{ $row['commercial_id'] }}">
                                                    <thead>
                                                    <tr><th colspan="5">Compartidas · {{ number_format(count($sharedDetails), 0, ',', '.') }}</th></tr>
                                                    <tr>
                                                        <th data-sortable="true">ID</th>
                                                        <th data-sortable="true">Oportunidad</th>
                                                        <th data-sortable="true">Propietario</th>
                                                        <th data-sortable="true">Fecha</th>
                                                        <th class="num" data-sortable="true">Importe</th>
                                                    </tr>
                                                    </thead>
                                                    <tbody data-sort-body="shared-{{ $row['commercial_id'] }}">
                                                    @forelse ($sharedDetails as $detail)
                                                        <tr>
                                                            <td>{{ $detail['opportunity_id'] }}</td>
                                                            <td>{{ $detail['opportunity_name'] }}</td>
                                                            <td>{{ $detail['owner_name'] }}</td>
                                                            <td>{{ $detail['cv_signed_date'] }}</td>
                                                            <td class="num">{{ number_format($detail['amount'], 2, ',', '.') }}</td>
                                                        </tr>
                                                    @empty
                                                        <tr><td colspan="5">Sin compartidas.</td></tr>
                                                    @endforelse
                                                    </tbody>
                                                </table>
                                            </div>

                                            <div class="table-shell is-hidden" data-commercial-detail-tab-panel="stock">
                                                <table data-sortable-table="stock-{{ $row['commercial_id'] }}">
                                                    <thead>
                                                    <tr><th colspan="6">Stock +150 · {{ number_format(count($stockDetails), 0, ',', '.') }}</th></tr>
                                                    <tr>
                                                        <th data-sortable="true">ID</th>
                                                        <th data-sortable="true">Oportunidad</th>
                                                        <th data-sortable="true">Matricula</th>
                                                        <th data-sortable="true">Fecha entrada</th>
                                                        <th class="num" data-sortable="true">Dias</th>
                                                        <th class="num" data-sortable="true">Importe</th>
                                                    </tr>
                                                    </thead>
                                                    <tbody data-sort-body="stock-{{ $row['commercial_id'] }}">
                                                    @forelse ($stockDetails as $detail)
                                                        <tr>
                                                            <td>{{ $detail['opportunity_id'] }}</td>
                                                            <td>{{ $detail['opportunity_name'] }}</td>
                                                            <td>{{ $detail['vehicle_plate'] }}</td>
                                                            <td>{{ $detail['vehicle_entry_date'] }}</td>
                                                            <td class="num">{{ number_format($detail['vehicle_days_in_stock'], 0, ',', '.') }}</td>
                                                            <td class="num">{{ number_format($detail['amount'], 2, ',', '.') }}</td>
                                                        </tr>
                                                    @empty
                                                        <tr><td colspan="6">Sin vehiculos +150.</td></tr>
                                                    @endforelse
                                                    </tbody>
                                                </table>
                                            </div>

                                            <div class="table-shell commission-reviews-table is-hidden" data-commercial-detail-tab-panel="reviews">
                                                <table data-sortable-table="reviews-{{ $row['commercial_id'] }}">
                                                    <thead>
                                                    <tr><th colspan="5">Resenas del mes · {{ number_format(count($reviewsDetails), 0, ',', '.') }}</th></tr>
                                                    <tr>
                                                        <th data-sortable="true">ID resena</th>
                                                        <th data-sortable="true">Fecha</th>
                                                        <th data-sortable="true">Oportunidad</th>
                                                        <th data-sortable="true">Propietario oportunidad</th>
                                                        <th data-sortable="true">Propietario resena</th>
                                                    </tr>
                                                    </thead>
                                                    <tbody data-sort-body="reviews-{{ $row['commercial_id'] }}">
                                                    @forelse ($reviewsDetails as $detail)
                                                        <tr>
                                                            <td>{{ $detail['review_id'] }}</td>
                                                            <td>{{ $detail['created_date'] }}</td>
                                                            <td>{{ $detail['opportunity_name'] ?: $detail['opportunity_id'] }}</td>
                                                            <td>{{ $detail['opportunity_owner_name'] }}</td>
                                                            <td>{{ $detail['review_owner_name'] }}</td>
                                                        </tr>
                                                    @empty
                                                        <tr><td colspan="5">Sin resenas en el mes.</td></tr>
                                                    @endforelse
                                                    </tbody>
                                                </table>
                                            </div>
                                        </section>
                                    </article>
                                @endforeach
                            </section>
                        </div>
                    </div>
                </section>
            @endif
        </section>
    </main>
</div>
</body>
</html>
