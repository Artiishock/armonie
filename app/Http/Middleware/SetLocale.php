<?php
public function handle($request, $next)
{
    if ($locale = $request->segment(1)) {
        if (in_array($locale, ['ru', 'fr', 'es'])) {
            app()->setLocale($locale);
        }
    }
    
    return $next($request);
}