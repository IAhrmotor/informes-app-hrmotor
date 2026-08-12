<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Report diagnostics
    |--------------------------------------------------------------------------
    |
    | This is deliberately independent of APP_DEBUG. Detailed Server-Timing
    | diagnostics are emitted only for administrators by ReportUserAccess.
    |
    */
    'server_timing' => (bool) env('REPORT_SERVER_TIMING', false),
];
