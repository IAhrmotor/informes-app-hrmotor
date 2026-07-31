<section class="stock-section">
    <article class="card panel">
        <div class="panel-title">
            <div>
                <h2>Simulador para nuevos vehículos</h2>
                <div class="small">Introduce el perfil y se mostrarán los tres destinos recomendados. No se ejecuta ningún traslado.</div>
            </div>
        </div>
        <form method="GET" action="{{ route('reports.stock.index') }}" class="stock-simulator">
            <input type="hidden" name="section" value="recommendations">
            @foreach ([
                'rec_brand'=>['Marca',$filterOptions['brands']],
                'rec_model'=>['Modelo',$filterOptions['models']],
                'rec_segment'=>['Segmento',$filterOptions['segments']],
                'rec_fuel'=>['Combustible',$filterOptions['fuels']],
            ] as $name=>[$label,$options])
                <div class="filter-group"><label for="{{ $name }}">{{ $label }}</label><select id="{{ $name }}" name="{{ $name }}"><option value="">Seleccionar</option>@foreach($options as $option)<option value="{{ $option }}" @selected(request($name)===$option)>{{ $option }}</option>@endforeach</select></div>
            @endforeach
            <div class="filter-group"><label for="rec_price">Precio de venta</label><div class="stock-input-unit"><input id="rec_price" name="rec_price" type="number" min="0" value="{{ request('rec_price') }}"><span>€</span></div></div>
            <button class="main-tab active" type="submit">Calcular destinos</button>
        </form>

        @if ($newVehicleRecommendations)
            <div class="stock-recommendation-cards">
                @forelse($newVehicleRecommendations['rows'] as $index=>$recommendation)
                    @include('reports.stock.partials.recommendation-card', ['recommendation'=>$recommendation,'position'=>$index+1])
                @empty
                    <div class="notice">No existe ninguna delegación comercial con capacidad para este perfil.</div>
                @endforelse
            </div>
        @endif
    </article>

    <article class="card panel">
        <div class="panel-title">
            <div>
                <h2>Vehículos disponibles candidatos a traslado</h2>
                <div class="small">
                    Desde {{ config('stock.review_days', 60) }} días: revisión. Desde {{ config('stock.priority_days', 90) }} días o exceso del mismo modelo: prioritario.
                    Se analizan {{ number_format($recommendationAvailableTotal,0,',','.') }} disponibles.
                    <strong>Mostrando {{ number_format($recommendationDisplayed,0,',','.') }} de {{ number_format($recommendationTotal,0,',','.') }} candidatos.</strong>
                </div>
            </div>
        </div>
        <div class="stock-scroll-region" data-stock-scroll-region>
            <div class="stock-scrollbar-top" data-stock-scroll-top aria-label="Desplazamiento horizontal superior"><div data-stock-scroll-spacer></div></div>
            <div class="table-scroll stock-overflow-table stock-candidate-scroll" data-stock-scroll-body>
            <table class="stock-table stock-recommendations-table" data-sortable-table>
                <thead><tr>
                    @foreach ([['Vehículo','text'],['Delegación actual','text'],['Días','number'],['Mismo modelo','number'],['Prioridad','text'],['Top 3 destinos','text']] as $index=>[$label,$type])
                        <th aria-sort="none"><button type="button" class="stock-sort-button" data-sort-index="{{ $index }}" data-sort-type="{{ $type }}">{{ $label }} <span>↕</span></button></th>
                    @endforeach
                </tr></thead>
                <tbody>
                @forelse($recommendationRows as $row)
                    <tr data-commercial="1">
                        <td data-sort-value="{{ $row['brand'].' '.$row['model'] }}"><strong>{{ $row['plate'] ?: $row['id'] }}</strong><small>{{ trim(($row['brand'] ?? '').' '.($row['model'] ?? '').' '.($row['version'] ?? '')) }}</small></td>
                        <td data-sort-value="{{ $row['delegation'] }}">{{ $row['delegation'] ?: 'Sin delegación' }}
                            @if($row['current_profile'])<small>{{ $row['current_profile']['model_sales'] }} ventas modelo · {{ $row['current_profile']['same_model_stock'] }} en stock</small>@endif
                        </td>
                        <td data-sort-value="{{ $row['days'] }}">{{ $row['days'] ?? '—' }}</td>
                        <td data-sort-value="{{ $row['same_model_stock'] }}">{{ $row['same_model_stock'] }}</td>
                        <td data-sort-value="{{ $row['review_level'] }}"><span @class(['stock-tag','danger'=>$row['review_level']==='priority','warning'=>$row['review_level']==='review'])>{{ $row['review_level']==='priority'?'Prioritario':($row['review_level']==='review'?'Revisión':'Normal') }}</span></td>
                        <td data-sort-value="{{ data_get($row,'recommendations.0.delegation') }}">
                            @forelse($row['recommendations'] as $index=>$recommendation)
                                <details class="stock-inline-recommendation">
                                    <summary><b>{{ $index+1 }}. {{ $recommendation['delegation'] }}</b><span>{{ $recommendation['free_capacity'] }} plazas</span></summary>
                                    <ul>@foreach($recommendation['reasons'] as $reason)<li>{{ $reason }}</li>@endforeach</ul>
                                </details>
                            @empty
                                <span class="stock-tag muted">Sin destino con capacidad</span>
                            @endforelse
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6">No hay vehículos disponibles para los filtros seleccionados.</td></tr>
                @endforelse
                </tbody>
            </table>
            </div>
        </div>
        @if($recommendationPages > 1)
            <nav class="stock-pagination" aria-label="Paginación de candidatos">
                @if($recommendationPage > 1)
                    <a class="secondary-button" href="{{ route('reports.stock.index', array_merge(request()->query(), ['section'=>'recommendations','recommendation_page'=>$recommendationPage-1])) }}">← Anterior</a>
                @endif
                <span>Página {{ $recommendationPage }} de {{ $recommendationPages }}</span>
                @if($recommendationPage < $recommendationPages)
                    <a class="secondary-button" href="{{ route('reports.stock.index', array_merge(request()->query(), ['section'=>'recommendations','recommendation_page'=>$recommendationPage+1])) }}">Siguiente →</a>
                @endif
            </nav>
        @endif
    </article>
</section>
