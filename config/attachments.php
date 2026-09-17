<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Maximum Upload Size (KB)
    |--------------------------------------------------------------------------
    |
    | Enforced by SendMessageRequest's validation rule. Note this is also
    | bounded by PHP's own upload_max_filesize/post_max_size ini settings -
    | if those are lower than this value, PHP will reject the upload before
    | Laravel's validation even runs.
    |
    */

    'max_size_kb' => (int) env('ATTACHMENT_MAX_SIZE_KB', 5120),

    /*
    |--------------------------------------------------------------------------
    | Maximum Extracted Characters
    |--------------------------------------------------------------------------
    |
    | A PDF/TXT file's extracted text is truncated to this length before
    | being stored and replayed into LLM requests, so one large attachment
    | can't blow out the context window or a provider's request size limit.
    |
    */

    'max_extract_chars' => (int) env('ATTACHMENT_MAX_EXTRACT_CHARS', 15000),

    /*
    |--------------------------------------------------------------------------
    | Storage Disk
    |--------------------------------------------------------------------------
    |
    | Attachments are stored on a private (non-public) disk - there is no
    | download/serve route, since the only thing the app does with them is
    | extract text for the LLM.
    |
    */

    'disk' => env('ATTACHMENT_DISK', 'local'),

];
