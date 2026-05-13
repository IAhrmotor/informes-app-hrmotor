<?php

namespace App\Services\Salesforce;

use Illuminate\Http\Client\Response;
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
        $response = $this->sendQuery($auth, $soql);

        if ($response->status() === 401) {
            $this->authService->clearToken();

            $auth = $this->authService->accessToken();
            $response = $this->sendQuery($auth, $soql);
        }

        if (! $response->successful()) {
            throw new RuntimeException(
                'Error consultando Salesforce SOQL: '.$response->status().' '.$this->sanitizeBody($response->body())
            );
        }

        return $this->collectPaginatedResults($auth, $response->json() ?? []);
    }

    private function sendQuery(array $auth, string $soql): Response
    {
        return Http::withToken($auth['access_token'])
            ->acceptJson()
            ->get($this->queryUrl($auth), [
                'q' => $soql,
            ]);
    }

    private function queryUrl(array $auth): string
    {
        return rtrim($auth['instance_url'], '/')
            .'/services/data/'
            .config('salesforce.api_version')
            .'/query';
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

            if ($response->status() === 401) {
                $this->authService->clearToken();
                $auth = $this->authService->accessToken();

                $response = Http::withToken($auth['access_token'])
                    ->acceptJson()
                    ->get(rtrim($auth['instance_url'], '/').$nextRecordsUrl);
            }

            if (! $response->successful()) {
                throw new RuntimeException(
                    'Error paginando Salesforce SOQL: '.$response->status().' '.$this->sanitizeBody($response->body())
                );
            }

            $page = $response->json() ?? [];

            $records = array_merge($records, $page['records'] ?? []);
            $done = (bool) ($page['done'] ?? true);
            $nextRecordsUrl = $page['nextRecordsUrl'] ?? null;
        }

        return $records;
    }

    private function sanitizeBody(?string $body): string
    {
        $body = (string) $body;

        foreach ([
            config('salesforce.client_secret'),
            config('salesforce.client_id'),
            config('salesforce.refresh_token'),
        ] as $secret) {
            if (filled($secret)) {
                $body = str_replace((string) $secret, '[redacted]', $body);
            }
        }

        return $body;
    }
}
