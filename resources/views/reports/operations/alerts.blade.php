<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Alertas operativas | HR Motor</title>
    <link rel="icon" href="/brand/favicon.ico" sizes="any">
    @include('partials.font-assets')
    @vite(['resources/css/reports/leads-dashboard.css'])
</head>
<body class="campaigns-report">
<div class="wrap">
    @include('reports.partials.report-header', ['currentReport' => 'operations', 'currentAdminPage' => 'operational-alerts'])

    <main>
        <section class="header">
            <div>
                <div class="eyebrow">Administración</div>
                <h1>Alertas operativas</h1>
                <p class="sub">Incidencias persistentes y sanitizadas. Este panel no muestra credenciales, cuerpos de peticiones ni datos personales.</p>
            </div>
        </section>

        @include('reports.partials.admin-nav', ['currentAdminPage' => 'operational-alerts'])

        <section class="campaign-context-grid">
            <article class="card campaign-context-card">
                <span>Abiertas</span>
                <strong>{{ number_format($openCount, 0, ',', '.') }}</strong>
            </article>
            <article class="card campaign-context-card">
                <span>Resueltas en retención</span>
                <strong>{{ number_format($resolvedCount, 0, ',', '.') }}</strong>
            </article>
        </section>

        <section class="card panel">
            <form method="GET" action="{{ route('reports.operational-alerts.index') }}" class="filters-grid">
                <div class="filter-group">
                    <label for="state">Estado</label>
                    <select id="state" name="state">
                        <option value="">Todos</option>
                        <option value="open" @selected(($filters['state'] ?? '') === 'open')>Abierta</option>
                        <option value="resolved" @selected(($filters['state'] ?? '') === 'resolved')>Resuelta</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="severity">Severidad</label>
                    <select id="severity" name="severity">
                        <option value="">Todas</option>
                        @foreach (['critical' => 'Crítica', 'high' => 'Alta', 'medium' => 'Media', 'low' => 'Baja'] as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['severity'] ?? '') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="filter-group">
                    <label for="type">Tipo</label>
                    <select id="type" name="type">
                        <option value="">Todos</option>
                        @foreach ($types as $type)
                            <option value="{{ $type }}" @selected(($filters['type'] ?? '') === $type)>{{ $type }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="filter-group">
                    <label for="source">Origen</label>
                    <select id="source" name="source">
                        <option value="">Todos</option>
                        @foreach ($sources as $source)
                            <option value="{{ $source }}" @selected(($filters['source'] ?? '') === $source)>{{ $source }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="filter-actions">
                    <button type="submit" class="main-tab active">Filtrar</button>
                    <a href="{{ route('reports.operational-alerts.index') }}" class="filter-reset">Limpiar</a>
                </div>
            </form>
        </section>

        <section class="card panel">
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Estado</th>
                        <th>Severidad</th>
                        <th>Tipo / origen</th>
                        <th>Mensaje</th>
                        <th>Identificador técnico</th>
                        <th>Última detección</th>
                        <th>Resolución</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($alerts as $alert)
                        <tr>
                            <td>{{ $alert->state === 'open' ? 'Abierta' : 'Resuelta' }}</td>
                            <td>{{ ucfirst($alert->severity) }}</td>
                            <td>{{ $alert->type }}<br><span class="small">{{ $alert->source }}</span></td>
                            <td>{{ $alert->message }}<br><span class="small">{{ $alert->occurrences }} detección(es)</span></td>
                            <td>{{ $alert->technical_identifier }}</td>
                            <td>{{ optional($alert->last_detected_at)->format('d/m/Y H:i:s') }}</td>
                            <td>
                                {{ $alert->resolution ?: '-' }}
                                @if ($alert->resolved_at)
                                    <br><span class="small">{{ $alert->resolved_at->format('d/m/Y H:i:s') }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7">No hay alertas para los filtros seleccionados.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            <div class="pagination-wrap">{{ $alerts->links() }}</div>
        </section>
    </main>
</div>
</body>
</html>
