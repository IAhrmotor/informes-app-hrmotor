<article class="stock-recommendation-card">
    <div class="stock-recommendation-position">{{ $position }}</div>
    <div>
        <h3>{{ $recommendation['delegation'] }}</h3>
        <p>{{ $recommendation['model_sales'] }} ventas del modelo · {{ $recommendation['same_model_stock'] }} unidades actuales · {{ $recommendation['free_capacity'] }} plazas</p>
        <ul>@foreach($recommendation['reasons'] as $reason)<li>{{ $reason }}</li>@endforeach</ul>
    </div>
</article>
