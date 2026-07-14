@php
    $financialSummaryRows = collect($financialDashboard['summary_rows'] ?? []);
    $financialDiagnostics = $financialDashboard['diagnostics'] ?? [];
@endphp

<section class="campaign-context-grid commission-context-grid">
    <article class="card campaign-context-card">
        <span>Zonas financieras</span>
        <strong>{{ number_format((int) ($financialDiagnostics['zones_count'] ?? 0), 0, ',', '.') }}</strong>
    </article>
    <article class="card campaign-context-card">
        <span>Operaciones elegibles</span>
        <strong>{{ number_format((int) ($financialDiagnostics['eligible_operations_count'] ?? 0), 0, ',', '.') }}</strong>
    </article>
    <article class="card campaign-context-card">
        <span>Rentabilidad valida</span>
        <strong>{{ number_format((int) ($financialDiagnostics['profitability_eligible_operations_count'] ?? 0), 0, ',', '.') }}</strong>
    </article>
    <article class="card campaign-context-card">
        <span>Total comision</span>
        <strong>{{ number_format((float) $financialSummaryRows->sum('final_commission'), 2, ',', '.') }} EUR</strong>
    </article>
</section>

<section class="platform-comparison-grid commission-overview-grid">
    <article class="card platform-comparison-card">
        <div class="platform-comparison-head">
            <strong>Reglas activas</strong>
            <span>Periodo calculado por `Fecha_firma_contrato__c`, tipos `Venta/Cambio` y agrupacion por `zona_financiera__c`.</span>
        </div>
        <div class="platform-comparison-metrics">
            <div class="platform-metric-item"><span>Bloque 1</span><strong>% financiado sobre comision neta</strong></div>
            <div class="platform-metric-item"><span>Bloque 2</span><strong>Rentabilidad sobre beneficio valido</strong></div>
            <div class="platform-metric-item"><span>Bloque 3</span><strong>% garantias sobre Garant_a_Total__c</strong></div>
            <div class="platform-metric-item"><span>Excluidas bloque 2</span><strong>{{ number_format((int) ($financialDiagnostics['profitability_excluded_operations_count'] ?? 0), 0, ',', '.') }}</strong></div>
        </div>
    </article>
</section>

@if ($financialSummaryRows->isNotEmpty())
    <div class="table-shell area-manager-summary-shell">
        <table data-sortable-table="financial-zones-summary">
            <thead>
            <tr>
                <th data-sortable="true">Zona financiera</th>
                <th class="num" data-sortable="true">Ops.</th>
                <th class="num" data-sortable="true">Imp. total</th>
                <th class="num" data-sortable="true">Imp. financiado</th>
                <th class="num" data-sortable="true">% financiado</th>
                <th class="num" data-sortable="true">Com. financiera</th>
                <th class="num" data-sortable="true">Desc. financiera</th>
                <th class="num" data-sortable="true">Com. neta</th>
                <th class="num" data-sortable="true">Inc. bloque 1</th>
                <th class="num" data-sortable="true">Com. bloque 1</th>
                <th class="num" data-sortable="true">Rentabilidad</th>
                <th class="num" data-sortable="true">Inc. bloque 2</th>
                <th class="num" data-sortable="true">Com. bloque 2</th>
                <th class="num" data-sortable="true">Garantia premium</th>
                <th class="num" data-sortable="true">% garantias</th>
                <th class="num" data-sortable="true">Inc. bloque 3</th>
                <th class="num" data-sortable="true">Com. bloque 3</th>
                <th class="num" data-sortable="true">Total comision</th>
            </tr>
            </thead>
            <tbody data-sort-body="financial-zones-summary">
            @foreach ($financialSummaryRows as $row)
                <tr>
                    <td>{{ $row['zone_name'] }}</td>
                    <td class="num">{{ number_format((int) ($row['operations_count'] ?? 0), 0, ',', '.') }}</td>
                    <td class="num">{{ number_format((float) ($row['amount_total'] ?? 0), 2, ',', '.') }}</td>
                    <td class="num">{{ number_format((float) ($row['amount_financed'] ?? 0), 2, ',', '.') }}</td>
                    <td class="num">{{ number_format((float) ($row['financed_percentage'] ?? 0), 2, ',', '.') }}%</td>
                    <td class="num">{{ number_format((float) ($row['financial_commission_total'] ?? 0), 2, ',', '.') }}</td>
                    <td class="num">{{ number_format((float) ($row['financial_discount_total'] ?? 0), 2, ',', '.') }}</td>
                    <td class="num">{{ number_format((float) ($row['net_commission'] ?? 0), 2, ',', '.') }}</td>
                    <td class="num">{{ number_format(((float) ($row['financed_incentive'] ?? 0)) * 100, 2, ',', '.') }}%</td>
                    <td class="num">{{ number_format((float) ($row['block_1_commission'] ?? 0), 2, ',', '.') }}</td>
                    <td class="num">{{ number_format((float) ($row['profitability_percentage'] ?? 0), 2, ',', '.') }}%</td>
                    <td class="num">{{ number_format(((float) ($row['profitability_incentive'] ?? 0)) * 100, 2, ',', '.') }}%</td>
                    <td class="num">{{ number_format((float) ($row['block_2_commission'] ?? 0), 2, ',', '.') }}</td>
                    <td class="num">{{ number_format((float) ($row['premium_guarantee_total'] ?? 0), 2, ',', '.') }}</td>
                    <td class="num">{{ number_format((float) ($row['guarantee_percentage'] ?? 0), 2, ',', '.') }}%</td>
                    <td class="num">{{ number_format(((float) ($row['guarantee_incentive'] ?? 0)) * 100, 2, ',', '.') }}%</td>
                    <td class="num">{{ number_format((float) ($row['block_3_commission'] ?? 0), 2, ',', '.') }}</td>
                    <td class="num"><strong>{{ number_format((float) ($row['final_commission'] ?? 0), 2, ',', '.') }}</strong></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif
