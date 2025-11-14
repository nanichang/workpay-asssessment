<?php

namespace App\Services;

use App\Models\ImportError;
use App\Models\ImportJob;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class ErrorReporter
{
    /**
     * Record an import error.
     *
     * @param ImportJob $job
     * @param int $rowNumber
     * @param string $type
     * @param string $message
     * @param array $data
     * @return ImportError
     */
    public function recordError(ImportJob $job, int $rowNumber, string $type, string $message, array $data = []): ImportError
    {
        return ImportError::create([
            'import_job_id' => $job->id,
            'row_number' => $rowNumber,
            'error_type' => $type,
            'error_message' => $message,
            'row_data' => $data,
        ]);
    }

    /**
     * Record a validation error.
     *
     * @param ImportJob $job
     * @param int $rowNumber
     * @param string $message
     * @param array $data
     * @return ImportError
     */
    public function recordValidationError(ImportJob $job, int $rowNumber, string $message, array $data = []): ImportError
    {
        return $this->recordError($job, $rowNumber, ImportError::TYPE_VALIDATION, $message, $data);
    }

    /**
     * Record a duplicate error.
     *
     * @param ImportJob $job
     * @param int $rowNumber
     * @param string $message
     * @param array $data
     * @return ImportError
     */
    public function recordDuplicateError(ImportJob $job, int $rowNumber, string $message, array $data = []): ImportError
    {
        return $this->recordError($job, $rowNumber, ImportError::TYPE_DUPLICATE, $message, $data);
    }

    /**
     * Record a format error.
     *
     * @param ImportJob $job
     * @param int $rowNumber
     * @param string $message
     * @param array $data
     * @return ImportError
     */
    public function recordFormatError(ImportJob $job, int $rowNumber, string $message, array $data = []): ImportError
    {
        return $this->recordError($job, $rowNumber, ImportError::TYPE_FORMAT, $message, $data);
    }

    /**
     * Record a business rule error.
     *
     * @param ImportJob $job
     * @param int $rowNumber
     * @param string $message
     * @param array $data
     * @return ImportError
     */
    public function recordBusinessRuleError(ImportJob $job, int $rowNumber, string $message, array $data = []): ImportError
    {
        return $this->recordError($job, $rowNumber, ImportError::TYPE_BUSINESS_RULE, $message, $data);
    }

    /**
     * Record a system error.
     *
     * @param ImportJob $job
     * @param int $rowNumber
     * @param string $message
     * @param array $data
     * @return ImportError
     */
    public function recordSystemError(ImportJob $job, int $rowNumber, string $message, array $data = []): ImportError
    {
        return $this->recordError($job, $rowNumber, ImportError::TYPE_SYSTEM, $message, $data);
    }

    /**
     * Get errors for a specific import job with optional filters.
     *
     * @param string $importId
     * @param array $filters
     * @return Collection
     */
    public function getErrors(string $importId, array $filters = []): Collection
    {
        $query = ImportError::byImportJob($importId);

        // Apply error type filter
        if (isset($filters['error_type']) && !empty($filters['error_type'])) {
            $query->byErrorType($filters['error_type']);
        }

        // Apply row number range filter
        if (isset($filters['row_start']) && is_numeric($filters['row_start'])) {
            $rowEnd = isset($filters['row_end']) && is_numeric($filters['row_end']) 
                ? (int) $filters['row_end'] 
                : null;
            $query->byRowRange((int) $filters['row_start'], $rowEnd);
        }

        // Apply search filter for error message
        if (isset($filters['search']) && !empty($filters['search'])) {
            $query->where('error_message', 'like', '%' . $filters['search'] . '%');
        }

        // Order by row number by default
        $query->orderBy('row_number');

        return $query->get();
    }

    /**
     * Get paginated errors for a specific import job with optional filters.
     *
     * @param string $importId
     * @param array $filters
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function getPaginatedErrors(string $importId, array $filters = [], int $perPage = 50): LengthAwarePaginator
    {
        $query = ImportError::byImportJob($importId);

        // Apply error type filter
        if (isset($filters['error_type']) && !empty($filters['error_type'])) {
            $query->byErrorType($filters['error_type']);
        }

        // Apply row number range filter
        if (isset($filters['row_start']) && is_numeric($filters['row_start'])) {
            $rowEnd = isset($filters['row_end']) && is_numeric($filters['row_end']) 
                ? (int) $filters['row_end'] 
                : null;
            $query->byRowRange((int) $filters['row_start'], $rowEnd);
        }

        // Apply search filter for error message
        if (isset($filters['search']) && !empty($filters['search'])) {
            $query->where('error_message', 'like', '%' . $filters['search'] . '%');
        }

        // Order by row number by default
        $query->orderBy('row_number');

        return $query->paginate($perPage);
    }

    /**
     * Get error summary and statistics for an import job.
     *
     * @param string $importId
     * @return array
     */
    public function getErrorSummary(string $importId): array
    {
        $errors = ImportError::byImportJob($importId)->get();
        
        $summary = [
            'total_errors' => $errors->count(),
            'error_types' => [],
            'error_distribution' => [],
            'most_common_errors' => [],
        ];

        // Count errors by type
        foreach (ImportError::getValidErrorTypes() as $type) {
            $count = $errors->where('error_type', $type)->count();
            $summary['error_types'][$type] = $count;
        }

        // Get error distribution by row ranges (for large imports)
        if ($errors->isNotEmpty()) {
            $minRow = $errors->min('row_number');
            $maxRow = $errors->max('row_number');
            $rangeSize = max(1, ceil(($maxRow - $minRow + 1) / 10)); // 10 ranges

            for ($i = 0; $i < 10; $i++) {
                $rangeStart = $minRow + ($i * $rangeSize);
                $rangeEnd = min($maxRow, $rangeStart + $rangeSize - 1);
                
                $rangeErrors = $errors->filter(function ($error) use ($rangeStart, $rangeEnd) {
                    return $error->row_number >= $rangeStart && $error->row_number <= $rangeEnd;
                })->count();

                if ($rangeErrors > 0) {
                    $summary['error_distribution'][] = [
                        'range' => "{$rangeStart}-{$rangeEnd}",
                        'count' => $rangeErrors,
                    ];
                }
            }
        }

        // Get most common error messages (top 5)
        $errorMessages = $errors->groupBy('error_message')
            ->map(function ($group) {
                return [
                    'message' => $group->first()->error_message,
                    'count' => $group->count(),
                    'type' => $group->first()->error_type,
                ];
            })
            ->sortByDesc('count')
            ->take(5)
            ->values();

        $summary['most_common_errors'] = $errorMessages->toArray();

        return $summary;
    }

    /**
     * Get errors by type for a specific import job.
     *
     * @param string $importId
     * @param string $errorType
     * @return Collection
     */
    public function getErrorsByType(string $importId, string $errorType): Collection
    {
        return ImportError::byImportJob($importId)
            ->byErrorType($errorType)
            ->orderBy('row_number')
            ->get();
    }

    /**
     * Get error count for a specific import job.
     *
     * @param string $importId
     * @return int
     */
    public function getErrorCount(string $importId): int
    {
        return ImportError::byImportJob($importId)->count();
    }

    /**
     * Get error count by type for a specific import job.
     *
     * @param string $importId
     * @param string $errorType
     * @return int
     */
    public function getErrorCountByType(string $importId, string $errorType): int
    {
        return ImportError::byImportJob($importId)
            ->byErrorType($errorType)
            ->count();
    }

    /**
     * Clear all errors for a specific import job.
     *
     * @param string $importId
     * @return int Number of deleted errors
     */
    public function clearErrors(string $importId): int
    {
        return ImportError::byImportJob($importId)->delete();
    }

    /**
     * Get recent errors for a specific import job (last N errors).
     *
     * @param string $importId
     * @param int $limit
     * @return Collection
     */
    public function getRecentErrors(string $importId, int $limit = 10): Collection
    {
        return ImportError::byImportJob($importId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Export errors to array format for CSV/Excel export.
     *
     * @param string $importId
     * @param array $filters
     * @return array
     */
    public function exportErrors(string $importId, array $filters = []): array
    {
        $errors = $this->getErrors($importId, $filters);
        
        return $errors->map(function (ImportError $error) {
            return [
                'Row Number' => $error->row_number,
                'Error Type' => ucfirst(str_replace('_', ' ', $error->error_type)),
                'Error Message' => $error->error_message,
                'Row Data' => json_encode($error->row_data),
                'Created At' => $error->created_at->format('Y-m-d H:i:s'),
            ];
        })->toArray();
    }
}