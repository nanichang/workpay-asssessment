# CSV Employee Import System

A robust, scalable Laravel application for bulk importing employee data from CSV and Excel files. Built for Workpay to handle large-scale employee data imports with real-time progress tracking, comprehensive validation, and fault-tolerant processing.

## Features

- **Multi-format Support**: Process CSV, XLSX, and XLS files
- **Asynchronous Processing**: Queue-based processing for large files (up to 50,000 rows)
- **Real-time Progress Tracking**: Live updates on import progress and statistics
- **Comprehensive Validation**: Row-by-row validation with detailed error reporting
- **Duplicate Detection**: Intelligent handling of duplicates within files and against existing data
- **Fault Tolerance**: Resumable processing with automatic retry mechanisms
- **Memory Efficient**: Streaming file processing with configurable chunk sizes
- **Performance Optimized**: Redis caching and optimized database operations
- **Interactive UI**: Livewire-powered dashboard with drag-and-drop file upload

## Requirements

- **PHP**: ^8.2
- **Laravel**: ^12.0
- **Database**: SQLite (default) or MySQL/PostgreSQL
- **Queue Backend**: Redis (recommended) or Database
- **Cache**: Redis (recommended) or Database
- **Memory**: Minimum 512MB PHP memory limit
- **Extensions**: 
  - `php-zip` (for Excel file processing)
  - `php-xml` (for Excel file processing)
  - `php-gd` (optional, for better Excel support)

## Installation

### 1. Clone and Setup

```bash
# Clone the repository
git clone <repository-url>
cd csv-employee-import

# Install dependencies and setup environment
composer run setup

# Or manually:
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install
npm run build
```

### 2. Environment Configuration

Edit your `.env` file with the following key configurations:

```env
# Database Configuration
DB_CONNECTION=sqlite
# Or for MySQL/PostgreSQL:
# DB_CONNECTION=mysql
# DB_HOST=127.0.0.1
# DB_PORT=3306
# DB_DATABASE=workpay
# DB_USERNAME=root
# DB_PASSWORD=

# Queue Configuration (Redis recommended for production)
QUEUE_CONNECTION=redis-imports
REDIS_HOST=127.0.0.1
REDIS_PORT=6379

# Cache Configuration
CACHE_STORE=redis

# Import System Configuration
IMPORT_MAX_FILE_SIZE_MB=20
IMPORT_MAX_ROWS=50000
IMPORT_CSV_CHUNK_SIZE=100
IMPORT_EXCEL_CHUNK_SIZE=50
```

### 3. Database Setup

```bash
# Run migrations
php artisan migrate

# Optional: Seed with test data
php artisan db:seed --class=TestEmployeeSeeder
```

### 4. Queue Workers Setup

For production, set up queue workers to process imports:

```bash
# Start queue workers (recommended for production)
php artisan queue:work redis-imports --queue=imports-small,imports-medium,imports-large --tries=3 --timeout=3600

# Or use Supervisor for process management (recommended)
# See "Production Deployment" section below
```

### 5. Development Server

```bash
# Start all development services (server, queue, logs, vite)
composer run dev

# Or start services individually:
php artisan serve                    # Web server
php artisan queue:listen --tries=1  # Queue worker
php artisan pail --timeout=0        # Log viewer
npm run dev                          # Asset compilation
```

## Usage

### Web Interface

1. Navigate to `http://localhost:8000` in your browser
2. Use the drag-and-drop interface to upload CSV or Excel files
3. Monitor real-time progress and view detailed error reports
4. Download error reports for failed validations

### API Usage

#### Upload File

```bash
curl -X POST http://localhost:8000/api/employee-import/upload \
  -F "file=@employees.csv" \
  -H "Accept: application/json"
```

**Response:**
```json
{
  "success": true,
  "message": "File uploaded successfully and processing started",
  "data": {
    "import_id": "550e8400-e29b-41d4-a716-446655440000",
    "filename": "employees.csv",
    "total_rows": 1500,
    "status": "pending"
  }
}
```

#### Check Progress

```bash
curl http://localhost:8000/api/employee-import/{import_id}/progress
```

**Response:**
```json
{
  "success": true,
  "data": {
    "import_id": "550e8400-e29b-41d4-a716-446655440000",
    "status": "processing",
    "progress": {
      "total_rows": 1500,
      "processed_rows": 750,
      "successful_rows": 720,
      "error_rows": 30,
      "percentage": 50.0
    },
    "estimated_completion": "2024-01-15T14:30:00Z"
  }
}
```

#### Get Errors

```bash
curl "http://localhost:8000/api/employee-import/{import_id}/errors?page=1&per_page=50&type=validation"
```

**Response:**
```json
{
  "success": true,
  "data": {
    "errors": [
      {
        "row_number": 15,
        "error_type": "validation",
        "error_message": "Invalid email format",
        "field": "email",
        "value": "invalid-email",
        "row_data": {
          "employee_number": "EMP001",
          "first_name": "John",
          "last_name": "Doe",
          "email": "invalid-email"
        }
      }
    ],
    "pagination": {
      "current_page": 1,
      "per_page": 50,
      "total": 30,
      "last_page": 1
    }
  }
}
```

#### Get Summary

```bash
curl http://localhost:8000/api/employee-import/{import_id}/summary
```

**Response:**
```json
{
  "success": true,
  "data": {
    "import_id": "550e8400-e29b-41d4-a716-446655440000",
    "status": "completed",
    "filename": "employees.csv",
    "total_rows": 1500,
    "successful_rows": 1470,
    "error_rows": 30,
    "processing_time": "00:02:45",
    "started_at": "2024-01-15T14:00:00Z",
    "completed_at": "2024-01-15T14:02:45Z",
    "error_summary": {
      "validation": 25,
      "duplicate": 5
    }
  }
}
```

## File Format Requirements

### CSV Format

```csv
employee_number,first_name,last_name,email,department,salary,currency,country_code,start_date
EMP001,John,Doe,john.doe@company.com,Engineering,75000,USD,US,2024-01-15
EMP002,Jane,Smith,jane.smith@company.com,Finance,65000,KES,KE,2024-01-10
```

### Excel Format

Excel files (.xlsx, .xls) should have the same column structure as CSV files. The first row should contain headers matching the expected field names.

### Required Fields

- `employee_number`: Unique identifier (alphanumeric, max 50 characters)
- `first_name`: Employee's first name (max 100 characters)
- `last_name`: Employee's last name (max 100 characters)
- `email`: Valid email address (unique across system)

### Optional Fields

- `department`: Department name (max 100 characters)
- `salary`: Positive numeric value (no text like "50k")
- `currency`: Valid currency code (KES, USD, ZAR, NGN, GHS, UGX, RWF, TZS)
- `country_code`: Valid country code (KE, NG, GH, UG, ZA, TZ, RW)
- `start_date`: Date in YYYY-MM-DD format (no future dates)

## Configuration

### Import Configuration

Key configuration options in `config/import.php`:

```php
// File processing limits
'limits' => [
    'max_file_size_mb' => 20,
    'max_rows' => 50000,
],

// Chunk sizes for memory management
'chunk_sizes' => [
    'csv' => 100,
    'excel' => 50,
],

// Queue configuration
'queue' => [
    'connection' => 'redis-imports',
    'queues' => [
        'small' => 'imports-small',   // < 1,000 rows
        'medium' => 'imports-medium', // < 10,000 rows
        'large' => 'imports-large',   // >= 10,000 rows
    ],
],
```

### Environment Variables

```env
# File Processing
IMPORT_MAX_FILE_SIZE_MB=20
IMPORT_MAX_ROWS=50000
IMPORT_CSV_CHUNK_SIZE=100
IMPORT_EXCEL_CHUNK_SIZE=50

# Queue Configuration
IMPORT_QUEUE_CONNECTION=redis-imports
IMPORT_TIMEOUT_SMALL=300
IMPORT_TIMEOUT_MEDIUM=1800
IMPORT_TIMEOUT_LARGE=3600

# Performance Tuning
IMPORT_MEMORY_LIMIT_MB=512
IMPORT_GC_FREQUENCY=1000
IMPORT_PROGRESS_UPDATE_FREQUENCY=50

# Caching
IMPORT_CACHE_STORE=redis
IMPORT_CACHE_PROGRESS_TTL=3600

# Validation
IMPORT_DUPLICATE_STRATEGY=last_wins
IMPORT_EMAIL_VALIDATION=strict
```

## Testing

### Run Tests

```bash
# Run all tests
composer run test

# Or manually:
php artisan test

# Run specific test suites
php artisan test --testsuite=Feature
php artisan test --testsuite=Unit

# Run with coverage (requires Xdebug)
php artisan test --coverage
```

### Test Data

The system includes comprehensive test data:

- `good-employees.csv`: 20,000+ clean records for performance testing
- `bad-employees.csv`: 20 records with validation errors
- `Assessment Data Set.xlsx`: Excel file for format testing

### Generate Test Scenarios

```bash
# Generate additional test files
php artisan generate:test-scenarios

# Clear test data
php artisan import:clear --test-data-only
```

## Monitoring and Logging

### Import Statistics

```bash
# View import statistics
php artisan import:stats

# View detailed statistics for specific import
php artisan import:stats --import-id=550e8400-e29b-41d4-a716-446655440000
```

### Queue Monitoring

```bash
# Monitor queue status
php artisan queue:monitor redis-imports

# View failed jobs
php artisan queue:failed

# Retry failed jobs
php artisan queue:retry all
```

### Performance Monitoring

The system logs detailed performance metrics:

```bash
# View performance logs
php artisan pail --filter="import"

# Monitor memory usage
php artisan import:monitor --memory
```

## Production Deployment

### Supervisor Configuration

Create `/etc/supervisor/conf.d/import-workers.conf`:

```ini
[program:import-workers-small]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/application/artisan queue:work redis-imports --queue=imports-small --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=3
redirect_stderr=true
stdout_logfile=/path/to/application/storage/logs/worker-small.log
stopwaitsecs=3600

[program:import-workers-medium]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/application/artisan queue:work redis-imports --queue=imports-medium --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/path/to/application/storage/logs/worker-medium.log
stopwaitsecs=3600

[program:import-workers-large]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/application/artisan queue:work redis-imports --queue=imports-large --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/path/to/application/storage/logs/worker-large.log
stopwaitsecs=3600
```

### Optimization

```bash
# Optimize for production
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# Optimize Composer autoloader
composer install --optimize-autoloader --no-dev
```

## Troubleshooting

### Common Issues

#### 1. File Upload Fails

**Problem**: File upload returns 413 or 422 errors

**Solutions**:
```bash
# Check file size limits
grep -i "upload_max_filesize\|post_max_size" /etc/php/*/apache2/php.ini

# Increase PHP limits in php.ini:
upload_max_filesize = 25M
post_max_size = 25M
max_execution_time = 300

# Check application limits
grep "IMPORT_MAX_FILE_SIZE_MB" .env
```

#### 2. Queue Jobs Not Processing

**Problem**: Import jobs stuck in "pending" status

**Solutions**:
```bash
# Check queue workers are running
ps aux | grep "queue:work"

# Start queue workers
php artisan queue:work redis-imports --tries=3

# Check Redis connection
php artisan tinker
>>> Cache::store('redis')->get('test')

# Check queue configuration
php artisan config:show queue
```

#### 3. Memory Exhaustion

**Problem**: "Fatal error: Allowed memory size exhausted"

**Solutions**:
```bash
# Reduce chunk sizes in .env
IMPORT_CSV_CHUNK_SIZE=50
IMPORT_EXCEL_CHUNK_SIZE=25

# Increase PHP memory limit
php -d memory_limit=1G artisan queue:work

# Enable garbage collection
IMPORT_GC_FREQUENCY=500
```

#### 4. Slow Processing

**Problem**: Import processing is slower than expected

**Solutions**:
```bash
# Check database indexes
php artisan db:show --counts

# Optimize database
php artisan migrate:status
php artisan db:seed --class=DatabaseOptimizationSeeder

# Scale queue workers
# Add more workers in supervisor configuration

# Enable Redis caching
CACHE_STORE=redis
IMPORT_CACHE_STORE=redis
```

#### 5. Validation Errors

**Problem**: High number of validation errors

**Solutions**:
```bash
# Check validation rules
php artisan import:validate-sample --file=employees.csv --rows=10

# Review error patterns
php artisan import:errors --import-id=xxx --group-by=type

# Adjust validation strictness
IMPORT_EMAIL_VALIDATION=basic
IMPORT_SALARY_VALIDATION=basic
```

### Debug Commands

```bash
# Test file processing without queue
php artisan import:test --file=employees.csv --sync

# Validate file format
php artisan import:validate --file=employees.csv

# Clear stuck jobs
php artisan queue:clear redis-imports

# Reset import job
php artisan import:reset --import-id=xxx

# Check system requirements
php artisan import:check-requirements
```

### Log Analysis

```bash
# View import-specific logs
tail -f storage/logs/laravel.log | grep "import"

# Monitor queue performance
php artisan pail --filter="queue"

# Check error patterns
grep -E "(validation|duplicate|error)" storage/logs/laravel.log | tail -20
```

### Performance Profiling

```bash
# Enable query logging
DB_LOG_QUERIES=true

# Monitor memory usage
php artisan import:profile --file=large-file.csv

# Analyze slow queries
php artisan db:monitor --slow-queries
```

## Security Considerations

- File uploads are validated for type and size
- All user input is sanitized and validated
- Database queries use parameter binding to prevent SQL injection
- File processing is sandboxed with memory and time limits
- Error messages don't expose sensitive system information

## Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

### Development Guidelines

- Follow PSR-12 coding standards
- Write tests for new features
- Update documentation for API changes
- Use conventional commit messages

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Support

For technical support or questions:

1. Check the troubleshooting guide above
2. Review the [DECISIONS.md](DECISIONS.md) file for architectural decisions
3. Check existing issues in the repository
4. Create a new issue with detailed information about your problem

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for a detailed list of changes and version history.
