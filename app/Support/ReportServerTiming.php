<?php

namespace App\Support;

use Closure;
use Illuminate\Http\Request;

class ReportServerTiming
{
    /** @var array<string, float> */
    private array $durations = [];

    public static function forRequest(Request $request): ?self
    {
        if (! config('reports.server_timing', false) || ! ReportUserAccess::canSeeSyncDiagnostics($request)) {
            return null;
        }

        return new self;
    }

    public function measure(string $name, Closure $callback): mixed
    {
        $startedAt = hrtime(true);

        try {
            return $callback();
        } finally {
            $this->mark($name, (hrtime(true) - $startedAt) / 1_000_000);
        }
    }

    public function mark(string $name, float $duration = 0.0): void
    {
        $this->durations[$name] = max(0, $duration);
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->durations);
    }

    public function headerValue(): string
    {
        return collect($this->durations)
            ->map(fn (float $duration, string $name): string => sprintf('%s;dur=%.3f', $name, $duration))
            ->implode(', ');
    }
}
