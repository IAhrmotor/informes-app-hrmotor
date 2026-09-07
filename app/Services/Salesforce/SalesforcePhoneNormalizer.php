<?php

namespace App\Services\Salesforce;

class SalesforcePhoneNormalizer
{
    public function normalize(mixed $value): ?string
    {
        $value = preg_replace('/\D+/', '', (string) $value);
        $value = preg_replace('/^34(?=\d{9}$)/', '', $value ?? '');

        return $value !== '' ? $value : null;
    }
}
