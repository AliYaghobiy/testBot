<?php

return [
    'host' => env('ELASTICSEARCH_HOST', 'localhost') . ':' . env('ELASTICSEARCH_PORT', '9200'),
    'index_prefix' => env('ELASTICSEARCH_INDEX_PREFIX', 'laravel_'),
    'connection_timeout' => env('ELASTICSEARCH_CONNECTION_TIMEOUT', 30),
    'request_timeout' => env('ELASTICSEARCH_REQUEST_TIMEOUT', 30),
];
