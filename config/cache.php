<?php

return [

    'defaults' => [
        'cache.store' => env('CACHE_STORE', 'array'),
        'lifetime' => 3600,
    ],

    'stores' => [
        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],
    ],

];
