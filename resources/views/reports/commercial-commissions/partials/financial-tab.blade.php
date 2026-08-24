@php
    $financialSummaryRows = collect($financialDashboard['summary_rows'] ?? []);
    $financialStandardRows = $financialSummaryRows->where('commission_mode', 'standard_blocks');
    $financialSpecialRows = $financialSummaryRows->where('commission_mode', 'net_percentage');
    $financialDelegationRows = collect($financialDashboard['delegation_rows'] ?? []);
    $financialDetailRows = collect($financialDashboard['detail_rows'] ?? []);
    $financialDiagnostics = $financialDashboard['diagnostics'] ?? [];
    $financialUnknownZones = collect($financialDiagnostics['unknown_financial_zones'] ?? []);
@endphp

<section class="campaign-context-grid commission-context-grid">
    <article class="card campaign-context-card">
        <span>Responsables</span>
        <strong>{{ number_format($financialSummaryRows->count(), 0, ',', '.') }}</strong>
    </article>
    <article class="card campaign-context-card">
        <span>Operaciones incluidas</span>
        <strong>{{ number_format((int) ($financialDiagnostics['eligible_operations_count'] ?? 0), 0, ',', '.') }}</strong>
    </article>
    <article class="card campaign-context-card">
        <span>Comision financiera</span>
        <strong>{{ number_format((float) ($financialDiagnostics['financial_commission_included'] ?? 0), 2, ',', '.') }} EUR</strong>
    </article>
    <article class="card campaign-context-card">
        <span>Total comision</span>
        <strong>{{ number_format((float) $financialSummaryRows->sum('final_commission'), 2, ',', '.') }} EUR</strong>
    </article>
</section>

@if ($canSeeSyncDiagnostics ?? false)
    <section class="card panel">
        <div class="panel-title">
            <div>
                <h2>Conciliacion financiera</h2>
                <div class="small">Diagnostico interno del universo local sincronizado. No realiza llamadas a Salesforce durante el render.</div>
            </div>
        </div>
        <div class="campaign-diagnostics commission-diagnostics-grid">
            <div class="diagnostic-item"><span>Universo local</span><strong>{{ number_format((int) ($financialDiagnostics['universe_operations_count'] ?? 0), 0, ',', '.') }}</strong></div>
            <div class="diagnostic-item"><span>Incluidas</span><strong>{{ number_format((int) ($financialDiagnostics['eligible_operations_count'] ?? 0), 0, ',', '.') }}</strong></div>
            <div class="diagnostic-item"><span>General / Sin zona</span><strong>{{ number_format((int) ($financialDiagnostics['general_or_without_zone_operations_count'] ?? 0), 0, ',', '.') }}</strong></div>
            <div class="diagnostic-item"><span>Sin delegacion</span><strong>{{ number_format((int) ($financialDiagnostics['operations_without_delegation_count'] ?? 0), 0, ',', '.') }}</strong></div>
            <div class="diagnostic-item"><span>Zona desconocida</span><strong>{{ number_format((int) ($financialDiagnostics['unknown_financial_zone_operations_count'] ?? 0), 0, ',', '.') }}</strong></div>
            <div class="diagnostic-item"><span>Ajustes redondeo</span><strong>{{ number_format((int) ($financialDiagnostics['rounding_adjustments_count'] ?? 0), 0, ',', '.') }}</strong></div>
            <div class="diagnostic-item"><span>Com. incluida</span><strong>{{ number_format((float) ($financialDiagnostics['financial_commission_included'] ?? 0), 2, ',', '.') }} EUR</strong></div>
            <div class="diagnostic-item"><span>Com. excluida</span><strong>{{ number_format((float) ($financialDiagnostics['financial_commission_excluded'] ?? 0), 2, ',', '.') }} EUR</strong></div>
            <div class="diagnostic-item"><span>Desc. incluido</span><strong>{{ number_format((float) ($financialDiagnostics['financial_discount_included'] ?? 0), 2, ',', '.') }} EUR</strong></div>
            <div class="diagnostic-item"><span>Desc. excluido</span><strong>{{ number_format((float) ($financialDiagnostics['financial_discount_excluded'] ?? 0), 2, ',', '.') }} EUR</strong></div>
        </div>

        @if ($financialUnknownZones->isNotEmpty())
            <div class="table-shell area-manager-summary-shell">
                <table>
                    <thead><tr>
                        <th>Zona desconocida</th><th class="num">Ops.</th>
                        <th class="num">Com. financiera</th><th class="num">Desc. financiera</th>
                        <th>Opportunity IDs</th>
                    </tr></thead>
                    <tbody>
                    @foreach ($financialUnknownZones as $zone)
                        <tr>
                            <td>{{ $zone['zone_name'] }}</td>
                            <td class="num">{{ number_format((int) $zone['operations_count'], 0, ',', '.') }}</td>
                            <td class="num">{{ number_format((float) $zone['financial_commission'], 2, ',', '.') }}</td>
                            <td class="num">{{ number_format((float) $zone['financial_discount'], 2, ',', '.') }}</td>
                            <td>{{ implode(', ', $zone['opportunity_ids'] ?? []) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endif

@if ($financialStandardRows->isNotEmpty())
    <section class="card panel">
        <div class="panel-title">
            <div>
                <h2>Carlos y Cristina</h2>
                <div class="small">Regla estandar: bloques de porcentaje financiado, rentabilidad y garantias.</div>
            </div>
        </div>
        <div class="table-shell area-manager-summary-shell">
            <table data-sortable-table="financial-standard-summary">
                <thead><tr>
                    <th data-sortable="true">Responsable</th><th class="num" data-sortable="true">Total comision</th>
                    <th class="num" data-sortable="true">Ops.</th><th class="num" data-sortable="true">Imp. total</th>
                    <th class="num" data-sortable="true">Imp. financiado</th><th class="num" data-sortable="true">% financiado</th>
                    <th class="num" data-sortable="true">Com. financiera</th><th class="num" data-sortable="true">Desc. financiera</th>
                    <th class="num" data-sortable="true">Com. neta</th><th class="num" data-sortable="true">Bloque 1</th>
                    <th class="num" data-sortable="true">Rentabilidad</th><th class="num" data-sortable="true">Bloque 2</th>
                    <th class="num" data-sortable="true">Garantia</th><th class="num" data-sortable="true">Bloque 3</th>
                </tr></thead>
                <tbody data-sort-body="financial-standard-summary">
                @foreach ($financialStandardRows as $row)
                    <tr>
                        <td>{{ $row['summary_label'] }}</td>
                        <td class="num"><strong>{{ number_format((float) $row['final_commission'], 2, ',', '.') }}</strong></td>
                        <td class="num">{{ number_format((int) $row['operations_count'], 0, ',', '.') }}</td>
                        <td class="num">{{ number_format((float) $row['amount_total'], 2, ',', '.') }}</td>
                        <td class="num">{{ number_format((float) $row['amount_financed'], 2, ',', '.') }}</td>
                        <td class="num">{{ number_format((float) $row['financed_percentage'], 2, ',', '.') }}%</td>
                        <td class="num">{{ number_format((float) $row['financial_commission_total'], 2, ',', '.') }}</td>
                        <td class="num">{{ number_format((float) $row['financial_discount_total'], 2, ',', '.') }}</td>
                        <td class="num">{{ number_format((float) $row['net_commission'], 2, ',', '.') }}</td>
                        <td class="num">{{ number_format((float) $row['block_1_commission'], 2, ',', '.') }}</td>
                        <td class="num">{{ number_format((float) $row['profitability_percentage'], 2, ',', '.') }}%</td>
                        <td class="num">{{ number_format((float) $row['block_2_commission'], 2, ',', '.') }}</td>
                        <td class="num">{{ number_format((float) $row['premium_guarantee_total'], 2, ',', '.') }}</td>
                        <td class="num">{{ number_format((float) $row['block_3_commission'], 2, ',', '.') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endif

@if ($financialSpecialRows->isNotEmpty())
    <section class="card panel">
        <div class="panel-title">
            <div>
                <h2>Irene y Nuria</h2>
                <div class="small">Regla exclusiva: (Comision financiera - Descuento financiero) x 0,50%. No se aplican los bloques estandar.</div>
            </div>
        </div>
        <div class="table-shell area-manager-summary-shell">
            <table data-sortable-table="financial-special-summary">
                <thead><tr>
                    <th data-sortable="true">Responsable</th><th class="num" data-sortable="true">Ops.</th>
                    <th class="num" data-sortable="true">Com. financiera</th><th class="num" data-sortable="true">Desc. financiera</th>
                    <th class="num" data-sortable="true">Com. neta</th><th class="num" data-sortable="true">Multiplicador</th>
                    <th class="num" data-sortable="true">Comision final</th>
                </tr></thead>
                <tbody data-sort-body="financial-special-summary">
                @foreach ($financialSpecialRows as $row)
                    <tr>
                        <td>{{ $row['summary_label'] }}</td>
                        <td class="num">{{ number_format((int) $row['operations_count'], 0, ',', '.') }}</td>
                        <td class="num">{{ number_format((float) $row['financial_commission_total'], 2, ',', '.') }}</td>
                        <td class="num">{{ number_format((float) $row['financial_discount_total'], 2, ',', '.') }}</td>
                        <td class="num">{{ number_format((float) $row['net_commission'], 2, ',', '.') }}</td>
                        <td class="num">{{ number_format(((float) $row['special_responsible_percent']) * 100, 2, ',', '.') }}%</td>
                        <td class="num"><strong>{{ number_format((float) $row['final_commission'], 2, ',', '.') }}</strong></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endif

@if ($financialDelegationRows->isNotEmpty())
    <section class="card panel">
        <div class="panel-title">
            <div>
                <h2>Detalle por responsable y delegacion</h2>
                <div class="small">Cada agregado conserva los Opportunity IDs que lo componen.</div>
            </div>
        </div>
        <div class="table-shell area-manager-summary-shell">
            <table data-sortable-table="financial-delegations">
                <thead><tr>
                    <th data-sortable="true">Responsable</th><th data-sortable="true">Delegacion</th>
                    <th class="num" data-sortable="true">Ops.</th><th class="num" data-sortable="true">Imp. total</th>
                    <th class="num" data-sortable="true">Imp. financiado</th><th class="num" data-sortable="true">% financiado</th>
                    <th class="num" data-sortable="true">Com. financiera</th><th class="num" data-sortable="true">Desc. financiera</th>
                    <th class="num" data-sortable="true">Com. neta</th><th class="num" data-sortable="true">Garantia</th>
                    <th class="num" data-sortable="true">Ajuste redondeo</th><th class="num" data-sortable="true">Comision final</th>
                </tr></thead>
                <tbody data-sort-body="financial-delegations">
                @foreach ($financialDelegationRows as $row)
                    <tr>
                        <td>{{ $row['summary_label'] }}</td><td>{{ $row['delegation_name'] }}</td>
                        <td class="num">{{ number_format((int) $row['operations_count'], 0, ',', '.') }}</td>
                        <td class="num">{{ number_format((float) $row['amount_total'], 2, ',', '.') }}</td>
                        <td class="num">{{ number_format((float) $row['amount_financed'], 2, ',', '.') }}</td>
                        <td class="num">{{ number_format((float) $row['financed_percentage'], 2, ',', '.') }}%</td>
                        <td class="num">{{ number_format((float) $row['financial_commission_total'], 2, ',', '.') }}</td>
                        <td class="num">{{ number_format((float) $row['financial_discount_total'], 2, ',', '.') }}</td>
                        <td class="num">{{ number_format((float) $row['net_commission'], 2, ',', '.') }}</td>
                        <td class="num">{{ number_format((float) $row['premium_guarantee_total'], 2, ',', '.') }}</td>
                        <td class="num">
                            @if ((float) ($row['rounding_adjustment'] ?? 0) !== 0.0)
                                <strong>{{ number_format((float) $row['rounding_adjustment'], 2, ',', '.') }}</strong>
                            @else
                                -
                            @endif
                        </td>
                        <td class="num"><strong>{{ number_format((float) $row['final_commission'], 2, ',', '.') }}</strong></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endif

@if ($financialDetailRows->isNotEmpty())
    <details class="card panel">
        <summary><strong>Trazabilidad por Opportunity ({{ number_format($financialDetailRows->count(), 0, ',', '.') }})</strong></summary>
        <div class="small">Detalle secundario para reconstruir cada agregado sin perder el Opportunity ID.</div>
        <div class="table-shell area-manager-summary-shell">
            <table data-sortable-table="financial-detail">
                <thead><tr>
                    <th data-sortable="true">Responsable</th><th data-sortable="true">Delegacion</th>
                    <th data-sortable="true">Opportunity ID</th><th data-sortable="true">Opportunity</th>
                    <th data-sortable="true">Tipo interes</th><th data-sortable="true">Estado bloque 2</th>
                    <th class="num" data-sortable="true">Imp. financiado</th>
                    <th class="num" data-sortable="true">Com. financiera</th><th class="num" data-sortable="true">Desc. financiera</th>
                </tr></thead>
                <tbody data-sort-body="financial-detail">
                @foreach ($financialDetailRows as $row)
                    <tr>
                        <td>{{ $row['summary_label'] }}</td><td>{{ $row['delegation_name'] }}</td>
                        <td>{{ $row['opportunity_id'] }}</td><td>{{ $row['opportunity_name'] }}</td>
                        <td>{{ $row['interest_rate'] !== '' ? $row['interest_rate'] : '-' }}</td>
                        <td>{{ $row['profitability_reason'] }}</td>
                        <td class="num">{{ number_format((float) $row['amount_financed'], 2, ',', '.') }}</td>
                        <td class="num">{{ number_format((float) $row['financial_commission'], 2, ',', '.') }}</td>
                        <td class="num">{{ number_format((float) $row['financial_discount'], 2, ',', '.') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </details>
@endif
