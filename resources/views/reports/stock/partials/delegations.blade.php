<section class="card panel stock-section">
    <div class="panel-title">
        <div>
            <h2>Stock, capacidad y ventas por delegación</h2>
            <div class="small">Ventas y rotación corresponden al periodo seleccionado. Ventas/stock es aproximado hasta completar el histórico diario.</div>
        </div>
    </div>
    <div class="table-scroll">
        <table class="stock-table stock-wide-table" data-sortable-table>
            <thead><tr>
                @foreach ([
                    ['Delegación','text'],['Zona','text'],['Stock','number'],['Disp.','number'],['Reserv.','number'],['Bloq.','number'],
                    ['Capacidad','number'],['Libres','number'],['Ocupación','number'],['Compra','number'],['Venta','number'],
                    ['Precio medio','number'],['Antigüedad','number'],['Rotación','number'],['Ventas','number'],['Ventas/stock','number'],
                    ['+60','number'],['+90','number'],['+120','number'],['+180','number'],
                ] as $index => [$label,$type])
                    <th aria-sort="none"><button type="button" class="stock-sort-button" data-sort-index="{{ $index }}" data-sort-type="{{ $type }}">{{ $label }} <span aria-hidden="true">↕</span></button></th>
                @endforeach
            </tr></thead>
            <tbody>
            @forelse ($delegationRows as $row)
                <tr data-commercial="{{ $row['is_commercial'] ? 1 : 0 }}" @class(['stock-zero-row'=>$row['is_commercial']&&$row['available']===0])>
                    <td data-sort-value="{{ $row['model']->canonical_name }}"><strong>{{ $row['model']->canonical_name }}</strong>@unless($row['is_commercial']) <span class="stock-tag muted">Ubicación no comercial</span>@else @if($row['available']===0)<span class="stock-tag danger">Sin disponibles</span>@endif @endunless</td>
                    <td data-sort-value="{{ $row['model']->zone }}">{{ $row['model']->zone ?: '—' }}</td>
                    <td data-sort-value="{{ $row['total'] }}">{{ $row['total'] }}</td>
                    <td data-sort-value="{{ $row['available'] }}">{{ $row['available'] }}</td>
                    <td data-sort-value="{{ $row['reserved'] }}">{{ $row['reserved'] }}</td>
                    <td data-sort-value="{{ $row['blocked'] }}">{{ $row['blocked'] }}</td>
                    <td data-sort-value="{{ $row['model']->capacity_total }}">{{ $row['model']->capacity_total ?? '—' }}</td>
                    <td data-sort-value="{{ $row['free_capacity'] }}" @class(['negative' => $row['free_capacity'] !== null && $row['free_capacity'] < 0])>{{ $row['free_capacity'] ?? '—' }}</td>
                    <td data-sort-value="{{ $row['occupancy'] }}">@if($row['occupancy'] !== null)<span @class(['stock-tag','danger'=>$row['occupancy']>100])>{{ number_format($row['occupancy'],1,',','.') }}%</span>@else—@endif</td>
                    <td data-sort-value="{{ $row['purchase_value'] }}">{{ number_format($row['purchase_value'],0,',','.') }} €</td>
                    <td data-sort-value="{{ $row['sale_value'] }}">{{ number_format($row['sale_value'],0,',','.') }} €</td>
                    <td data-sort-value="{{ $row['average_price'] }}">{{ $row['average_price'] !== null ? number_format($row['average_price'],0,',','.').' €' : '—' }}</td>
                    <td data-sort-value="{{ $row['average_age'] }}">{{ $row['average_age'] !== null ? number_format($row['average_age'],1,',','.').' d' : '—' }}</td>
                    <td data-sort-value="{{ $row['average_rotation'] }}">{{ $row['average_rotation'] !== null ? number_format($row['average_rotation'],1,',','.').' d' : '—' }}</td>
                    <td data-sort-value="{{ $row['sales'] }}"><strong>{{ $row['sales'] }}</strong></td>
                    <td data-sort-value="{{ $row['sales_per_stock'] }}">{{ $row['sales_per_stock'] !== null ? number_format($row['sales_per_stock'],2,',','.') : '—' }}</td>
                    <td data-sort-value="{{ $row['over_60'] }}">{{ $row['over_60'] }}</td>
                    <td data-sort-value="{{ $row['over_90'] }}">{{ $row['over_90'] }}</td>
                    <td data-sort-value="{{ $row['over_120'] }}">{{ $row['over_120'] }}</td>
                    <td data-sort-value="{{ $row['over_180'] }}">{{ $row['over_180'] }}</td>
                </tr>
            @empty
                <tr><td colspan="20">No hay delegaciones para los filtros seleccionados.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</section>

<section class="stock-distribution-grid">
    @foreach ([
        'brand'=>'Marcas en stock','model'=>'Modelos en stock','segment'=>'Segmentos','fuel'=>'Combustibles','body'=>'Carrocerías','price_band'=>'Tramos de precio'
    ] as $key=>$label)
        <article class="card panel stock-mini-chart">
            <h2>{{ $label }}</h2>
            @php($maxValue = max((int) collect($distributions[$key])->max('value'), 1))
            @forelse($distributions[$key] as $row)
                <div><span title="{{ $row['label'] }}">{{ $row['label'] }}</span><i><b style="width: {{ ($row['value']/$maxValue)*100 }}%"></b></i><strong>{{ $row['value'] }}</strong></div>
            @empty
                <div class="stock-empty">Sin datos.</div>
            @endforelse
        </article>
    @endforeach
</section>
