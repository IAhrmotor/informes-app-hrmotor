<section class="stock-section">
    @php
        $rankingView = in_array(request('ranking_view'), ['top', 'bottom', 'all'], true) ? request('ranking_view') : 'top';
    @endphp
    <div class="card panel stock-context-banner">
        <div>
            <h2>Ventas por perfil {{ $filters['delegation'] ? '· '.$filters['delegation'] : '· Total grupo' }}</h2>
            <p>Ordenadas únicamente por unidades vendidas. Stock, rotación y antigüedad se mantienen como contexto de análisis.</p>
        </div>
        <nav class="stock-ranking-modes" aria-label="Vista de rankings">
            @foreach(['top'=>'Más vendidos','bottom'=>'Menos vendidos','all'=>'Ver todos'] as $mode=>$label)
                <a @class(['secondary-button','is-active'=>$rankingView===$mode]) href="{{ route('reports.stock.index', array_merge(request()->except(['ranking_view']), ['section'=>'delegations','ranking_view'=>$mode])) }}">{{ $label }}</a>
            @endforeach
        </nav>
    </div>
    <div class="stock-rankings-grid">
        @foreach ($rankings as $ranking)
            <article class="card panel stock-ranking">
                <div class="panel-title"><h2>{{ $ranking['label'] }} · {{ $rankingView === 'bottom' ? 'menos vendidos' : ($rankingView === 'all' ? 'todos' : 'más vendidos') }}</h2></div>
                <div class="table-scroll stock-overflow-table">
                    @php($rankingListId = 'stock-ranking-'.$loop->index)
                    <table class="stock-table" data-sortable-table>
                        <thead><tr>
                            @foreach([['Perfil','text'],['Ventas','number'],['Stock','number'],['Rotación','number'],['Antig. stock','number']] as $index => [$label,$type])
                                <th aria-sort="none"><button type="button" class="stock-sort-button" data-sort-index="{{ $index }}" data-sort-type="{{ $type }}">{{ $label }} <span aria-hidden="true">↕</span></button></th>
                            @endforeach
                        </tr></thead>
                        <tbody id="{{ $rankingListId }}" data-expandable-list @class(['is-expanded' => $rankingView === 'all'])>
                        @forelse($ranking['rows'] as $index => $row)
                            <tr @class(['stock-expandable-extra' => $index >= 10])>
                                <td data-sort-value="{{ $row['label'] }}"><strong>{{ $row['label'] }}</strong></td>
                                <td data-sort-value="{{ $row['sales'] }}">{{ $row['sales'] }}</td>
                                <td data-sort-value="{{ $row['stock'] }}">{{ $row['stock'] }}</td>
                                <td data-sort-value="{{ $row['rotation'] }}">{{ $row['rotation'] !== null ? number_format($row['rotation'],1,',','.').' d' : '—' }}</td>
                                <td data-sort-value="{{ $row['age'] }}">{{ $row['age'] !== null ? number_format($row['age'],1,',','.').' d' : '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5">Sin datos suficientes.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                @if($ranking['rows']->count() > 10)
                    <button type="button" class="secondary-button stock-expand-list-button" data-expand-list="{{ $rankingListId }}" data-show-label="Mostrar todos ({{ $ranking['rows']->count() }})" data-hide-label="Mostrar solo 10">{{ $rankingView === 'all' ? 'Mostrar solo 10' : 'Mostrar todos ('.$ranking['rows']->count().')' }}</button>
                @endif
            </article>
        @endforeach
    </div>
</section>
