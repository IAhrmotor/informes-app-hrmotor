<?php

namespace App\Services\Reports\CommercialCommissions;

use Carbon\CarbonImmutable;

class CommissionMonthResolver
{
    /** @return array{month: CarbonImmutable, warning: ?string, is_current: bool, status: string} */
    public function resolveWithContext(?string $month): array
    {
        $currentMonth = CarbonImmutable::now(config('app.timezone'))->startOfMonth();
        $warning = null;

        if (is_string($month) && preg_match('/^\d{4}-\d{2}$/', $month) === 1) {
            try {
                $selectedMonth = CarbonImmutable::createFromFormat('!Y-m', $month, config('app.timezone'))->startOfMonth();
            } catch (\Throwable) {
                $selectedMonth = $currentMonth;
                $warning = 'El mes solicitado no es válido. Se ha cargado el mes actual.';
            }
        } else {
            $selectedMonth = $currentMonth;
            if (filled($month)) {
                $warning = 'El mes solicitado no es válido. Se ha cargado el mes actual.';
            }
        }

        if ($selectedMonth->greaterThan($currentMonth)) {
            $selectedMonth = $currentMonth;
            $warning = 'No se pueden consultar meses futuros. Se ha cargado el mes actual.';
        }

        $isCurrent = $selectedMonth->equalTo($currentMonth);

        return [
            'month' => $selectedMonth,
            'warning' => $warning,
            'is_current' => $isCurrent,
            'status' => $isCurrent ? 'provisional' : 'pending_approval',
        ];
    }

    public function resolve(?string $month): CarbonImmutable
    {
        return $this->resolveWithContext($month)['month'];
    }
}
