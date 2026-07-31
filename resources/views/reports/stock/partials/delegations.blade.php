<section class="card panel stock-section">
    <div class="panel-title">
        <div>
            <h2>Stock, capacidad y ventas por delegación</h2>
            <div class="small">Haz clic en una delegación para filtrar conjuntamente su stock, ventas y rankings. Ventas y rotación corresponden al periodo seleccionado.</div>
        </div>
        @php
            $delegationColumns = [
                ['delegation', 'Delegación', 'text'], ['zone', 'Zona', 'text'], ['total', 'Stock', 'number'],
                ['available', 'Disp.', 'number'], ['reserved', 'Reserv.', 'number'], ['blocked', 'Bloq.', 'number'],
                ['capacity', 'Capacidad', 'number'], ['free_capacity', 'Libres', 'number'], ['occupancy', 'Ocupación', 'number'],
                ['purchase_value', 'Compra', 'number'], ['sale_value', 'Venta', 'number'], ['average_price', 'Precio medio', 'number'],
                ['average_age', 'Antigüedad', 'number'], ['average_rotation', 'Rotación', 'number'], ['sales', 'Ventas', 'number'],
                ['sales_per_stock', 'Ventas/Stock', 'number'], ['age_under_60', '<60', 'number'], ['age_60_90', '60–89', 'number'],
                ['age_90_120', '90–119', 'number'], ['age_120_180', '120–180', 'number'], ['age_over_180', '>180', 'number'],
                ['age_unknown', 'Sin fecha', 'number'],
            ];
            $hiddenDelegationColumns = ['purchase_value', 'sale_value', 'sales_per_stock', 'age_under_60', 'age_60_90', 'age_90_120', 'age_120_180', 'age_over_180', 'age_unknown'];
        @endphp
        <details class="stock-column-picker">
            <summary class="secondary-button">Añadir/quitar columnas</summary>
            <div>
                @foreach($delegationColumns as [$key, $label])
                    <label><input type="checkbox" data-column-toggle data-table-target="stock-delegations-table" value="{{ $key }}" @checked(!in_array($key, $hiddenDelegationColumns, true))> {{ $label }}</label>
                @endforeach
            </div>
        </details>
    </div>
    <div class="stock-scroll-region" data-stock-scroll-region>
        <div class="stock-scrollbar-top" data-stock-scroll-top aria-label="Desplazamiento horizontal superior"><div data-stock-scroll-spacer></div></div>
        <div class="table-scroll stock-overflow-table" data-stock-scroll-body>
        <table id="stock-delegations-table" class="stock-table stock-wide-table" data-sortable-table>
            <thead><tr>
                @foreach ($delegationColumns as $index => [$key,$label,$type])
                    <th data-column-key="{{ $key }}" @class(['stock-column-hidden' => in_array($key, $hiddenDelegationColumns, true)]) aria-sort="none"><button type="button" class="stock-sort-button" data-sort-index="{{ $index }}" data-sort-type="{{ $type }}">{{ $label }} <span aria-hidden="true">↕</span></button></th>
                @endforeach
            </tr></thead>
            <tbody>
            @forelse ($delegationRows as $row)
                <tr data-commercial="{{ $row['is_commercial'] ? 1 : 0 }}" @class(['stock-zero-row'=>$row['is_commercial']&&$row['available']===0])>
                    <td data-column-key="delegation" data-sort-value="{{ $row['model']->canonical_name }}"><strong><a class="stock-delegation-link" href="{{ route('reports.stock.index', array_merge(request()->except(['delegation', 'recommendation_page']), ['section'=>'delegations', 'delegation'=>$row['model']->canonical_name])) }}">{{ $row['model']->canonical_name }}</a></strong>@unless($row['is_commercial']) <span class="stock-tag muted">Ubicación no comercial</span>@else @if($row['available']===0)<span class="stock-tag danger">Sin disponibles</span>@endif @endunless</td>
                    <td data-column-key="zone" data-sort-value="{{ $row['model']->zone }}">{{ $row['model']->zone ?: '—' }}</td>
                    @foreach([
                        'total' => $row['total'], 'available' => $row['available'], 'reserved' => $row['reserved'], 'blocked' => $row['blocked'],
                        'capacity' => $row['model']->capacity_total, 'free_capacity' => $row['free_capacity'], 'occupancy' => $row['occupancy'],
                        'purchase_value' => $row['purchase_value'], 'sale_value' => $row['sale_value'], 'average_price' => $row['average_price'],
                        'average_age' => $row['average_age'], 'average_rotation' => $row['average_rotation'], 'sales' => $row['sales'],
                        'sales_per_stock' => $row['sales_per_stock'], 'age_under_60' => $row['age_under_60'], 'age_60_90' => $row['age_60_90'],
                        'age_90_120' => $row['age_90_120'], 'age_120_180' => $row['age_120_180'], 'age_over_180' => $row['age_over_180'], 'age_unknown' => $row['age_unknown'],
                    ] as $key => $value)
                        <td data-column-key="{{ $key }}" data-sort-value="{{ $value }}" @class(['stock-column-hidden' => in_array($key, $hiddenDelegationColumns, true), 'negative' => $key === 'free_capacity' && $value !== null && $value < 0])>
                            @if($key === 'occupancy')
                                @if($value !== null)<span @class(['stock-tag','danger'=>$value>100])>{{ number_format($value,1,',','.') }}%</span>@else — @endif
                            @elseif(in_array($key, ['purchase_value', 'sale_value', 'average_price'], true))
                                {{ $value !== null ? number_format($value, 0, ',', '.').' €' : '—' }}
                            @elseif(in_array($key, ['average_age', 'average_rotation'], true))
                                {{ $value !== null ? number_format($value, 1, ',', '.').' d' : '—' }}
                            @elseif($key === 'sales')
                                <strong>{{ $value }}</strong>
                            @elseif($key === 'sales_per_stock')
                                {{ $value !== null ? number_format($value, 2, ',', '.') : '—' }}
                            @else
                                {{ $value ?? '—' }}
                            @endif
                        </td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="22">No hay delegaciones para los filtros seleccionados.</td></tr>
            @endforelse
            </tbody>
        </table>
        </div>
    </div>
</section>

<section class="stock-distribution-grid">
    @foreach ([
        'brand'=>'Marcas en stock','model'=>'Modelos en stock','segment'=>'Segmentos','fuel'=>'Combustibles','body'=>'Carrocerías','price_band'=>'Tramos de precio'
    ] as $key=>$label)
        <article class="card panel stock-mini-chart">
            <h2>{{ $label }}</h2>
            @php
                $maxValue = max((int) collect($distributions[$key])->max('value'), 1);
            @endphp
            <div id="stock-distribution-{{ $key }}" class="stock-mini-chart-list" data-expandable-list>
            @forelse($distributions[$key] as $index => $row)
                <div @class(['stock-expandable-extra' => $index >= 10])><span title="{{ $row['label'] }}">{{ $row['label'] }}</span><i><b style="width: {{ ($row['value']/$maxValue)*100 }}%"></b></i><strong>{{ $row['value'] }}</strong></div>
            @empty
                <div class="stock-empty">Sin datos.</div>
            @endforelse
            </div>
            @if(count($distributions[$key]) > 10)
                <button type="button" class="secondary-button stock-expand-list-button" data-expand-list="stock-distribution-{{ $key }}" data-show-label="Mostrar todos ({{ count($distributions[$key]) }})" data-hide-label="Mostrar solo 10">Mostrar todos ({{ count($distributions[$key]) }})</button>
            @endif
        </article>
    @endforeach
</section>
