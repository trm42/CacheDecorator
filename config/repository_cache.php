<?php

return [
    'enabled' => env('REPOSITORY_CACHE', true),
    // TTL in seconds (Laravel 5.8+ semantic). Default: 5 minutes.
    'ttl' => env('REPOSITORY_CACHE_TTL', 300),
    'use_tags' => env('REPOSITORY_CACHE_TAGS', true),

];
