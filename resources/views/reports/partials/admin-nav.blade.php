@php
    $currentAdminPage = $currentAdminPage ?? 'users';
    $adminLinks = [
        ['key' => 'users', 'label' => 'Usuarios', 'route' => 'reports.users.index'],
        ['key' => 'access-settings', 'label' => 'Permisos informes', 'route' => 'reports.access-settings.index'],
        ['key' => 'commission-settings', 'label' => 'Coeficientes comisiones', 'route' => 'reports.commission-settings.index'],
    ];
@endphp

<nav class="tabs-main admin-nav" aria-label="Administracion de informes">
    @foreach ($adminLinks as $link)
        @if (\Illuminate\Support\Facades\Route::has($link['route']))
            <a
                href="{{ route($link['route']) }}"
                @class(['main-tab', 'active' => $currentAdminPage === $link['key']])
            >
                {{ $link['label'] }}
            </a>
        @endif
    @endforeach
</nav>
