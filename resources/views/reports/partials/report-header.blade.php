@php
    $currentReport = $currentReport ?? 'leads';
    $tabs = [
        ['key' => 'leads', 'label' => 'Leads', 'subtitle' => 'Captacion y seguimiento comercial', 'route' => 'reports.leads.index'],
        ['key' => 'reservations-sales', 'label' => 'Reservas / Ventas', 'subtitle' => 'Reservas, ventas y contratos', 'route' => 'reports.reservations-sales.index'],
        ['key' => 'calls', 'label' => 'Llamadas', 'subtitle' => 'Actividad telefonica y atencion', 'route' => 'reports.calls.index'],
        ['key' => 'campaigns', 'label' => 'Campañas', 'subtitle' => 'Rentabilidad digital', 'route' => 'reports.campaigns.index'],
    ];

    $visibleTabs = array_values(array_filter($tabs, function (array $tab): bool {
        if ($tab['key'] === 'campaigns') {
            return \App\Support\ReportUserAccess::canViewCampaigns(request());
        }

        return true;
    }));
@endphp

<header class="app-header">
    <div class="header-actions">
        <div class="badge" id="updatedBadge">Cargando datos de Salesforce...</div>
        @if (config('services.informes_auth.enabled'))
            <form method="POST" action="{{ route('logout') }}" class="logout-form">
                @csrf
                <button type="submit" class="logout-button">Cerrar sesión</button>
            </form>
        @endif
    </div>
</header>

<nav class="report-switch" aria-label="Informes comerciales">
    @foreach ($visibleTabs as $tab)
        <a href="{{ route($tab['route']) }}" @class(['active' => $currentReport === $tab['key']])>
            <strong>{{ $tab['label'] }}</strong>
            <span>{{ $tab['subtitle'] }}</span>
        </a>
    @endforeach
</nav>
