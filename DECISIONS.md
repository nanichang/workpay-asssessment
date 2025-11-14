# Architecture Decision Records (ADR)

## CSV Employee Import System Design Decisions

This document captures the key architectural and design decisions made during the development of the CSV Employee Import System, along with their rationale and trade-offs.

---

## 1. Schema Design Decisions

### 1.1 Employee Table Structure

**Decision**: Use separate fields for employee data rather than JSON storage

**Rationale**:
- Individual columns enable efficient indexing and querying
- Type safety through database constraints and Laravel casting
- Better performance for filtering and searching operations
- Clearer data validation at the database level

**Trade-offs**:
- ✅ Better query performance and indexing capabilities
- ✅ Type safety and data integrity
- ✅ Easier to add database constraints
- ❌ Schema changes require migrations
- ❌ Less flexible for varying employee data structures

**Implementation**:
```sql
CREATE TABLE employees (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_number VARCHAR(50) UNIQUE NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    department VARCHAR(100),
    salary DECIMAL(12,2),
    currency VARCHAR(3),
    country_code VARCHAR(2),
    start_date DATE,
    -- indexes for performance
    INDEX idx_employee_lookup (employee_number, email)
);
```

### 1.2 Import Job Tracking with UUIDs

**Decision**: Use UUID primary keys for ImportJob model instead of auto-incrementing integers

**Rationale**:
- Provides globally unique identifiers across distributed systems
- Prevents enumeration attacks on import job IDs
- Enables better API security and privacy
- Supports future horizontal scaling scenarios

**Trade-offs**:
- ✅ Better security and privacy
- ✅ Globally unique across systems
- ✅ No collision risk in distributed environments
- ❌ Slightly larger storage footprint (36 chars vs 8 bytes)
- ❌ Less human-readable in logs and debugging

### 1.3 Separate Error Tracking Table

**Decision**: Create dedicated `import_errors` table instead of storing errors in JSON field

**Rationale**:
- Enables efficient querying and filtering of errors by type
- Supports pagination of large error lists
- Allows for detailed error analytics and reporting
- Better performance when retrieving specific error categories

**Trade-offs**:
- ✅ Efficient error querying and filtering
- ✅ Better analytics capabilities
- ✅ Supports large error datasets
- ❌ Additional table complexity
- ❌ More database storage for error metadata

---

## 2. Validation Strategy Decisions

### 2.1 Row-by-Row Validation Approach

**Decision**: Validate each CSV row individually and continue processing on validation failures

**Rationale**:
- Maximizes successful data imports even with some invalid rows
- Provides detailed feedback on specific validation failures
- Prevents single bad row from blocking entire import
- Aligns with user expectation of partial success scenarios

**Trade-offs**:
- ✅ Maximizes successful imports
- ✅ Detailed error reporting per row
- ✅ Better user experience with partial failures
- ❌ More complex error handling logic
- ❌ Potential for large error datasets

**Implementation Pattern**:
```php
foreach ($rows as $rowNumber => $row) {
    try {
        $validationResult = $this->validator->validate($row);
        if ($validationResult->isValid()) {
            $this->processValidRow($row);
        } else {
            $this->errorReporter->recordErrors($validationResult->getErrors(), $rowNumber);
        }
    } catch (Exception $e) {
        $this->errorReporter->recordSystemError($e, $rowNumber);
    }
}
```

### 2.2 Business Rule Validation Strategy

**Decision**: Implement strict validation rules based on sample data analysis

**Rationale**:
- Prevents data quality issues from propagating into the system
- Ensures consistency with existing Workpay data standards
- Reduces downstream processing errors and data cleanup needs
- Provides clear feedback to users about data expectations

**Key Validation Rules**:
- **Salary Format**: Numeric only, reject text formats like "50k" or "66.5k"
- **Currency Codes**: Whitelist approach with specific supported currencies
- **Date Formats**: Strict YYYY-MM-DD format, no future dates for start_date
- **Email Validation**: Must contain @ symbol and valid domain structure
- **Employee Numbers**: Unique constraint with reasonable length limits

**Trade-offs**:
- ✅ High data quality and consistency
- ✅ Clear user expectations
- ✅ Prevents downstream data issues
- ❌ May reject some valid but non-standard data
- ❌ Requires maintenance as business rules evolve

### 2.3 Duplicate Detection Within Files

**Decision**: Process last occurrence of duplicates within a single file

**Rationale**:
- Assumes most recent data in file is the intended version
- Provides predictable behavior for users
- Simplifies conflict resolution logic
- Aligns with common spreadsheet editing patterns

**Implementation**:
```php
// Track seen employee numbers/emails during processing
private array $seenEmployees = [];

private function processDuplicates(array $row, int $rowNumber): bool
{
    $key = $row['employee_number'] . '|' . $row['email'];
    
    if (isset($this->seenEmployees[$key])) {
        // Mark previous occurrence as duplicate
        $this->errorReporter->recordError(
            $this->seenEmployees[$key], 
            'duplicate', 
            'Duplicate found, processing later occurrence'
        );
    }
    
    $this->seenEmployees[$key] = $rowNumber;
    return true;
}
```

**Trade-offs**:
- ✅ Predictable duplicate resolution
- ✅ Simple implementation logic
- ✅ Handles common user scenarios
- ❌ May not match all user expectations
- ❌ Requires full file scan for optimal duplicate detection

---

## 3. Error Handling and Recovery

### 3.1 Graceful Degradation Strategy

**Decision**: Continue processing on individual row failures, fail job only on system-level errors

**Rationale**:
- Maximizes value from import operations
- Provides detailed feedback on what succeeded vs failed
- Reduces need for users to fix entire files for minor issues
- Supports iterative data cleanup workflows

**Error Categories**:
1. **Row-level errors**: Skip row, continue processing, log error
2. **System errors**: Retry with backoff, fail job if persistent
3. **File-level errors**: Fail fast before processing begins

**Trade-offs**:
- ✅ Better user experience with partial success
- ✅ Detailed error reporting and recovery guidance
- ✅ Reduces support burden from minor data issues
- ❌ More complex error handling and recovery logic
- ❌ Potential for large error datasets requiring management

### 3.2 Retry and Resumption Strategy

**Decision**: Implement job-level retries with checkpoint-based resumption

**Rationale**:
- Handles transient system failures gracefully
- Prevents data loss from infrastructure issues
- Reduces need for users to re-upload files after failures
- Supports processing of very large files that may exceed time limits

**Implementation Approach**:
```php
class ProcessEmployeeImportJob implements ShouldQueue
{
    public $tries = 3;
    public $backoff = [60, 300, 900]; // 1min, 5min, 15min
    
    public function handle()
    {
        $startRow = $this->importJob->last_processed_row ?? 0;
        $this->fileProcessor->processFromRow($this->importJob, $startRow);
    }
    
    public function failed(Throwable $exception)
    {
        $this->importJob->update(['status' => 'failed']);
        $this->errorReporter->recordSystemError($exception);
    }
}
```

**Trade-offs**:
- ✅ Resilient to transient failures
- ✅ Supports very large file processing
- ✅ Reduces user frustration from system issues
- ❌ More complex state management
- ❌ Potential for partial state inconsistencies

---

## 4. Idempotency Implementation

### 4.1 Upsert-Based Data Operations

**Decision**: Use "createOrUpdate" pattern for employee records based on employee_number and email

**Rationale**:
- Ensures safe retries without data duplication
- Supports data correction workflows (re-importing with fixes)
- Handles scenarios where employees exist from previous imports
- Provides predictable behavior for users

**Implementation**:
```php
public function createOrUpdate(array $data): Employee
{
    return Employee::updateOrCreate(
        [
            'employee_number' => $data['employee_number'],
            'email' => $data['email']
        ],
        $data
    );
}
```

**Trade-offs**:
- ✅ Safe for retries and re-imports
- ✅ Supports data correction workflows
- ✅ Handles existing employee scenarios
- ❌ May overwrite intentional data changes
- ❌ Requires careful consideration of update vs create semantics

### 4.2 Progress Tracking Idempotency

**Decision**: Track progress by row position rather than processed count

**Rationale**:
- Enables accurate resumption from specific points in file
- Prevents double-counting of rows during retries
- Supports debugging and troubleshooting of specific row issues
- Provides more granular progress reporting

**Implementation**:
```php
private function updateProgress(ImportJob $job, int $currentRow, bool $success)
{
    $job->update([
        'last_processed_row' => $currentRow,
        'processed_rows' => $currentRow,
        'successful_rows' => $success ? $job->successful_rows + 1 : $job->successful_rows,
        'error_rows' => $success ? $job->error_rows : $job->error_rows + 1
    ]);
}
```

**Trade-offs**:
- ✅ Accurate resumption capabilities
- ✅ Precise progress tracking
- ✅ Better debugging support
- ❌ More complex progress calculation logic
- ❌ Requires careful state management during retries

---

## 5. Progress Reporting Implementation

### 5.1 Real-Time Progress Updates

**Decision**: Update progress after each processed chunk rather than individual rows

**Rationale**:
- Balances real-time feedback with database performance
- Reduces database write load during large imports
- Provides sufficiently granular updates for user experience
- Prevents progress update bottlenecks

**Configuration**:
- Chunk size: 100 rows (configurable)
- Progress update frequency: Per chunk
- Progress calculation: (processed_rows / total_rows) * 100

**Trade-offs**:
- ✅ Good balance of performance and user feedback
- ✅ Reduces database load
- ✅ Configurable granularity
- ❌ Less precise real-time updates
- ❌ May appear to "jump" in progress increments

### 5.2 Caching Strategy for Progress Data

**Decision**: Cache progress data in Redis for fast API responses

**Rationale**:
- Reduces database load from frequent progress checks
- Provides sub-second response times for progress APIs
- Supports high-frequency polling from frontend applications
- Enables better user experience with smooth progress updates

**Implementation Pattern**:
```php
class ProgressTracker
{
    public function updateProgress(ImportJob $job, int $processedRows): void
    {
        // Update database
        $job->update(['processed_rows' => $processedRows]);
        
        // Cache for fast API access
        Cache::put("import_progress:{$job->id}", [
            'processed_rows' => $processedRows,
            'total_rows' => $job->total_rows,
            'percentage' => ($processedRows / $job->total_rows) * 100,
            'updated_at' => now()
        ], 3600);
    }
}
```

**Trade-offs**:
- ✅ Fast API response times
- ✅ Reduced database load
- ✅ Better user experience
- ❌ Additional infrastructure dependency (Redis)
- ❌ Potential cache invalidation complexity
- ❌ Memory usage for progress data

---

## 6. File Processing Architecture

### 6.1 Streaming vs. Memory-Loaded Processing

**Decision**: Use streaming file readers for both CSV and Excel files

**Rationale**:
- Supports processing of very large files (50,000+ rows)
- Prevents memory exhaustion on resource-constrained systems
- Enables processing files larger than available RAM
- Provides consistent memory usage regardless of file size

**Implementation**:
```php
private function readCsvFile(string $filePath): Generator
{
    $handle = fopen($filePath, 'r');
    while (($row = fgetcsv($handle)) !== false) {
        yield $row;
    }
    fclose($handle);
}

private function readExcelFile(string $filePath): Generator
{
    $reader = IOFactory::createReader('Xlsx');
    $reader->setReadDataOnly(true);
    $spreadsheet = $reader->load($filePath);
    
    foreach ($spreadsheet->getActiveSheet()->getRowIterator() as $row) {
        yield $row->getCellIterator()->toArray();
    }
}
```

**Trade-offs**:
- ✅ Supports very large files
- ✅ Consistent memory usage
- ✅ Scalable architecture
- ❌ More complex implementation
- ❌ Cannot easily seek to specific rows
- ❌ Requires careful resource management

### 6.2 Chunked Processing Strategy

**Decision**: Process files in configurable chunks with database transactions per chunk

**Rationale**:
- Provides balance between performance and memory usage
- Enables progress reporting at reasonable intervals
- Reduces risk of long-running transactions
- Supports resumable processing from chunk boundaries

**Configuration**:
- Default chunk size: 100 rows
- Configurable via environment variable
- Transaction boundary: Per chunk
- Progress update: Per chunk

**Trade-offs**:
- ✅ Balanced performance and resource usage
- ✅ Reasonable progress reporting granularity
- ✅ Supports resumable processing
- ❌ More complex transaction management
- ❌ Potential for partial chunk failures

---

## 7. Queue System Integration

### 7.1 Asynchronous Processing Decision

**Decision**: Use Laravel queues for all file processing operations

**Rationale**:
- Prevents HTTP request timeouts for large files
- Enables better resource management and scaling
- Provides built-in retry and failure handling
- Supports concurrent processing of multiple imports

**Queue Configuration**:
- Queue: `imports` (dedicated queue for import jobs)
- Timeout: 3600 seconds (1 hour for very large files)
- Retries: 3 attempts with exponential backoff
- Worker scaling: Configurable based on system resources

**Trade-offs**:
- ✅ Better user experience (no timeouts)
- ✅ Scalable processing architecture
- ✅ Built-in retry and failure handling
- ❌ Additional infrastructure complexity
- ❌ Requires queue worker management
- ❌ More complex debugging of processing issues

### 7.2 Job Failure and Recovery Strategy

**Decision**: Implement comprehensive failure handling with detailed error reporting

**Rationale**:
- Provides clear feedback when imports fail
- Enables troubleshooting of system issues
- Supports recovery from transient failures
- Maintains audit trail of processing attempts

**Failure Handling**:
```php
public function failed(Throwable $exception): void
{
    $this->importJob->update([
        'status' => 'failed',
        'completed_at' => now()
    ]);
    
    $this->errorReporter->recordSystemError(
        $this->importJob,
        'job_failure',
        $exception->getMessage(),
        ['exception' => $exception->getTraceAsString()]
    );
}
```

**Trade-offs**:
- ✅ Clear failure feedback and debugging
- ✅ Comprehensive error tracking
- ✅ Supports system monitoring and alerting
- ❌ Additional complexity in error handling
- ❌ Potential for large error logs

---

## 8. Security and Data Privacy

### 8.1 File Upload Security

**Decision**: Implement strict file validation and temporary storage

**Rationale**:
- Prevents malicious file uploads
- Ensures only expected file types are processed
- Limits resource consumption from oversized files
- Provides audit trail of uploaded files

**Security Measures**:
- File type validation (CSV, Excel only)
- File size limits (20MB maximum)
- Header validation before processing
- Temporary file storage with cleanup
- UUID-based file naming to prevent enumeration

**Trade-offs**:
- ✅ Strong security posture
- ✅ Prevents resource abuse
- ✅ Clear audit trail
- ❌ May reject some valid files with unusual formats
- ❌ Additional validation overhead

### 8.2 Data Access Control

**Decision**: Use UUID-based import job IDs for API access

**Rationale**:
- Prevents enumeration of import jobs
- Provides better privacy for import operations
- Supports future multi-tenant scenarios
- Reduces information leakage in logs and URLs

**Trade-offs**:
- ✅ Better security and privacy
- ✅ Prevents enumeration attacks
- ✅ Future-proof for multi-tenancy
- ❌ Less human-readable identifiers
- ❌ Slightly more complex debugging

---

## 9. Performance Optimization Decisions

### 9.1 Database Indexing Strategy

**Decision**: Implement composite indexes for common query patterns

**Rationale**:
- Optimizes duplicate detection queries
- Supports efficient employee lookups
- Enables fast error filtering and reporting
- Balances query performance with storage overhead

**Index Strategy**:
```sql
-- Primary lookups for duplicate detection
INDEX idx_employee_lookup (employee_number, email)

-- Error reporting and filtering
INDEX idx_import_errors (import_job_id, error_type)

-- Currency and country filtering
INDEX idx_currency (currency)
INDEX idx_country (country_code)
```

**Trade-offs**:
- ✅ Fast query performance for common operations
- ✅ Efficient duplicate detection
- ✅ Supports complex error reporting
- ❌ Additional storage overhead
- ❌ Slower write operations due to index maintenance

### 9.2 Caching Architecture

**Decision**: Multi-layer caching with Redis for hot data

**Rationale**:
- Reduces database load for frequently accessed data
- Provides fast response times for progress APIs
- Supports high-frequency polling from frontend
- Enables better scalability under load

**Caching Layers**:
1. **Progress Data**: Redis cache with 1-hour TTL
2. **Validation Results**: In-memory cache during processing
3. **File Metadata**: Redis cache for resume operations

**Trade-offs**:
- ✅ Excellent performance for cached operations
- ✅ Reduced database load
- ✅ Better scalability
- ❌ Additional infrastructure complexity
- ❌ Cache invalidation complexity
- ❌ Potential for stale data if not managed properly

---

## 10. Future Considerations

### 10.1 Scalability Roadmap

**Prepared Decisions for Future Growth**:

1. **Horizontal Scaling**: UUID-based job IDs support distributed processing
2. **Multi-tenancy**: Job isolation supports future tenant separation
3. **File Format Extensions**: Modular file processor supports additional formats
4. **Advanced Validation**: Plugin-based validation system for custom rules

### 10.2 Monitoring and Observability

**Observability Strategy**:
- Comprehensive logging at all processing stages
- Metrics collection for performance monitoring
- Error tracking and alerting integration
- Progress monitoring and SLA tracking

### 10.3 Data Retention and Cleanup

**Data Lifecycle Management**:
- Configurable retention periods for import jobs and errors
- Automated cleanup of temporary files
- Archive strategy for historical import data
- GDPR compliance considerations for employee data

---

## Conclusion

These architectural decisions prioritize data integrity, user experience, and system reliability while maintaining flexibility for future enhancements. The design emphasizes graceful error handling, comprehensive progress reporting, and robust idempotency to ensure the system performs reliably under various conditions and use cases.

Each decision represents a careful balance of trade-offs, with priority given to data quality, user experience, and operational reliability over implementation simplicity where necessary.