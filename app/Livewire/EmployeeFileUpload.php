<?php

namespace App\Livewire;

use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\Attributes\Validate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class EmployeeFileUpload extends Component
{
    use WithFileUploads;

    #[Validate('required|file|mimes:csv,xlsx,xls|max:20480')] // 20MB max
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
        $this->validate();
        
        if ($this->errorMessage) {
            return;
        }

        $this->isUploading = true;
        $this->uploadProgress = 0;

        try {
            // Store the file temporarily
            $filePath = $this->file->store('imports', 'local');
            
            // Create import job via API using multipart form data
            $response = Http::attach(
                'file', file_get_contents($this->file->getRealPath()), $this->file->getClientOriginalName()
            )->post('/api/employee-import/upload');

            if ($response->successful()) {
                $data = $response->json();
                $this->importJobId = $data['import_job_id'] ?? $data['id'];
                $this->uploadComplete = true;
                $this->uploadProgress = 100;
                
                // Emit event to parent components
                $this->dispatch('fileUploaded', $this->importJobId);
            } else {
                $errorData = $response->json();
                $this->errorMessage = $errorData['message'] ?? 'Upload failed. Please try again.';
            }

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
