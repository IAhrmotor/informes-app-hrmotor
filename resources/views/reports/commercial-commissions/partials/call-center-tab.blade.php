<section class="card panel call-center-toolbar-panel">
    <div class="panel-title compact">
        <div>
            <h3>Filtros de contrato</h3>
            <div class="small">El bloque de Call Center se calcula por Fecha firma contrato dentro del mes cerrado seleccionado.</div>
        </div>
    </div>

    <form method="GET" class="commission-filter-form call-center-filter-form">
        <input type="hidden" name="month" value="{{ $dashboard['month'] }}">
        <div class="filter-group">
            <label for="callCenterContractFrom">Fecha contrato desde</label>
            <input type="date" id="callCenterContractFrom" name="call_center_contract_from" value="{{ $callCenterDashboard['contract_from'] ?? '' }}">
        </div>
        <div class="filter-group">
            <label for="callCenterContractTo">Fecha contrato hasta</label>
            <input type="date" id="callCenterContractTo" name="call_center_contract_to" value="{{ $callCenterDashboard['contract_to'] ?? '' }}">
        </div>
        <div class="filter-group filter-group-readonly">
            <label>Comando de resync recomendado</label>
            <div class="filter-readonly-code">
                <code>php artisan salesforce:sync-opportunities --from={{ $callCenterDashboard['month'] }}-01 --to={{ \Carbon\CarbonImmutable::parse(($callCenterDashboard['month'] ?? $dashboard['month']).'-01')->addMonth()->format('Y-m-d') }}</code>
            </div>
        </div>
        <div class="filter-actions commission-filter-actions">
            <button type="submit" class="main-tab">Aplicar filtro</button>
        </div>
    </form>
</section>

@if ($callCenterSummaryRows->isNotEmpty())
    <section class="call-center-kpis-grid">
        <article class="card campaign-context-card">
            <span>Compras / Tasaciones</span>
            <strong>{{ number_format($callCenterCountPurchases, 0, ',', '.') }} ops / {{ number_format($callCenterTotalPurchases, 2, ',', '.') }} EUR</strong>
        </article>
        <article class="card campaign-context-card">
            <span>Ventas</span>
            <strong>{{ number_format($callCenterCountSales, 0, ',', '.') }} ops / {{ number_format($callCenterTotalSales, 2, ',', '.') }} EUR</strong>
        </article>
        <article class="card campaign-context-card">
            <span>Cambios</span>
            <strong>{{ number_format($callCenterCountChanges, 0, ',', '.') }} ops / {{ number_format($callCenterTotalChanges, 2, ',', '.') }} EUR</strong>
        </article>
        <article class="card campaign-context-card">
            <span>Negociaciones German</span>
            <strong>{{ number_format($callCenterCountGerman, 0, ',', '.') }} ops / {{ number_format($callCenterTotalGerman, 2, ',', '.') }} EUR</strong>
        </article>
        <article class="card campaign-context-card">
            <span>Facilitea</span>
            <strong>{{ number_format($callCenterCountFacilitea, 0, ',', '.') }} ops / {{ number_format($callCenterTotalFacilitea, 2, ',', '.') }} EUR</strong>
        </article>
    </section>

    <section class="platform-comparison-grid commission-overview-grid call-center-overview-grid">
        <article class="card platform-comparison-card">
            <div class="platform-comparison-head">
                <strong>Base mensual</strong>
                <span>Bloques automáticos calculados desde Salesforce para el rango de contrato activo.</span>
            </div>
            <div class="platform-comparison-metrics">
                <div class="platform-metric-item"><span>Agentes / captadores</span><strong>{{ number_format($callCenterSummaryRows->count(), 0, ',', '.') }}</strong></div>
                <div class="platform-metric-item"><span>Operaciones base</span><strong>{{ number_format($callCenterDashboard['diagnostics']['monthly_opportunities'] ?? 0, 0, ',', '.') }}</strong></div>
                <div class="platform-metric-item"><span>Sin captador</span><strong>{{ number_format($callCenterDashboard['diagnostics']['missing_captador_count'] ?? 0, 0, ',', '.') }}</strong></div>
                <div class="platform-metric-item"><span>Comisiones vacías</span><strong>{{ number_format($callCenterDashboard['diagnostics']['missing_commission_count'] ?? 0, 0, ',', '.') }}</strong></div>
                <div class="platform-metric-item"><span>Total automático</span><strong>{{ number_format($callCenterTotalAutomatic, 2, ',', '.') }} EUR</strong></div>
            </div>
        </article>
        <article class="card platform-comparison-card">
            <div class="platform-comparison-head">
                <strong>Bloques aplicados</strong>
                <span>Compras, ventas y cambios usan Captador + Comisión Captador. Facilitea usa origen FACILITEA.</span>
            </div>
            <div class="platform-comparison-metrics">
                <div class="platform-metric-item"><span>Compras</span><strong>{{ number_format($callCenterTotalPurchases, 2, ',', '.') }} EUR</strong></div>
                <div class="platform-metric-item"><span>Ventas</span><strong>{{ number_format($callCenterTotalSales, 2, ',', '.') }} EUR</strong></div>
                <div class="platform-metric-item"><span>Cambios</span><strong>{{ number_format($callCenterTotalChanges, 2, ',', '.') }} EUR</strong></div>
                <div class="platform-metric-item"><span>Neg. German</span><strong>{{ number_format($callCenterTotalGerman, 2, ',', '.') }} EUR</strong></div>
                <div class="platform-metric-item"><span>Facilitea</span><strong>{{ number_format($callCenterTotalFacilitea, 2, ',', '.') }} EUR</strong></div>
            </div>
        </article>
    </section>

    <div class="table-shell call-center-summary-shell">
        <table data-sortable-table="call-center-summary">
            <thead>
            <tr>
                <th data-sortable="true">Mes</th>
                <th data-sortable="true">Agente / Captador</th>
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
                <th class="num" data-sortable="true">Total automático</th>
                <th class="num" data-sortable="true">Ajuste manual</th>
                <th class="num" data-sortable="true">Total final</th>
                <th data-sortable="true">Observaciones</th>
            </tr>
            </thead>
            <tbody data-sort-body="call-center-summary">
            @foreach ($callCenterSummaryRows as $row)
                <tr>
                    <td data-sort-value="{{ $callCenterDashboard['month'] }}">{{ $callCenterDashboard['month'] }}</td>
                    <td data-sort-value="{{ $row['agent_name'] }}">{{ $row['agent_name'] }}</td>
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
                    <td class="num" data-sort-value="{{ $row['final_total'] }}"><strong>{{ number_format($row['final_total'], 2, ',', '.') }}</strong></td>
                    <td data-sort-value="{{ $row['observations'] }}">{{ $row['observations'] !== '' ? $row['observations'] : '-' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    <section class="commission-detail-shell commission-call-center-detail-shell" data-agent-browser>
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
            <div class="notice is-hidden" data-agent-empty>No hay agentes que coincidan con la búsqueda.</div>
        </aside>

        <div class="commission-detail-stage">
            @foreach ($callCenterSummaryRows as $row)
                <section
                    class="commission-commercial-panel{{ $row['agent_key'] === $callCenterDefaultAgentKey ? ' is-active' : ' is-hidden' }}"
                    data-agent-panel
                    data-agent-id="{{ $row['agent_key'] }}"
                >
                    <section class="call-center-agent-hero">
                        <article class="card campaign-context-card">
                            <span>Agente / Captador</span>
                            <strong>{{ $row['agent_name'] }}</strong>
                        </article>
                        <article class="card campaign-context-card">
                            <span>Total automático</span>
                            <strong>{{ number_format($row['automatic_total'], 2, ',', '.') }} EUR</strong>
                        </article>
                        <article class="card campaign-context-card">
                            <span>Total final</span>
                            <strong>{{ number_format($row['final_total'], 2, ',', '.') }} EUR</strong>
                        </article>
                        <article class="card campaign-context-card">
                            <span>Observaciones</span>
                            <strong>{{ $row['observations'] !== '' ? $row['observations'] : 'Sin incidencias' }}</strong>
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
                                <table data-sortable-table="call-center-purchases-{{ $row['agent_key'] }}">
                                    <thead>
                                    <tr>
                                        <th data-sortable="true">Opportunity Id</th>
                                        <th data-sortable="true">Opportunity Name</th>
                                        <th data-sortable="true">Record Type</th>
                                        <th data-sortable="true">Captador</th>
                                        <th class="num" data-sortable="true">Comisión Captador</th>
                                        <th data-sortable="true">Fecha firma contrato</th>
                                        <th data-sortable="true">Vehículo a tasar</th>
                                        <th data-sortable="true">Fecha captador</th>
                                        <th data-sortable="true">Account Name</th>
                                    </tr>
                                    </thead>
                                    <tbody data-sort-body="call-center-purchases-{{ $row['agent_key'] }}">
                                    @forelse ($row['details']['purchases'] as $detail)
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
                                <table data-sortable-table="call-center-sales-{{ $row['agent_key'] }}">
                                    <thead>
                                    <tr>
                                        <th data-sortable="true">Opportunity Id</th>
                                        <th data-sortable="true">Opportunity Name</th>
                                        <th data-sortable="true">Record Type</th>
                                        <th data-sortable="true">Captador</th>
                                        <th class="num" data-sortable="true">Comisión Captador</th>
                                        <th data-sortable="true">Fecha firma contrato</th>
                                        <th data-sortable="true">Vehículo a tasar</th>
                                        <th data-sortable="true">Vehículo de interés</th>
                                        <th data-sortable="true">Account Name</th>
                                        <th data-sortable="true">Fuente de origen</th>
                                        <th data-sortable="true">Opportunity Owner</th>
                                    </tr>
                                    </thead>
                                    <tbody data-sort-body="call-center-sales-{{ $row['agent_key'] }}">
                                    @forelse ($row['details']['sales'] as $detail)
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
                                <table data-sortable-table="call-center-changes-{{ $row['agent_key'] }}">
                                    <thead>
                                    <tr>
                                        <th data-sortable="true">Opportunity Id</th>
                                        <th data-sortable="true">Opportunity Name</th>
                                        <th data-sortable="true">Record Type</th>
                                        <th data-sortable="true">Captador</th>
                                        <th class="num" data-sortable="true">Comisión Captador</th>
                                        <th data-sortable="true">Fecha firma contrato</th>
                                        <th data-sortable="true">Vehículo a tasar</th>
                                        <th data-sortable="true">Vehículo de interés</th>
                                        <th data-sortable="true">Account Name</th>
                                        <th data-sortable="true">Fuente de origen</th>
                                        <th data-sortable="true">Opportunity Owner</th>
                                    </tr>
                                    </thead>
                                    <tbody data-sort-body="call-center-changes-{{ $row['agent_key'] }}">
                                    @forelse ($row['details']['changes'] as $detail)
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
                                <table data-sortable-table="call-center-german-{{ $row['agent_key'] }}">
                                    <thead>
                                    <tr>
                                        <th data-sortable="true">Opportunity Id</th>
                                        <th data-sortable="true">Opportunity Name</th>
                                        <th data-sortable="true">Record Type</th>
                                        <th data-sortable="true">Fecha firma contrato</th>
                                        <th data-sortable="true">Captador original</th>
                                        <th data-sortable="true">Captador 2</th>
                                        <th data-sortable="true">Captador 3</th>
                                        <th data-sortable="true">Captador 4</th>
                                        <th class="num" data-sortable="true">Importe</th>
                                    </tr>
                                    </thead>
                                    <tbody data-sort-body="call-center-german-{{ $row['agent_key'] }}">
                                    @forelse ($row['details']['german_negotiations'] as $detail)
                                        <tr>
                                            <td>{{ $detail['opportunity_id'] }}</td>
                                            <td>{{ $detail['opportunity_name'] }}</td>
                                            <td>{{ $detail['record_type_name'] }}</td>
                                            <td>{{ $detail['contract_signed_date'] ?? '-' }}</td>
                                            <td>{{ $detail['captador_original'] !== '' ? $detail['captador_original'] : '-' }}</td>
                                            <td>{{ $detail['negotiation_1'] !== '' ? $detail['negotiation_1'] : '-' }}</td>
                                            <td>{{ $detail['negotiation_2'] !== '' ? $detail['negotiation_2'] : '-' }}</td>
                                            <td>{{ $detail['negotiation_3'] !== '' ? $detail['negotiation_3'] : '-' }}</td>
                                            <td class="num">{{ number_format($detail['commission_amount'], 2, ',', '.') }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="9">Sin negociaciones German para este agente en el mes.</td></tr>
                                    @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="is-hidden" data-commercial-detail-tab-panel="facilitea">
                            <div class="table-shell">
                                <table data-sortable-table="call-center-facilitea-{{ $row['agent_key'] }}">
                                    <thead>
                                    <tr>
                                        <th data-sortable="true">Opportunity Id</th>
                                        <th data-sortable="true">Opportunity Owner</th>
                                        <th class="num" data-sortable="true">Días entrega</th>
                                        <th data-sortable="true">Opportunity Name</th>
                                        <th data-sortable="true">Account Name</th>
                                        <th data-sortable="true">Fecha firma contrato</th>
                                        <th data-sortable="true">Coopropietario</th>
                                        <th data-sortable="true">Delegación propietario</th>
                                        <th data-sortable="true">Vehículo de interés</th>
                                        <th data-sortable="true">Fecha entrega</th>
                                        <th class="num" data-sortable="true">Importe</th>
                                    </tr>
                                    </thead>
                                    <tbody data-sort-body="call-center-facilitea-{{ $row['agent_key'] }}">
                                    @forelse ($row['details']['facilitea'] as $detail)
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
            @endforeach
        </div>
    </section>
@else
    <div class="notice">No hay operaciones comisionables de Call Center para el mes seleccionado o el rango de contrato activo.</div>
@endif
