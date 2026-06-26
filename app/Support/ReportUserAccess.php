<?php

namespace App\Support;

use App\Models\ReportAccessSetting;
use App\Models\ReportUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class ReportUserAccess
{
    private const REPORT_DEFINITIONS = [
        'leads' => [
            'label' => 'Leads',
            'route' => 'reports.leads.index',
            'default_minimum_role' => ReportUser::ROLE_VIEWER,
        ],
        'reservations-sales' => [
            'label' => 'Reservas / Ventas',
            'route' => 'reports.reservations-sales.index',
            'default_minimum_role' => ReportUser::ROLE_VIEWER,
        ],
        'calls' => [
            'label' => 'Llamadas',
            'route' => 'reports.calls.index',
            'default_minimum_role' => ReportUser::ROLE_VIEWER,
        ],
        'campaigns' => [
            'label' => 'Campanas',
            'route' => 'reports.campaigns.index',
            'default_minimum_role' => ReportUser::ROLE_DIRECTOR,
        ],
        'commercial-commissions' => [
            'label' => 'Comisiones Comerciales',
            'route' => 'reports.commercial-commissions.index',
            'default_minimum_role' => ReportUser::ROLE_DIRECTOR,
        ],
    ];
    public static function current(Request $request): ?array
    {
        if (! config('services.informes_auth.enabled', true)) {
            return [
                'id' => null,
                'email' => 'local',
                'role' => ReportUser::ROLE_ADMIN,
            ];
        }

        if (! $request->session()->get('informes_authenticated')) {
            return null;
        }

        return [
            'id' => $request->session()->get('report_user_id'),
            'email' => $request->session()->get('report_user_email', $request->session()->get('informes_user')),
            'role' => $request->session()->get('report_user_role', ReportUser::ROLE_ADMIN),
        ];
    }

    public static function role(Request $request): string
    {
        return (string) (self::current($request)['role'] ?? ReportUser::ROLE_VIEWER);
    }

    public static function isAdmin(Request $request): bool
    {
        return self::normalizeRole(self::role($request)) === ReportUser::ROLE_ADMIN;
    }

    public static function isDirector(Request $request): bool
    {
        return in_array(self::normalizeRole(self::role($request)), [
            ReportUser::ROLE_DIRECTOR,
            'direction',
            'direccion',
        ], true);
    }

    public static function canViewCampaigns(Request $request): bool
    {
        return self::canViewReport($request, 'campaigns');
    }

    public static function canViewCommercialCommissions(Request $request): bool
    {
        return self::canViewReport($request, 'commercial-commissions');
    }

    public static function canViewLeads(Request $request): bool
    {
        return self::canViewReport($request, 'leads');
    }

    public static function canViewReservationsSales(Request $request): bool
    {
        return self::canViewReport($request, 'reservations-sales');
    }

    public static function canViewCalls(Request $request): bool
    {
        return self::canViewReport($request, 'calls');
    }

    public static function canManageReportUsers(Request $request): bool
    {
        return self::isAdmin($request);
    }

    public static function canExport(Request $request): bool
    {
        return self::isAdmin($request);
    }

    public static function canSeeSyncDiagnostics(Request $request): bool
    {
        return self::isAdmin($request);
    }

    private static function normalizeRole(string $role): string
    {
        return trim(mb_strtolower($role));
    }

    public static function canViewReport(Request $request, string $reportKey): bool
    {
        $currentRole = self::canonicalRole(self::role($request));
        $minimumRole = self::canonicalRole(self::minimumRoleForReport($reportKey));

        if ($currentRole === null || $minimumRole === null) {
            return false;
        }

        return ReportUser::roleWeight($currentRole) >= ReportUser::roleWeight($minimumRole);
    }

    public static function minimumRoleForReport(string $reportKey): string
    {
        $settings = self::minimumRolesByReport();
        $fallback = self::reportDefinitions()[$reportKey]['default_minimum_role'] ?? ReportUser::ROLE_ADMIN;

        return $settings[$reportKey] ?? $fallback;
    }

    public static function minimumRolesByReport(): array
    {
        $defaults = [];

        foreach (self::reportDefinitions() as $reportKey => $definition) {
            $defaults[$reportKey] = $definition['default_minimum_role'];
        }

        if (! Schema::hasTable('report_access_settings')) {
            return $defaults;
        }

        $stored = ReportAccessSetting::query()
            ->get(['report_key', 'minimum_role'])
            ->pluck('minimum_role', 'report_key')
            ->all();

        foreach ($stored as $reportKey => $minimumRole) {
            if (array_key_exists($reportKey, self::reportDefinitions()) && self::canonicalRole($minimumRole) !== null) {
                $defaults[$reportKey] = self::canonicalRole($minimumRole);
            }
        }

        return $defaults;
    }

    public static function flushResolvedSettings(): void
    {
        // Compatibility no-op. Access settings are resolved fresh on each call.
    }

    public static function reportDefinitions(): array
    {
        return self::REPORT_DEFINITIONS;
    }

    public static function defaultAccessibleRouteName(Request $request): ?string
    {
        foreach (self::reportDefinitions() as $reportKey => $definition) {
            if (self::canViewReport($request, $reportKey)) {
                return $definition['route'];
            }
        }

        return null;
    }

    public static function roleOptions(): array
    {
        return ReportUser::roleOptions();
    }

    private static function canonicalRole(?string $role): ?string
    {
        return match (self::normalizeRole((string) $role)) {
            ReportUser::ROLE_ADMIN => ReportUser::ROLE_ADMIN,
            ReportUser::ROLE_DIRECTOR, 'direction', 'direccion' => ReportUser::ROLE_DIRECTOR,
            ReportUser::ROLE_AREA_MANAGER => ReportUser::ROLE_AREA_MANAGER,
            ReportUser::ROLE_VIEWER => ReportUser::ROLE_VIEWER,
            default => null,
        };
    }
}
