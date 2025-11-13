<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Import Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration options for the employee import system
    |
    */

    'chunk_size' => env('IMPORT_CHUNK_SIZE', 100),
    'max_file_size' => env('IMPORT_MAX_FILE_SIZE', 20971520), // 20MB in bytes
    'max_rows' => env('IMPORT_MAX_ROWS', 50000),
    
    'allowed_file_types' => ['csv', 'xlsx', 'xls'],
    
    'required_headers' => [
        'employee_number',
        'first_name', 
        'last_name',
        'email',
        'department',
        'salary',
        'currency',
        'country_code',
        'start_date'
    ],
    
    'valid_currencies' => ['KES', 'USD', 'ZAR', 'NGN', 'GHS', 'UGX', 'RWF', 'TZS'],
    'valid_countries' => ['KE', 'NG', 'GH', 'UG', 'ZA', 'TZ', 'RW'],
    
    'queue' => [
        'connection' => env('QUEUE_CONNECTION', 'database'),
        'name' => 'import',
        'timeout' => 3600, // 1 hour
        'retry_after' => 90,
        'max_tries' => 3,
    ],

];