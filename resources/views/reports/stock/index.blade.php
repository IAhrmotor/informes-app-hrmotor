<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Stock | HR Motor - Informes comerciales</title>
    <link rel="icon" href="/brand/favicon.ico" sizes="any">
    @include('partials.font-assets')
    @vite(['resources/css/reports/leads-dashboard.css', 'resources/css/reports/stock-dashboard.css'])
</head>
<body class="stock-report">
<div class="wrap">
    @include('reports.partials.report-header', [
        'currentReport' => 'stock',
        'subtitle' => 'Stock',
        'updatedBadgeText' => $latestSnapshotDate
            ? 'Fotografía de stock: '.\Carbon\CarbonImmutable::parse($latestSnapshotDate)->format('d/m/Y')
            : 'Sin fotografía diaria',
    ])

    <main>
        <section class="header stock-title">
            <div>
                <div class="eyebrow">Stock y ventas</div>
                <h1>Situación actual del stock</h1>
                <p class="sub">Datos diarios de Product2, ventas firmadas y capacidad comercial por delegación.</p>
            </div>
            <div class="stock-title-meta">
                <span>{{ number_format($saleSnapshotsCount, 0, ',', '.') }} ventas congeladas</span>
                <span>Ventas/stock actual: aproximación</span>
            </div>
        </section>

        <nav class="tabs-main stock-section-nav" aria-label="Secciones de stock">
            <a class="main-tab active" href="#resumen">Resumen</a>
            <a class="main-tab" href="#delegaciones">Delegaciones</a>
            @if ($isAdmin)
                <a class="main-tab" href="#capacidades">Capacidades</a>
            @endif
            <a class="main-tab" href="#calidad">Calidad del dato</a>
        </nav>

        @if (session('status'))
            <div class="notice notice-success">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="notice">{{ $errors->first() }}</div>
        @endif

        <section id="resumen" class="stock-section">
            <div class="stock-kpis">
                @foreach ([
                    ['Stock total', $summary['total'], 'vehículos'],
                    ['Disponibles', $summary['available'], 'comercializables'],
                    ['Reservados', $summary['reserved'], 'ocupan plaza'],
                    ['Bloqueados', $summary['blocked'], 'ocupan plaza'],
                    ['Valor de compra', number_format($summary['purchase_value'], 0, ',', '.').' €', 'stock actual'],
                    ['Valor de venta', number_format($summary['sale_value'], 0, ',', '.').' €', 'precio actual'],
                    ['Margen bruto potencial', number_format($summary['potential_margin'], 0, ',', '.').' €', 'antes de costes Sage'],
                    ['Antigüedad media', $summary['average_age'] !== null ? number_format($summary['average_age'], 1, ',', '.').' días' : '—', 'stock con fecha de entrada'],
                ] as [$label, $value, $hint])
                    <article class="card stock-kpi">
                        <span>{{ $label }}</span>
                        <strong>{{ is_numeric($value) ? number_format($value, 0, ',', '.') : $value }}</strong>
                        <small>{{ $hint }}</small>
                    </article>
                @endforeach
            </div>

            <div class="stock-age-grid">
                @foreach ([60, 90, 120, 180] as $days)
                    <article class="card stock-age-card @if($days >= 90) priority @endif">
                        <span>Desde {{ $days }} días</span>
                        <strong>{{ number_format($summary["over_$days"], 0, ',', '.') }}</strong>
                        <small>{{ $days === 60 ? 'Candidatos a revisión' : ($days === 90 ? 'Revisión prioritaria' : 'Stock envejecido') }}</small>
                    </article>
                @endforeach
            </div>
        </section>

        <section id="delegaciones" class="card panel stock-section">
            <div class="panel-title">
                <div>
                    <h2>Stock y capacidad por delegación</h2>
                    <div class="small">Disponible, reservado y bloqueado ocupan una plaza cada uno.</div>
                </div>
            </div>
            <div class="table-scroll">
                <table class="stock-table">
                    <thead>
                    <tr>
                        <th>Delegación</th>
                        <th>Zona</th>
                        <th>Stock</th>
                        <th>Disponibles</th>
                        <th>Reservados</th>
                        <th>Bloqueados</th>
                        <th>Capacidad</th>
                        <th>Plazas libres</th>
                        <th>Ocupación</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($delegations as $row)
                        <tr>
                            <td>
                                <strong>{{ $row['model']->canonical_name }}</strong>
                                @unless ($row['model']->is_commercial)
                                    <span class="stock-tag muted">Ubicación no comercial</span>
                                @endunless
                            </td>
                            <td>{{ $row['model']->zone ?: '—' }}</td>
                            <td>{{ $row['total'] }}</td>
                            <td>{{ $row['available'] }}</td>
                            <td>{{ $row['reserved'] }}</td>
                            <td>{{ $row['blocked'] }}</td>
                            <td>{{ $row['model']->capacity_total ?? '—' }}</td>
                            <td @class(['negative' => $row['free_capacity'] !== null && $row['free_capacity'] < 0])>
                                {{ $row['free_capacity'] ?? '—' }}
                            </td>
                            <td>
                                @if ($row['occupancy'] !== null)
                                    <span @class(['stock-tag', 'danger' => $row['occupancy'] > 100])>{{ number_format($row['occupancy'], 1, ',', '.') }}%</span>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9">Todavía no hay stock sincronizado.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        @if ($isAdmin)
            <section id="capacidades" class="stock-section">
                <div class="stock-capacity-layout">
                    <article class="card panel">
                        <div class="panel-title">
                            <div>
                                <h2>Importar capacidades</h2>
                                <div class="small">Se utilizará exclusivamente la columna “Plazas totales”. Admite XLSX y CSV.</div>
                            </div>
                        </div>
                        <form method="POST" action="{{ route('reports.stock.capacities.import') }}" enctype="multipart/form-data" class="stock-upload-form">
                            @csrf
                            <div class="filter-group">
                                <label for="capacity_file">Archivo de capacidades</label>
                                <input id="capacity_file" type="file" name="capacity_file" accept=".xlsx,.csv,.txt" required>
                            </div>
                            <div class="filter-group">
                                <label for="delimiter">Separador CSV</label>
                                <select id="delimiter" name="delimiter">
                                    <option value=",">Coma</option>
                                    <option value=";">Punto y coma</option>
                                </select>
                            </div>
                            <button type="submit" class="main-tab active">Subir y aplicar archivo</button>
                        </form>
                    </article>

                    <article class="card panel stock-capacity-help">
                        <h2>Criterio aplicado</h2>
                        <ul>
                            <li>Las tiendas incluidas en el archivo pasan a ser delegaciones comerciales.</li>
                            <li>Las ubicaciones sin capacidad quedan fuera de recomendaciones.</li>
                            <li>Una nueva importación sustituye el listado de capacidades anterior.</li>
                            <li>La edición manual permite corregir una tienda sin volver a subir el Excel.</li>
                        </ul>
                    </article>
                </div>

                <article class="card panel">
                    <div class="panel-title">
                        <div>
                            <h2>Edición manual de capacidades</h2>
                            <div class="small">Deja la capacidad vacía para clasificar la ubicación como no comercial.</div>
                        </div>
                    </div>
                    <form method="POST" action="{{ route('reports.stock.capacities.update') }}">
                        @csrf
                        @method('PUT')
                        <div class="table-scroll">
                            <table class="stock-table stock-capacity-table">
                                <thead>
                                <tr>
                                    <th>Delegación normalizada</th>
                                    <th>Nombre Salesforce</th>
                                    <th>Origen capacidad</th>
                                    <th>Plazas totales</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach ($capacityDelegations as $delegation)
                                    <tr>
                                        <td>
                                            <strong>{{ $delegation->canonical_name }}</strong>
                                            @if ($delegation->is_commercial)
                                                <span class="stock-tag">Comercial</span>
                                            @endif
                                        </td>
                                        <td>{{ $delegation->salesforce_name ?: '—' }}</td>
                                        <td>{{ $delegation->capacity_source_name ?: 'Sin capacidad' }}</td>
                                        <td>
                                            <input
                                                type="number"
                                                min="0"
                                                max="10000"
                                                name="capacities[{{ $delegation->id }}]"
                                                value="{{ old('capacities.'.$delegation->id, $delegation->capacity_total) }}"
                                                aria-label="Plazas totales de {{ $delegation->canonical_name }}"
                                            >
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                        <div class="stock-form-actions">
                            <button type="submit" class="main-tab active">Guardar capacidades</button>
                        </div>
                    </form>
                </article>
            </section>
        @endif

        <section id="calidad" class="card panel stock-section">
            <div class="panel-title">
                <div>
                    <h2>Calidad del dato</h2>
                    <div class="small">Registros que requieren revisión en Salesforce.</div>
                </div>
            </div>
            <div class="stock-quality-grid">
                @foreach ([
                    ['Stock sin fecha de entrada', $quality['stock_missing_entry_date']],
                    ['Stock sin delegación', $quality['stock_missing_delegation']],
                    ['Stock sin segmento', $quality['stock_missing_segment']],
                    ['Stock sin combustible', $quality['stock_missing_fuel']],
                    ['Ventas sin fecha de firma', $quality['sales_missing_signed_date']],
                    ['Ventas sin tienda de entrega', $quality['sales_missing_delivery_store']],
                    ['Ventas sin fecha de entrada', $quality['sales_missing_entry_date']],
                    ['Ventas sin precio contractual', $quality['sales_missing_price']],
                ] as [$label, $value])
                    <article>
                        <span>{{ $label }}</span>
                        <strong @class(['negative' => $value > 0])>{{ number_format($value, 0, ',', '.') }}</strong>
                    </article>
                @endforeach
            </div>
        </section>
    </main>
</div>
</body>
</html>
