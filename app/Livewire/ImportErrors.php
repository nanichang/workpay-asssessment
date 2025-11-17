<?php

namespace App\Livewire;

use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\On;
use App\Http\Controllers\EmployeeImportController;

class ImportErrors extends Component
{
    use WithPagination;

    public $importJobId;
    public $errors = [];
    public $errorSummary = [];
    public $paginationData = [];
    public $selectedErrorType = '';
    public $searchTerm = '';
    public $perPage = 10;
    public $sortBy = 'row_number';
    public $sortDirection = 'asc';

    protected $queryString = [
        'selectedErrorType' => ['except' => ''],
        'searchTerm' => ['except' => ''],
        'sortBy' => ['except' => 'row_number'],
        'sortDirection' => ['except' => 'asc'],
    ];

    public function mount($importJobId = null)
    {
        if ($importJobId) {
            $this->importJobId = $importJobId;
            $this->loadErrors();
        }
    }

    #[On('fileUploaded')]
    public function startTracking($importJobId)
    {
        $this->importJobId = $importJobId;
        $this->loadErrors();
    }

    public function loadErrors()
    {
        if (!$this->importJobId) {
            return;
        }

        try {
            // Build query parameters
            $params = [
                'page' => $this->getPage(),
                'per_page' => $this->perPage,
                'sort_by' => $this->sortBy,
                'sort_direction' => $this->sortDirection,
            ];

            if ($this->selectedErrorType) {
                $params['error_type'] = $this->selectedErrorType;
            }

            if ($this->searchTerm) {
                $params['search'] = $this->searchTerm;
            }

            // Create a request with the parameters
            $request = new \Illuminate\Http\Request($params);
            
            // Load errors via controller
            $controller = app(EmployeeImportController::class);
            $response = $controller->getErrors($request, $this->importJobId);
            
            if ($response->getStatusCode() === 200) {
                $responseData = $response->getData(true);
                $data = $responseData['data'] ?? $responseData;
                $this->errors = $data['errors'] ?? [];
                $this->errorSummary = $data['summary'] ?? [];
                $this->paginationData = $data['pagination'] ?? [];
                
                // Update pagination info
                $this->setPage($this->paginationData['current_page'] ?? 1);
            }
        } catch (\Exception $e) {
            // Handle API errors gracefully
            $this->errors = [];
            $this->errorSummary = [];
        }
    }

    public function updatedSelectedErrorType()
    {
        $this->resetPage();
        $this->loadErrors();
    }

    public function updatedSearchTerm()
    {
        $this->resetPage();
        $this->loadErrors();
    }

    public function updatedPerPage()
    {
        $this->resetPage();
        $this->loadErrors();
    }

    public function sortBy($field)
    {
        if ($this->sortBy === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $field;
            $this->sortDirection = 'asc';
        }
        
        $this->resetPage();
        $this->loadErrors();
    }

    public function clearFilters()
    {
        $this->reset(['selectedErrorType', 'searchTerm']);
        $this->resetPage();
        $this->loadErrors();
    }

    public function refreshErrors()
    {
        $this->loadErrors();
    }

    public function getErrorTypeColorProperty()
    {
        return [
            'validation' => 'red',
            'duplicate' => 'yellow',
            'format' => 'orange',
            'business_rule' => 'purple',
            'system' => 'gray',
        ];
    }

    public function getErrorTypeOptions()
    {
        return [
            '' => 'All Error Types',
            'validation' => 'Validation Errors',
            'duplicate' => 'Duplicate Records',
            'format' => 'Format Errors',
            'business_rule' => 'Business Rule Violations',
            'system' => 'System Errors',
        ];
    }

    public function render()
    {
        return view('livewire.import-errors');
    }
}
