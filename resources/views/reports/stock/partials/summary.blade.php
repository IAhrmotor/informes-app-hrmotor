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
        ] as [$label, $value, $hint])
            <article class="card stock-kpi">
                <span>{{ $label }}</span>
                <strong>{{ $value }}</strong>
                <small>{{ $hint }}</small>
            </article>
        @endforeach
    </div>

    <div class="stock-age-grid">
        @foreach ([
            ['Menos de 60 días', 'age_under_60', 'Stock reciente', false],
            ['60–89 días', 'age_60_90', 'Candidatos a revisión', false],
            ['90–119 días', 'age_90_120', 'Revisión prioritaria', true],
            ['120–180 días', 'age_120_180', 'Stock envejecido', true],
            ['Más de 180 días', 'age_over_180', 'Stock crítico', true],
            ['Sin fecha de entrada', 'age_unknown', 'Requiere corregir el dato', true],
        ] as [$label, $key, $hint, $priority])
            <article @class(['card', 'stock-age-card', 'priority' => $priority])>
                <span>{{ $label }}</span>
                <strong>{{ number_format($summary[$key], 0, ',', '.') }}</strong>
                <small>{{ $hint }}</small>
            </article>
        @endforeach
    </div>
    <div class="stock-age-reconciliation">Total por tramos: <strong>{{ number_format($summary['age_bucket_total'], 0, ',', '.') }}</strong> · Stock total: <strong>{{ number_format($summary['total'], 0, ',', '.') }}</strong></div>

    <div class="stock-summary-stack">
        <details id="stock-history-panel" class="card panel stock-collapsible-panel" @if($stockHistory['days'] >= 15) open @endif>
            <summary>
                <div>
                    <h2>Evolución diaria del stock</h2>
                    <div class="small">{{ $stockHistory['days'] }} de {{ $stockHistory['expected_days'] }} días disponibles · cobertura {{ number_format($stockHistory['coverage'], 1, ',', '.') }}%</div>
                </div>
                <div class="stock-collapsible-actions">
                    @unless ($stockHistory['sufficient'])
                        <span class="stock-tag warning">Histórico insuficiente</span>
                    @endunless
                    <span class="secondary-button stock-toggle-open">Mostrar</span>
                    <span class="secondary-button stock-toggle-close">Ocultar</span>
                </div>
            </summary>
            <div class="stock-collapsible-content">
            @if ($stockHistory['series']->isEmpty())
                <div class="stock-empty">Todavía no hay fotografías dentro del periodo seleccionado.</div>
            @else
                @php
                    $chartRows = $stockHistory['series']->values();
                    $chartCount = $chartRows->count();
                    $chartMax = max((int) $chartRows->max(fn($point) => max($point['available'], $point['reserved'], $point['blocked'])), 1);
                    $chartPoints = function (string $key) use ($chartRows, $chartCount, $chartMax): string {
                        return $chartRows->map(function ($point, $index) use ($key, $chartCount, $chartMax): string {
                            $x = $chartCount > 1 ? ($index / ($chartCount - 1)) * 100 : 50;
                            $y = 92 - (((int) $point[$key] / $chartMax) * 84);
                            return number_format($x, 2, '.', '').','.number_format($y, 2, '.', '');
                        })->implode(' ');
                    };
                @endphp
                <div class="campaign-evolution campaign-evolution-lines stock-line-chart-wrap">
                    <div class="campaign-line-chart stock-line-chart">
                        <svg viewBox="-0.75 0 101.5 96" preserveAspectRatio="none" role="img" aria-label="Evolución diaria de disponibles, reservados y bloqueados">
                            @foreach ([8, 29, 50, 71, 92] as $y)
                                <line class="line-grid" x1="0" x2="100" y1="{{ $y }}" y2="{{ $y }}"></line>
                            @endforeach
                            <line class="line-axis-frame is-bottom" x1="0" x2="100" y1="92" y2="92"></line>
                            <polyline class="line-series stock-available-line" points="{{ $chartPoints('available') }}"></polyline>
                            <polyline class="line-series stock-reserved-line" points="{{ $chartPoints('reserved') }}"></polyline>
                            <polyline class="line-series stock-blocked-line" points="{{ $chartPoints('blocked') }}"></polyline>
                        </svg>
                        <div class="stock-line-hover-layer">
                            @foreach ($chartRows as $index => $point)
                                @php
                                    $x = $chartCount > 1 ? ($index / ($chartCount - 1)) * 100 : 50;
                                @endphp
                                <span class="stock-line-hover" style="left: {{ $x }}%" title="{{ \Carbon\CarbonImmutable::parse($point['date'])->format('d/m/Y') }} · {{ $point['available'] }} disponibles · {{ $point['reserved'] }} reservados · {{ $point['blocked'] }} bloqueados"></span>
                            @endforeach
                        </div>
                    </div>
                    <div class="stock-line-labels">
                        @foreach ($chartRows as $index => $point)
                            @if($index === 0 || $index === $chartCount - 1 || $index % max((int) ceil($chartCount / 8), 1) === 0)
                                <time style="left: {{ $chartCount > 1 ? ($index / ($chartCount - 1)) * 100 : 50 }}%">{{ \Carbon\CarbonImmutable::parse($point['date'])->format('d/m') }}</time>
                            @endif
                        @endforeach
                    </div>
                </div>
                <div class="stock-legend">
                    <span><i class="available"></i>Disponible</span>
                    <span><i class="reserved"></i>Reservado</span>
                    <span><i class="blocked"></i>Bloqueado</span>
                </div>
            @endif
            </div>
        </details>

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
                        @php
                            $maxDistribution = max((int) collect($distributions[$key])->max('value'), 1);
                        @endphp
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

    @php
        $overCapacityDelegations = $capacityAlertRows->filter(fn($row) => $row['is_commercial'] && $row['occupancy'] !== null && $row['occupancy'] > 100);
        $underCapacityDelegations = $capacityAlertRows->filter(fn($row) => $row['is_commercial'] && $row['occupancy'] !== null && $row['occupancy'] < 80);
        $capacityAlerts = $overCapacityDelegations->concat($underCapacityDelegations);
    @endphp
    <article class="card panel stock-alert-panel">
        <div class="panel-title">
            <div>
                <h2>Alertas de stock comercial</h2>
                <div class="small">Delegaciones con ocupación superior al 100% o inferior al 80% de su capacidad informada.</div>
            </div>
            <span @class(['stock-tag','danger'=>$capacityAlerts->isNotEmpty()])>{{ $capacityAlerts->count() }} alertas de capacidad</span>
        </div>
        @if($capacityAlerts->isEmpty())
            <div class="stock-empty">Todas las delegaciones están entre el 80% y el 100% de ocupación.</div>
        @else
            <div id="stock-capacity-alerts" class="stock-alert-list" data-expandable-list>
                @foreach($capacityAlerts as $index => $row)
                    @php($isOverCapacity = $row['occupancy'] > 100)
                    <div @class(['stock-alert-under' => !$isOverCapacity, 'stock-expandable-extra' => $index >= 9])>
                        <strong>{{ $row['model']->canonical_name }} · {{ $isOverCapacity ? 'Sobre capacidad' : 'Baja ocupación' }}</strong>
                        <span>{{ number_format($row['occupancy'], 1, ',', '.') }}% ocupado · {{ $isOverCapacity ? abs($row['free_capacity']).' plazas de exceso' : $row['free_capacity'].' plazas libres' }}</span>
                    </div>
                @endforeach
            </div>
            @if($capacityAlerts->count() > 9)
                <button type="button" class="secondary-button stock-expand-list-button" data-expand-list="stock-capacity-alerts" data-show-label="Mostrar todas ({{ $capacityAlerts->count() }})" data-hide-label="Mostrar solo 9">Mostrar todas ({{ $capacityAlerts->count() }})</button>
            @endif
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
