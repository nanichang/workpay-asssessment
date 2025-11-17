<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportResumptionLog extends Model
{
    /**
     * Disable automatic timestamps since we only need created_at
     */
    public $timestamps = false;

    /**
     * Event types for resumption logging
     */
    public const EVENT_RESUMPTION_ATTEMPT = 'resumption_attempt';
    public const EVENT_RESUMPTION_SUCCESS = 'resumption_success';
    public const EVENT_RESUMPTION_FAILURE = 'resumption_failure';
    public const EVENT_INTEGRITY_CHECK = 'integrity_check';
    public const EVENT_LOCK_RENEWAL = 'lock_renewal';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'import_job_id',
        'event_type',
        'attempt_number',
        'resumed_from_row',
        'details',
        'metadata',
        'created_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'resumed_from_row' => 'integer',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Get the import job that this log belongs to.
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
     * Scope to filter by event type.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $eventType
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByEventType($query, string $eventType)
    {
        return $query->where('event_type', $eventType);
    }

    /**
     * Log a resumption attempt.
     *
     * @param string $importJobId
     * @param int $attemptNumber
     * @param int|null $resumedFromRow
     * @param string|null $details
     * @param array $metadata
     * @return static
     */
    public static function logResumptionAttempt(
        string $importJobId,
        int $attemptNumber,
        ?int $resumedFromRow = null,
        ?string $details = null,
        array $metadata = []
    ): static {
        return static::create([
            'import_job_id' => $importJobId,
            'event_type' => static::EVENT_RESUMPTION_ATTEMPT,
            'attempt_number' => $attemptNumber,
            'resumed_from_row' => $resumedFromRow,
            'details' => $details,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }

    /**
     * Log a successful resumption.
     *
     * @param string $importJobId
     * @param int $resumedFromRow
     * @param array $metadata
     * @return static
     */
    public static function logResumptionSuccess(
        string $importJobId,
        int $resumedFromRow,
        array $metadata = []
    ): static {
        return static::create([
            'import_job_id' => $importJobId,
            'event_type' => static::EVENT_RESUMPTION_SUCCESS,
            'resumed_from_row' => $resumedFromRow,
            'details' => 'Import successfully resumed from checkpoint',
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }

    /**
     * Log a failed resumption.
     *
     * @param string $importJobId
     * @param string $reason
     * @param array $metadata
     * @return static
     */
    public static function logResumptionFailure(
        string $importJobId,
        string $reason,
        array $metadata = []
    ): static {
        return static::create([
            'import_job_id' => $importJobId,
            'event_type' => static::EVENT_RESUMPTION_FAILURE,
            'details' => $reason,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }

    /**
     * Log an integrity check result.
     *
     * @param string $importJobId
     * @param bool $passed
     * @param string $details
     * @param array $metadata
     * @return static
     */
    public static function logIntegrityCheck(
        string $importJobId,
        bool $passed,
        string $details,
        array $metadata = []
    ): static {
        return static::create([
            'import_job_id' => $importJobId,
            'event_type' => static::EVENT_INTEGRITY_CHECK,
            'details' => $passed ? "Integrity check passed: {$details}" : "Integrity check failed: {$details}",
            'metadata' => array_merge($metadata, ['passed' => $passed]),
            'created_at' => now(),
        ]);
    }

    /**
     * Log a lock renewal.
     *
     * @param string $importJobId
     * @param bool $successful
     * @param array $metadata
     * @return static
     */
    public static function logLockRenewal(
        string $importJobId,
        bool $successful,
        array $metadata = []
    ): static {
        return static::create([
            'import_job_id' => $importJobId,
            'event_type' => static::EVENT_LOCK_RENEWAL,
            'details' => $successful ? 'Lock renewed successfully' : 'Lock renewal failed',
            'metadata' => array_merge($metadata, ['successful' => $successful]),
            'created_at' => now(),
        ]);
    }

    /**
     * Get resumption statistics for monitoring.
     *
     * @param \Carbon\Carbon|null $since
     * @return array
     */
    public static function getResumptionStatistics(?\Carbon\Carbon $since = null): array
    {
        $query = static::query();
        
        if ($since) {
            $query->where('created_at', '>=', $since);
        }

        $logs = $query->get();

        return [
            'total_attempts' => $logs->where('event_type', static::EVENT_RESUMPTION_ATTEMPT)->count(),
            'successful_resumptions' => $logs->where('event_type', static::EVENT_RESUMPTION_SUCCESS)->count(),
            'failed_resumptions' => $logs->where('event_type', static::EVENT_RESUMPTION_FAILURE)->count(),
            'integrity_checks' => $logs->where('event_type', static::EVENT_INTEGRITY_CHECK)->count(),
            'lock_renewals' => $logs->where('event_type', static::EVENT_LOCK_RENEWAL)->count(),
            'success_rate' => $logs->where('event_type', static::EVENT_RESUMPTION_ATTEMPT)->count() > 0 
                ? round(($logs->where('event_type', static::EVENT_RESUMPTION_SUCCESS)->count() / 
                        $logs->where('event_type', static::EVENT_RESUMPTION_ATTEMPT)->count()) * 100, 2)
                : 0,
        ];
    }

    /**
     * Get failed resumption jobs for alerting.
     *
     * @param \Carbon\Carbon|null $since
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getFailedResumptions(?\Carbon\Carbon $since = null): \Illuminate\Database\Eloquent\Collection
    {
        $query = static::byEventType(static::EVENT_RESUMPTION_FAILURE)
            ->with('importJob');
        
        if ($since) {
            $query->where('created_at', '>=', $since);
        }

        return $query->orderBy('created_at', 'desc')->get();
    }
}