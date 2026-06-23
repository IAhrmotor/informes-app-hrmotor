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
    ])
    <script>
        window.reportUserRole = @json($reportUserRole ?? 'viewer');
    </script>
</head>
<body class="campaigns-report commercial-commissions-report">
<div class="wrap">
    @include('reports.partials.report-header', ['currentReport' => 'commercial-commissions', 'subtitle' => 'Comisiones mensuales'])

    <main>
        <section class="tab-panel active">
            <section class="filters card">
                <form method="GET" style="display:grid;grid-template-columns:minmax(0,220px) auto;gap:14px;align-items:end;">
                    <div class="filter-group">
                        <label for="month">Mes cerrado</label>
                        <input type="month" id="month" name="month" value="{{ $dashboard['month'] }}">
                    </div>
                    <div class="filter-actions" style="justify-content:flex-start;">
                        <button type="submit" class="main-tab active">Cargar informe</button>
                    </div>
                </form>
            </section>

            @if (! $dashboard['ready'])
                <div class="notice">
                    El informe no está listo para cálculo final. Hay bloqueos de configuración o lógica que impiden sacar comisiones definitivas.
                </div>
            @endif

            @foreach ($dashboard['issues'] as $issue)
                <div class="notice" style="margin-top:12px;">{{ $issue }}</div>
            @endforeach

            @foreach ($dashboard['warnings'] ?? [] as $warning)
                <div class="notice" style="margin-top:12px;background:#fff7ed;border-color:rgba(245,158,11,.28);color:#9a3412;">{{ $warning }}</div>
            @endforeach

            <section class="kpis dashboard-kpis">
                <article class="card kpi">
                    <span>Mes analizado</span>
                    <strong>{{ $dashboard['month_label'] }}</strong>
                    <small>Periodo cerrado seleccionado para contraste.</small>
                </article>
                <article class="card kpi">
                    <span>Oportunidades</span>
                    <strong>{{ number_format($dashboard['diagnostics']['opportunities_total'], 0, ',', '.') }}</strong>
                    <small>CV firmados de Venta, Cambio y Tasación en el mes.</small>
                </article>
                <article class="card kpi">
                    <span>Reseñas</span>
                    <strong>{{ number_format($dashboard['diagnostics']['reviews_count'], 0, ',', '.') }}</strong>
                    <small>Objeto `Resena__c` creado dentro del mes.</small>
                </article>
                <article class="card kpi">
                    <span>Estado</span>
                    <strong>{{ $dashboard['ready'] ? 'Listo para validar' : 'Pendiente de cierre' }}</strong>
                    <small>Solo administradores pueden verlo por ahora.</small>
                </article>
            </section>

            <section class="card panel">
                <div class="panel-title">
                    <div>
                        <h2>Diagnóstico de datos base</h2>
                        <div class="small">Volumen real sincronizado para el mes seleccionado.</div>
                    </div>
                </div>
                <div class="table-shell">
                    <table>
                        <tbody>
                        <tr>
                            <th>Ventas / Cambios</th>
                            <td>{{ number_format($dashboard['diagnostics']['sales_count'], 0, ',', '.') }}</td>
                        </tr>
                        <tr>
                            <th>Compras base</th>
                            <td>{{ number_format($dashboard['diagnostics']['purchases_count'], 0, ',', '.') }}</td>
                        </tr>
                        <tr>
                            <th>Operaciones</th>
                            <td>{{ number_format($dashboard['diagnostics']['operations_count'], 0, ',', '.') }}</td>
                        </tr>
                        <tr>
                            <th>Compartidas</th>
                            <td>{{ number_format($dashboard['diagnostics']['shared_sales_count'], 0, ',', '.') }}</td>
                        </tr>
                        <tr>
                            <th>Stock +150</th>
                            <td>{{ number_format($dashboard['diagnostics']['stock_150_count'], 0, ',', '.') }}</td>
                        </tr>
                        <tr>
                            <th>Comerciales detectados</th>
                            <td>{{ number_format($dashboard['diagnostics']['commercials_count'], 0, ',', '.') }}</td>
                        </tr>
                        <tr>
                            <th>Filtro Gestión de venta</th>
                            <td>{{ $dashboard['diagnostics']['sale_management_filter_applied'] ? 'Aplicado' : 'No aplicado' }}</td>
                        </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="card panel">
                <div class="panel-title">
                    <div>
                        <h2>Campos candidatos de rentabilidad</h2>
                        <div class="small">Se sincronizan ambos para poder contrastarlos antes de fijar el cálculo final de compras.</div>
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
                                <th>{{ $candidate['field'] }}</th>
                                <td class="num">{{ number_format($candidate['non_null_rows'], 0, ',', '.') }}</td>
                                <td class="num">{{ number_format($candidate['positive_rows'], 0, ',', '.') }}</td>
                                <td class="num">{{ number_format($candidate['sum'], 2, ',', '.') }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            @if ($dashboard['summary_rows'] !== [])
                <section class="card panel">
                    <div class="panel-title">
                        <div>
                            <h2>Resumen por comercial</h2>
                            <div class="small">Agrupado por propietario Salesforce. La financiación se calcula sobre Venta + Cambio y las compras se pagan cuando el vehículo comprado se vende.</div>
                        </div>
                    </div>
                    <div class="table-shell">
                        <table>
                            <thead>
                            <tr>
                                <th>Comercial</th>
                                <th class="num">Entregas</th>
                                <th class="num">Operaciones</th>
                                <th class="num">Ventas</th>
                                <th class="num">Compras</th>
                                <th class="num">Compartidas</th>
                                <th class="num">Descuento 5%</th>
                                <th class="num">Stock +150</th>
                                <th class="num">Bonus +15</th>
                                <th class="num">Prima total</th>
                                <th class="num">Tramo</th>
                                <th class="num">Prima ajustada</th>
                                <th class="num">Reseñas</th>
                                <th class="num">% reseñas</th>
                                <th class="num">% financiación</th>
                                <th class="num">Penalizaciones</th>
                                <th class="num">Prod. financiación</th>
                                <th class="num">Prod. garantías</th>
                                <th class="num">Comisión final</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach ($dashboard['summary_rows'] as $row)
                                <tr>
                                    <th>{{ $row['commercial_name'] }}</th>
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
                </section>

                <section class="card panel">
                    <div class="panel-title">
                        <div>
                            <h2>Detalle auditable</h2>
                            <div class="small">Detalle por comercial para revisar de dónde sale cada concepto.</div>
                        </div>
                    </div>

                    @foreach ($dashboard['summary_rows'] as $row)
                        <article class="card panel" style="margin-bottom:18px;padding:20px;">
                            <div class="panel-title compact">
                                <div>
                                    <h3 style="margin:0;">{{ $row['commercial_name'] }}</h3>
                                    <div class="small">ID {{ $row['commercial_id'] }} · Comisión final {{ number_format($row['final_commission'], 2, ',', '.') }} €</div>
                                </div>
                            </div>

                            <div class="grid" style="grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;">
                                <div class="table-shell">
                                    <table>
                                        <thead>
                                        <tr><th colspan="5">Entregas</th></tr>
                                        <tr>
                                            <th>ID</th>
                                            <th>Oportunidad</th>
                                            <th>Tipo</th>
                                            <th>Fecha</th>
                                            <th class="num">Importe</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        @forelse ($row['details']['deliveries'] as $detail)
                                            <tr>
                                                <td>{{ $detail['opportunity_id'] }}</td>
                                                <td>{{ $detail['opportunity_name'] }}</td>
                                                <td>{{ $detail['record_type_name'] }}</td>
                                                <td>{{ $detail['cv_signed_date'] }}</td>
                                                <td class="num">{{ number_format($detail['amount'], 2, ',', '.') }}</td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="5">Sin entregas.</td></tr>
                                        @endforelse
                                        </tbody>
                                    </table>
                                </div>

                                <div class="table-shell">
                                    <table>
                                        <thead>
                                        <tr><th colspan="7">Compras cobradas este mes</th></tr>
                                        <tr>
                                            <th>Matrícula</th>
                                            <th>Compra</th>
                                            <th>Tipo compra</th>
                                            <th>Fecha compra</th>
                                            <th>Venta posterior</th>
                                            <th class="num">Rentabilidad</th>
                                            <th class="num">Comisión</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        @forelse ($row['details']['purchases'] as $detail)
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

                                <div class="table-shell">
                                    <table>
                                        <thead>
                                        <tr><th colspan="5">Compartidas</th></tr>
                                        <tr>
                                            <th>ID</th>
                                            <th>Oportunidad</th>
                                            <th>Propietario</th>
                                            <th>Fecha</th>
                                            <th class="num">Importe</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        @forelse ($row['details']['shared'] as $detail)
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

                                <div class="table-shell">
                                    <table>
                                        <thead>
                                        <tr><th colspan="6">Stock +150</th></tr>
                                        <tr>
                                            <th>ID</th>
                                            <th>Oportunidad</th>
                                            <th>Matrícula</th>
                                            <th>Fecha entrada</th>
                                            <th class="num">Días</th>
                                            <th class="num">Importe</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        @forelse ($row['details']['stock_150'] as $detail)
                                            <tr>
                                                <td>{{ $detail['opportunity_id'] }}</td>
                                                <td>{{ $detail['opportunity_name'] }}</td>
                                                <td>{{ $detail['vehicle_plate'] }}</td>
                                                <td>{{ $detail['vehicle_entry_date'] }}</td>
                                                <td class="num">{{ number_format($detail['vehicle_days_in_stock'], 0, ',', '.') }}</td>
                                                <td class="num">{{ number_format($detail['amount'], 2, ',', '.') }}</td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="6">Sin vehículos +150.</td></tr>
                                        @endforelse
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </section>
            @endif
        </section>
    </main>
</div>
</body>
</html>
