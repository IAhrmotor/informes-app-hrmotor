<?php

namespace App\Support;

use App\Models\ReportUser;
use Illuminate\Http\Request;

class ReportUserAccess
{
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
        return self::role($request) === ReportUser::ROLE_ADMIN;
    }

    public static function canExport(Request $request): bool
    {
        return self::isAdmin($request);
    }
}
