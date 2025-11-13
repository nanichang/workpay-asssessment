<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportError extends Model
{
    use HasFactory;

    /**
     * Error type constants.
     */
    public const TYPE_VALIDATION = 'validation';
    public const TYPE_DUPLICATE = 'duplicate';
    public const TYPE_FORMAT = 'format';
    public const TYPE_BUSINESS_RULE = 'business_rule';
    public const TYPE_SYSTEM = 'system';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'import_job_id',
        'row_number',
        'error_type',
        'error_message',
        'row_data',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'row_number' => 'integer',
            'row_data' => 'array',
        ];
    }

    /**
     * Get all valid error types.
     *
     * @return array<string>
     */
    public static function getValidErrorTypes(): array
    {
        return [
            self::TYPE_VALIDATION,
            self::TYPE_DUPLICATE,
            self::TYPE_FORMAT,
            self::TYPE_BUSINESS_RULE,
            self::TYPE_SYSTEM,
        ];
    }

    /**
     * Get the import job that this error belongs to.
     *
     * @return BelongsTo
     */
    public function importJob(): BelongsTo
    {
        return $this->belongsTo(ImportJob::class);
    }

    /**
     * Scope to filter by error type.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $errorType
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByErrorType($query, string $errorType)
    {
        return $query->where('error_type', $errorType);
    }

    /**
     * Scope to filter by import job.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $importJobId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByImportJob($query, string $importJobId)
    {
        return $query->where('import_job_id', $importJobId);
    }

    /**
     * Scope to filter by row number range.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $startRow
     * @param int|null $endRow
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByRowRange($query, int $startRow, ?int $endRow = null)
    {
        $query->where('row_number', '>=', $startRow);
        
        if ($endRow !== null) {
            $query->where('row_number', '<=', $endRow);
        }

        return $query;
    }

    /**
     * Create a validation error.
     *
     * @param string $importJobId
     * @param int $rowNumber
     * @param string $message
     * @param array $rowData
     * @return static
     */
    public static function createValidationError(string $importJobId, int $rowNumber, string $message, array $rowData = []): static
    {
        return static::create([
            'import_job_id' => $importJobId,
            'row_number' => $rowNumber,
            'error_type' => self::TYPE_VALIDATION,
            'error_message' => $message,
            'row_data' => $rowData,
        ]);
    }

    /**
     * Create a duplicate error.
     *
     * @param string $importJobId
     * @param int $rowNumber
     * @param string $message
     * @param array $rowData
     * @return static
     */
    public static function createDuplicateError(string $importJobId, int $rowNumber, string $message, array $rowData = []): static
    {
        return static::create([
            'import_job_id' => $importJobId,
            'row_number' => $rowNumber,
            'error_type' => self::TYPE_DUPLICATE,
            'error_message' => $message,
            'row_data' => $rowData,
        ]);
    }

    /**
     * Create a format error.
     *
     * @param string $importJobId
     * @param int $rowNumber
     * @param string $message
     * @param array $rowData
     * @return static
     */
    public static function createFormatError(string $importJobId, int $rowNumber, string $message, array $rowData = []): static
    {
        return static::create([
            'import_job_id' => $importJobId,
            'row_number' => $rowNumber,
            'error_type' => self::TYPE_FORMAT,
            'error_message' => $message,
            'row_data' => $rowData,
        ]);
    }

    /**
     * Create a business rule error.
     *
     * @param string $importJobId
     * @param int $rowNumber
     * @param string $message
     * @param array $rowData
     * @return static
     */
    public static function createBusinessRuleError(string $importJobId, int $rowNumber, string $message, array $rowData = []): static
    {
        return static::create([
            'import_job_id' => $importJobId,
            'row_number' => $rowNumber,
            'error_type' => self::TYPE_BUSINESS_RULE,
            'error_message' => $message,
            'row_data' => $rowData,
        ]);
    }

    /**
     * Create a system error.
     *
     * @param string $importJobId
     * @param int $rowNumber
     * @param string $message
     * @param array $rowData
     * @return static
     */
    public static function createSystemError(string $importJobId, int $rowNumber, string $message, array $rowData = []): static
    {
        return static::create([
            'import_job_id' => $importJobId,
            'row_number' => $rowNumber,
            'error_type' => self::TYPE_SYSTEM,
            'error_message' => $message,
            'row_data' => $rowData,
        ]);
    }

    /**
     * Get error statistics for an import job.
     *
     * @param string $importJobId
     * @return array<string, int>
     */
    public static function getErrorStatistics(string $importJobId): array
    {
        $errors = static::byImportJob($importJobId)->get();

        $statistics = [
            'total_errors' => $errors->count(),
        ];

        foreach (static::getValidErrorTypes() as $type) {
            $statistics[$type . '_errors'] = $errors->where('error_type', $type)->count();
        }

        return $statistics;
    }

    /**
     * Get formatted error details.
     *
     * @return array<string, mixed>
     */
    public function getFormattedDetails(): array
    {
        return [
            'id' => $this->id,
            'row_number' => $this->row_number,
            'error_type' => $this->error_type,
            'error_message' => $this->error_message,
            'row_data' => $this->row_data,
            'created_at' => $this->created_at,
        ];
    }
}