<x-reports.app-shell title="Penalizaciones financieras" current-admin-page="commission-penalties" body-class="campaigns-report report-users-page">
    <x-slot:head>
    @vite(['resources/css/reports/leads-dashboard.css'])
    </x-slot:head>
<div class="wrap">
    <main>
        <section class="header">
            <div>
                <div class="eyebrow">Administracion</div>
                <h1>Penalizaciones financieras</h1>
                <p class="sub">Carga el Excel mensual de cancelaciones anticipadas. El importe se descuenta tal como lo indique Cristina, agrupado por mes y email del comercial.</p>
            </div>
        </section>

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
                    <div class="small">Formato obligatorio: <strong>Mes comision</strong>, <strong>Email comercial</strong> y <strong>descontar comercial 4%</strong>. Nombre e ID Salesforce son opcionales y solo sirven para auditoria.</div>
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
                    <div class="small">No se aplican hasta que el email comercial coincida con el usuario sincronizado desde Salesforce.</div>
                </div>
            </div>
            <div class="table-shell">
                <table>
                    <thead><tr><th>Mes</th><th>Comercial</th><th>Email</th><th>ID Salesforce</th><th>Hoja</th><th class="num">Fila</th><th class="num">Importe</th></tr></thead>
                    <tbody>
                    @forelse ($unmatchedPenalties as $penalty)
                        <tr>
                            <td>{{ optional($penalty->commission_month)->format('Y-m') }}</td>
                            <td>{{ $penalty->commercial_name ?: '-' }}</td>
                            <td>{{ $penalty->commercial_email ?: '-' }}</td>
                            <td>{{ $penalty->salesforce_user_id ?: '-' }}</td>
                            <td>{{ $penalty->source_sheet ?: '-' }}</td>
                            <td class="num">{{ $penalty->source_row ?: '-' }}</td>
                            <td class="num commission-penalty-text">{{ number_format($penalty->amount, 2, ',', '.') }} EUR</td>
                        </tr>
                    @empty
                        <tr><td colspan="7">Todas las filas activas tienen match con Salesforce.</td></tr>
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
</x-reports.app-shell>
