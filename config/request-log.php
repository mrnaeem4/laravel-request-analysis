<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Master Switch
    |--------------------------------------------------------------------------
    | When false the middleware does nothing.
    */
    'enabled' => env('REQUEST_LOG_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Log Directory
    |--------------------------------------------------------------------------
    | Absolute path. Empty falls back to storage_path('logs').
    */
    'log_dir' => env('REQUEST_LOG_LOG_DIR', ''),

    /*
    |--------------------------------------------------------------------------
    | Log File Name
    |--------------------------------------------------------------------------
    | Single file; rotated daily with gzip.
    */
    'log_file' => env('REQUEST_LOG_LOG_FILE', 'analysis.log'),

    /*
    |--------------------------------------------------------------------------
    | Sensitive Fields to Redact
    |--------------------------------------------------------------------------
    | Comma-separated, case-insensitive. Applied to headers and body.
    */
    'redact_fields' => explode(',', env('REQUEST_LOG_REDACT_FIELDS', 'password,nik,Api-Key,no_telp')),

    /*
    |--------------------------------------------------------------------------
    | Maximum Raw Body Size (bytes)
    |--------------------------------------------------------------------------
    | Bodies longer than this are truncated.
    */
    'max_body_size' => (int) env('REQUEST_LOG_MAX_BODY_SIZE', 3145728),

    /*
    |--------------------------------------------------------------------------
    | IP / CIDR Whitelist
    |--------------------------------------------------------------------------
    | Requests from these IPs are not logged.
    */
    'whitelist_ips' => explode(',', env('REQUEST_LOG_WHITELIST_IPS', '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,127.0.0.1')),

    /*
    |--------------------------------------------------------------------------
    | Truncation Suffix
    |--------------------------------------------------------------------------
    | Appended when raw_body is truncated.
    */
    'truncate_suffix' => env('REQUEST_LOG_TRUNCATE_SUFFIX', '... [truncated]'),

    /*
    |--------------------------------------------------------------------------
    | Retention Days
    |--------------------------------------------------------------------------
    | Compressed logs older than this are pruned.
    */
    'retention_days' => (int) env('REQUEST_LOG_RETENTION_DAYS', 30),
];