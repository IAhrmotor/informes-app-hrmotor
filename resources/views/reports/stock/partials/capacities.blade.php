<section class="stock-section">
    <div class="stock-capacity-layout">
        <article class="card panel">
            <div class="panel-title"><div><h2>Importar capacidades</h2><div class="small">Se utilizará exclusivamente la columna “Plazas totales”. Admite XLSX y CSV.</div></div></div>
            <form method="POST" action="{{ route('reports.stock.capacities.import') }}" enctype="multipart/form-data" class="stock-upload-form">
                @csrf
                <div class="filter-group"><label for="capacity_file">Archivo de capacidades</label><input id="capacity_file" type="file" name="capacity_file" accept=".xlsx,.csv,.txt" required></div>
                <div class="filter-group"><label for="delimiter">Separador CSV</label><select id="delimiter" name="delimiter"><option value=",">Coma</option><option value=";">Punto y coma</option></select></div>
                <button type="submit" class="main-tab active">Subir y aplicar archivo</button>
            </form>
        </article>
        <article class="card panel stock-capacity-help">
            <h2>Criterio aplicado</h2>
            <ul><li>Solo las cuatro ubicaciones acordadas son no comerciales.</li><li>Sin capacidad no se participa en recomendaciones.</li><li>Una importación sustituye las capacidades anteriores.</li><li>La edición manual permite correcciones puntuales.</li></ul>
        </article>
    </div>
    <article class="card panel">
        <div class="panel-title"><div><h2>Edición manual de capacidades</h2><div class="small">La clasificación comercial no depende de que la capacidad esté informada.</div></div></div>
        <form method="POST" action="{{ route('reports.stock.capacities.update') }}">@csrf @method('PUT')
            <div class="table-scroll"><table class="stock-table stock-capacity-table"><thead><tr><th>Delegación normalizada</th><th>Nombre Salesforce</th><th>Origen capacidad</th><th>Plazas totales</th></tr></thead><tbody>
            @foreach($capacityDelegations as $delegation)
                <tr><td><strong>{{ $delegation->canonical_name }}</strong>@if($delegation->is_commercial)<span class="stock-tag">Comercial</span>@endif</td><td>{{ $delegation->salesforce_name ?: '—' }}</td><td>{{ $delegation->capacity_source_name ?: 'Sin capacidad' }}</td><td><input type="number" min="0" max="10000" name="capacities[{{ $delegation->id }}]" value="{{ old('capacities.'.$delegation->id,$delegation->capacity_total) }}" aria-label="Plazas totales de {{ $delegation->canonical_name }}"></td></tr>
            @endforeach
            </tbody></table></div>
            <div class="stock-form-actions"><button type="submit" class="main-tab active">Guardar capacidades</button></div>
        </form>
    </article>
</section>
