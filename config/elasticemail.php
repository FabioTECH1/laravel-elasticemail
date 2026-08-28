<?php

return [
    'key' => env('ELASTIC_EMAIL_API_KEY'),

    'connect_timeout' => (float) env('ELASTIC_EMAIL_CONNECT_TIMEOUT', 5),

    'timeout' => (float) env('ELASTIC_EMAIL_TIMEOUT', 15),
];
