<?php

return [
    'key' => env('ELASTIC_EMAIL_API_KEY'),

    'connect_timeout' => env('ELASTIC_EMAIL_CONNECT_TIMEOUT', 5),

    'timeout' => env('ELASTIC_EMAIL_TIMEOUT', 15),

    'max_body_bytes' => env('ELASTIC_EMAIL_MAX_BODY_BYTES', 2_000_000),

    'max_attachments' => env('ELASTIC_EMAIL_MAX_ATTACHMENTS', 10),

    'max_attachment_bytes' => env('ELASTIC_EMAIL_MAX_ATTACHMENT_BYTES', 10_000_000),

    'max_message_bytes' => env('ELASTIC_EMAIL_MAX_MESSAGE_BYTES', 15_000_000),

    'max_payload_bytes' => env('ELASTIC_EMAIL_MAX_PAYLOAD_BYTES', 20_000_000),

    'max_recipients' => env('ELASTIC_EMAIL_MAX_RECIPIENTS', 1_000),

    'max_header_bytes' => env('ELASTIC_EMAIL_MAX_HEADER_BYTES', 16_384),

    'max_metadata_bytes' => env('ELASTIC_EMAIL_MAX_METADATA_BYTES', 262_144),

    'max_response_bytes' => env('ELASTIC_EMAIL_MAX_RESPONSE_BYTES', 65_536),
];
