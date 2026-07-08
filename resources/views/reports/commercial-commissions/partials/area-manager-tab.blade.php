@php
    $areaManagerSummaryRows = collect($areaManagerDashboard['summary_rows'] ?? []);
    $areaManagerActiveRow = $areaManagerSummaryRows->first();
    $areaManagerDiagnostics = $areaManagerDashboard['diagnostics'] ?? [];
    $areaManagerGlobalIncidents = collect($areaManagerDashboard['global_incidents'] ?? []);
    $areaManagerKpiSections = [
        'deliveries' => ['label' => 'Entregas', 'money' => false],
        'benefit' => ['label' => 'Beneficio', 'money' => true],
        'guarantee' => ['label' => 'Garantia Premium', 'money' => true],
        'purchases' => ['label' => 'Compras', 'money' => false],
    ];
    $formatMetric = static function (float|int|null $value, bool $money = false): string {
        $number = (float) ($value ?? 0);
        $decimals = abs($number - round($number)) < 0.0001 ? 0 : 2;
        $formatted = number_format($number, $decimals, ',', '.');

        return $money ? $formatted.' EUR' : $formatted;
    };
@endphp

<section class="call-center-kpis-grid">
    <article class="card campaign-context-card">
        <span>Managers</span>
        <strong>{{ number_format($areaManagerSummaryRows->count(), 0, ',', '.') }}</strong>
    </article>
    <article class="card campaign-context-card">
        <span>Delegaciones</span>
        <strong>{{ number_format((int) ($areaManagerDiagnostics['delegations_count'] ?? 0), 0, ',', '.') }}</strong>
    </article>
    <article class="card campaign-context-card">
        <span>Total automatico</span>
        <strong>{{ number_format((float) $areaManagerSummaryRows->sum('automatic_total'), 2, ',', '.') }} EUR</strong>
    </article>
    <article class="card campaign-context-card">
        <span>Incidencias</span>
        <strong>{{ number_format($areaManagerGlobalIncidents->count(), 0, ',', '.') }}</strong>
    </article>
</section>

<section class="platform-comparison-grid commission-overview-grid call-center-overview-grid">
    <article class="card platform-comparison-card">
        <div class="platform-comparison-head">
            <strong>Base mensual</strong>
            <span>Totales auditables del mes y delegaciones configuradas.</span>
        </div>
        <div class="platform-comparison-metrics">
            <div class="platform-metric-item"><span>Entregas base</span><strong>{{ number_format((int) ($areaManagerDiagnostics['delivery_operations_count'] ?? 0), 0, ',', '.') }}</strong></div>
            <div class="platform-metric-item"><span>Compras base</span><strong>{{ number_format((int) ($areaManagerDiagnostics['purchase_operations_count'] ?? 0), 0, ',', '.') }}</strong></div>
            <div class="platform-metric-item"><span>Delegaciones configuradas</span><strong>{{ number_format((int) ($areaManagerDiagnostics['configured_delegations_count'] ?? 0), 0, ',', '.') }}</strong></div>
            <div class="platform-metric-item"><span>Managers activos</span><strong>{{ number_format((int) ($areaManagerDiagnostics['managers_count'] ?? 0), 0, ',', '.') }}</strong></div>
        </div>
    </article>

    <article class="card platform-comparison-card">
        <div class="platform-comparison-head">
            <strong>Reglas activas</strong>
            <span>Comision por KPI por delegacion y llave posterior por zona.</span>
        </div>
        <div class="platform-comparison-metrics">
            <div class="platform-metric-item"><span>Entregas</span><strong>{{ number_format((float) ($formulaSettings['area_manager']['kpi_bases']['deliveries'] ?? 150), 2, ',', '.') }} EUR</strong></div>
            <div class="platform-metric-item"><span>Beneficio</span><strong>{{ number_format((float) ($formulaSettings['area_manager']['kpi_bases']['benefit'] ?? 150), 2, ',', '.') }} EUR</strong></div>
            <div class="platform-metric-item"><span>Garantia Premium</span><strong>{{ number_format((float) ($formulaSettings['area_manager']['kpi_bases']['guarantee'] ?? 100), 2, ',', '.') }} EUR</strong></div>
            <div class="platform-metric-item"><span>Compras</span><strong>{{ number_format((float) ($formulaSettings['area_manager']['kpi_bases']['purchases'] ?? 100), 2, ',', '.') }} EUR</strong></div>
        </div>
    </article>
</section>

<div class="table-shell call-center-summary-shell">
    <table data-sortable-table="area-manager-summary">
        <thead>
        <tr>
            <th data-sortable="true">Manager</th>
            <th class="num" data-sortable="true">Delegaciones</th>
            <th class="num" data-sortable="true">Obj. entregas</th>
            <th class="num" data-sortable="true">Real entregas</th>
            <th class="num" data-sortable="true">% entregas zona</th>
            <th class="num" data-sortable="true">Com. entregas</th>
            <th class="num" data-sortable="true">Obj. beneficio</th>
            <th class="num" data-sortable="true">Real beneficio</th>
            <th class="num" data-sortable="true">% beneficio zona</th>
            <th class="num" data-sortable="true">Com. beneficio</th>
            <th class="num" data-sortable="true">Obj. garantia</th>
            <th class="num" data-sortable="true">Real garantia</th>
            <th class="num" data-sortable="true">% garantia zona</th>
            <th class="num" data-sortable="true">Com. garantia</th>
            <th class="num" data-sortable="true">Obj. compras</th>
            <th class="num" data-sortable="true">Real compras</th>
            <th class="num" data-sortable="true">% compras zona</th>
            <th class="num" data-sortable="true">Com. compras</th>
            <th class="num" data-sortable="true">Total automatico</th>
            <th class="num" data-sortable="true">Total final</th>
        </tr>
        </thead>
        <tbody data-sort-body="area-manager-summary">
        @forelse ($areaManagerSummaryRows as $row)
            <tr>
                <td>{{ $row['manager_name'] }}</td>
                <td class="num">{{ number_format((int) ($row['delegations_count'] ?? 0), 0, ',', '.') }}</td>
                <td class="num">{{ $formatMetric($row['deliveries_objective'] ?? 0) }}</td>
                <td class="num">{{ $formatMetric($row['deliveries_actual'] ?? 0) }}</td>
                <td class="num">{{ number_format((float) ($row['deliveries_zone_percent'] ?? 0), 2, ',', '.') }}%</td>
                <td class="num">{{ number_format((float) ($row['deliveries_commission'] ?? 0), 2, ',', '.') }}</td>
                <td class="num">{{ $formatMetric($row['benefit_objective'] ?? 0, true) }}</td>
                <td class="num">{{ $formatMetric($row['benefit_actual'] ?? 0, true) }}</td>
                <td class="num">{{ number_format((float) ($row['benefit_zone_percent'] ?? 0), 2, ',', '.') }}%</td>
                <td class="num">{{ number_format((float) ($row['benefit_commission'] ?? 0), 2, ',', '.') }}</td>
                <td class="num">{{ $formatMetric($row['guarantee_objective'] ?? 0, true) }}</td>
                <td class="num">{{ $formatMetric($row['guarantee_actual'] ?? 0, true) }}</td>
                <td class="num">{{ number_format((float) ($row['guarantee_zone_percent'] ?? 0), 2, ',', '.') }}%</td>
                <td class="num">{{ number_format((float) ($row['guarantee_commission'] ?? 0), 2, ',', '.') }}</td>
                <td class="num">{{ $formatMetric($row['purchases_objective'] ?? 0) }}</td>
                <td class="num">{{ $formatMetric($row['purchases_actual'] ?? 0) }}</td>
                <td class="num">{{ number_format((float) ($row['purchases_zone_percent'] ?? 0), 2, ',', '.') }}%</td>
                <td class="num">{{ number_format((float) ($row['purchases_commission'] ?? 0), 2, ',', '.') }}</td>
                <td class="num"><strong>{{ number_format((float) ($row['automatic_total'] ?? 0), 2, ',', '.') }}</strong></td>
                <td class="num"><strong>{{ number_format((float) ($row['final_total'] ?? 0), 2, ',', '.') }}</strong></td>
            </tr>
        @empty
            <tr><td colspan="20">Sin managers calculables para este mes.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

@if ($areaManagerSummaryRows->isNotEmpty())
    <section class="commission-detail-shell commission-contact-center-detail-shell" data-agent-browser>
        <aside class="card panel commission-commercial-picker">
            <div class="panel-title compact">
                <div>
                    <h2>Buscar manager</h2>
                    <div class="small">Selecciona un manager para abrir el detalle por delegacion y KPI.</div>
                </div>
            </div>
            <div class="filter-group">
                <label for="areaManagerSearch">Manager</label>
                <input id="areaManagerSearch" type="search" placeholder="Filtrar manager" data-agent-search-input>
            </div>
            <div class="commission-commercial-list">
                @foreach ($areaManagerSummaryRows as $row)
                    <button
                        type="button"
                        class="commission-commercial-option{{ $loop->first ? ' is-active' : '' }}"
                        data-agent-option
                        data-agent-id="{{ $row['manager_key'] }}"
                        data-agent-search="{{ \Illuminate\Support\Str::lower($row['manager_name']) }}"
                    >
                        <strong>{{ $row['manager_name'] }}</strong>
                        <span>{{ number_format((float) ($row['final_total'] ?? 0), 2, ',', '.') }} EUR</span>
                        <small>{{ number_format((int) ($row['delegations_count'] ?? 0), 0, ',', '.') }} delegaciones</small>
                    </button>
                @endforeach
            </div>
            <div class="small is-hidden" data-agent-empty>No hay managers que coincidan con el filtro.</div>
        </aside>

        <div class="commission-commercial-panels">
            @foreach ($areaManagerSummaryRows as $row)
                @php
                    $detailRows = collect($row['detail_rows'] ?? []);
                @endphp
                <article
                    class="commission-commercial-panel{{ $loop->first ? ' is-active' : ' is-hidden' }}"
                    data-agent-panel
                    data-agent-id="{{ $row['manager_key'] }}"
                >
                    <section class="call-center-agent-hero">
                        <article class="card campaign-context-card">
                            <span>Manager</span>
                            <strong>{{ $row['manager_name'] }}</strong>
                        </article>
                        <article class="card campaign-context-card">
                            <span>Total automatico</span>
                            <strong>{{ number_format((float) ($row['automatic_total'] ?? 0), 2, ',', '.') }} EUR</strong>
                        </article>
                        <article class="card campaign-context-card">
                            <span>Total final</span>
                            <strong>{{ number_format((float) ($row['final_total'] ?? 0), 2, ',', '.') }} EUR</strong>
                        </article>
                        <article class="card campaign-context-card">
                            <span>Observaciones</span>
                            <strong>{{ $row['observations'] }}</strong>
                        </article>
                    </section>

                    <div class="area-manager-kpi-grid">
                        @foreach ($areaManagerKpiSections as $kpiKey => $kpiSection)
                            @php
                                $kpiRows = $detailRows->filter(fn (array $detail) => ($detail['kpi_key'] ?? null) === $kpiKey)->values();
                            @endphp
                            <section class="card panel area-manager-kpi-panel">
                                <div class="panel-title compact">
                                    <div>
                                        <h2>{{ $kpiSection['label'] }}</h2>
                                        <div class="small">Detalle por delegacion para {{ \Illuminate\Support\Str::lower($kpiSection['label']) }}.</div>
                                    </div>
                                </div>
                                <div class="table-shell area-manager-kpi-table-shell">
                                    <table data-sortable-table="area-manager-detail-{{ $row['manager_key'] }}-{{ $kpiKey }}">
                                        <thead>
                                        <tr>
                                            <th data-sortable="true">Delegacion</th>
                                            <th class="num" data-sortable="true">Objetivo</th>
                                            <th class="num" data-sortable="true">Real</th>
                                            <th class="num" data-sortable="true">% real</th>
                                            <th class="num" data-sortable="true">% usado</th>
                                            <th class="num" data-sortable="true">Base KPI</th>
                                            <th data-sortable="true">>=85%</th>
                                            <th class="num" data-sortable="true">Com. pre llave</th>
                                            <th class="num" data-sortable="true">% zona</th>
                                            <th class="num" data-sortable="true">Com. final</th>
                                            <th data-sortable="true">Motivo</th>
                                        </tr>
                                        </thead>
                                        <tbody data-sort-body="area-manager-detail-{{ $row['manager_key'] }}-{{ $kpiKey }}">
                                        @forelse ($kpiRows as $detail)
                                            <tr>
                                                <td>{{ $detail['delegation_name'] }}</td>
                                                <td class="num">{{ $formatMetric($detail['objective'] ?? 0, $kpiSection['money']) }}</td>
                                                <td class="num">{{ $formatMetric($detail['actual'] ?? 0, $kpiSection['money']) }}</td>
                                                <td class="num">{{ $detail['compliance_percent_raw'] !== null ? number_format((float) $detail['compliance_percent_raw'], 2, ',', '.').'%' : '-' }}</td>
                                                <td class="num">{{ $detail['compliance_percent_used'] !== null ? number_format((float) $detail['compliance_percent_used'], 0, ',', '.').'%' : '-' }}</td>
                                                <td class="num">{{ number_format((float) ($detail['base_amount'] ?? 0), 2, ',', '.') }}</td>
                                                <td>{{ ($detail['qualified'] ?? false) ? 'Si' : 'No' }}</td>
                                                <td class="num">{{ number_format((float) ($detail['pre_key_commission'] ?? 0), 2, ',', '.') }}</td>
                                                <td class="num">{{ $detail['zone_percent_raw'] !== null ? number_format((float) $detail['zone_percent_raw'], 2, ',', '.').'%' : '-' }}</td>
                                                <td class="num"><strong>{{ number_format((float) ($detail['final_commission'] ?? 0), 2, ',', '.') }}</strong></td>
                                                <td>{{ $detail['reason'] }}</td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="11">Sin delegaciones calculables para este KPI.</td>
                                            </tr>
                                        @endforelse
                                        </tbody>
                                    </table>
                                </div>
                            </section>
                        @endforeach
                    </div>

                    @if (! empty($row['incidents']))
                        <section class="card panel contact-center-global-incidents-panel">
                            <div class="panel-title compact">
                                <div>
                                    <h2>Incidencias de {{ $row['manager_name'] }}</h2>
                                    <div class="small">Casos sin objetivo o sin asignacion de manager.</div>
                                </div>
                            </div>
                            <div class="table-shell">
                                <table data-sortable-table="area-manager-incidents-{{ $row['manager_key'] }}">
                                    <thead>
                                    <tr>
                                        <th data-sortable="true">Delegacion</th>
                                        <th data-sortable="true">KPI</th>
                                        <th data-sortable="true">Mensaje</th>
                                    </tr>
                                    </thead>
                                    <tbody data-sort-body="area-manager-incidents-{{ $row['manager_key'] }}">
                                    @foreach ($row['incidents'] as $incident)
                                        <tr>
                                            <td>{{ $incident['delegation_name'] }}</td>
                                            <td>{{ $incident['kpi'] }}</td>
                                            <td>{{ $incident['message'] }}</td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    @endif
                </article>
            @endforeach
        </div>
    </section>
@endif

@if ($areaManagerGlobalIncidents->isNotEmpty())
    <section class="card panel contact-center-global-incidents-panel">
        <div class="panel-title compact">
            <div>
                <h2>Incidencias globales Area Manager</h2>
                <div class="small">Delegaciones con datos reales sin configuracion completa.</div>
            </div>
        </div>
        <div class="table-shell">
            <table data-sortable-table="area-manager-global-incidents">
                <thead>
                <tr>
                    <th data-sortable="true">Delegacion</th>
                    <th data-sortable="true">Manager</th>
                    <th data-sortable="true">KPI</th>
                    <th data-sortable="true">Mensaje</th>
                </tr>
                </thead>
                <tbody data-sort-body="area-manager-global-incidents">
                @foreach ($areaManagerGlobalIncidents as $incident)
                    <tr>
                        <td>{{ $incident['delegation_name'] }}</td>
                        <td>{{ $incident['manager_name'] }}</td>
                        <td>{{ $incident['kpi'] }}</td>
                        <td>{{ $incident['message'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endif
