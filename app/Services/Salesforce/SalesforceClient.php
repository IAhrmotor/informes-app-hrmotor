<?php

namespace App\Services\Salesforce;

use Generator;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SalesforceClient
{
    public function __construct(
        private readonly SalesforceAuthService $authService,
    ) {}

    public function query(string $soql): array
    {
        return $this->runQuery($soql, false);
    }

    public function queryAll(string $soql): array
    {
        return $this->runQuery($soql, true);
    }

    private function runQuery(string $soql, bool $includeDeleted): array
    {
        $records = [];

        foreach ($this->queryPages($soql, $includeDeleted) as $page) {
            $records = array_merge($records, $page);
        }

        return $records;
    }

    /** @return Generator<int, array<int, array<string, mixed>>> */
    public function queryPages(string $soql, bool $includeDeleted = false): Generator
    {
        $auth = $this->authService->accessToken();
        $response = $this->sendQuery($auth, $soql, $includeDeleted);

        if ($response->status() === 401) {
            $this->authService->clearToken();

            $auth = $this->authService->accessToken();
            $response = $this->sendQuery($auth, $soql, $includeDeleted);
        }

        if (! $response->successful()) {
            throw new RuntimeException(
                'Error consultando Salesforce SOQL: '.$response->status().' '.$this->sanitizeBody($response->body())
            );
        }

        $page = $response->json() ?? [];

        while (true) {
            yield $page['records'] ?? [];

            if ((bool) ($page['done'] ?? true) || blank($page['nextRecordsUrl'] ?? null)) {
                break;
            }

            $nextRecordsUrl = $page['nextRecordsUrl'];
            $response = Http::withToken($auth['access_token'])
                ->timeout((int) config('salesforce.timeout', 120))
                ->acceptJson()
                ->get(rtrim($auth['instance_url'], '/').$nextRecordsUrl);

            if ($response->status() === 401) {
                $this->authService->clearToken();
                $auth = $this->authService->accessToken();
                $response = Http::withToken($auth['access_token'])
                    ->timeout((int) config('salesforce.timeout', 120))
                    ->acceptJson()
                    ->get(rtrim($auth['instance_url'], '/').$nextRecordsUrl);
            }

            if (! $response->successful()) {
                throw new RuntimeException(
                    'Error paginando Salesforce SOQL: '.$response->status().' '.$this->sanitizeBody($response->body())
                );
            }

            $page = $response->json() ?? [];
        }
    }

    public function create(string $object, array $fields): string
    {
        $auth = $this->authService->accessToken();
        $response = $this->sendCreate($auth, $object, $fields);
        if ($response->status() === 401) {
            $this->authService->clearToken();
            $auth = $this->authService->accessToken();
            $response = $this->sendCreate($auth, $object, $fields);
        }
        if (! $response->successful() || blank($response->json('id'))) {
            throw new RuntimeException('Error creando '.$object.' en Salesforce: '.$response->status().' '.$this->sanitizeBody($response->body()));
        }

        return (string) $response->json('id');
    }

    public function update(string $object, string $id, array $fields): void
    {
        $auth = $this->authService->accessToken();
        $response = $this->sendUpdate($auth, $object, $id, $fields);
        if ($response->status() === 401) {
            $this->authService->clearToken();
            $auth = $this->authService->accessToken();
            $response = $this->sendUpdate($auth, $object, $id, $fields);
        }
        if (! $response->successful()) {
            throw new RuntimeException('Error actualizando '.$object.' en Salesforce: '.$response->status().' '.$this->sanitizeBody($response->body()));
        }
    }

    private function sendCreate(array $auth, string $object, array $fields): Response
    {
        return Http::withToken($auth['access_token'])
            ->timeout((int) config('salesforce.timeout', 120))
            ->acceptJson()
            ->post($this->sObjectUrl($auth, $object), $fields);
    }

    private function sendUpdate(array $auth, string $object, string $id, array $fields): Response
    {
        return Http::withToken($auth['access_token'])
            ->timeout((int) config('salesforce.timeout', 120))
            ->acceptJson()
            ->patch($this->sObjectUrl($auth, $object).'/'.$id, $fields);
    }

    private function sObjectUrl(array $auth, string $object): string
    {
        return rtrim($auth['instance_url'], '/').'/services/data/'.config('salesforce.api_version').'/sobjects/'.$object;
    }

    private function sendQuery(array $auth, string $soql, bool $includeDeleted = false): Response
    {
        return Http::withToken($auth['access_token'])
            ->timeout((int) config('salesforce.timeout', 120))
            ->acceptJson()
            ->get($this->queryUrl($auth, $includeDeleted), [
                'q' => $soql,
            ]);
    }

    private function queryUrl(array $auth, bool $includeDeleted = false): string
    {
        return rtrim($auth['instance_url'], '/')
            .'/services/data/'
            .config('salesforce.api_version')
            .($includeDeleted ? '/queryAll' : '/query');
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
