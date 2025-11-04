<?php

namespace App\Http;

use Illuminate\Foundation\Http\Kernel as HttpKernel;

class Kernel extends HttpKernel
{
    protected $middlewareGroups = [
          \Fruitcake\Cors\HandleCors::class,
      \App\Http\Middleware\GlideFallback::class,
        'web' => [
            \App\Http\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \App\Http\Middleware\VerifyCsrfToken::class, // ⬅️ ЭТА СТРОКА ДОЛЖНА БЫТЬ
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ],

        'api' => [
            // ...
        ],
    ];
}