<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array
     */
    protected $except = [
        // Для формы Statamic
        '!/Statamic/forms/submit/property_form',
        
        // Ваши существующие исключения (перенесены из другого файла)
        'api/*',
        'telegram-webhook',
        '/api/telegram-property',
        '/api/telegram-property/*',
        'telegram-property*',
                'telegram-blok', 
        'submit-application',
        'test-upload',
        'supabase-test',
        'debug-config',
        'health-check',
        'simple-test-upload',
        'test-create-property',
        '*'
    ];
}