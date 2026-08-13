@props([
    'title',
    'currentReport' => null,
    'currentAdminPage' => null,
    'bodyClass' => '',
    'updatedBadgeText' => null,
])

@php
    $accessibleReportKeys = \App\Support\ReportUserAccess::accessibleReportKeys(request());
    $reportUser = \App\Support\ReportUserAccess::reportUser(request());
    $reportUserDisplayName = \App\Support\ReportUserAccess::displayName(request());
    $canManageAdministration = \App\Support\ReportUserAccess::canManageReportUsers(request());
    $canManageFinancingPenalties = \App\Support\ReportUserAccess::canManageFinancingPenalties(request());
@endphp

<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ $title }} | HR Motor - Informes comerciales</title>
    <link rel="icon" href="/brand/favicon.ico" sizes="any">
    <link rel="shortcut icon" href="/brand/favicon.ico">
    @include('partials.font-assets')
    {{ $head ?? '' }}
    @vite(['resources/css/reports/app-shell.css'])
    <script>
        (() => {
            try {
                if (localStorage.getItem('hrmotor-report-sidebar') === 'closed') {
                    document.documentElement.classList.add('app-sidebar-precollapsed');
                }
            } catch (_) {}
        })();
    </script>
</head>
<body @class([$bodyClass, 'app-shell-page'])>
<a class="app-skip-link" href="#report-content">Saltar al contenido</a>
<div class="app-shell" data-app-shell>
    @include('reports.partials.app-sidebar', [
        'accessibleReportKeys' => $accessibleReportKeys,
        'currentReport' => $currentReport,
        'currentAdminPage' => $currentAdminPage,
        'canManageAdministration' => $canManageAdministration,
        'canManageFinancingPenalties' => $canManageFinancingPenalties,
    ])

    <button class="app-sidebar-overlay" type="button" data-sidebar-overlay aria-label="Cerrar navegaci&oacute;n" tabindex="-1"></button>

    <div class="app-workspace">
        <header class="app-topbar">
            <div class="app-topbar-primary">
                <button
                    class="app-sidebar-toggle"
                    type="button"
                    data-sidebar-toggle
                    aria-controls="app-sidebar"
                    aria-expanded="true"
                    aria-label="Ocultar navegaci&oacute;n"
                >
                    <span class="app-sidebar-toggle-lines" aria-hidden="true"></span>
                </button>
                <div class="app-page-context">
                    <span>HR Motor</span>
                    <strong>{{ $title }}</strong>
                </div>
            </div>

            <div class="app-topbar-actions">
                @if (filled($updatedBadgeText))
                    <div class="badge" id="updatedBadge">{{ $updatedBadgeText }}</div>
                @endif
                @if (filled($reportUserDisplayName))
                    <div class="app-user" title="{{ \App\Models\ReportUser::roleLabel($reportUser?->role) }}">
                        <span class="app-user-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($reportUserDisplayName, 0, 1)) }}</span>
                        <span class="app-user-name">{{ $reportUserDisplayName }}</span>
                    </div>
                @endif
                <form method="POST" action="{{ route('logout') }}" class="logout-form">
                    @csrf
                    <button type="submit" class="logout-button">Cerrar sesi&oacute;n</button>
                </form>
            </div>
        </header>

        <div class="app-content" id="report-content">
            {{ $slot }}
        </div>
    </div>
</div>
@vite(['resources/js/reports/app-shell.js'])
{{ $scripts ?? '' }}
</body>
</html>
