<x-reports.app-shell title="SEO y Analytics" current-report="seo-analytics">
    <div class="wrap">
        <main>
            <x-reports.ui.page-header
                eyebrow="Marketing estratégico"
                title="SEO y Analytics"
                description="Visibilidad orgánica de Search Console y Leads orgánicos registrados en Salesforce."
            >
                <x-slot:actions>
                    <form method="GET" action="{{ route('reports.seo-analytics.index') }}" class="report-ui-filter-bar">
                        <input type="hidden" name="section" value="{{ $section }}">
                        <label class="report-ui-field">
                            <span class="report-ui-label">Periodo cerrado</span>
                            <select class="report-ui-select" name="range">
                                @foreach ($ranges as $rangeOption)
                                    <option value="{{ $rangeOption }}" @selected($range === $rangeOption)>{{ $rangeOption }} días</option>
                                @endforeach
                            </select>
                        </label>
                        <button class="report-ui-button report-ui-button--secondary" type="submit">Aplicar</button>
                    </form>
                </x-slot:actions>
            </x-reports.ui.page-header>

            <nav class="report-ui-tabs" aria-label="Secciones SEO y Analytics">
                @foreach ($sections as $sectionKey => $sectionLabel)
                    <a
                        href="{{ route('reports.seo-analytics.index', ['section' => $sectionKey, 'range' => $range]) }}"
                        class="report-ui-tab {{ $section === $sectionKey ? 'is-active' : '' }}"
                        @if ($section === $sectionKey) aria-current="page" @endif
                    >{{ $sectionLabel }}</a>
                @endforeach
            </nav>

            <section class="report-ui-data-panel" aria-label="Estado de las fuentes" style="margin-top: var(--report-ui-space-4)">
                <div class="report-ui-data-panel__body">
                    @foreach ($sources as $source)
                        <x-reports.ui.source-status :title="$source['title']" :detail="$source['detail']" :data-source="$source['key']">
                            <x-slot:indicator><span class="report-ui-badge">{{ $source['badge'] }}</span></x-slot:indicator>
                        </x-reports.ui.source-status>
                    @endforeach
                </div>
            </section>

            @if (in_array($section, ['summary', 'traffic'], true) && $common_period['end'])
                <p class="report-ui-help" style="margin: var(--report-ui-space-3) 0">
                    Periodo común cerrado: {{ $common_period['start'] }} — {{ $common_period['end'] }}.
                </p>
            @elseif ($section === 'search' && $search_console_period['end'])
                <p class="report-ui-help" style="margin: var(--report-ui-space-3) 0">
                    Periodo Search Console: {{ $search_console_period['start'] }} — {{ $search_console_period['end'] }}.
                </p>
            @endif

            @if ($section === 'summary')
                @if (! $has_search_console && ! $has_salesforce && ! $has_ga4)
                    <x-reports.ui.empty-state
                        kicker="Pendiente de sincronización"
                        title="Todavía no hay métricas SEO persistidas"
                        description="Las fuentes se sincronizan mediante CLI y scheduler; abrir esta pantalla nunca ejecuta llamadas externas."
                    />
                @else
                    <section class="report-ui-kpi-strip" aria-label="Indicadores SEO">
                        @foreach ([
                            ['Clicks orgánicos', $kpis['spain']['clicks'], null],
                            ['Impresiones orgánicas', $kpis['spain']['impressions'], null],
                            ['CTR', $kpis['spain']['ctr'], 'percent'],
                            ['Posición media', $kpis['spain']['position'], 'decimal'],
                            ['Lead orgánico (Salesforce)', $kpis['salesforce_leads'], null],
                            ['Conversiones web orgánicas (GA4)', $kpis['ga4_key_events'], 'ga4'],
                        ] as [$label, $value, $format])
                            <div class="report-ui-kpi-strip__item">
                                <div class="report-ui-kpi-strip__label">{{ $label }}</div>
                                <div class="report-ui-kpi-strip__value">
                                    @if ($value === null) —
                                    @elseif ($format === 'percent') {{ number_format($value * 100, 2, ',', '.') }}%
                                    @elseif ($format === 'decimal') {{ number_format($value, 2, ',', '.') }}
                                    @elseif ($format === 'ga4') {{ number_format($value, 2, ',', '.') }}
                                    @else {{ number_format($value, 0, ',', '.') }}
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </section>

                    <div class="report-ui-data-panel" style="margin-top: var(--report-ui-space-4)">
                        <div class="report-ui-data-panel__header"><x-reports.ui.section-header title="Tráfico de marca en España" description="Segmentación descriptiva basada exclusivamente en las variantes de marca configuradas." /></div>
                        <div class="report-ui-data-panel__body">
                            <p class="report-ui-help">La segmentación Marca/No marca puede no sumar el total de España porque Search Console excluye consultas anonimizadas al aplicar filtros de búsqueda.</p>
                        </div>
                        <div class="report-ui-data-panel__scroll" tabindex="0" aria-label="Tráfico de marca en España">
                            <table class="report-ui-table">
                                <thead><tr><th scope="col">Segmento</th><th scope="col" class="report-ui-table__numeric">Clicks</th><th scope="col" class="report-ui-table__numeric">Impresiones</th><th scope="col" class="report-ui-table__numeric">CTR</th><th scope="col" class="report-ui-table__numeric">Posición</th></tr></thead>
                                <tbody>
                                    @foreach (['brand' => 'Marca', 'non_brand' => 'No marca'] as $key => $label)
                                        <tr><td>{{ $label }}</td><td class="report-ui-table__numeric">{{ $segments[$key]['clicks'] === null ? '—' : number_format($segments[$key]['clicks'], 0, ',', '.') }}</td><td class="report-ui-table__numeric">{{ $segments[$key]['impressions'] === null ? '—' : number_format($segments[$key]['impressions'], 0, ',', '.') }}</td><td class="report-ui-table__numeric">{{ $segments[$key]['ctr'] === null ? '—' : number_format($segments[$key]['ctr'] * 100, 2, ',', '.').'%' }}</td><td class="report-ui-table__numeric">{{ $segments[$key]['position'] === null ? '—' : number_format($segments[$key]['position'], 2, ',', '.') }}</td></tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="report-ui-data-panel" style="margin-top: var(--report-ui-space-4)">
                        <div class="report-ui-data-panel__header"><x-reports.ui.section-header title="España y resto del mundo" /></div>
                        <div class="report-ui-data-panel__scroll" tabindex="0" aria-label="España y resto del mundo">
                            <table class="report-ui-table"><thead><tr><th scope="col">Ámbito</th><th scope="col" class="report-ui-table__numeric">Clicks</th><th scope="col" class="report-ui-table__numeric">Impresiones</th><th scope="col" class="report-ui-table__numeric">CTR</th><th scope="col" class="report-ui-table__numeric">Posición</th></tr></thead><tbody>
                                @foreach (['spain' => 'España', 'rest' => 'Resto del mundo'] as $key => $label)
                                    <tr class="{{ $key === 'spain' ? 'report-ui-table-row--highlight' : '' }}"><td>{{ $label }}</td><td class="report-ui-table__numeric">{{ $geography[$key]['clicks'] === null ? '—' : number_format($geography[$key]['clicks'], 0, ',', '.') }}</td><td class="report-ui-table__numeric">{{ $geography[$key]['impressions'] === null ? '—' : number_format($geography[$key]['impressions'], 0, ',', '.') }}</td><td class="report-ui-table__numeric">{{ $geography[$key]['ctr'] === null ? '—' : number_format($geography[$key]['ctr'] * 100, 2, ',', '.').'%' }}</td><td class="report-ui-table__numeric">{{ $geography[$key]['position'] === null ? '—' : number_format($geography[$key]['position'], 2, ',', '.') }}</td></tr>
                                @endforeach
                            </tbody></table>
                        </div>
                    </div>
                @endif
            @elseif ($section === 'traffic')
                <div class="report-ui-data-panel"><div class="report-ui-data-panel__header"><x-reports.ui.section-header title="Tráfico y conversión" description="Search Console, Lead orgánico Salesforce y Conversiones web orgánicas GA4 son fuentes distintas y no se suman." /></div>
                    @if ($daily === []) <div class="report-ui-data-panel__body"><x-reports.ui.empty-state title="Sin serie diaria sincronizada" description="Las fuentes se cargan exclusivamente mediante sus comandos y scheduler." /></div>
                    @else <div class="report-ui-data-panel__scroll" tabindex="0" aria-label="Tráfico orgánico diario"><table class="report-ui-table report-ui-table--sticky-header"><thead><tr><th scope="col">Fecha</th><th scope="col" class="report-ui-table__numeric">Clicks España</th><th scope="col" class="report-ui-table__numeric">Impresiones España</th><th scope="col" class="report-ui-table__numeric">CTR</th><th scope="col" class="report-ui-table__numeric">Posición</th><th scope="col" class="report-ui-table__numeric">Lead orgánico Salesforce</th><th scope="col" class="report-ui-table__numeric">Conversiones web orgánicas GA4</th></tr></thead><tbody>
                        @foreach ($daily as $row)<tr><td>{{ $row['date'] }}</td><td class="report-ui-table__numeric">{{ $row['clicks'] ?? '—' }}</td><td class="report-ui-table__numeric">{{ $row['impressions'] ?? '—' }}</td><td class="report-ui-table__numeric">{{ $row['ctr'] === null ? '—' : number_format($row['ctr'] * 100, 2, ',', '.').'%' }}</td><td class="report-ui-table__numeric">{{ $row['position'] === null ? '—' : number_format($row['position'], 2, ',', '.') }}</td><td class="report-ui-table__numeric">{{ $row['leads'] ?? '—' }}</td><td class="report-ui-table__numeric">{{ $row['ga4_key_events'] === null ? '—' : number_format($row['ga4_key_events'], 2, ',', '.') }}</td></tr>@endforeach
                    </tbody></table></div>@endif
                </div>
                <div class="report-ui-data-panel" style="margin-top: var(--report-ui-space-4)">
                    <div class="report-ui-data-panel__header"><x-reports.ui.section-header title="Conversiones web orgánicas (GA4)" description="Key Events atribuidos a Organic Search en plataforma web. No se suman a Lead orgánico Salesforce." /></div>
                    <div class="report-ui-data-panel__body">
                        <p class="report-ui-help">
                            España: {{ $ga4['spain']['key_events'] === null ? '—' : number_format($ga4['spain']['key_events'], 2, ',', '.') }} ·
                            Global: {{ $ga4['global']['key_events'] === null ? '—' : number_format($ga4['global']['key_events'], 2, ',', '.') }} ·
                            Resto: {{ $ga4['rest']['key_events'] === null ? '—' : number_format($ga4['rest']['key_events'], 2, ',', '.') }}
                        </p>
                    </div>
                    @if ($ga4['events']->isEmpty())
                        <div class="report-ui-data-panel__body"><x-reports.ui.empty-state title="Sin detalle de Key Events para el periodo" /></div>
                    @else
                        <div class="report-ui-data-panel__scroll" tabindex="0" aria-label="Conversiones web orgánicas por evento clave">
                            <table class="report-ui-table">
                                <thead><tr><th scope="col">Evento clave</th><th scope="col" class="report-ui-table__numeric">Conversiones atribuidas</th></tr></thead>
                                <tbody>@foreach ($ga4['events'] as $event)<tr><td>{{ $event->event_name }}</td><td class="report-ui-table__numeric">{{ number_format((float) $event->key_events, 2, ',', '.') }}</td></tr>@endforeach</tbody>
                            </table>
                        </div>
                    @endif
                </div>
            @elseif ($section === 'search')
                @foreach ([['Principales búsquedas en España', $queries, 'query'], ['Principales páginas en España', $pages, 'page']] as [$title, $rows, $kind])
                    <div class="report-ui-data-panel" style="margin-bottom: var(--report-ui-space-4)"><div class="report-ui-data-panel__header"><x-reports.ui.section-header :title="$title" description="Principales resultados disponibles; no representan necesariamente todas las filas de Search Console." /></div>
                        @if ($rows->isEmpty()) <div class="report-ui-data-panel__body"><x-reports.ui.empty-state title="Sin ranking sincronizado" /></div>
                        @else <div class="report-ui-data-panel__scroll" tabindex="0" aria-label="{{ $title }}"><table class="report-ui-table"><thead><tr><th scope="col">{{ $kind === 'query' ? 'Consulta' : 'Página' }}</th>@if ($kind === 'query')<th scope="col">Tipo</th>@endif<th scope="col" class="report-ui-table__numeric">Clicks</th><th scope="col" class="report-ui-table__numeric">Impresiones</th><th scope="col" class="report-ui-table__numeric">CTR</th><th scope="col" class="report-ui-table__numeric">Posición</th></tr></thead><tbody>
                            @foreach ($rows as $row)<tr><td title="{{ $row->dimension_value }}">{{ $row->dimension_value }}</td>@if ($kind === 'query')<td><span class="report-ui-badge">{{ $row->brand_segment === 'brand' ? 'Marca' : 'No marca' }}</span></td>@endif<td class="report-ui-table__numeric">{{ number_format($row->clicks, 0, ',', '.') }}</td><td class="report-ui-table__numeric">{{ number_format($row->impressions, 0, ',', '.') }}</td><td class="report-ui-table__numeric">{{ $row->ctr === null ? '—' : number_format((float) $row->ctr * 100, 2, ',', '.').'%' }}</td><td class="report-ui-table__numeric">{{ $row->position === null ? '—' : number_format((float) $row->position, 2, ',', '.') }}</td></tr>@endforeach
                        </tbody></table></div>@endif
                    </div>
                @endforeach
                <details class="report-ui-data-panel"><summary class="report-ui-data-panel__header">Principales países</summary><div class="report-ui-data-panel__scroll" tabindex="0" aria-label="Principales países"><table class="report-ui-table"><thead><tr><th scope="col">País</th><th scope="col" class="report-ui-table__numeric">Clicks</th><th scope="col" class="report-ui-table__numeric">Impresiones</th></tr></thead><tbody>@foreach ($countries as $row)<tr class="{{ strtoupper($row->dimension_value) === 'ESP' ? 'report-ui-table-row--highlight' : '' }}"><td>{{ $row->dimension_value }}</td><td class="report-ui-table__numeric">{{ $row->clicks }}</td><td class="report-ui-table__numeric">{{ $row->impressions }}</td></tr>@endforeach</tbody></table></div></details>
            @elseif ($section === 'health')
                <x-reports.ui.empty-state kicker="Siguiente lote" title="Salud técnica pendiente" description="No se ejecutan crawler, sitemap sync ni comprobaciones HTTP en este lote." />
            @else
                <x-reports.ui.empty-state kicker="GEO / IA" title="SISTRIX AI Check pendiente" description="La configuración puede diagnosticarse por CLI; esta pantalla no consume créditos ni ejecuta AI Check." />
            @endif
        </main>
    </div>
</x-reports.app-shell>
