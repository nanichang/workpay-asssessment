<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Employee Import Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration settings for the employee CSV/Excel import system.
    | These settings control performance, queue behavior, and processing limits.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for queue workers and job processing optimization.
    |
    */
    'queue' => [
        // Default queue connection for imports
        'connection' => env('IMPORT_QUEUE_CONNECTION', 'redis-imports'),
        
        // Queue names for different file sizes (allows prioritization)
        'queues' => [
            'small' => env('IMPORT_QUEUE_SMALL', 'imports-small'),     // < 1,000 rows
            'medium' => env('IMPORT_QUEUE_MEDIUM', 'imports-medium'),  // < 10,000 rows  
            'large' => env('IMPORT_QUEUE_LARGE', 'imports-large'),     // >= 10,000 rows
            'default' => env('IMPORT_QUEUE_DEFAULT', 'imports'),       // Unknown size
        ],

        // Job timeout settings (in seconds)
        'timeouts' => [
            'small' => (int) env('IMPORT_TIMEOUT_SMALL', 300),    // 5 minutes
            'medium' => (int) env('IMPORT_TIMEOUT_MEDIUM', 1800), // 30 minutes
            'large' => (int) env('IMPORT_TIMEOUT_LARGE', 3600),   // 1 hour
        ],

        // Retry configuration
        'retries' => [
            'max_attempts' => (int) env('IMPORT_MAX_ATTEMPTS', 3),
            'backoff_delays' => [30, 60, 120], // seconds between retries
            'retry_until_hours' => (int) env('IMPORT_RETRY_UNTIL_HOURS', 2),
        ],

        // Worker scaling settings
        'workers' => [
            'small_files' => (int) env('IMPORT_WORKERS_SMALL', 3),   // High priority workers
            'medium_files' => (int) env('IMPORT_WORKERS_MEDIUM', 2), // Medium priority workers
            'large_files' => (int) env('IMPORT_WORKERS_LARGE', 1),   // Low priority workers
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | File Processing Configuration
    |--------------------------------------------------------------------------
    |
    | Settings that control how files are processed and validated.
    |
    */
    'processing' => [
        // Chunk sizes for different file types
        'chunk_sizes' => [
            'csv' => (int) env('IMPORT_CSV_CHUNK_SIZE', 100),
            'excel' => (int) env('IMPORT_EXCEL_CHUNK_SIZE', 50), // Excel is more memory intensive
        ],

        // File size limits
        'limits' => [
            'max_file_size_mb' => (int) env('IMPORT_MAX_FILE_SIZE_MB', 20),
            'max_rows' => (int) env('IMPORT_MAX_ROWS', 50000),
        ],

        // Memory management
        'memory' => [
            'memory_limit_mb' => (int) env('IMPORT_MEMORY_LIMIT_MB', 512),
            'gc_frequency' => (int) env('IMPORT_GC_FREQUENCY', 1000), // Run garbage collection every N rows
        ],

        // Progress tracking frequency
        'progress_update_frequency' => (int) env('IMPORT_PROGRESS_UPDATE_FREQUENCY', 50), // Update every N rows
    ],

    /*
    |--------------------------------------------------------------------------
    | Caching Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for caching import progress and validation results.
    |
    */
    'cache' => [
        // Cache store to use for import data
        'store' => env('IMPORT_CACHE_STORE', 'redis'),
        
        // Cache TTL settings (in seconds)
        'ttl' => [
            'progress' => (int) env('IMPORT_CACHE_PROGRESS_TTL', 3600),      // 1 hour
            'validation' => (int) env('IMPORT_CACHE_VALIDATION_TTL', 1800),  // 30 minutes
            'file_metadata' => (int) env('IMPORT_CACHE_METADATA_TTL', 7200), // 2 hours
            'locks' => (int) env('IMPORT_CACHE_LOCKS_TTL', 3600),           // 1 hour
        ],

        // Cache key prefixes
        'prefixes' => [
            'progress' => 'import:progress:',
            'validation' => 'import:validation:',
            'metadata' => 'import:metadata:',
            'locks' => 'import:lock:',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoring and Logging Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for performance monitoring and logging.
    |
    */
    'monitoring' => [
        // Enable detailed performance logging
        'performance_logging' => env('IMPORT_PERFORMANCE_LOGGING', true),
        
        // Log levels for different events
        'log_levels' => [
            'job_start' => env('IMPORT_LOG_LEVEL_START', 'info'),
            'job_complete' => env('IMPORT_LOG_LEVEL_COMPLETE', 'info'),
            'job_failed' => env('IMPORT_LOG_LEVEL_FAILED', 'error'),
            'validation_errors' => env('IMPORT_LOG_LEVEL_VALIDATION', 'warning'),
            'performance' => env('IMPORT_LOG_LEVEL_PERFORMANCE', 'debug'),
        ],

        // Metrics collection
        'metrics' => [
            'enabled' => env('IMPORT_METRICS_ENABLED', true),
            'batch_size' => (int) env('IMPORT_METRICS_BATCH_SIZE', 100),
        ],

        // Error tracking
        'error_tracking' => [
            'enabled' => env('IMPORT_ERROR_TRACKING_ENABLED', true),
            'max_errors_per_job' => (int) env('IMPORT_MAX_ERRORS_PER_JOB', 1000),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Validation Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for data validation and duplicate detection.
    |
    */
    'validation' => [
        // Duplicate detection settings
        'duplicates' => [
            'check_within_file' => env('IMPORT_CHECK_FILE_DUPLICATES', true),
            'check_against_database' => env('IMPORT_CHECK_DB_DUPLICATES', true),
            'strategy' => env('IMPORT_DUPLICATE_STRATEGY', 'last_wins'), // 'last_wins', 'first_wins', 'error'
        ],

        // Field validation settings
        'fields' => [
            'email_validation' => env('IMPORT_EMAIL_VALIDATION', 'strict'), // 'strict', 'basic', 'none'
            'salary_validation' => env('IMPORT_SALARY_VALIDATION', 'strict'), // 'strict', 'basic'
            'date_validation' => env('IMPORT_DATE_VALIDATION', 'strict'), // 'strict', 'basic'
        ],

        // Supported values
        'supported' => [
            'currencies' => ['KES', 'USD', 'ZAR', 'NGN', 'GHS', 'UGX', 'RWF', 'TZS'],
            'countries' => ['KE', 'NG', 'GH', 'UG', 'ZA', 'TZ', 'RW'],
            'file_types' => ['csv', 'xlsx', 'xls'],
        ],
    ],

];