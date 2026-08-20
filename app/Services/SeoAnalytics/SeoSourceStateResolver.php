<?php

namespace App\Services\SeoAnalytics;

use App\Models\ReportSyncRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class SeoSourceStateResolver
{
    public function latestCompletedRun(
        string $dataset,
        ?string $sourceIdentifier = null,
        string $identifierStat = 'property',
    ): ?ReportSyncRun {
        return ReportSyncRun::query()
            ->where('dataset', $dataset)
            ->where('status', 'completed')
            ->whereNotNull('source_cutoff_at')
            ->latest('completed_at')
            ->limit(50)
            ->get()
            ->first(
                fn (ReportSyncRun $run): bool => $this->matchesIdentifier($run, $sourceIdentifier, $identifierStat)
            );
    }

    public function latestRun(
        string $dataset,
        ?string $sourceIdentifier = null,
        string $identifierStat = 'property',
    ): ?ReportSyncRun {
        return $this->runs($dataset)->first(
            fn (ReportSyncRun $run): bool => $this->matchesIdentifier($run, $sourceIdentifier, $identifierStat)
        );
    }

    public function cutoff(?ReportSyncRun $run): ?CarbonImmutable
    {
        return $run?->source_cutoff_at
            ? CarbonImmutable::parse($run->source_cutoff_at)
            : null;
    }

    /** @return Collection<int, ReportSyncRun> */
    private function runs(string $dataset): Collection
    {
        return ReportSyncRun::query()
            ->where('dataset', $dataset)
            ->latest('started_at')
            ->limit(50)
            ->get();
    }

    private function matchesIdentifier(
        ReportSyncRun $run,
        ?string $sourceIdentifier,
        string $identifierStat,
    ): bool {
        if ($sourceIdentifier === null) {
            return true;
        }

        $runIdentifier = data_get($run->stats, $identifierStat);

        return is_string($runIdentifier) && hash_equals($sourceIdentifier, $runIdentifier);
    }
}
