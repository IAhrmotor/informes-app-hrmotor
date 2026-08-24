SEO Y ANALYTICS · RESUMEN DIARIO
{{ \Carbon\CarbonImmutable::parse($payload['report_date'])->format('d/m/Y') }}

RESUMEN EJECUTIVO
{{ $payload['executive_summary'] }}

SEIS MÉTRICAS ACTUALES
@foreach ($payload['metrics'] as $metric)
- {{ $metric['label'] }} | {{ $metric['source'] }} | Dato cerrado: {{ $metric['data_date'] ?? '—' }} | Actual: {{ $metric['current'] }} | Referencia: {{ $metric['baseline'] }} | Variación: {{ $metric['relative_change'] }} | Estado: {{ ['ok' => 'Correcto', 'observation' => 'Observación', 'deviation' => 'Desviación relevante', 'critical' => 'Crítico', 'not-evaluable' => 'No evaluable'][$metric['status']] ?? 'No evaluable' }} | {{ $metric['reading'] }}
@endforeach

@if ($payload['highlighted_metrics'] !== [])
SEÑALES DESTACADAS
@foreach ($payload['highlighted_metrics'] as $metric)
- {{ $metric['label'] }}: {{ $metric['reading'] }}
@endforeach

@endif
FRESCURA DE FUENTES
@foreach ($payload['sources'] as $source)
- {{ $source['title'] }} · {{ $source['badge'] }}: {{ $source['detail'] }}
@endforeach

SALUD TÉCNICA
{{ $payload['health']['source']['badge'] }}: {{ $payload['health']['source']['detail'] }}
@if ($payload['health']['available'])
Fecha: {{ $payload['health']['check_date'] ?? '—' }}
URLs comprobadas: {{ $payload['health']['checked_urls'] }}
HTTP 4xx: {{ $payload['health']['http_4xx'] }}
HTTP 5xx: {{ $payload['health']['http_5xx'] }}
Errores de red: {{ $payload['health']['network_errors'] }}
Noindex: {{ $payload['health']['noindex_urls'] }}
Canonical incorrecto: {{ $payload['health']['canonical_mismatch_urls'] }}
Redirecciones: {{ $payload['health']['redirected_urls'] }}
{{ $payload['health']['sitemap_label'] }}
@if ($payload['health']['sitemap_scan_complete'])
Fuera del sitemap: {{ $payload['health']['outside_sitemap_urls'] }}
@endif
@endif

Versiones de reglas: {{ $payload['rule_versions'] === [] ? '—' : implode(', ', $payload['rule_versions']) }}
Dashboard: {{ $payload['dashboard_url'] }}
