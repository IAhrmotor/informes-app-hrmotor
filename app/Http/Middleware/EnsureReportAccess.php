<?php

namespace App\Http\Middleware;

use App\Support\ReportUserAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureReportAccess
{
    public function handle(Request $request, Closure $next, string $reportKey): Response
    {
        if (ReportUserAccess::canViewReport($request, $reportKey)) {
            return $next($request);
        }

        $routeName = ReportUserAccess::defaultAccessibleRouteName($request);

        if ($this->mustReturnForbidden($request) || $routeName === null) {
            abort(403);
        }

        return redirect()->route($routeName);
    }

    private function mustReturnForbidden(Request $request): bool
    {
        $routeName = (string) optional($request->route())->getName();

        return $request->expectsJson()
            || str_contains($routeName, '.data.')
            || str_contains($routeName, '.export.');
    }
}
