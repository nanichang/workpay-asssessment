<?php

namespace App\Livewire;

use Livewire\Component;
use Livewire\Attributes\On;
use App\Models\ImportJob;
use Illuminate\Support\Facades\Http;

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
            // Load progress via API
            $response = Http::get("/api/employee-import/{$this->importJobId}/progress");
            
            if ($response->successful()) {
                $data = $response->json();
                $this->importJob = (object) $data;
                $this->progress = $this->importJob->progress_percentage ?? 0;
                $this->isComplete = in_array($this->importJob->status, ['completed', 'failed']);
                
                if ($this->isComplete) {
                    $this->autoRefresh = false;
                }
            }
        } catch (\Exception $e) {
            // Handle API errors gracefully
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
        if (!$this->importJob) {
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
        if (!$this->importJob) {
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
