<?php
// app/Http/Middleware/GlideFallback.php

namespace App\Http\Middleware;

use Closure;

class GlideFallback
{
    public function handle($request, Closure $next)
    {
        try {
            return $next($request);
        } catch (\ArgumentCountError $e) {
            if (str_contains($e->getMessage(), 'GlideController::generateByPath')) {
                // Возвращаем заглушку для изображений Glide
                return response()->file(public_path('assets/placeholder.jpg'));
            }
            throw $e;
        }
    }
}