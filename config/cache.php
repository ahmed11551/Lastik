<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

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
