@php
    $callCenterMonthStart = \Carbon\CarbonImmutable::parse(($callCenterDashboard['month'] ?? $dashboard['month']).'-01');
    $callCenterMonthEnd = $callCenterMonthStart->addMonth();
    $callCenterKpiTooltips = [
        'Compras / Tasaciones' => 'Tasaciones con Captador informado, gestion de venta false y Comision Captador sumada como importe final.',
        'Ventas' => 'Ventas con Captador informado, gestion de venta false y Comision Captador sumada como importe final.',
        'Cambios' => 'Cambios comisionados una sola vez dentro del bloque de ventas/cambios usando Comision Captador.',
        'Negociaciones German' => 'Tasaciones con Seguimiento German y Negociaci_n_1__c informada. Si falta fecha firma contrato en local, se usa CreatedDate del registro sincronizado.',
        'Facilitea' => 'Operaciones Facilitea validas con owner Vanessa/Vanesa y 5 EUR por operacion.',
        'Agentes / captadores' => 'Numero de filas agrupadas por captador o agente dentro del mes y rango de contrato activo.',
        'Operaciones base' => 'Oportunidades locales del rango activo evaluadas para compras, ventas, cambios y Facilitea.',
        'Tasaciones sync' => 'Tasaciones sincronizadas del rango activo usando Fecha firma contrato y, si no existe, fallback a CreatedDate.',
        'Sin captador' => 'Conteo auditor de oportunidades con señales reales de Call Center pero sin Captador principal informado.',
        'Comisiones vacias' => 'Operaciones con Comision Captador vacia; se computan a 0 EUR y quedan visibles para revision.',
        'Total automatico' => 'Suma automatica de compras, ventas, cambios, negociaciones German y Facilitea.',
        'Compras' => 'Suma de Comision Captador del bloque compras/tasaciones.',
        'Neg. German' => 'Suma de negociaciones atribuidas a German Olsen a 5 EUR por tasacion valida.',
    ];
    $callCenterTooltip = static fn (string $label): string => $callCenterKpiTooltips[$label] ?? '';
    $callCenterMissingCaptadorParams = [
        'month' => $dashboard['month'],
        'call_center_contract_from' => $callCenterDashboard['contract_from'] ?? null,
        'call_center_contract_to' => $callCenterDashboard['contract_to'] ?? null,
    ];
    $callCenterMissingCaptadorExportUrl = \Illuminate\Support\Facades\Route::has('reports.commercial-commissions.export.call-center-missing-captador')
        ? route('reports.commercial-commissions.export.call-center-missing-captador', $callCenterMissingCaptadorParams)
        : url('/informes/comisiones-comerciales/export/call-center-missing-captador.csv?'.http_build_query(array_filter(
            $callCenterMissingCaptadorParams,
            fn ($value) => $value !== null && $value !== ''
        )));
@endphp

<section class="card panel call-center-toolbar-panel">
    <div class="panel-title compact">
        <div>
            <h3>Filtros de contrato</h3>
            <div class="small">El bloque de Call Center se calcula por Fecha firma contrato dentro del mes cerrado seleccionado.</div>
        </div>
    </div>

    <form method="GET" class="commission-filter-form call-center-filter-form">
        <input type="hidden" name="month" value="{{ $dashboard['month'] }}">
        <input type="hidden" name="tab" value="call-center">
        <div class="filter-group">
            <label for="callCenterContractFrom">Fecha contrato desde</label>
            <input type="date" id="callCenterContractFrom" name="call_center_contract_from" value="{{ $callCenterDashboard['contract_from'] ?? '' }}">
        </div>
        <div class="filter-group">
            <label for="callCenterContractTo">Fecha contrato hasta</label>
            <input type="date" id="callCenterContractTo" name="call_center_contract_to" value="{{ $callCenterDashboard['contract_to'] ?? '' }}">
        </div>
        <div class="filter-actions commission-filter-actions">
            <button type="submit" class="main-tab">Aplicar filtro</button>
        </div>
    </form>
</section>

@if ($callCenterSummaryRows->isNotEmpty())
    <section class="call-center-kpis-grid">
        <article class="card campaign-context-card">
            <span class="kpi-tooltip" data-kpi-tooltip="{{ $callCenterTooltip('Compras / Tasaciones') }}">Compras / Tasaciones</span>
            <strong>{{ number_format($callCenterCountPurchases, 0, ',', '.') }} ops / {{ number_format($callCenterTotalPurchases, 2, ',', '.') }} EUR</strong>
        </article>
        <article class="card campaign-context-card">
            <span class="kpi-tooltip" data-kpi-tooltip="{{ $callCenterTooltip('Ventas') }}">Ventas</span>
            <strong>{{ number_format($callCenterCountSales, 0, ',', '.') }} ops / {{ number_format($callCenterTotalSales, 2, ',', '.') }} EUR</strong>
        </article>
        <article class="card campaign-context-card">
            <span class="kpi-tooltip" data-kpi-tooltip="{{ $callCenterTooltip('Cambios') }}">Cambios</span>
            <strong>{{ number_format($callCenterCountChanges, 0, ',', '.') }} ops / {{ number_format($callCenterTotalChanges, 2, ',', '.') }} EUR</strong>
        </article>
        <article class="card campaign-context-card">
            <span class="kpi-tooltip" data-kpi-tooltip="{{ $callCenterTooltip('Negociaciones German') }}">Negociaciones German</span>
            <strong>{{ number_format($callCenterCountGerman, 0, ',', '.') }} ops / {{ number_format($callCenterTotalGerman, 2, ',', '.') }} EUR</strong>
        </article>
        <article class="card campaign-context-card">
            <span class="kpi-tooltip" data-kpi-tooltip="{{ $callCenterTooltip('Facilitea') }}">Facilitea</span>
            <strong>{{ number_format($callCenterCountFacilitea, 0, ',', '.') }} ops / {{ number_format($callCenterTotalFacilitea, 2, ',', '.') }} EUR</strong>
        </article>
    </section>

    <section class="platform-comparison-grid commission-overview-grid call-center-overview-grid">
        <article class="card platform-comparison-card">
            <div class="platform-comparison-head">
                <strong>Base mensual</strong>
                <span>Bloques automaticos calculados desde Salesforce para el rango de contrato activo.</span>
            </div>
            <div class="platform-comparison-metrics">
                <div class="platform-metric-item"><span class="kpi-tooltip" data-kpi-tooltip="{{ $callCenterTooltip('Agentes / captadores') }}">Agentes / captadores</span><strong>{{ number_format($callCenterSummaryRows->count(), 0, ',', '.') }}</strong></div>
                <div class="platform-metric-item"><span class="kpi-tooltip" data-kpi-tooltip="{{ $callCenterTooltip('Operaciones base') }}">Operaciones base</span><strong>{{ number_format($callCenterDashboard['diagnostics']['monthly_opportunities'] ?? 0, 0, ',', '.') }}</strong></div>
                <div class="platform-metric-item"><span class="kpi-tooltip" data-kpi-tooltip="{{ $callCenterTooltip('Tasaciones sync') }}">Tasaciones sync</span><strong>{{ number_format($callCenterDashboard['diagnostics']['monthly_tasaciones'] ?? 0, 0, ',', '.') }}</strong></div>
                <div class="platform-metric-item"><span class="kpi-tooltip" data-kpi-tooltip="{{ $callCenterTooltip('Sin captador') }}">Sin captador</span><strong>{{ number_format($callCenterDashboard['diagnostics']['missing_captador_count'] ?? 0, 0, ',', '.') }}</strong></div>
                <div class="platform-metric-item"><span class="kpi-tooltip" data-kpi-tooltip="{{ $callCenterTooltip('Comisiones vacias') }}">Comisiones vacias</span><strong>{{ number_format($callCenterDashboard['diagnostics']['missing_commission_count'] ?? 0, 0, ',', '.') }}</strong></div>
                <div class="platform-metric-item"><span class="kpi-tooltip" data-kpi-tooltip="{{ $callCenterTooltip('Total automatico') }}">Total automatico</span><strong>{{ number_format($callCenterTotalAutomatic, 2, ',', '.') }} EUR</strong></div>
            </div>
        </article>
        <article class="card platform-comparison-card">
            <div class="platform-comparison-head">
                <strong>Bloques aplicados</strong>
                <span>Compras, ventas y cambios usan Captador + Comision Captador. Facilitea usa origen FACILITEA.</span>
            </div>
            <div class="platform-comparison-metrics">
                <div class="platform-metric-item"><span class="kpi-tooltip" data-kpi-tooltip="{{ $callCenterTooltip('Compras') }}">Compras</span><strong>{{ number_format($callCenterTotalPurchases, 2, ',', '.') }} EUR</strong></div>
                <div class="platform-metric-item"><span class="kpi-tooltip" data-kpi-tooltip="{{ $callCenterTooltip('Ventas') }}">Ventas</span><strong>{{ number_format($callCenterTotalSales, 2, ',', '.') }} EUR</strong></div>
                <div class="platform-metric-item"><span class="kpi-tooltip" data-kpi-tooltip="{{ $callCenterTooltip('Cambios') }}">Cambios</span><strong>{{ number_format($callCenterTotalChanges, 2, ',', '.') }} EUR</strong></div>
                <div class="platform-metric-item"><span class="kpi-tooltip" data-kpi-tooltip="{{ $callCenterTooltip('Neg. German') }}">Neg. German</span><strong>{{ number_format($callCenterTotalGerman, 2, ',', '.') }} EUR</strong></div>
                <div class="platform-metric-item"><span class="kpi-tooltip" data-kpi-tooltip="{{ $callCenterTooltip('Facilitea') }}">Facilitea</span><strong>{{ number_format($callCenterTotalFacilitea, 2, ',', '.') }} EUR</strong></div>
            </div>
        </article>
        @if (($reportUserRole ?? null) === \App\Models\ReportUser::ROLE_ADMIN)
            <article class="card platform-comparison-card call-center-diagnostics-card">
                <div class="platform-comparison-head">
                    <strong>Diagnostico y resync</strong>
                    <span>Usa estos comandos para refrescar el mes completo antes de validar captadores y negociaciones.</span>
                </div>
                <div class="call-center-diagnostics-list">
                    <div class="call-center-diagnostics-row">
                        <span>Opportunities</span>
                        <code>php artisan salesforce:sync-opportunities --from={{ $callCenterMonthStart->toDateString() }} --to={{ $callCenterMonthEnd->toDateString() }}</code>
                    </div>
                    <div class="call-center-diagnostics-row">
                        <span>Tasaciones</span>
                        <code>php artisan salesforce:sync-tasaciones --from={{ $callCenterMonthStart->toDateString() }} --to={{ $callCenterMonthEnd->toDateString() }}</code>
                    </div>
                </div>
                <div class="filter-actions commission-filter-actions">
                    <a
                        class="main-tab"
                        href="{{ $callCenterMissingCaptadorExportUrl }}"
                    >
                        Descargar CSV sin Captador ({{ number_format($callCenterDashboard['diagnostics']['missing_captador_count'] ?? 0, 0, ',', '.') }})
                    </a>
                </div>
            </article>
        @endif
    </section>

    <div class="table-shell call-center-summary-shell">
        <table data-sortable-table="call-center-summary">
            <thead>
            <tr>
                <th data-sortable="true">Agente / Captador</th>
                <th class="num" data-sortable="true">Total final</th>
                <th class="num" data-sortable="true">Com. compras</th>
                <th class="num" data-sortable="true">N compras</th>
                <th class="num" data-sortable="true">Com. ventas</th>
                <th class="num" data-sortable="true">N ventas</th>
                <th class="num" data-sortable="true">Com. cambios</th>
                <th class="num" data-sortable="true">N cambios</th>
                <th class="num" data-sortable="true">Com. neg. German</th>
                <th class="num" data-sortable="true">N neg. German</th>
                <th class="num" data-sortable="true">Com. Facilitea</th>
                <th class="num" data-sortable="true">N Facilitea</th>
                <th class="num" data-sortable="true">Total automatico</th>
                <th class="num" data-sortable="true">Ajuste manual</th>
            </tr>
            </thead>
            <tbody data-sort-body="call-center-summary">
            @foreach ($callCenterSummaryRows as $row)
                <tr>
                    <td data-sort-value="{{ $row['agent_name'] }}">{{ $row['agent_name'] }}</td>
                    <td class="num" data-sort-value="{{ $row['final_total'] }}"><strong>{{ number_format($row['final_total'], 2, ',', '.') }}</strong></td>
                    <td class="num" data-sort-value="{{ $row['purchase_commission'] }}">{{ number_format($row['purchase_commission'], 2, ',', '.') }}</td>
                    <td class="num" data-sort-value="{{ $row['purchase_count'] }}">{{ number_format($row['purchase_count'], 0, ',', '.') }}</td>
                    <td class="num" data-sort-value="{{ $row['sales_commission'] }}">{{ number_format($row['sales_commission'], 2, ',', '.') }}</td>
                    <td class="num" data-sort-value="{{ $row['sales_count'] }}">{{ number_format($row['sales_count'], 0, ',', '.') }}</td>
                    <td class="num" data-sort-value="{{ $row['changes_commission'] }}">{{ number_format($row['changes_commission'], 2, ',', '.') }}</td>
                    <td class="num" data-sort-value="{{ $row['changes_count'] }}">{{ number_format($row['changes_count'], 0, ',', '.') }}</td>
                    <td class="num" data-sort-value="{{ $row['german_negotiation_commission'] }}">{{ number_format($row['german_negotiation_commission'], 2, ',', '.') }}</td>
                    <td class="num" data-sort-value="{{ $row['german_negotiation_count'] }}">{{ number_format($row['german_negotiation_count'], 0, ',', '.') }}</td>
                    <td class="num" data-sort-value="{{ $row['facilitea_commission'] }}">{{ number_format($row['facilitea_commission'], 2, ',', '.') }}</td>
                    <td class="num" data-sort-value="{{ $row['facilitea_count'] }}">{{ number_format($row['facilitea_count'], 0, ',', '.') }}</td>
                    <td class="num" data-sort-value="{{ $row['automatic_total'] }}"><strong>{{ number_format($row['automatic_total'], 2, ',', '.') }}</strong></td>
                    <td class="num" data-sort-value="{{ $row['manual_adjustment'] }}">{{ number_format($row['manual_adjustment'], 2, ',', '.') }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    @php
        $callCenterAgentPayload = $callCenterSummaryRows
            ->mapWithKeys(fn (array $row) => [$row['agent_key'] => $row])
            ->all();
        $callCenterActiveRow = $callCenterSummaryRows->firstWhere('agent_key', $callCenterDefaultAgentKey) ?? $callCenterSummaryRows->first();
    @endphp

    <section class="commission-detail-shell commission-call-center-detail-shell" data-call-center-browser>
        <aside class="card commission-commercial-picker">
            <div class="filter-group">
                <label for="callCenterAgentSearch">Buscar agente</label>
                <input
                    type="search"
                    id="callCenterAgentSearch"
                    placeholder="Filtrar captador o agente"
                    autocomplete="off"
                    data-agent-search-input
                >
            </div>
            <div class="commission-commercial-list">
                @foreach ($callCenterSummaryRows as $row)
                    <button
                        type="button"
                        class="commission-commercial-option{{ $row['agent_key'] === $callCenterDefaultAgentKey ? ' is-active' : '' }}"
                        data-agent-option
                        data-agent-id="{{ $row['agent_key'] }}"
                        data-agent-search="{{ \Illuminate\Support\Str::lower(\Illuminate\Support\Str::ascii($row['agent_name'].' '.$row['observations'])) }}"
                    >
                        <strong>{{ $row['agent_name'] }}</strong>
                        <span>{{ number_format($row['final_total'], 2, ',', '.') }} EUR</span>
                        <small>{{ number_format($row['purchase_count'] + $row['sales_count'] + $row['changes_count'] + $row['german_negotiation_count'] + $row['facilitea_count'], 0, ',', '.') }} operaciones / bloques</small>
                    </button>
                @endforeach
            </div>
            <div class="notice is-hidden" data-agent-empty>No hay agentes que coincidan con la busqueda.</div>
        </aside>

        <div class="commission-detail-stage">
            @if ($callCenterActiveRow)
                <section
                    class="commission-commercial-panel is-active"
                    data-call-center-panel-root
                    data-agent-id="{{ $callCenterActiveRow['agent_key'] }}"
                >
                    <section class="call-center-agent-hero">
                        <article class="card campaign-context-card">
                            <span>Agente / Captador</span>
                            <strong>{{ $callCenterActiveRow['agent_name'] }}</strong>
                        </article>
                        <article class="card campaign-context-card">
                            <span>Total automatico</span>
                            <strong>{{ number_format($callCenterActiveRow['automatic_total'], 2, ',', '.') }} EUR</strong>
                        </article>
                        <article class="card campaign-context-card">
                            <span>Total final</span>
                            <strong>{{ number_format($callCenterActiveRow['final_total'], 2, ',', '.') }} EUR</strong>
                        </article>
                        <article class="card campaign-context-card">
                            <span>Observaciones</span>
                            <strong>{{ $callCenterActiveRow['observations'] !== '' ? $callCenterActiveRow['observations'] : 'Sin incidencias' }}</strong>
                        </article>
                    </section>

                    <nav class="tabs-main commission-detail-tabs" aria-label="Detalle Call Center por bloque">
                        <button type="button" class="main-tab active" data-commercial-detail-tab-trigger="purchases">Compras</button>
                        <button type="button" class="main-tab" data-commercial-detail-tab-trigger="sales">Ventas</button>
                        <button type="button" class="main-tab" data-commercial-detail-tab-trigger="changes">Cambios</button>
                        <button type="button" class="main-tab" data-commercial-detail-tab-trigger="german">Negociaciones German</button>
                        <button type="button" class="main-tab" data-commercial-detail-tab-trigger="facilitea">Facilitea</button>
                    </nav>

                    <div class="commission-detail-grid">
                        <div data-commercial-detail-tab-panel="purchases">
                            <div class="table-shell">
                                <table data-sortable-table="call-center-purchases-{{ $callCenterActiveRow['agent_key'] }}">
                                    <thead>
                                    <tr>
                                        <th data-sortable="true">Opportunity Id</th>
                                        <th data-sortable="true">Opportunity Name</th>
                                        <th data-sortable="true">Record Type</th>
                                        <th data-sortable="true">Captador</th>
                                        <th class="num" data-sortable="true">Comision Captador</th>
                                        <th data-sortable="true">Fecha firma contrato</th>
                                        <th data-sortable="true">Vehiculo a tasar</th>
                                        <th data-sortable="true">Fecha captador</th>
                                        <th data-sortable="true">Account Name</th>
                                    </tr>
                                    </thead>
                                    <tbody data-sort-body="call-center-purchases-{{ $callCenterActiveRow['agent_key'] }}">
                                    @forelse ($callCenterActiveRow['details']['purchases'] as $detail)
                                        <tr>
                                            <td>{{ $detail['opportunity_id'] }}</td>
                                            <td>{{ $detail['opportunity_name'] }}</td>
                                            <td>{{ $detail['record_type_name'] }}</td>
                                            <td>{{ $detail['captador'] }}</td>
                                            <td class="num">{{ number_format($detail['commission_amount'], 2, ',', '.') }}</td>
                                            <td>{{ $detail['contract_signed_date'] ?? '-' }}</td>
                                            <td>{{ $detail['vehicle_to_appraise'] !== '' ? $detail['vehicle_to_appraise'] : '-' }}</td>
                                            <td>{{ $detail['capture_date'] ?? '-' }}</td>
                                            <td>{{ $detail['account_name'] !== '' ? $detail['account_name'] : '-' }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="9">Sin compras/tasaciones comisionables para este agente.</td></tr>
                                    @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="is-hidden" data-commercial-detail-tab-panel="sales">
                            <div class="table-shell">
                                <table data-sortable-table="call-center-sales-{{ $callCenterActiveRow['agent_key'] }}">
                                    <thead>
                                    <tr>
                                        <th data-sortable="true">Opportunity Id</th>
                                        <th data-sortable="true">Opportunity Name</th>
                                        <th data-sortable="true">Record Type</th>
                                        <th data-sortable="true">Captador</th>
                                        <th class="num" data-sortable="true">Comision Captador</th>
                                        <th data-sortable="true">Fecha firma contrato</th>
                                        <th data-sortable="true">Vehiculo a tasar</th>
                                        <th data-sortable="true">Vehiculo de interes</th>
                                        <th data-sortable="true">Account Name</th>
                                        <th data-sortable="true">Fuente de origen</th>
                                        <th data-sortable="true">Opportunity Owner</th>
                                    </tr>
                                    </thead>
                                    <tbody data-sort-body="call-center-sales-{{ $callCenterActiveRow['agent_key'] }}">
                                    @forelse ($callCenterActiveRow['details']['sales'] as $detail)
                                        <tr>
                                            <td>{{ $detail['opportunity_id'] }}</td>
                                            <td>{{ $detail['opportunity_name'] }}</td>
                                            <td>{{ $detail['record_type_name'] }}</td>
                                            <td>{{ $detail['captador'] }}</td>
                                            <td class="num">{{ number_format($detail['commission_amount'], 2, ',', '.') }}</td>
                                            <td>{{ $detail['contract_signed_date'] ?? '-' }}</td>
                                            <td>{{ $detail['vehicle_to_appraise'] !== '' ? $detail['vehicle_to_appraise'] : '-' }}</td>
                                            <td>{{ $detail['vehicle_interest'] !== '' ? $detail['vehicle_interest'] : '-' }}</td>
                                            <td>{{ $detail['account_name'] !== '' ? $detail['account_name'] : '-' }}</td>
                                            <td>{{ $detail['source'] !== '' ? $detail['source'] : '-' }}</td>
                                            <td>{{ $detail['owner_name'] !== '' ? $detail['owner_name'] : '-' }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="11">Sin ventas comisionables para este agente.</td></tr>
                                    @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="is-hidden" data-commercial-detail-tab-panel="changes">
                            <div class="table-shell">
                                <table data-sortable-table="call-center-changes-{{ $callCenterActiveRow['agent_key'] }}">
                                    <thead>
                                    <tr>
                                        <th data-sortable="true">Opportunity Id</th>
                                        <th data-sortable="true">Opportunity Name</th>
                                        <th data-sortable="true">Record Type</th>
                                        <th data-sortable="true">Captador</th>
                                        <th class="num" data-sortable="true">Comision Captador</th>
                                        <th data-sortable="true">Fecha firma contrato</th>
                                        <th data-sortable="true">Vehiculo a tasar</th>
                                        <th data-sortable="true">Vehiculo de interes</th>
                                        <th data-sortable="true">Account Name</th>
                                        <th data-sortable="true">Fuente de origen</th>
                                        <th data-sortable="true">Opportunity Owner</th>
                                    </tr>
                                    </thead>
                                    <tbody data-sort-body="call-center-changes-{{ $callCenterActiveRow['agent_key'] }}">
                                    @forelse ($callCenterActiveRow['details']['changes'] as $detail)
                                        <tr>
                                            <td>{{ $detail['opportunity_id'] }}</td>
                                            <td>{{ $detail['opportunity_name'] }}</td>
                                            <td>{{ $detail['record_type_name'] }}</td>
                                            <td>{{ $detail['captador'] }}</td>
                                            <td class="num">{{ number_format($detail['commission_amount'], 2, ',', '.') }}</td>
                                            <td>{{ $detail['contract_signed_date'] ?? '-' }}</td>
                                            <td>{{ $detail['vehicle_to_appraise'] !== '' ? $detail['vehicle_to_appraise'] : '-' }}</td>
                                            <td>{{ $detail['vehicle_interest'] !== '' ? $detail['vehicle_interest'] : '-' }}</td>
                                            <td>{{ $detail['account_name'] !== '' ? $detail['account_name'] : '-' }}</td>
                                            <td>{{ $detail['source'] !== '' ? $detail['source'] : '-' }}</td>
                                            <td>{{ $detail['owner_name'] !== '' ? $detail['owner_name'] : '-' }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="11">Sin cambios comisionables para este agente.</td></tr>
                                    @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="is-hidden" data-commercial-detail-tab-panel="german">
                            <div class="table-shell">
                                <table data-sortable-table="call-center-german-{{ $callCenterActiveRow['agent_key'] }}">
                                    <thead>
                                    <tr>
                                        <th data-sortable="true">Tasacion Id</th>
                                        <th data-sortable="true">Tasacion</th>
                                        <th data-sortable="true">Opportunity Id</th>
                                        <th data-sortable="true">Opportunity Name</th>
                                        <th data-sortable="true">Fecha firma contrato</th>
                                        <th data-sortable="true">Seguimiento</th>
                                        <th data-sortable="true">Negociacion 1</th>
                                        <th data-sortable="true">Negociacion 2</th>
                                        <th data-sortable="true">Negociacion 3</th>
                                        <th data-sortable="true">Negociacion 4</th>
                                        <th class="num" data-sortable="true">Importe</th>
                                    </tr>
                                    </thead>
                                    <tbody data-sort-body="call-center-german-{{ $callCenterActiveRow['agent_key'] }}">
                                    @forelse ($callCenterActiveRow['details']['german_negotiations'] as $detail)
                                        <tr>
                                            <td>{{ $detail['tasacion_id'] }}</td>
                                            <td>{{ $detail['tasacion_name'] !== '' ? $detail['tasacion_name'] : '-' }}</td>
                                            <td>{{ $detail['opportunity_id'] !== '' ? $detail['opportunity_id'] : '-' }}</td>
                                            <td>{{ $detail['opportunity_name'] !== '' ? $detail['opportunity_name'] : '-' }}</td>
                                            <td>{{ $detail['contract_signed_date'] ?? '-' }}</td>
                                            <td>{{ $detail['tracking_name'] !== '' ? $detail['tracking_name'] : '-' }}</td>
                                            <td>{{ $detail['negotiation_1'] !== '' ? $detail['negotiation_1'] : '-' }}</td>
                                            <td>{{ $detail['negotiation_2'] !== '' ? $detail['negotiation_2'] : '-' }}</td>
                                            <td>{{ $detail['negotiation_3'] !== '' ? $detail['negotiation_3'] : '-' }}</td>
                                            <td>{{ $detail['negotiation_4'] !== '' ? $detail['negotiation_4'] : '-' }}</td>
                                            <td class="num">{{ number_format($detail['commission_amount'], 2, ',', '.') }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="11">Sin negociaciones German para este agente en el mes.</td></tr>
                                    @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="is-hidden" data-commercial-detail-tab-panel="facilitea">
                            <div class="table-shell">
                                <table data-sortable-table="call-center-facilitea-{{ $callCenterActiveRow['agent_key'] }}">
                                    <thead>
                                    <tr>
                                        <th data-sortable="true">Opportunity Id</th>
                                        <th data-sortable="true">Opportunity Owner</th>
                                        <th class="num" data-sortable="true">Dias entrega</th>
                                        <th data-sortable="true">Opportunity Name</th>
                                        <th data-sortable="true">Account Name</th>
                                        <th data-sortable="true">Fecha firma contrato</th>
                                        <th data-sortable="true">Coopropietario</th>
                                        <th data-sortable="true">Delegacion propietario</th>
                                        <th data-sortable="true">Vehiculo de interes</th>
                                        <th data-sortable="true">Fecha entrega</th>
                                        <th class="num" data-sortable="true">Importe</th>
                                    </tr>
                                    </thead>
                                    <tbody data-sort-body="call-center-facilitea-{{ $callCenterActiveRow['agent_key'] }}">
                                    @forelse ($callCenterActiveRow['details']['facilitea'] as $detail)
                                        <tr>
                                            <td>{{ $detail['opportunity_id'] }}</td>
                                            <td>{{ $detail['owner_name'] !== '' ? $detail['owner_name'] : '-' }}</td>
                                            <td class="num" data-sort-value="{{ $detail['delivery_days'] ?? -1 }}">{{ $detail['delivery_days'] ?? '-' }}</td>
                                            <td>{{ $detail['opportunity_name'] }}</td>
                                            <td>{{ $detail['account_name'] !== '' ? $detail['account_name'] : '-' }}</td>
                                            <td>{{ $detail['contract_signed_date'] ?? '-' }}</td>
                                            <td>{{ $detail['coowner_name'] !== '' ? $detail['coowner_name'] : '-' }}</td>
                                            <td>{{ $detail['owner_delegation'] !== '' ? $detail['owner_delegation'] : '-' }}</td>
                                            <td>{{ $detail['vehicle_interest'] !== '' ? $detail['vehicle_interest'] : '-' }}</td>
                                            <td>{{ $detail['delivery_date'] ?? '-' }}</td>
                                            <td class="num">{{ number_format($detail['commission_amount'], 2, ',', '.') }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="11">Sin operaciones Facilitea para este agente en el mes.</td></tr>
                                    @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </section>
            @endif
        </div>
    </section>
    <script type="application/json" id="callCenterAgentPayload">@json($callCenterAgentPayload)</script>
@else
    <section class="platform-comparison-grid commission-overview-grid call-center-overview-grid">
        @if (($reportUserRole ?? null) === \App\Models\ReportUser::ROLE_ADMIN)
            <article class="card platform-comparison-card call-center-diagnostics-card">
                <div class="platform-comparison-head">
                    <strong>Diagnostico y resync</strong>
                    <span>Si el bloque llega vacio, refresca opportunities y tasaciones del mes completo antes de revisar captadores o Negociaciones German.</span>
                </div>
                <div class="call-center-diagnostics-list">
                    <div class="call-center-diagnostics-row">
                        <span>Opportunities</span>
                        <code>php artisan salesforce:sync-opportunities --from={{ $callCenterMonthStart->toDateString() }} --to={{ $callCenterMonthEnd->toDateString() }}</code>
                    </div>
                    <div class="call-center-diagnostics-row">
                        <span>Tasaciones</span>
                        <code>php artisan salesforce:sync-tasaciones --from={{ $callCenterMonthStart->toDateString() }} --to={{ $callCenterMonthEnd->toDateString() }}</code>
                    </div>
                </div>
                <div class="filter-actions commission-filter-actions">
                    <a
                        class="main-tab"
                        href="{{ $callCenterMissingCaptadorExportUrl }}"
                    >
                        Descargar CSV sin Captador (0)
                    </a>
                </div>
            </article>
        @endif
    </section>
    <div class="notice">No hay operaciones comisionables de Call Center para el mes seleccionado o el rango de contrato activo.</div>
@endif
