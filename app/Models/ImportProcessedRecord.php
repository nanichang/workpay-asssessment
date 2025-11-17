<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportProcessedRecord extends Model
{
    /**
     * Disable automatic timestamps since we only need processed_at
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'import_job_id',
        'employee_number',
        'email',
        'row_number',
        'status',
        'processed_at',
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
            'processed_at' => 'datetime',
        ];
    }

    /**
     * Get the import job that this record belongs to.
     *
     * @return BelongsTo
     */
    public function importJob(): BelongsTo
    {
        return $this->belongsTo(ImportJob::class);
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
     * Scope to filter by status.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $status
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Get processed employee numbers for an import job.
     *
     * @param string $importJobId
     * @return array
     */
    public static function getProcessedEmployeeNumbers(string $importJobId): array
    {
        return static::byImportJob($importJobId)
            ->byStatus('processed')
            ->pluck('employee_number')
            ->toArray();
    }

    /**
     * Get processed emails for an import job.
     *
     * @param string $importJobId
     * @return array
     */
    public static function getProcessedEmails(string $importJobId): array
    {
        return static::byImportJob($importJobId)
            ->byStatus('processed')
            ->pluck('email')
            ->toArray();
    }

    /**
     * Check if employee number was already processed.
     *
     * @param string $importJobId
     * @param string $employeeNumber
     * @return bool
     */
    public static function wasEmployeeNumberProcessed(string $importJobId, string $employeeNumber): bool
    {
        return static::byImportJob($importJobId)
            ->where('employee_number', $employeeNumber)
            ->exists();
    }

    /**
     * Check if email was already processed.
     *
     * @param string $importJobId
     * @param string $email
     * @return bool
     */
    public static function wasEmailProcessed(string $importJobId, string $email): bool
    {
        return static::byImportJob($importJobId)
            ->where('email', $email)
            ->exists();
    }

    /**
     * Record a processed employee.
     *
     * @param string $importJobId
     * @param string $employeeNumber
     * @param string $email
     * @param int $rowNumber
     * @param string $status
     * @return static
     */
    public static function recordProcessed(
        string $importJobId,
        string $employeeNumber,
        string $email,
        int $rowNumber,
        string $status = 'processed'
    ): static {
        return static::create([
            'import_job_id' => $importJobId,
            'employee_number' => $employeeNumber,
            'email' => $email,
            'row_number' => $rowNumber,
            'status' => $status,
            'processed_at' => now(),
        ]);
    }

    /**
     * Get resumption statistics for an import job.
     *
     * @param string $importJobId
     * @return array
     */
    public static function getResumptionStats(string $importJobId): array
    {
        $records = static::byImportJob($importJobId)->get();

        return [
            'total_processed' => $records->count(),
            'successful' => $records->where('status', 'processed')->count(),
            'skipped' => $records->where('status', 'skipped')->count(),
            'errors' => $records->where('status', 'error')->count(),
            'last_processed_row' => $records->max('row_number') ?? 0,
        ];
    }

    /**
     * Clear processed records for an import job (for cleanup).
     *
     * @param string $importJobId
     * @return int Number of deleted records
     */
    public static function clearForImportJob(string $importJobId): int
    {
        return static::byImportJob($importJobId)->delete();
    }
}