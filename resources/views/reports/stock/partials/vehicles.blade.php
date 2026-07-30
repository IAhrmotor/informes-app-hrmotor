<section class="card panel stock-section">
    <div class="panel-title">
        <div>
            <h2>Detalle de vehículos</h2>
            <div class="small">Mostrando {{ number_format($detailRows->count(),0,',','.') }} de {{ number_format($detailTotal,0,',','.') }} vehículos. Usa los filtros para localizar cualquier unidad.</div>
        </div>
    </div>
    <div class="stock-scroll-region" data-stock-scroll-region>
        <div class="stock-scrollbar-top" data-stock-scroll-top aria-label="Desplazamiento horizontal superior"><div data-stock-scroll-spacer></div></div>
        <div class="table-scroll stock-overflow-table" data-stock-scroll-body>
        <table class="stock-table stock-detail-table" data-sortable-table>
            <thead><tr>
                @foreach ([
                    ['ID / matrícula','text'],['Vehículo','text'],['Delegación','text'],['Estado','text'],['Entrada','text'],['Días','number'],
                    ['Compra','number'],['Venta','number'],['Margen bruto','number'],['Segmento','text'],['Combustible','text'],['Km','number'],
                    ['Responsable compra','text'],['Procedencia','text'],['Delegaciones recomendadas','text'],
                ] as $index=>[$label,$type])
                    <th aria-sort="none"><button type="button" class="stock-sort-button" data-sort-index="{{ $index }}" data-sort-type="{{ $type }}">{{ $label }} <span>↕</span></button></th>
                @endforeach
            </tr></thead>
            <tbody>
            @forelse($detailRows as $row)
                <tr data-commercial="1">
                    <td data-sort-value="{{ $row['plate'] }}"><strong>{{ $row['plate'] ?: 'Sin matrícula' }}</strong><small>{{ $row['id'] }}</small></td>
                    <td data-sort-value="{{ $row['brand'].' '.$row['model'] }}"><strong>{{ trim(($row['brand'] ?? '').' '.($row['model'] ?? '')) ?: 'Sin normalizar' }}</strong><small>{{ $row['version'] ?: '—' }}</small></td>
                    <td data-sort-value="{{ $row['delegation'] }}">{{ $row['delegation'] ?: 'Sin delegación' }}</td>
                    <td data-sort-value="{{ $row['state'] }}"><span @class(['stock-state','available'=>$row['state']==='Disponible','reserved'=>$row['state']==='Reservado','blocked'=>$row['state']==='Bloqueado'])>{{ $row['state'] }}</span></td>
                    <td data-sort-value="{{ $row['entry_date'] }}">{{ $row['entry_date'] ? \Carbon\CarbonImmutable::parse($row['entry_date'])->format('d/m/Y') : '—' }}</td>
                    <td data-sort-value="{{ $row['days'] }}" @class(['negative'=>$row['days']!==null&&$row['days']>=90])>{{ $row['days'] ?? '—' }}</td>
                    <td data-sort-value="{{ $row['purchase_price'] }}">{{ $row['purchase_price']!==null?number_format($row['purchase_price'],0,',','.').' €':'—' }}</td>
                    <td data-sort-value="{{ $row['sale_price'] }}">{{ $row['sale_price']!==null?number_format($row['sale_price'],0,',','.').' €':'—' }}</td>
                    <td data-sort-value="{{ $row['margin'] }}">{{ $row['margin']!==null?number_format($row['margin'],0,',','.').' €':'—' }}</td>
                    <td data-sort-value="{{ $row['segment'] }}">{{ $row['segment'] ?: '—' }}</td>
                    <td data-sort-value="{{ $row['fuel'] }}">{{ $row['fuel'] ?: '—' }}</td>
                    <td data-sort-value="{{ $row['mileage'] }}">{{ $row['mileage']!==null?number_format($row['mileage'],0,',','.'):'—' }}</td>
                    <td data-sort-value="{{ $row['buyer'] }}">{{ $row['buyer'] ?: '—' }}</td>
                    <td data-sort-value="{{ $row['purchase_source'] }}">{{ $row['purchase_source'] ?: '—' }}</td>
                    <td data-sort-value="{{ data_get($row,'recommendations.0.delegation') }}">
                        @if($row['state'] !== 'Disponible')
                            <span class="stock-tag muted">No trasladable</span>
                        @else
                            @forelse($row['recommendations'] as $index=>$recommendation)
                                <details class="stock-inline-recommendation">
                                    <summary><b>{{ $index+1 }}. {{ $recommendation['delegation'] }}</b></summary>
                                    <ul>@foreach($recommendation['reasons'] as $reason)<li>{{ $reason }}</li>@endforeach</ul>
                                </details>
                            @empty
                                <span class="stock-tag muted">Sin destino con capacidad</span>
                            @endforelse
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="15">No hay vehículos para los filtros seleccionados.</td></tr>
            @endforelse
            </tbody>
        </table>
        </div>
    </div>
</section>
