<?php

namespace App\Services\Salesforce;

use App\Models\LeadRaw;

class SalesforceLeadImporter
{
    public function __construct(
        private readonly SalesforceLeadMapper $mapper
    ) {
    }

    public function import(array $salesforceLeads): int
    {
        $count = 0;

        foreach ($salesforceLeads as $salesforceLead) {
            $mapped = $this->mapper->map($salesforceLead);

            if (blank($mapped['salesforce_id'])) {
                continue;
            }

            LeadRaw::updateOrCreate(
                ['salesforce_id' => $mapped['salesforce_id']],
                $mapped
            );

            $count++;
        }

        return $count;
    }
}