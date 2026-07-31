<section class="stock-section">
    <div class="stock-kpis">
        @foreach ([
            ['Stock total', number_format($summary['total'], 0, ',', '.'), 'vehículos'],
            ['Disponibles', number_format($summary['available'], 0, ',', '.'), 'stock comercial'],
            ['Reservados', number_format($summary['reserved'], 0, ',', '.'), 'ocupan plaza'],
            ['Bloqueados', number_format($summary['blocked'], 0, ',', '.'), 'ocupan plaza'],
            ['Valor de compra', number_format($summary['purchase_value'], 0, ',', '.').' €', 'stock actual'],
            ['Valor de venta', number_format($summary['sale_value'], 0, ',', '.').' €', 'precio actual'],
            ['Margen bruto potencial', number_format($summary['potential_margin'], 0, ',', '.').' €', 'antes de costes Sage'],
            ['Precio medio compra', number_format($summary['average_purchase_price'], 0, ',', '.').' €', 'stock filtrado'],
            ['Precio medio venta', number_format($summary['average_sale_price'], 0, ',', '.').' €', 'stock filtrado'],
            ['Antigüedad media', $summary['average_age'] !== null ? number_format($summary['average_age'], 1, ',', '.').' días' : '—', 'hoy − fecha de entrada'],
            ['Rotación media', $summary['average_rotation'] !== null ? number_format($summary['average_rotation'], 1, ',', '.').' días' : '—', 'firma − entrada'],
            ['Ventas del periodo', number_format($summary['sales'], 0, ',', '.'), \Carbon\CarbonImmutable::parse($filters['date_from'])->format('d/m/Y').'–'.\Carbon\CarbonImmutable::parse($filters['date_to'])->format('d/m/Y')],
            ['Ventas por stock', $summary['sales_stock_ratio'] !== null ? number_format($summary['sales_stock_ratio'], 2, ',', '.') : '—', $summary['sales_stock_approximate'] ? 'aprox. con stock actual' : 'con stock medio disponible'],
        ] as [$label, $value, $hint])
            <article class="card stock-kpi">
                <span>{{ $label }}</span>
                <strong>{{ $value }}</strong>
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

    <div class="stock-summary-stack">
        <article class="card panel">
            <div class="panel-title">
                <div>
                    <h2>Evolución diaria del stock</h2>
                    <div class="small">{{ $stockHistory['days'] }} de {{ $stockHistory['expected_days'] }} días disponibles · cobertura {{ number_format($stockHistory['coverage'], 1, ',', '.') }}%</div>
                </div>
                @unless ($stockHistory['sufficient'])
                    <span class="stock-tag warning">Histórico insuficiente</span>
                @endunless
            </div>
            @if ($stockHistory['series']->isEmpty())
                <div class="stock-empty">Todavía no hay fotografías dentro del periodo seleccionado.</div>
            @else
                @php($seriesMax = max((int) $stockHistory['series']->max('total'), 1))
                <div class="stock-evolution">
                    @foreach ($stockHistory['series'] as $point)
                        <div class="stock-evolution-row">
                            <time>{{ \Carbon\CarbonImmutable::parse($point['date'])->format('d/m') }}</time>
                            <div class="stock-stacked-bar" title="{{ $point['total'] }} vehículos">
                                <span class="available" style="width: {{ ($point['available'] / $seriesMax) * 100 }}%"></span>
                                <span class="reserved" style="width: {{ ($point['reserved'] / $seriesMax) * 100 }}%"></span>
                                <span class="blocked" style="width: {{ ($point['blocked'] / $seriesMax) * 100 }}%"></span>
                            </div>
                            <strong>{{ $point['total'] }}</strong>
                        </div>
                    @endforeach
                </div>
                <div class="stock-legend">
                    <span><i class="available"></i>Disponible</span>
                    <span><i class="reserved"></i>Reservado</span>
                    <span><i class="blocked"></i>Bloqueado</span>
                </div>
            @endif
        </article>

        <article class="card panel">
            <div class="panel-title">
                <div>
                    <h2>Distribución del stock</h2>
                    <div class="small">Top 10 sobre los filtros actuales.</div>
                </div>
            </div>
            <div class="stock-distribution-tabs">
                @foreach (['brand' => 'Marca', 'segment' => 'Segmento', 'fuel' => 'Combustible', 'price_band' => 'Precio'] as $key => $label)
                    <div class="stock-mini-chart">
                        <h3>{{ $label }}</h3>
                        @php($maxDistribution = max((int) collect($distributions[$key])->max('value'), 1))
                        @foreach ($distributions[$key] as $row)
                            <div>
                                <span title="{{ $row['label'] }}">{{ $row['label'] }}</span>
                                <i><b style="width: {{ ($row['value'] / $maxDistribution) * 100 }}%"></b></i>
                                <strong>{{ $row['value'] }}</strong>
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </article>
    </div>

    @php($zeroAvailableDelegations = $delegationRows->filter(fn($row) => $row['is_commercial'] && $row['model']->capacity_total !== null && $row['available'] === 0))
    <article class="card panel stock-alert-panel">
        <div class="panel-title">
            <div>
                <h2>Alertas de stock comercial</h2>
                <div class="small">Una tienda entra en alerta cuando tiene cero vehículos disponibles, aunque conserve reservados o bloqueados.</div>
            </div>
            <span @class(['stock-tag','danger'=>$zeroAvailableDelegations->isNotEmpty()])>{{ $zeroAvailableDelegations->count() }} tiendas sin disponibles</span>
        </div>
        @if($zeroAvailableDelegations->isEmpty())
            <div class="stock-empty">Todas las delegaciones con capacidad tienen al menos un vehículo disponible.</div>
        @else
            <div class="stock-alert-list">
                @foreach($zeroAvailableDelegations as $row)
                    <div><strong>{{ $row['model']->canonical_name }}</strong><span>{{ $row['reserved'] }} reservados · {{ $row['blocked'] }} bloqueados · {{ $row['free_capacity'] }} plazas libres</span></div>
                @endforeach
            </div>
        @endif
    </article>

    <article id="calidad" class="card panel stock-quality-panel">
        <div class="panel-title">
            <div>
                <h2>Calidad del dato</h2>
                <div class="small">Registros que requieren revisión en Salesforce.</div>
            </div>
            <a class="main-tab active stock-export-button" href="{{ route('reports.stock.export.quality') }}">Exportar incidencias a Excel</a>
        </div>
        <div class="stock-quality-grid">
            @foreach ([
                ['Stock sin fecha de entrada', $quality['stock_missing_entry_date']],
                ['Stock sin delegación', $quality['stock_missing_delegation']],
                ['Stock sin marca', $quality['stock_missing_brand']],
                ['Stock sin modelo', $quality['stock_missing_model']],
                ['Stock sin segmento', $quality['stock_missing_segment']],
                ['Stock sin combustible', $quality['stock_missing_fuel']],
                ['Vehículos vendidos aún en stock', $quality['stock_delivered']],
                ['Stock comercial sin zona', $quality['stock_commercial_without_zone']],
                ['Ventas sin fecha de firma', $quality['sales_missing_signed_date']],
                ['Ventas sin tienda de entrega', $quality['sales_missing_delivery_store']],
                ['Ventas sin fecha de entrada', $quality['sales_missing_entry_date']],
                ['Ventas sin precio contractual', $quality['sales_missing_price']],
                ['Fecha de firma sin contrato firmado', $quality['signed_date_without_contract']],
                ['Contrato firmado en Cerrada perdida', $quality['signed_closed_lost']],
                ['Ventas válidas duplicadas por vehículo', $quality['duplicate_valid_vehicle']],
                ['Contratos firmados en fases inesperadas', $quality['signed_unexpected_stage']],
                ['Vehículos con fecha de entrada futura', $quality['future_entry_date']],
                ['Tiendas sin capacidad válida', $quality['stores_without_capacity']],
                ['Valores de catálogo duplicados o sin normalizar', $quality['catalog_duplicates']],
                ['Vehículos con valores de prueba, formación o fuera de stock', $quality['non_operational_catalog_values']],
            ] as [$label, $value])
                <article>
                    <span>{{ $label }}</span>
                    <strong @class(['negative' => $value > 0])>{{ number_format($value, 0, ',', '.') }}</strong>
                </article>
            @endforeach
        </div>
    </article>
</section>
