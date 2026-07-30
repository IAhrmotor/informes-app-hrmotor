<?php

return [
    'alert_email' => env('STOCK_ALERT_EMAIL', 'sara.lopez@hrmotor.com'),
    'recommendation_weights' => [
        'model_sale' => 9.0,
        'brand_sale' => 2.5,
        'segment_sale' => 1.6,
        'fuel_sale' => 1.2,
        'price_band_sale' => 1.8,
        'fast_rotation' => 12.0,
        'free_capacity' => 1.2,
        'same_model_stock' => 15.0,
        'old_same_model_stock' => 10.0,
        'similar_stock' => 1.0,
        'no_history' => 30.0,
    ],
    'review_days' => 60,
    'priority_days' => 90,
    'duplicate_model_priority' => 3,
    'clearly_better_score_delta' => 40,
    'ranking_limit' => 10,
];
