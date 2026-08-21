<?php

namespace App\Services\SeoAnalytics;

use RuntimeException;

final class SeoAnalyticalRuleSetConflictException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Los umbrales han cambiado desde que abriste esta pantalla. Recarga los valores antes de volver a guardar.');
    }
}
