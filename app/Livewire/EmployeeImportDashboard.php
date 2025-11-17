<?php

namespace App\Livewire;

use Livewire\Component;
use Livewire\Attributes\On;

class EmployeeImportDashboard extends Component
{
    public $currentImportJobId = null;
    public $activeTab = 'upload';

    #[On('fileUploaded')]
    public function handleFileUploaded($importJobId)
    {
        $this->currentImportJobId = $importJobId;
        $this->activeTab = 'progress';
    }

    public function setActiveTab($tab)
    {
        $this->activeTab = $tab;
    }

    public function render()
    {
        return view('livewire.employee-import-dashboard');
    }
}
