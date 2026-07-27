<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Penalizaciones financieras | HR Motor - Informes comerciales</title>
    <link rel="icon" href="/brand/favicon.ico" sizes="any">
    <link rel="shortcut icon" href="/brand/favicon.ico">
    @include('partials.font-assets')
    @vite(['resources/css/reports/leads-dashboard.css'])
</head>
<body class="campaigns-report report-users-page">
<div class="wrap">
    @include('reports.partials.report-header', ['currentReport' => 'commission-settings', 'currentAdminPage' => 'commission-penalties'])

    <main>
        <section class="header">
            <div>
                <div class="eyebrow">Administracion</div>
                <h1>Penalizaciones financieras</h1>
                <p class="sub">Carga el Excel mensual de cancelaciones anticipadas. El importe se descuenta tal como lo indique Cristina, agrupado por mes y email del comercial.</p>
            </div>
        </section>

        @include('reports.partials.admin-nav', ['currentAdminPage' => 'commission-penalties'])

        @if (session('status'))
            <div class="notice notice-success">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="notice">{{ $errors->first() }}</div>
        @endif

        @if ($migrationPending ?? false)
            <div class="notice">Faltan las tablas de penalizaciones. Ejecuta <code>php artisan migrate</code> para habilitar la carga.</div>
        @else

        <section class="card panel">
            <div class="panel-title">
                <div>
                    <h2>Subir Excel</h2>
                    <div class="small">Formato obligatorio: <strong>Mes comision</strong>, <strong>Email comercial</strong> y <strong>descontar comercial 4%</strong>. Se admiten variaciones de mayusculas, acentos y el texto “a comercial”.</div>
                </div>
            </div>

            <form method="POST" action="{{ route('reports.commission-penalties.store') }}" enctype="multipart/form-data" class="report-user-form-grid">
                @csrf
                <div class="filter-group">
                    <label for="financing_penalties_file">Archivo XLSX</label>
                    <input id="financing_penalties_file" name="file" type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
                </div>
                <div class="filter-actions commission-filter-actions">
                    <button type="submit" class="main-tab active">Importar penalizaciones</button>
                </div>
            </form>

            <p class="small">Una nueva carga sustituye las penalizaciones activas de los meses incluidos en el archivo. Las cargas anteriores se conservan como historial y no se vuelven a sumar.</p>
        </section>

        <section class="card panel">
            <div class="panel-title">
                <div>
                    <h2>Penalizaciones activas por mes</h2>
                    <div class="small">Estos importes se muestran negativos y restan directamente de la comision final.</div>
                </div>
            </div>
            <div class="table-shell">
                <table>
                    <thead><tr><th>Mes</th><th class="num">Filas</th><th class="num">Total activo</th></tr></thead>
                    <tbody>
                    @forelse ($activePenalties as $penalty)
                        <tr>
                            <td>{{ optional($penalty->commission_month)->format('Y-m') }}</td>
                            <td class="num">{{ number_format($penalty->rows_count, 0, ',', '.') }}</td>
                            <td class="num commission-penalty-text">{{ number_format($penalty->total_amount, 2, ',', '.') }} EUR</td>
                        </tr>
                    @empty
                        <tr><td colspan="3">No hay penalizaciones activas cargadas.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="card panel">
            <div class="panel-title">
                <div>
                    <h2>Filas sin match Salesforce</h2>
                    <div class="small">No se aplican hasta que el email coincida con el usuario sincronizado desde Salesforce. Ejecuta la sincronizacion de usuarios despues de revisar el email.</div>
                </div>
            </div>
            <div class="table-shell">
                <table>
                    <thead><tr><th>Mes</th><th>Email comercial</th><th>Hoja</th><th class="num">Fila</th><th class="num">Importe</th></tr></thead>
                    <tbody>
                    @forelse ($unmatchedPenalties as $penalty)
                        <tr>
                            <td>{{ optional($penalty->commission_month)->format('Y-m') }}</td>
                            <td>{{ $penalty->commercial_email }}</td>
                            <td>{{ $penalty->source_sheet ?: '-' }}</td>
                            <td class="num">{{ $penalty->source_row ?: '-' }}</td>
                            <td class="num commission-penalty-text">{{ number_format($penalty->amount, 2, ',', '.') }} EUR</td>
                        </tr>
                    @empty
                        <tr><td colspan="5">Todas las filas activas tienen match con Salesforce.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="card panel">
            <div class="panel-title"><div><h2>Historial de cargas</h2></div></div>
            <div class="table-shell">
                <table>
                    <thead><tr><th>Fecha</th><th>Archivo</th><th>Meses</th><th class="num">Filas</th><th class="num">Sin match</th></tr></thead>
                    <tbody>
                    @forelse ($recentImports as $import)
                        <tr>
                            <td>{{ optional($import->created_at)->format('d/m/Y H:i') }}</td>
                            <td>{{ $import->original_filename }}</td>
                            <td>{{ implode(', ', $import->commission_months ?? []) }}</td>
                            <td class="num">{{ number_format($import->rows_imported, 0, ',', '.') }}</td>
                            <td class="num">{{ number_format($import->rows_unmatched, 0, ',', '.') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5">Aun no se ha cargado ningun archivo.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
        @endif
    </main>
</div>
</body>
</html>
