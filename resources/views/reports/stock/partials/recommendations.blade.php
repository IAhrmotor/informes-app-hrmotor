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
        <script type="application/json" id="stockModelsByBrand">@json($filterOptions['models_by_brand'])</script>

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
                <h2>Vehículos propuestos para traslado</h2>
                <div class="small">
                    Se analizan todos los disponibles. Desde {{ config('stock.review_days', 60) }} días: prioridad 60; desde {{ config('stock.priority_days', 90) }} días o exceso del mismo modelo: prioridad 90.
                    Los destinos se ordenan teóricamente y la capacidad se aplica después al plan conjunto.
                    <strong>Mostrando {{ number_format($recommendationDisplayed,0,',','.') }} de {{ number_format($recommendationTotal,0,',','.') }} candidatos.</strong>
                </div>
            </div>
        </div>
        <div class="stock-recommendation-legend" aria-label="Conciliación del plan de traslados">
            @foreach ([
                ['Contexto de stock', [
                    ['Stock total', $recommendationReconciliation['universe'] ?? 0, 'neutral'],
                    ['Disponibles', $recommendationReconciliation['available'] ?? 0, 'positive'],
                    ['Reservados', $recommendationReconciliation['reserved'] ?? 0, 'neutral'],
                    ['Bloqueados', $recommendationReconciliation['blocked'] ?? 0, 'neutral'],
                ]],
                ['Evaluación y plan', [
                    ['Disponibles evaluados', $recommendationReconciliation['evaluated'] ?? 0, 'positive'],
                    ['Asignados en el plan', $recommendationReconciliation['planned'] ?? 0, 'positive'],
                    ['Sin alternativas', $recommendationReconciliation['without_destination'] ?? 0, 'warning'],
                    ['Sin asignar por capacidad', $recommendationReconciliation['unallocated_by_capacity'] ?? 0, 'warning'],
                    ['Catálogo no operativo', $recommendationReconciliation['excluded_non_operational_catalog'] ?? 0, 'warning'],
                ]],
                ['Prioridad', [
                    ['Normal', $recommendationReconciliation['priority_normal'] ?? 0, 'neutral'],
                    ['60 días', $recommendationReconciliation['priority_60_days'] ?? 0, 'priority-60'],
                    ['90 días', $recommendationReconciliation['priority_90_days'] ?? 0, 'priority-90'],
                ]],
            ] as [$groupLabel, $items])
                <section class="stock-legend-group">
                    <h3>{{ $groupLabel }}</h3>
                    <div class="stock-legend-items">
                        @foreach ($items as [$label, $value, $tone])
                            <div class="stock-quality-item stock-quality-{{ $tone }}">
                                <span>{{ $label }}</span>
                                <strong>{{ number_format($value, 0, ',', '.') }}</strong>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>
        <form method="GET" action="{{ route('reports.stock.index') }}" class="stock-inline-filter" data-live-plate-form>
            @foreach(request()->except(['candidate_plate', 'recommendation_page']) as $name => $value)
                @if(is_scalar($value))<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endif
            @endforeach
            <label for="stock-candidate-plate">Filtrar por matrícula</label>
            <input id="stock-candidate-plate" name="candidate_plate" type="search" value="{{ request('candidate_plate') }}" placeholder="Escribe una matrícula…" autocomplete="off" data-live-plate-input data-live-table-target="stock-recommendations-table">
        </form>
        <div class="stock-scroll-region" data-stock-scroll-region>
            <div class="stock-scrollbar-top" data-stock-scroll-top aria-label="Desplazamiento horizontal superior"><div data-stock-scroll-spacer></div></div>
            <div class="table-scroll stock-overflow-table stock-candidate-scroll" data-stock-scroll-body>
            <table id="stock-recommendations-table" class="stock-table stock-recommendations-table" data-sortable-table>
                <thead><tr>
                    @foreach ([['Vehículo','text'],['Delegación actual','text'],['Días','number'],['Mismo modelo','number'],['Prioridad','text'],['Destino del plan','text'],['Top 3 destinos','text']] as $index=>[$label,$type])
                        <th aria-sort="none"><button type="button" class="stock-sort-button" data-sort-index="{{ $index }}" data-sort-type="{{ $type }}">{{ $label }} <span>↕</span></button></th>
                    @endforeach
                </tr></thead>
                <tbody>
                @forelse($recommendationRows as $row)
                    <tr data-commercial="1" data-plate="{{ $row['plate'] }}">
                        <td data-sort-value="{{ $row['brand'].' '.$row['model'] }}"><strong>{{ $row['plate'] ?: $row['id'] }}</strong><small>{{ trim(($row['brand'] ?? '').' '.($row['model'] ?? '').' '.($row['version'] ?? '')) }}</small></td>
                        <td data-sort-value="{{ $row['delegation'] }}">{{ $row['delegation'] ?: 'Sin delegación' }}
                            @if($row['current_profile'])<small>{{ $row['current_profile']['model_sales'] }} ventas modelo · {{ $row['current_profile']['same_model_stock'] }} en stock</small>@endif
                        </td>
                        <td data-sort-value="{{ $row['days'] }}">{{ $row['days'] ?? '—' }}</td>
                        <td data-sort-value="{{ $row['same_model_stock'] }}">{{ $row['same_model_stock'] }}</td>
                        <td data-sort-value="{{ $row['review_level'] }}"><span @class(['stock-tag','danger'=>$row['review_level']==='priority','warning'=>$row['review_level']==='review'])>{{ $row['review_level']==='priority'?'Prioritario':($row['review_level']==='review'?'Revisión':'Normal') }}</span></td>
                        <td data-sort-value="{{ data_get($row, 'planned_destination.delegation') }}">
                            @if(data_get($row, 'planned_destination.delegation'))
                                <strong>{{ data_get($row, 'planned_destination.delegation') }}</strong>
                                <small>Asignación conjunta · score {{ data_get($row, 'planned_destination.score') }}</small>
                            @else
                                <span class="stock-tag warning">Sin plaza en el plan</span>
                            @endif
                        </td>
                        <td data-sort-value="{{ data_get($row,'recommendations.0.delegation') }}">
                            @forelse($row['recommendations'] as $index=>$recommendation)
                                <details class="stock-inline-recommendation">
                                    <summary><b>{{ $index+1 }}. {{ $recommendation['delegation'] }}</b><span>{{ $recommendation['is_executable'] ? $recommendation['free_capacity'].' plazas' : 'No ejecutable' }}</span></summary>
                                    @unless($recommendation['is_executable'])<small>Exceso previsto: {{ $recommendation['capacity_excess'] }} · liberar {{ $recommendation['places_to_release'] }} plaza(s)</small>@endunless
                                    <ul>@foreach($recommendation['reasons'] as $reason)<li>{{ $reason }}</li>@endforeach</ul>
                                </details>
                            @empty
                                <span class="stock-tag muted">Sin alternativas comparables</span>
                            @endforelse
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7">No hay vehículos disponibles para los filtros seleccionados.</td></tr>
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
