<?php

return [
    'api_key' => env('OPENAI_API_KEY'),
    'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
    'timeout' => (int) env('OPENAI_TIMEOUT', 30),
    'enabled' => filter_var(env('OPENAI_ENABLED', false), FILTER_VALIDATE_BOOL),
];
