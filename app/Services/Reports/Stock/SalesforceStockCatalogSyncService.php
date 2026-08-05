<?php

namespace App\Services\Reports\Stock;

use App\Models\StockCatalogValue;
use App\Services\Salesforce\SalesforceClient;

class SalesforceStockCatalogSyncService
{
    public const OBJECT = 'Product2';
    public const FIELDS = [
        'PRO_SEL_Marca__c', 'PRO_TEX_Modelo__c', 'Segmento__c', 'PRO_SEL_Combustible__c',
        'PRO_SEL_Carroceria__c', 'PRO_SEL_Estado__c', 'Procedencia_de_compra__c',
    ];

    public function __construct(private readonly SalesforceClient $client) {}

    public function sync(): array
    {
        $describe = $this->client->describe(self::OBJECT);
        $fields = collect($describe['fields'] ?? [])->keyBy('name');
        $saved = 0;
        foreach (self::FIELDS as $fieldApiName) {
            $field = $fields->get($fieldApiName);
            if (! is_array($field) || ! in_array($field['type'] ?? null, ['picklist', 'multipicklist'], true)) {
                continue;
            }
            $seen = [];
            foreach ($field['picklistValues'] ?? [] as $value) {
                $apiValue = trim((string) ($value['value'] ?? ''));
                if ($apiValue === '') {
                    continue;
                }
                $seen[] = $apiValue;
                StockCatalogValue::query()->updateOrCreate(
                    ['object_api_name' => self::OBJECT, 'field_api_name' => $fieldApiName, 'api_value' => $apiValue],
                    ['label' => (string) ($value['label'] ?? $apiValue), 'is_active' => (bool) ($value['active'] ?? false), 'synced_at' => now()],
                );
                $saved++;
            }
            StockCatalogValue::query()->where('object_api_name', self::OBJECT)->where('field_api_name', $fieldApiName)
                ->when($seen !== [], fn ($query) => $query->whereNotIn('api_value', $seen))->update(['is_active' => false, 'synced_at' => now()]);
        }

        return ['fields_described' => count(self::FIELDS), 'values_saved' => $saved];
    }
}
