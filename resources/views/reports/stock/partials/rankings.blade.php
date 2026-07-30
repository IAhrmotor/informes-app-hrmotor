<section class="stock-section">
    <div class="card panel stock-context-banner">
        <div>
            <h2>Rankings de rendimiento {{ $filters['delegation'] ? '· '.$filters['delegation'] : '· Total grupo' }}</h2>
            <p>Ordenados por rendimiento respecto al stock, no únicamente por volumen de ventas.</p>
        </div>
        <span @class(['stock-tag','warning'=>$summary['sales_stock_approximate']])>
            {{ $summary['sales_stock_approximate'] ? 'Ventas / stock actual (aprox.)' : 'Ventas / stock medio' }}
        </span>
    </div>
    <div class="stock-rankings-grid">
        @foreach ($rankings as $ranking)
            <article class="card panel stock-ranking">
                <div class="panel-title"><h2>{{ $ranking['label'] }}</h2></div>
                <div class="table-scroll stock-overflow-table">
                    <table class="stock-table">
                        <thead><tr><th>Perfil</th><th>Ventas</th><th>Stock</th><th>Rotación</th><th>Antig. stock</th><th>Ventas/stock</th></tr></thead>
                        <tbody>
                        @forelse($ranking['rows'] as $row)
                            <tr>
                                <td><strong>{{ $row['label'] }}</strong></td>
                                <td>{{ $row['sales'] }}</td>
                                <td>{{ $row['stock'] }}</td>
                                <td>{{ $row['rotation'] !== null ? number_format($row['rotation'],1,',','.').' d' : '—' }}</td>
                                <td>{{ $row['age'] !== null ? number_format($row['age'],1,',','.').' d' : '—' }}</td>
                                <td><span class="stock-tag">{{ number_format($row['performance'],2,',','.') }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="6">Sin datos suficientes.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </article>
        @endforeach
    </div>
</section>
