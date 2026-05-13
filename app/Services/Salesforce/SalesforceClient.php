<?php

namespace App\Services\Salesforce;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class SalesforceClient
{
    public function __construct(
        private readonly SalesforceAuthService $authService,
    ) {
    }

    public function query(string $soql): array
    {
        $auth = $this->authService->accessToken();

        $url = rtrim($auth['instance_url'], '/')
            .'/services/data/'
            .config('salesforce.api_version')
            .'/query';

        $response = Http::withToken($auth['access_token'])
            ->acceptJson()
            ->get($url, [
                'q' => $soql,
            ]);

        if ($response->status() === 401) {
            $this->authService->clearToken();

            $auth = $this->authService->accessToken();

            $response = Http::withToken($auth['access_token'])
                ->acceptJson()
                ->get($url, [
                    'q' => $soql,
                ]);
        }

        if (! $response->successful()) {
            throw new RuntimeException(
                'Error consultando Salesforce SOQL: '.$response->status().' '.$response->body()
            );
        }

        $data = $response->json();

        return $this->collectPaginatedResults($auth, $data);
    }

    private function collectPaginatedResults(array $auth, array $firstPage): array
    {
        $records = $firstPage['records'] ?? [];
        $done = (bool) ($firstPage['done'] ?? true);
        $nextRecordsUrl = $firstPage['nextRecordsUrl'] ?? null;

        while (! $done && $nextRecordsUrl) {
            $url = rtrim($auth['instance_url'], '/').$nextRecordsUrl;

            $response = Http::withToken($auth['access_token'])
                ->acceptJson()
                ->get($url);

            if (! $response->successful()) {
                throw new RuntimeException(
                    'Error paginando Salesforce SOQL: '.$response->status().' '.$response->body()
                );
            }

            $page = $response->json();

            $records = array_merge($records, $page['records'] ?? []);
            $done = (bool) ($page['done'] ?? true);
            $nextRecordsUrl = $page['nextRecordsUrl'] ?? null;
        }

        return $records;
    }
}
