<?php

declare(strict_types=1);

// Do not edit. Content will be replaced.
return [
    '/' => [
        'di-web' => [
            'yiisoft/error-handler' => [
                'config/di-web.php',
            ],
            'yiisoft/yii-event' => [
                'config/di-web.php',
            ],
        ],
        'events-web' => [
            'yiisoft/middleware-dispatcher' => [
                'config/events-web.php',
            ],
        ],
        'di' => [
            'yiisoft/yii-event' => [
                'config/di.php',
            ],
        ],
        'di-console' => [
            'yiisoft/yii-event' => [
                'config/di-console.php',
            ],
        ],
        'params-web' => [
            'yiisoft/yii-event' => [
                'config/params-web.php',
            ],
        ],
        'events-console' => [],
        'params-console' => [
            'yiisoft/yii-event' => [
                'config/params-console.php',
            ],
        ],
    ],
];
