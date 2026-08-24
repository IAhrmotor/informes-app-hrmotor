<?php

return [
    'purchase_rentability_field' => env('SALESFORCE_COMMISSIONS_PURCHASE_RENTABILITY_FIELD', 'informe_rentabilidad'),
    'sale_management_field' => env('SALESFORCE_COMMISSIONS_SALE_MANAGEMENT_FIELD', 'gestion_de_venta'),
    // Special financial rules use stable responsible keys and explicit zones.
    // Opportunity owners are commercial users and must not select these rules.
    'financial_special_responsible_net_commission_percentages' => [
        'zona_nuria' => [
            'label' => 'Nuria Moracho',
            'zone_name' => 'Zona Nuria',
            'effective_from' => '2026-06-01',
            'percent' => 0.005,
        ],
        'zona_irene' => [
            'label' => 'Irene Simon',
            'zone_name' => 'Zona Irene',
            'effective_from' => '2026-06-01',
            'percent' => 0.005,
        ],
    ],
];
