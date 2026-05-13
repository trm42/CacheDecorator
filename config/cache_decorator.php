<?php

return [
    'enabled' => env('CACHE_DECORATOR_ENABLED', true),
    // TTL in seconds (Laravel 5.8+ semantic). Default: 5 minutes.
    'ttl' => env('CACHE_DECORATOR_TTL', 300),
    'use_tags' => env('CACHE_DECORATOR_TAGS', true),

];
