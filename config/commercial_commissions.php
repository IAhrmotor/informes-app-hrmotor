<?php

return [
    'purchase_rentability_field' => env('SALESFORCE_COMMISSIONS_PURCHASE_RENTABILITY_FIELD', 'informe_rentabilidad'),
    'sale_management_field' => env('SALESFORCE_COMMISSIONS_SALE_MANAGEMENT_FIELD', 'gestion_de_venta'),
    // Personal financial exceptions are keyed exclusively by Salesforce User ID.
    // Labels are informational and must never be used to select a rule.
    'financial_special_user_net_commission_percentages' => [
        '005Qx00000Bzv33IAB' => [
            'label' => 'Nuria Moracho',
            'effective_from' => '2026-06-01',
            'percent' => 0.005,
        ],
        '005Qx00000E7ZQnIAN' => [
            'label' => 'Irene Simon',
            'effective_from' => '2026-06-01',
            'percent' => 0.005,
        ],
    ],
];
