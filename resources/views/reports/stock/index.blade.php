<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Stock | HR Motor - Informes comerciales</title>
    <link rel="icon" href="/brand/favicon.ico" sizes="any">
    @include('partials.font-assets')
    @vite([
        'resources/css/reports/leads-dashboard.css',
        'resources/css/reports/stock-dashboard.css',
        'resources/js/reports/stock-dashboard.js',
    ])
</head>
<body class="stock-report">
<div class="wrap">
    @include('reports.partials.report-header', [
        'currentReport' => 'stock',
        'subtitle' => 'Stock',
        'updatedBadgeText' => $latestSnapshotDate
            ? 'Fotografía de stock: '.\Carbon\CarbonImmutable::parse($latestSnapshotDate)->format('d/m/Y')
            : 'Sin fotografía diaria',
    ])

    <main>
        <section class="header stock-title">
            <div>
                <div class="eyebrow">Stock, ventas y logística</div>
                <h1>Análisis integral del stock</h1>
                <p class="sub">Situación actual, rendimiento por tienda, rotación y recomendaciones logísticas explicadas.</p>
            </div>
            <div class="stock-title-meta">
                <span>{{ number_format($saleSnapshotsCount, 0, ',', '.') }} snapshots económicos</span>
                <span>{{ $stockHistory['days'] }} fotografías diarias</span>
                @if ($summary['sales_stock_approximate'])
                    <span class="stock-warning-pill">Ventas/stock: aproximación</span>
                @endif
            </div>
        </section>

        @php
            $tabQuery = request()->except('section');
        @endphp
        <nav class="tabs-main stock-section-nav" aria-label="Secciones de stock">
            @foreach ([
                'summary' => 'Resumen',
                'delegations' => 'Delegaciones y ventas',
                'recommendations' => 'Recomendaciones',
                'vehicles' => 'Vehículos',
            ] as $key => $label)
                <a @class(['main-tab', 'active' => $activeStockTab === $key])
                   href="{{ route('reports.stock.index', array_merge($tabQuery, ['section' => $key])) }}">{{ $label }}</a>
            @endforeach
            @if ($isAdmin)
                <a @class(['main-tab', 'active' => $activeStockTab === 'capacities'])
                   href="{{ route('reports.stock.index', ['section' => 'capacities']) }}">Capacidades</a>
            @endif
        </nav>

        @if (session('status'))
            <div class="notice notice-success">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="notice">{{ $errors->first() }}</div>
        @endif

        @unless ($activeStockTab === 'capacities')
            @include('reports.stock.partials.filters')
        @endunless

        @if ($activeStockTab === 'summary')
            @include('reports.stock.partials.summary')
        @elseif ($activeStockTab === 'delegations')
            @include('reports.stock.partials.delegations')
            @include('reports.stock.partials.rankings')
        @elseif ($activeStockTab === 'recommendations')
            @include('reports.stock.partials.recommendations')
        @elseif ($activeStockTab === 'vehicles')
            @include('reports.stock.partials.vehicles')
        @elseif ($isAdmin && $activeStockTab === 'capacities')
            @include('reports.stock.partials.capacities')
        @endif
    </main>
</div>
</body>
</html>
