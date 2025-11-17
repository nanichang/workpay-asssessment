<?php

namespace App\Livewire;

use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\Attributes\Validate;
use Illuminate\Http\UploadedFile;
use App\Models\ImportJob;
use App\Jobs\ProcessEmployeeImportJob;
use Illuminate\Support\Str;

class EmployeeFileUpload extends Component
{
    use WithFileUploads;

    public $file;

    public $uploadProgress = 0;
    public $isUploading = false;
    public $uploadComplete = false;
    public $importJobId = null;
    public $errorMessage = null;
    public $validationErrors = [];

    protected $listeners = ['fileSelected' => 'handleFileSelected'];



    public function updatedFile()
    {
        $this->validateFile();
    }

    public function validateFile()
    {
        $this->resetValidation();
        $this->errorMessage = null;
        $this->validationErrors = [];

        if (!$this->file) {
            return;
        }

        // Validate file size (20MB max)
        if ($this->file->getSize() > 20 * 1024 * 1024) {
            $this->errorMessage = 'File size must not exceed 20MB.';
            return;
        }

        // Validate file type
        $allowedExtensions = ['csv', 'xlsx', 'xls'];
        $extension = strtolower($this->file->getClientOriginalExtension());
        
        if (!in_array($extension, $allowedExtensions)) {
            $this->errorMessage = 'File must be a CSV or Excel file (.csv, .xlsx, .xls).';
            return;
        }

        // Validate CSV headers if it's a CSV file
        if ($extension === 'csv') {
            $this->validateCsvHeaders();
        }
    }

    private function validateCsvHeaders()
    {
        try {
            $handle = fopen($this->file->getRealPath(), 'r');
            $headers = fgetcsv($handle);
            fclose($handle);

            $expectedHeaders = [
                'employee_number',
                'first_name', 
                'last_name',
                'email',
                'department',
                'salary',
                'currency',
                'country_code',
                'start_date'
            ];

            $missingHeaders = array_diff($expectedHeaders, $headers);
            
            if (!empty($missingHeaders)) {
                $this->errorMessage = 'Missing required headers: ' . implode(', ', $missingHeaders);
                return;
            }

        } catch (\Exception $e) {
            $this->errorMessage = 'Unable to read CSV file headers. Please ensure the file is valid.';
        }
    }

    public function uploadFile()
    {
        $this->validate([
            'file' => 'required|file|mimes:csv,xlsx,xls|max:20480'
        ]);
        
        if ($this->errorMessage) {
            return;
        }

        $this->isUploading = true;
        $this->uploadProgress = 0;

        try {
            // Store the uploaded file
            $filePath = $this->file->store('imports', 'local');
            \Log::info('File stored', [
                'original_name' => $this->file->getClientOriginalName(),
                'stored_path' => $filePath,
                'full_path' => storage_path('app/private/' . $filePath),
                'file_exists' => file_exists(storage_path('app/private/' . $filePath))
            ]);
            
            // Create import job record
            $importJob = ImportJob::create([
                'id' => Str::uuid(),
                'filename' => $this->file->getClientOriginalName(),
                'file_path' => $filePath,
                'status' => 'pending',
                'total_rows' => 0, // Will be updated by the job
                'processed_rows' => 0,
                'successful_rows' => 0,
                'error_rows' => 0,
                'started_at' => null,
                'completed_at' => null,
            ]);
            
            // Dispatch the processing job
            ProcessEmployeeImportJob::dispatch($importJob);
            
            $this->importJobId = $importJob->id;
            $this->uploadComplete = true;
            $this->uploadProgress = 100;
            
            // Emit event to parent components
            $this->dispatch('fileUploaded', $this->importJobId);

        } catch (\Exception $e) {
            $this->errorMessage = 'Upload failed: ' . $e->getMessage();
        } finally {
            $this->isUploading = false;
        }
    }

    public function resetUpload()
    {
        $this->reset(['file', 'uploadProgress', 'isUploading', 'uploadComplete', 'importJobId', 'errorMessage', 'validationErrors']);
    }

    public function render()
    {
        return view('livewire.employee-file-upload');
    }
}
