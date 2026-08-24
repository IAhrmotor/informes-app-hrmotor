<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $payload['subject'] }}</title>
</head>
<body style="margin:0;background:#f1f5f9;color:#1f2944;font-family:Arial,sans-serif">
@php
    $statusLabels = ['ok' => 'Correcto', 'observation' => 'Observación', 'deviation' => 'Desviación relevante', 'critical' => 'Crítico', 'not-evaluable' => 'No evaluable'];
@endphp
<div style="max-width:920px;margin:0 auto;padding:24px">
    <div style="background:#1f2944;color:#fff;padding:20px;border-radius:8px">
        <div style="font-size:12px;text-transform:uppercase;letter-spacing:.08em">HR Motor · Informes</div>
        <h1 style="font-size:24px;margin:8px 0 4px">SEO y Analytics · Resumen diario</h1>
        <div>{{ \Carbon\CarbonImmutable::parse($payload['report_date'])->format('d/m/Y') }}</div>
    </div>

    <div style="background:#fff;border:1px solid #cbd5e1;border-radius:8px;padding:18px;margin-top:16px">
        <strong>Resumen ejecutivo</strong>
        <p style="margin:8px 0 0">{{ $payload['executive_summary'] }}</p>
    </div>

    <h2 style="font-size:18px;margin:24px 0 10px">Seis métricas actuales</h2>
    <div style="overflow-x:auto">
        <table style="width:100%;border-collapse:collapse;background:#fff;border:1px solid #cbd5e1">
            <thead><tr style="background:#f1f5f9;text-align:left"><th style="padding:10px;border-bottom:1px solid #cbd5e1">Métrica</th><th style="padding:10px;border-bottom:1px solid #cbd5e1">Fuente</th><th style="padding:10px;border-bottom:1px solid #cbd5e1">Dato cerrado</th><th style="padding:10px;border-bottom:1px solid #cbd5e1">Actual</th><th style="padding:10px;border-bottom:1px solid #cbd5e1">Referencia</th><th style="padding:10px;border-bottom:1px solid #cbd5e1">Variación</th><th style="padding:10px;border-bottom:1px solid #cbd5e1">Estado</th></tr></thead>
            <tbody>
            @foreach ($payload['metrics'] as $metric)
                <tr>
                    <td style="padding:10px;border-bottom:1px solid #e2e8f0"><strong>{{ $metric['label'] }}</strong><br><span style="color:#5f6b7d">{{ $metric['reading'] }}</span></td>
                    <td style="padding:10px;border-bottom:1px solid #e2e8f0">{{ $metric['source'] }}</td>
                    <td style="padding:10px;border-bottom:1px solid #e2e8f0">{{ $metric['data_date'] ?? '—' }}</td>
                    <td style="padding:10px;border-bottom:1px solid #e2e8f0">{{ $metric['current'] }}</td>
                    <td style="padding:10px;border-bottom:1px solid #e2e8f0">{{ $metric['baseline'] }}</td>
                    <td style="padding:10px;border-bottom:1px solid #e2e8f0">{{ $metric['relative_change'] }}</td>
                    <td style="padding:10px;border-bottom:1px solid #e2e8f0">{{ $statusLabels[$metric['status']] ?? 'No evaluable' }}@if ($metric['status'] === 'observation' && $metric['direction'] === 'favorable') · Favorable @endif</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    @if ($payload['highlighted_metrics'] !== [])
        <h2 style="font-size:18px;margin:24px 0 10px">Señales destacadas</h2>
        <ul style="background:#fff;border:1px solid #cbd5e1;border-radius:8px;padding:16px 16px 16px 36px">
            @foreach ($payload['highlighted_metrics'] as $metric)
                <li style="margin:6px 0"><strong>{{ $metric['label'] }}:</strong> {{ $statusLabels[$metric['status']] ?? 'No evaluable' }} · {{ $metric['reading'] }}</li>
            @endforeach
        </ul>
    @endif

    <h2 style="font-size:18px;margin:24px 0 10px">Frescura de fuentes</h2>
    <ul style="background:#fff;border:1px solid #cbd5e1;border-radius:8px;padding:16px 16px 16px 36px">
        @foreach ($payload['sources'] as $source)
            <li style="margin:6px 0"><strong>{{ $source['title'] }} · {{ $source['badge'] }}:</strong> {{ $source['detail'] }}</li>
        @endforeach
    </ul>

    <h2 style="font-size:18px;margin:24px 0 10px">Salud técnica</h2>
    <div style="background:#fff;border:1px solid #cbd5e1;border-radius:8px;padding:16px">
        <p><strong>{{ $payload['health']['source']['badge'] }}:</strong> {{ $payload['health']['source']['detail'] }}</p>
        @if ($payload['health']['available'])
            <p>Fecha: {{ $payload['health']['check_date'] ?? '—' }} · URLs: {{ $payload['health']['checked_urls'] }} · 4xx: {{ $payload['health']['http_4xx'] }} · 5xx: {{ $payload['health']['http_5xx'] }} · Errores de red: {{ $payload['health']['network_errors'] }}</p>
            <p>Noindex: {{ $payload['health']['noindex_urls'] }} · Canonical incorrecto: {{ $payload['health']['canonical_mismatch_urls'] }} · Redirecciones: {{ $payload['health']['redirected_urls'] }}</p>
            <p>{{ $payload['health']['sitemap_label'] }}@if ($payload['health']['sitemap_scan_complete']) · Fuera del sitemap: {{ $payload['health']['outside_sitemap_urls'] }} @endif</p>
        @endif
    </div>

    <p style="color:#5f6b7d;font-size:13px;margin-top:18px">Versiones de reglas: {{ $payload['rule_versions'] === [] ? '—' : implode(', ', $payload['rule_versions']) }}</p>
    <p><a href="{{ $payload['dashboard_url'] }}" style="color:#a50f23">Abrir SEO y Analytics</a></p>
</div>
</body>
</html>
