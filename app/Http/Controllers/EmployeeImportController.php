<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessEmployeeImportJob;
use App\Models\ImportJob;
use App\Services\ErrorReporter;
use App\Services\ProgressTracker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

class EmployeeImportController extends Controller
{
    private ProgressTracker $progressTracker;
    private ErrorReporter $errorReporter;

    public function __construct(ProgressTracker $progressTracker, ErrorReporter $errorReporter)
    {
        $this->progressTracker = $progressTracker;
        $this->errorReporter = $errorReporter;
    }

    /**
     * Upload and process employee CSV/Excel file
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function upload(Request $request): JsonResponse
    {
        try {
            // Validate the request
            $validator = $this->validateUploadRequest($request);
            
            if ($validator->fails()) {
                return $this->errorResponse(
                    'Validation failed',
                    $validator->errors()->toArray(),
                    422
                );
            }

            $file = $request->file('file');
            
            // Validate file format and headers
            $fileValidation = $this->validateFileFormat($file);
            if (!$fileValidation['valid']) {
                return $this->errorResponse(
                    'File validation failed',
                    ['file' => $fileValidation['errors']],
                    422
                );
            }

            // Validate file size (row count)
            $sizeValidation = $this->validateFileSize($file);
            if (!$sizeValidation['valid']) {
                return $this->errorResponse(
                    'File size validation failed',
                    ['file' => $sizeValidation['errors']],
                    422
                );
            }

            // Store the uploaded file
            $filePath = $this->storeUploadedFile($file);
            
            // Create import job record
            $importJob = $this->createImportJob($file, $filePath);
            
            // Dispatch the processing job
            ProcessEmployeeImportJob::dispatch($importJob);
            
            Log::info("Employee import job created and dispatched", [
                'import_job_id' => $importJob->id,
                'filename' => $importJob->filename,
                'file_size' => $file->getSize(),
            ]);

            return $this->successResponse([
                'import_job_id' => $importJob->id,
                'filename' => $importJob->filename,
                'status' => $importJob->status,
                'message' => 'File uploaded successfully and processing has started',
            ], 201);

        } catch (ValidationException $e) {
            return $this->errorResponse(
                'Validation error',
                $e->errors(),
                422
            );
        } catch (Throwable $e) {
            Log::error('Employee import upload failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse(
                'Upload failed',
                ['system' => 'An unexpected error occurred while processing your upload'],
                500
            );
        }
    }    /**

     * Get import progress
     *
     * @param string $importId
     * @return JsonResponse
     */
    public function getProgress(string $importId): JsonResponse
    {
        try {
            $progress = $this->progressTracker->getProgress($importId);
            
            if ($progress['job_id'] === null) {
                return $this->errorResponse(
                    'Import job not found',
                    ['import_id' => 'The specified import job does not exist'],
                    404
                );
            }

            return $this->successResponse($progress);

        } catch (Throwable $e) {
            Log::error('Failed to get import progress', [
                'import_id' => $importId,
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'Failed to retrieve progress',
                ['system' => 'An error occurred while retrieving progress information'],
                500
            );
        }
    }

    /**
     * Get import errors with optional filtering and pagination
     *
     * @param Request $request
     * @param string $importId
     * @return JsonResponse
     */
    public function getErrors(Request $request, string $importId): JsonResponse
    {
        try {
            // Validate import job exists
            $importJob = ImportJob::find($importId);
            if (!$importJob) {
                return $this->errorResponse(
                    'Import job not found',
                    ['import_id' => 'The specified import job does not exist'],
                    404
                );
            }

            // Validate query parameters
            $validator = $this->validateErrorsRequest($request);
            if ($validator->fails()) {
                return $this->errorResponse(
                    'Invalid query parameters',
                    $validator->errors()->toArray(),
                    422
                );
            }

            $filters = $this->buildErrorFilters($request);
            $perPage = min((int) $request->get('per_page', 50), 100); // Max 100 per page

            // Get paginated errors
            $errors = $this->errorReporter->getPaginatedErrors($importId, $filters, $perPage);

            return $this->successResponse([
                'errors' => $errors->items(),
                'pagination' => [
                    'current_page' => $errors->currentPage(),
                    'per_page' => $errors->perPage(),
                    'total' => $errors->total(),
                    'last_page' => $errors->lastPage(),
                    'from' => $errors->firstItem(),
                    'to' => $errors->lastItem(),
                ],
                'filters_applied' => $filters,
            ]);

        } catch (Throwable $e) {
            Log::error('Failed to get import errors', [
                'import_id' => $importId,
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'Failed to retrieve errors',
                ['system' => 'An error occurred while retrieving error information'],
                500
            );
        }
    }

    /**
     * Get import summary and statistics
     *
     * @param string $importId
     * @return JsonResponse
     */
    public function getSummary(string $importId): JsonResponse
    {
        try {
            // Validate import job exists
            $importJob = ImportJob::find($importId);
            if (!$importJob) {
                return $this->errorResponse(
                    'Import job not found',
                    ['import_id' => 'The specified import job does not exist'],
                    404
                );
            }

            // Get progress information
            $progress = $this->progressTracker->getProgress($importId);
            
            // Get error summary
            $errorSummary = $this->errorReporter->getErrorSummary($importId);

            // Combine into comprehensive summary
            $summary = [
                'import_job' => $importJob->getSummary(),
                'progress' => $progress,
                'error_summary' => $errorSummary,
                'statistics' => [
                    'success_rate' => $this->calculateSuccessRate($importJob),
                    'error_rate' => $this->calculateErrorRate($importJob),
                    'processing_time' => $this->calculateProcessingTime($importJob),
                ],
            ];

            return $this->successResponse($summary);

        } catch (Throwable $e) {
            Log::error('Failed to get import summary', [
                'import_id' => $importId,
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'Failed to retrieve summary',
                ['system' => 'An error occurred while retrieving summary information'],
                500
            );
        }
    }   
 /**
     * Validate upload request
     *
     * @param Request $request
     * @return \Illuminate\Validation\Validator
     */
    private function validateUploadRequest(Request $request): \Illuminate\Validation\Validator
    {
        $maxFileSizeKB = config('import.max_file_size', 20971520) / 1024; // Convert bytes to KB
        $allowedTypes = implode(',', config('import.allowed_file_types', ['csv', 'xlsx', 'xls']));
        
        return Validator::make($request->all(), [
            'file' => [
                'required',
                'file',
                'max:' . $maxFileSizeKB,
                'mimes:' . $allowedTypes,
            ],
        ], [
            'file.required' => 'A file is required for upload.',
            'file.file' => 'The uploaded item must be a valid file.',
            'file.max' => 'The file size must not exceed ' . ($maxFileSizeKB / 1024) . 'MB.',
            'file.mimes' => 'The file must be one of the following types: ' . $allowedTypes . '.',
        ]);
    }

    /**
     * Validate errors request parameters
     *
     * @param Request $request
     * @return \Illuminate\Validation\Validator
     */
    private function validateErrorsRequest(Request $request): \Illuminate\Validation\Validator
    {
        return Validator::make($request->all(), [
            'error_type' => 'sometimes|string|in:validation,duplicate,format,business_rule,system',
            'row_start' => 'sometimes|integer|min:1',
            'row_end' => 'sometimes|integer|min:1|gte:row_start',
            'search' => 'sometimes|string|max:255',
            'per_page' => 'sometimes|integer|min:1|max:100',
            'page' => 'sometimes|integer|min:1',
        ], [
            'error_type.in' => 'Error type must be one of: validation, duplicate, format, business_rule, system.',
            'row_start.min' => 'Row start must be at least 1.',
            'row_end.gte' => 'Row end must be greater than or equal to row start.',
            'per_page.max' => 'Per page cannot exceed 100 items.',
        ]);
    }

    /**
     * Validate file format and headers
     *
     * @param \Illuminate\Http\UploadedFile $file
     * @return array
     */
    private function validateFileFormat($file): array
    {
        $errors = [];
        
        try {
            $extension = strtolower($file->getClientOriginalExtension());
            
            if ($extension === 'csv') {
                $validation = $this->validateCsvHeaders($file);
            } else {
                $validation = $this->validateExcelHeaders($file);
            }
            
            if (!$validation['valid']) {
                $errors = array_merge($errors, $validation['errors']);
            }

        } catch (Throwable $e) {
            Log::error('File format validation failed', [
                'filename' => $file->getClientOriginalName(),
                'error' => $e->getMessage(),
            ]);
            
            $errors[] = 'Unable to read file format. Please ensure the file is not corrupted.';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Validate file size (row count) against maximum allowed rows
     *
     * @param \Illuminate\Http\UploadedFile $file
     * @return array
     */
    private function validateFileSize($file): array
    {
        $errors = [];
        $maxRows = config('import.max_rows', 50000);
        
        try {
            $extension = strtolower($file->getClientOriginalExtension());
            $rowCount = 0;
            
            if ($extension === 'csv') {
                $rowCount = $this->countCsvRows($file);
            } else {
                $rowCount = $this->countExcelRows($file);
            }
            
            if ($rowCount > $maxRows) {
                $errors[] = "File contains {$rowCount} rows, which exceeds the maximum allowed {$maxRows} rows.";
            }

        } catch (Throwable $e) {
            Log::warning('Could not validate file size', [
                'filename' => $file->getClientOriginalName(),
                'error' => $e->getMessage(),
            ]);
            
            // Don't fail validation if we can't count rows - let the processor handle it
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Count rows in CSV file
     *
     * @param \Illuminate\Http\UploadedFile $file
     * @return int
     */
    private function countCsvRows($file): int
    {
        $handle = fopen($file->getPathname(), 'r');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open CSV file for reading.');
        }

        $count = 0;
        while (fgetcsv($handle) !== false) {
            $count++;
        }
        
        fclose($handle);
        
        // Subtract 1 for header row
        return max(0, $count - 1);
    }

    /**
     * Count rows in Excel file
     *
     * @param \Illuminate\Http\UploadedFile $file
     * @return int
     */
    private function countExcelRows($file): int
    {
        $reader = IOFactory::createReaderForFile($file->getPathname());
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($file->getPathname());
        
        $worksheet = $spreadsheet->getActiveSheet();
        $highestRow = $worksheet->getHighestRow();
        
        // Subtract 1 for header row
        return max(0, $highestRow - 1);
    }

    /**
     * Validate CSV file headers
     *
     * @param \Illuminate\Http\UploadedFile $file
     * @return array
     */
    private function validateCsvHeaders($file): array
    {
        $errors = [];
        
        try {
            $handle = fopen($file->getPathname(), 'r');
            if ($handle === false) {
                return [
                    'valid' => false,
                    'errors' => ['Unable to open CSV file for reading.'],
                ];
            }

            $headers = fgetcsv($handle);
            fclose($handle);

            if ($headers === false || empty($headers)) {
                $errors[] = 'CSV file appears to be empty or has no headers.';
            } else {
                $validation = $this->validateEmployeeHeaders($headers);
                if (!$validation['valid']) {
                    $errors = array_merge($errors, $validation['errors']);
                }
            }

        } catch (Throwable $e) {
            $errors[] = 'Error reading CSV file: ' . $e->getMessage();
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Validate Excel file headers
     *
     * @param \Illuminate\Http\UploadedFile $file
     * @return array
     */
    private function validateExcelHeaders($file): array
    {
        $errors = [];
        
        try {
            $reader = IOFactory::createReaderForFile($file->getPathname());
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($file->getPathname());
            
            $worksheet = $spreadsheet->getActiveSheet();
            $highestColumn = $worksheet->getHighestColumn();
            
            $headers = [];
            for ($col = 'A'; $col <= $highestColumn; $col++) {
                $headers[] = $worksheet->getCell($col . '1')->getCalculatedValue();
            }

            if (empty($headers) || (count($headers) === 1 && empty($headers[0]))) {
                $errors[] = 'Excel file appears to be empty or has no headers.';
            } else {
                $validation = $this->validateEmployeeHeaders($headers);
                if (!$validation['valid']) {
                    $errors = array_merge($errors, $validation['errors']);
                }
            }

        } catch (Throwable $e) {
            $errors[] = 'Error reading Excel file: ' . $e->getMessage();
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Validate employee data headers
     *
     * @param array $headers
     * @return array
     */
    private function validateEmployeeHeaders(array $headers): array
    {
        $errors = [];
        
        // Normalize headers for comparison
        $normalizedHeaders = array_map(function ($header) {
            return strtolower(str_replace(' ', '_', trim($header)));
        }, $headers);

        // Get required headers from configuration
        $configHeaders = config('import.required_headers', [
            'employee_number',
            'first_name', 
            'last_name',
            'email',
        ]);
        
        // Separate into required and optional based on business rules
        $requiredHeaders = [
            'employee_number',
            'first_name', 
            'last_name',
            'email',
        ];

        // All other configured headers are optional
        $optionalHeaders = array_diff($configHeaders, $requiredHeaders);

        $allValidHeaders = array_merge($requiredHeaders, $optionalHeaders);

        // Check for required headers
        $missingRequired = [];
        foreach ($requiredHeaders as $required) {
            if (!in_array($required, $normalizedHeaders)) {
                $missingRequired[] = str_replace('_', ' ', ucwords($required, '_'));
            }
        }

        if (!empty($missingRequired)) {
            $errors[] = 'Missing required headers: ' . implode(', ', $missingRequired);
        }

        // Check for invalid headers (warn about unexpected columns)
        $invalidHeaders = [];
        foreach ($normalizedHeaders as $header) {
            if (!empty($header) && !in_array($header, $allValidHeaders)) {
                $invalidHeaders[] = $header;
            }
        }

        if (!empty($invalidHeaders)) {
            $errors[] = 'Unexpected headers found (will be ignored): ' . implode(', ', $invalidHeaders);
        }

        return [
            'valid' => empty($missingRequired), // Only fail on missing required headers
            'errors' => $errors,
        ];
    } 
   /**
     * Store uploaded file
     *
     * @param \Illuminate\Http\UploadedFile $file
     * @return string
     */
    private function storeUploadedFile($file): string
    {
        $filename = Str::uuid() . '_' . $file->getClientOriginalName();
        $path = $file->storeAs('imports', $filename, 'local');
        
        return Storage::disk('local')->path($path);
    }

    /**
     * Create import job record
     *
     * @param \Illuminate\Http\UploadedFile $file
     * @param string $filePath
     * @return ImportJob
     */
    private function createImportJob($file, string $filePath): ImportJob
    {
        $job = ImportJob::create([
            'filename' => $file->getClientOriginalName(),
            'file_path' => $filePath,
            'status' => ImportJob::STATUS_PENDING,
        ]);

        // Calculate and store file integrity metadata
        try {
            $integrityService = app(FileIntegrityService::class);
            $integrityService->calculateFileIntegrity($job, $filePath);
        } catch (\Exception $e) {
            Log::warning("Failed to calculate file integrity for job {$job->id}: " . $e->getMessage());
            // Continue without integrity data - not critical for job creation
        }

        return $job;
    }

    /**
     * Build error filters from request
     *
     * @param Request $request
     * @return array
     */
    private function buildErrorFilters(Request $request): array
    {
        $filters = [];

        if ($request->has('error_type')) {
            $filters['error_type'] = $request->get('error_type');
        }

        if ($request->has('row_start')) {
            $filters['row_start'] = (int) $request->get('row_start');
        }

        if ($request->has('row_end')) {
            $filters['row_end'] = (int) $request->get('row_end');
        }

        if ($request->has('search')) {
            $filters['search'] = $request->get('search');
        }

        return $filters;
    }

    /**
     * Calculate success rate percentage
     *
     * @param ImportJob $job
     * @return float
     */
    private function calculateSuccessRate(ImportJob $job): float
    {
        if ($job->processed_rows === 0) {
            return 0.0;
        }

        return round(($job->successful_rows / $job->processed_rows) * 100, 2);
    }

    /**
     * Calculate error rate percentage
     *
     * @param ImportJob $job
     * @return float
     */
    private function calculateErrorRate(ImportJob $job): float
    {
        if ($job->processed_rows === 0) {
            return 0.0;
        }

        return round(($job->error_rows / $job->processed_rows) * 100, 2);
    }

    /**
     * Calculate processing time in seconds
     *
     * @param ImportJob $job
     * @return int|null
     */
    private function calculateProcessingTime(ImportJob $job): ?int
    {
        if (!$job->started_at) {
            return null;
        }

        $endTime = $job->completed_at ?? now();
        return $job->started_at->diffInSeconds($endTime);
    }

    /**
     * Return success response
     *
     * @param mixed $data
     * @param int $status
     * @return JsonResponse
     */
    private function successResponse($data, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $data,
        ], $status);
    }

    /**
     * Return error response
     *
     * @param string $message
     * @param array $errors
     * @param int $status
     * @return JsonResponse
     */
    private function errorResponse(string $message, array $errors = [], int $status = 400): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ], $status);
    }
}