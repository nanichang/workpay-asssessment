<?php

namespace App\Livewire;

use Livewire\Component;
use Livewire\Attributes\On;
use App\Models\ImportJob;
use App\Http\Controllers\EmployeeImportController;

class ImportProgress extends Component
{
    public $importJobId;
    public $importJob;
    public $progress = 0;
    public $isComplete = false;
    public $autoRefresh = true;
    public $refreshInterval = 2000; // 2 seconds

    protected $listeners = ['startTracking' => 'startTracking'];

    public function mount($importJobId = null)
    {
        if ($importJobId) {
            $this->importJobId = $importJobId;
            $this->loadProgress();
        }
    }

    #[On('fileUploaded')]
    public function startTracking($importJobId)
    {
        $this->importJobId = $importJobId;
        $this->autoRefresh = true;
        $this->loadProgress();
    }

    public function loadProgress()
    {
        if (!$this->importJobId) {
            return;
        }

        try {
            // Load progress directly from controller
            $controller = app(EmployeeImportController::class);
            $response = $controller->getProgress($this->importJobId);
            
            if ($response->getStatusCode() === 200) {
                $responseData = $response->getData(true);
                \Log::info('Progress response loaded', ['response' => $responseData]);
                
                // Extract the actual data from the API response structure
                $data = $responseData['data'] ?? $responseData;
                $this->importJob = (object) $data;
                $this->progress = $data['percentage'] ?? 0;
                
                // Safe check for status
                $status = $this->importJob->status ?? 'unknown';
                $this->isComplete = in_array($status, ['completed', 'failed']);
                
                if ($this->isComplete) {
                    $this->autoRefresh = false;
                }
            } else {
                \Log::error('Progress API returned error', [
                    'status' => $response->getStatusCode(),
                    'data' => $response->getData(true)
                ]);
                $this->autoRefresh = false;
            }
        } catch (\Exception $e) {
            // Handle errors gracefully
            \Log::error('Progress loading failed', [
                'error' => $e->getMessage(),
                'import_id' => $this->importJobId
            ]);
            $this->autoRefresh = false;
        }
    }

    public function toggleAutoRefresh()
    {
        $this->autoRefresh = !$this->autoRefresh;
    }

    public function refreshProgress()
    {
        $this->loadProgress();
    }

    public function getStatusColorProperty()
    {
        if (!$this->importJob || !isset($this->importJob->status)) {
            return 'gray';
        }

        return match($this->importJob->status) {
            'pending' => 'yellow',
            'processing' => 'blue',
            'completed' => 'green',
            'failed' => 'red',
            default => 'gray'
        };
    }

    public function getStatusTextProperty()
    {
        if (!$this->importJob || !isset($this->importJob->status)) {
            return 'Unknown';
        }

        return match($this->importJob->status) {
            'pending' => 'Pending',
            'processing' => 'Processing',
            'completed' => 'Completed',
            'failed' => 'Failed',
            default => 'Unknown'
        };
    }

    public function getEstimatedTimeProperty()
    {
        if (!$this->importJob || $this->progress <= 0) {
            return null;
        }

        $elapsedTime = now()->diffInSeconds($this->importJob->started_at ?? now());
        $estimatedTotal = ($elapsedTime / $this->progress) * 100;
        $remaining = max(0, $estimatedTotal - $elapsedTime);

        if ($remaining < 60) {
            return 'Less than 1 minute';
        } elseif ($remaining < 3600) {
            return ceil($remaining / 60) . ' minutes';
        } else {
            return ceil($remaining / 3600) . ' hours';
        }
    }

    public function render()
    {
        return view('livewire.import-progress');
    }
}
