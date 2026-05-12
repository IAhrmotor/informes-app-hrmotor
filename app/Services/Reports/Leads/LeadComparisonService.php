<?php

namespace App\Services\Reports\Leads;

class LeadComparisonService
{
    public function diff(float|int|null $current, float|int|null $previous): float|int|null
    {
        if ($current === null || $previous === null) {
            return null;
        }

        return $current - $previous;
    }
}