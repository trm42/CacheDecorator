<?php

return [
    'enabled' => env('REPOSITORY_CACHE', true),
    'ttl' => env('REPOSITORY_CACHE_TTL', 300),
    'use_tags' => env('REPOSITORY_CACHE_TAGS', true),

];