<section class="card panel stock-section">
    <div class="panel-title">
        <div>
            <h2>Ventas firmadas y operaciones de cambio</h2>
            <div class="small">Mostrando {{ number_format($salesRows->count(),0,',','.') }} de {{ number_format($salesTotal,0,',','.') }} operaciones del periodo. El importe total es la liquidación del cliente, no el precio del vehículo.</div>
        </div>
    </div>
    <div class="table-scroll">
        <table class="stock-table stock-sales-table" data-sortable-table>
            <thead><tr>
            @foreach([
                ['Firma','text'],['Tipo','text'],['Delegación entrega','text'],['Vehículo vendido','text'],['Precio venta','number'],
                ['Precio compra','number'],['Margen bruto','number'],['Rotación','number'],['Vehículo recogido','text'],['Valor recogido','number'],
                ['Gestión','number'],['Logística','number'],['Traslado','number'],['Garantía','number'],['Plan Auto Plus','number'],
                ['CAE','number'],['Descuentos','number'],['Liquidación cliente','number'],['Oportunidad','text'],
            ] as $index=>[$label,$type])
                <th aria-sort="none"><button type="button" class="stock-sort-button" data-sort-index="{{ $index }}" data-sort-type="{{ $type }}">{{ $label }} <span>↕</span></button></th>
            @endforeach
            </tr></thead>
            <tbody>
            @forelse($salesRows as $row)
                <tr data-commercial="1">
                    <td data-sort-value="{{ $row['signed_date'] }}">{{ $row['signed_date']?\Carbon\CarbonImmutable::parse($row['signed_date'])->format('d/m/Y'):'—' }}</td>
                    <td data-sort-value="{{ $row['type'] }}"><span @class(['stock-tag','warning'=>$row['type']==='Cambio'])>{{ $row['type'] ?: '—' }}</span></td>
                    <td data-sort-value="{{ $row['delivery_store'] }}">{{ $row['delivery_store'] ?: 'Sin asignar' }}</td>
                    <td data-sort-value="{{ $row['vehicle'] }}"><strong>{{ $row['plate'] ?: 'Sin matrícula' }}</strong><small>{{ $row['vehicle'] ?: 'Sin perfil' }} · {{ $row['vehicle_id'] ?: 'Sin ID' }}</small></td>
                    <td data-sort-value="{{ $row['sale_price'] }}">{{ $row['sale_price']!==null?number_format($row['sale_price'],0,',','.').' €':'—' }}</td>
                    <td data-sort-value="{{ $row['purchase_price'] }}">{{ $row['purchase_price']!==null?number_format($row['purchase_price'],0,',','.').' €':'—' }}</td>
                    <td data-sort-value="{{ $row['gross_margin'] }}">{{ $row['gross_margin']!==null?number_format($row['gross_margin'],0,',','.').' €':'—' }}</td>
                    <td data-sort-value="{{ $row['rotation_days'] }}">{{ $row['rotation_days']!==null?$row['rotation_days'].' d':'—' }}</td>
                    <td data-sort-value="{{ $row['trade_in_plate'] }}">@if($row['type']==='Cambio')<strong>{{ $row['trade_in_plate'] ?: 'Sin matrícula' }}</strong><small>{{ $row['trade_in_id'] ?: 'Sin ID Salesforce' }}</small>@else—@endif</td>
                    @foreach(['trade_in_amount','management','logistics','transfer','warranty','plan_auto_plus','cae','discount','total_amount'] as $field)
                        <td data-sort-value="{{ $row[$field] }}">{{ $row[$field]!==null?number_format($row[$field],0,',','.').' €':'—' }}</td>
                    @endforeach
                    <td data-sort-value="{{ $row['opportunity_id'] }}"><strong>{{ $row['opportunity'] ?: '—' }}</strong><small>{{ $row['opportunity_id'] }}</small></td>
                </tr>
            @empty
                <tr><td colspan="19">No hay ventas para los filtros y periodo seleccionados.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</section>
