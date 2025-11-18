<?php

namespace App\Http\Middleware;

use Closure;

class VerifyTelegramApi
{
    public function handle($request, Closure $next)
    {
        // Проверяем API ключ
        $token = $request->bearerToken();
        $expectedToken = env('TELEGRAM_API_TOKEN');
        
        if (!$token || $token !== $expectedToken) {
            return response()->json([
                'success' => false, 
                'message' => 'Unauthorized'
            ], 401);
        }

        return $next($request);
    }
}
protected $except = [
    'api/*',
    'telegram-webhook',
    '/api/telegram-property',
    '/api/telegram-property/*',
       'telegram-property*'
];