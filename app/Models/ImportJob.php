<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportJob extends Model
{
    use HasFactory, HasUuids;

    /**
     * The possible status values for import jobs.
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'filename',
        'file_path',
        'status',
        'total_rows',
        'processed_rows',
        'successful_rows',
        'error_rows',
        'last_processed_row',
        'started_at',
        'completed_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_rows' => 'integer',
            'processed_rows' => 'integer',
            'successful_rows' => 'integer',
            'error_rows' => 'integer',
            'last_processed_row' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Get all valid status values.
     *
     * @return array<string>
     */
    public static function getValidStatuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_PROCESSING,
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
        ];
    }

    /**
     * Calculate the progress percentage.
     *
     * @return float
     */
    public function getProgressPercentageAttribute(): float
    {
        if ($this->total_rows === 0) {
            return 0.0;
        }

        return round(($this->processed_rows / $this->total_rows) * 100, 2);
    }

    /**
     * Check if the import job is completed.
     *
     * @return bool
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Check if the import job is processing.
     *
     * @return bool
     */
    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    /**
     * Check if the import job has failed.
     *
     * @return bool
     */
    public function hasFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Check if the import job is pending.
     *
     * @return bool
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Mark the job as started.
     *
     * @return void
     */
    public function markAsStarted(): void
    {
        $this->update([
            'status' => self::STATUS_PROCESSING,
            'started_at' => now(),
        ]);
    }

    /**
     * Mark the job as completed.
     *
     * @return void
     */
    public function markAsCompleted(): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
    }

    /**
     * Mark the job as failed.
     *
     * @return void
     */
    public function markAsFailed(): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'completed_at' => now(),
        ]);
    }

    /**
     * Update progress counters.
     *
     * @param int $processedRows
     * @param int $successfulRows
     * @param int $errorRows
     * @param int $lastProcessedRow
     * @return void
     */
    public function updateProgress(int $processedRows, int $successfulRows, int $errorRows, int $lastProcessedRow): void
    {
        $this->update([
            'processed_rows' => $processedRows,
            'successful_rows' => $successfulRows,
            'error_rows' => $errorRows,
            'last_processed_row' => $lastProcessedRow,
        ]);
    }

    /**
     * Increment the processed row count.
     *
     * @param bool $successful
     * @param int $rowNumber
     * @return void
     */
    public function incrementProcessedRows(bool $successful, int $rowNumber): void
    {
        $this->increment('processed_rows');
        
        if ($successful) {
            $this->increment('successful_rows');
        } else {
            $this->increment('error_rows');
        }

        $this->update(['last_processed_row' => $rowNumber]);
    }

    /**
     * Get the import errors for this job.
     *
     * @return HasMany
     */
    public function importErrors(): HasMany
    {
        return $this->hasMany(ImportError::class);
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
     * Get a summary of the import job.
     *
     * @return array<string, mixed>
     */
    public function getSummary(): array
    {
        return [
            'id' => $this->id,
            'filename' => $this->filename,
            'status' => $this->status,
            'progress_percentage' => $this->progress_percentage,
            'total_rows' => $this->total_rows,
            'processed_rows' => $this->processed_rows,
            'successful_rows' => $this->successful_rows,
            'error_rows' => $this->error_rows,
            'started_at' => $this->started_at,
            'completed_at' => $this->completed_at,
            'error_count' => $this->importErrors()->count(),
        ];
    }
}