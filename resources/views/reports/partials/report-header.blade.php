@php
    $currentReport = $currentReport ?? 'leads';
    $subtitle = $subtitle ?? 'Leads';
@endphp

<header class="app-header">
    <div class="brand-block">
        <img src="/brand/logo-horizontal.svg" alt="HR Motor" class="brand-logo">
        <div class="brand-copy">
            <div class="brand-title">HR Motor - Informes comerciales</div>
            <div class="brand-subtitle">{{ $subtitle }}</div>
        </div>
    </div>

    <div class="header-actions">
        <div class="badge" id="updatedBadge">Cargando datos de Salesforce...</div>
    </div>
</header>

<nav class="report-switch" aria-label="Informes comerciales">
    <a href="{{ route('reports.leads.index') }}" @class(['active' => $currentReport === 'leads'])>
        <strong>Leads</strong>
        <span>Captacion y seguimiento comercial</span>
    </a>
    <a href="{{ route('reports.reservations-sales.index') }}" @class(['active' => $currentReport === 'reservations-sales'])>
        <strong>Reservas / Ventas</strong>
        <span>Reservas, ventas y contratos</span>
    </a>
</nav>
