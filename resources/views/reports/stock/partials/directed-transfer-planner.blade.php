<article class="card panel stock-transfer-planner">
    <div class="panel-title">
        <div>
            <h2>Planificador de traslado</h2>
            <div class="small">Selecciona origen, destino y capacidad del camión. La propuesta prioriza el encaje comercial con el destino y no aplica los filtros generales del informe.</div>
        </div>
    </div>

    <form method="GET" action="{{ route('reports.stock.index') }}" class="stock-transfer-form">
        <input type="hidden" name="section" value="recommendations">
        <input type="hidden" name="transfer_plan" value="1">
        <div class="filter-group">
            <label for="transfer_origin_id">Delegación de origen</label>
            <select id="transfer_origin_id" name="transfer_origin_id" required>
                <option value="">Seleccionar origen</option>
                @foreach ($transferOriginDelegations as $delegation)
                    <option value="{{ $delegation->id }}" @selected((string) old('transfer_origin_id', request('transfer_origin_id')) === (string) $delegation->id)>{{ $delegation->canonical_name }}</option>
                @endforeach
            </select>
            @error('transfer_origin_id')<small class="stock-field-error">{{ $message }}</small>@enderror
        </div>
        <div class="filter-group">
            <label for="transfer_destination_id">Delegación de destino</label>
            <select id="transfer_destination_id" name="transfer_destination_id" required>
                <option value="">Seleccionar destino</option>
                @foreach ($transferDestinationDelegations as $delegation)
                    <option value="{{ $delegation->id }}" @selected((string) old('transfer_destination_id', request('transfer_destination_id')) === (string) $delegation->id)>{{ $delegation->canonical_name }}</option>
                @endforeach
            </select>
            @error('transfer_destination_id')<small class="stock-field-error">{{ $message }}</small>@enderror
        </div>
        <div class="filter-group">
            <label for="transfer_units">Número de vehículos</label>
            <input id="transfer_units" name="transfer_units" type="number" min="1" max="{{ config('stock.directed_transfer_max_units', 150) }}" step="1" value="{{ old('transfer_units', request('transfer_units')) }}" required>
            @error('transfer_units')<small class="stock-field-error">{{ $message }}</small>@enderror
        </div>
        <button class="main-tab active" type="submit">Calcular propuesta</button>
    </form>

    <div class="notice stock-transfer-readonly">
        Esta propuesta es una simulación y no realiza movimientos ni reservas en Salesforce.
    </div>

    @if ($directedTransferPlan)
        @php($capacity = $directedTransferPlan['capacity'])
        <section class="stock-transfer-result" aria-labelledby="stock-transfer-result-title">
            <div class="panel-title">
                <div>
                    <h3 id="stock-transfer-result-title">{{ $directedTransferPlan['origin']->canonical_name }} → {{ $directedTransferPlan['destination']->canonical_name }}</h3>
                    <div class="small">Resultado ordenado por score comercial, prioridad, antigüedad e identificador estable.</div>
                </div>
            </div>

            <div class="stock-transfer-summary" aria-label="Resumen de la simulación">
                @foreach ([
                    ['Solicitados', $directedTransferPlan['requested_units']],
                    ['Propuestos', $directedTransferPlan['proposed_units']],
                    ['Faltantes', $directedTransferPlan['missing_units']],
                    ['Stock actual destino', $capacity['current_stock']],
                    ['Capacidad', $capacity['configured'] ?? 'No configurada'],
                    ['Plazas libres actuales', $capacity['current_free_places'] ?? 'No calculable'],
                    ['Stock previsto', $capacity['projected_stock']],
                    ['Ocupación prevista', $capacity['projected_occupancy'] !== null ? number_format($capacity['projected_occupancy'], 1, ',', '.').' %' : 'No calculable'],
                ] as [$label, $value])
                    <div class="stock-quality-item">
                        <span>{{ $label }}</span>
                        <strong>{{ is_int($value) ? number_format($value, 0, ',', '.') : $value }}</strong>
                    </div>
                @endforeach
            </div>

            @if ($directedTransferPlan['missing_units'] > 0)
                <div class="notice stock-transfer-warning">
                    Solo hay {{ $directedTransferPlan['proposed_units'] }} candidatos válidos para {{ $directedTransferPlan['requested_units'] }} plazas; faltan {{ $directedTransferPlan['missing_units'] }} vehículos.
                </div>
            @endif

            @if ($capacity['configured'] === null)
                <div class="notice stock-transfer-warning">El destino no tiene capacidad configurada. La propuesta se calcula, pero no puede validarse su ocupación ni sobrecapacidad.</div>
            @elseif ($capacity['current_excess'] > 0)
                <div class="notice stock-transfer-danger">El destino ya supera su capacidad en {{ $capacity['current_excess'] }} {{ $capacity['current_excess'] === 1 ? 'plaza' : 'plazas' }}. Tras la propuesta, el exceso previsto sería de {{ $capacity['projected_excess'] }} {{ $capacity['projected_excess'] === 1 ? 'plaza' : 'plazas' }}.</div>
            @elseif ($capacity['projected_excess'] > 0)
                <div class="notice stock-transfer-danger">La propuesta excedería la capacidad del destino en {{ $capacity['projected_excess'] }} {{ $capacity['projected_excess'] === 1 ? 'plaza' : 'plazas' }}.</div>
            @else
                <div class="notice notice-success">La capacidad configurada admite los {{ $directedTransferPlan['proposed_units'] }} vehículos propuestos.</div>
            @endif

            <div class="table-scroll stock-overflow-table">
                <table class="stock-table stock-transfer-table">
                    <thead><tr><th>Posición</th><th>Matrícula</th><th>Vehículo</th><th>Días en stock</th><th>Origen</th><th>Prioridad</th><th>Score destino</th><th>Motivos</th></tr></thead>
                    <tbody>
                    @forelse ($directedTransferPlan['rows'] as $row)
                        <tr>
                            <td><strong>{{ $row['position'] }}</strong></td>
                            <td>{{ $row['plate'] ?: $row['id'] }}</td>
                            <td>{{ $row['vehicle'] ?: 'Sin descripción' }}</td>
                            <td>{{ $row['days'] ?? 'Sin fecha' }}</td>
                            <td>{{ $row['origin'] ?: $directedTransferPlan['origin']->canonical_name }}</td>
                            <td><span @class(['stock-tag', 'danger' => $row['review_level'] === 'priority', 'warning' => $row['review_level'] === 'review'])>{{ $row['review_level'] === 'priority' ? 'Prioridad 90' : ($row['review_level'] === 'review' ? 'Prioridad 60' : 'Normal') }}</span></td>
                            <td><strong>{{ number_format($row['score'], 1, ',', '.') }}</strong></td>
                            <td><ul class="stock-transfer-reasons">@foreach ($row['reasons'] as $reason)<li>{{ $reason }}</li>@endforeach</ul></td>
                        </tr>
                    @empty
                        <tr><td colspan="8">No hay vehículos disponibles, operativos y trasladables en la delegación de origen.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <div class="stock-form-actions">
                <a class="secondary-button" href="{{ route('reports.stock.index', ['section' => 'recommendations']) }}">Volver al plan general</a>
            </div>
        </section>
    @endif
</article>
