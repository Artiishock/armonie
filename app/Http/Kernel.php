<?php
protected $middleware = [
    \Fruitcake\Cors\HandleCors::class,
      \App\Http\Middleware\GlideFallback::class,
    // другие middleware
];