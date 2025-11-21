<?php

namespace App\Http\Middleware;

use Closure;
use Statamic\Facades\Site;

class SetCurrentSite
{
    public function handle($request, Closure $next)
    {
        $path = $request->path();
        
        // Определяем сайт по URL
        if (str_starts_with($path, 'en/') || $path === 'en') {
            Site::setCurrent('english');
        } elseif (str_starts_with($path, 'uk/') || $path === 'uk') {
            Site::setCurrent('ukrainian');
        } else {
            Site::setCurrent('default');
        }
        
        return $next($request);
    }
}