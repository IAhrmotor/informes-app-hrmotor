<?php

namespace App\Services\Reports\CommercialCommissions;

use App\Models\CommercialFinancingPenalty;
use App\Models\SalesforceUser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CommercialFinancingPenaltyService
{
    /**
     * @return array{amounts_by_user_id: array<string, float>, details_by_user_id: array<string, array<int, array<string, mixed>>, unmatched_rows: array<int, array<string, mixed>>}
     */
    public function forMonth(CarbonImmutable $month): array
    {
        if (! Schema::hasTable('commercial_financing_penalties')) {
            return $this->emptyLedger();
        }

        $penalties = CommercialFinancingPenalty::query()
            ->where('is_active', true)
            ->whereDate('commission_month', $month->toDateString())
            ->orderBy('commercial_email')
            ->orderBy('source_sheet')
            ->orderBy('source_row')
            ->get();
        $usersByEmail = SalesforceUser::query()
            ->whereNotNull('email')
            ->get(['salesforce_id', 'name', 'email'])
            ->keyBy(fn (SalesforceUser $user): string => $this->emailKey($user->email));
        $amountsByUserId = [];
        $detailsByUserId = [];
        $unmatchedRows = [];

        foreach ($penalties as $penalty) {
            $user = $usersByEmail->get($this->emailKey($penalty->commercial_email));

            if (! $user instanceof SalesforceUser) {
                $unmatchedRows[] = [
                    'email' => $penalty->commercial_email,
                    'amount' => (float) $penalty->amount,
                    'source_sheet' => $penalty->source_sheet,
                    'source_row' => $penalty->source_row,
                ];
                continue;
            }

            $userId = (string) $user->salesforce_id;
            $amount = (float) $penalty->amount;
            $amountsByUserId[$userId] = round(($amountsByUserId[$userId] ?? 0) + $amount, 2);
            $detailsByUserId[$userId][] = [
                'commercial_email' => $penalty->commercial_email,
                'commercial_name' => $user->name,
                'amount' => $amount,
                'source_sheet' => $penalty->source_sheet,
                'source_row' => $penalty->source_row,
            ];
        }

        return [
            'amounts_by_user_id' => $amountsByUserId,
            'details_by_user_id' => $detailsByUserId,
            'unmatched_rows' => $unmatchedRows,
        ];
    }

    /** @return array{amounts_by_user_id: array<string, float>, details_by_user_id: array<string, array<int, array<string, mixed>>, unmatched_rows: array<int, array<string, mixed>>} */
    private function emptyLedger(): array
    {
        return [
            'amounts_by_user_id' => [],
            'details_by_user_id' => [],
            'unmatched_rows' => [],
        ];
    }

    private function emailKey(?string $email): string
    {
        return Str::lower(trim((string) $email));
    }
}
