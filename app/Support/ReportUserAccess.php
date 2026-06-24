<?php

namespace App\Support;

use App\Models\ReportUser;
use Illuminate\Http\Request;

class ReportUserAccess
{
    private const COMMERCIAL_COMMISSIONS_ALLOWED_EMAILS = [
        'carlos.torres@hrmotor.es',
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
        return in_array(self::normalizeRole(self::role($request)), [
            ReportUser::ROLE_ADMIN,
            ReportUser::ROLE_DIRECTOR,
            'direction',
            'direccion',
        ], true);
    }

    public static function canViewCommercialCommissions(Request $request): bool
    {
        if (self::isAdmin($request)) {
            return true;
        }

        return in_array(self::normalizeEmail((string) (self::current($request)['email'] ?? '')), self::COMMERCIAL_COMMISSIONS_ALLOWED_EMAILS, true);
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

    private static function normalizeEmail(string $email): string
    {
        return trim(mb_strtolower($email));
    }
}
