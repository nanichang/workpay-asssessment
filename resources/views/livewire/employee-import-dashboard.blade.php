<div class="min-h-screen bg-gray-100">
    <!-- Header -->
    <div class="bg-white shadow">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center py-6">
                <div>
                    <h1 class="text-3xl font-bold text-gray-900">Employee Import System</h1>
                    <p class="mt-1 text-sm text-gray-500">Upload and manage employee data imports</p>
                </div>
                @if ($currentImportJobId)
                    <div class="text-sm text-gray-600">
                        Current Job: <span class="font-mono bg-gray-100 px-2 py-1 rounded">{{ $currentImportJobId }}</span>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <div class="bg-white border-b border-gray-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <nav class="-mb-px flex space-x-8">
                <button 
                    wire:click="setActiveTab('upload')"
                    class="py-4 px-1 border-b-2 font-medium text-sm {{ $activeTab === 'upload' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}"
                >
                    <div class="flex items-center">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                        </svg>
                        Upload File
                    </div>
                </button>
                
                <button 
                    wire:click="setActiveTab('progress')"
                    class="py-4 px-1 border-b-2 font-medium text-sm {{ $activeTab === 'progress' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}"
                    @if (!$currentImportJobId) disabled @endif
                >
                    <div class="flex items-center">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                        </svg>
                        Progress
                    </div>
                </button>
                
                <button 
                    wire:click="setActiveTab('errors')"
                    class="py-4 px-1 border-b-2 font-medium text-sm {{ $activeTab === 'errors' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}"
                    @if (!$currentImportJobId) disabled @endif
                >
                    <div class="flex items-center">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        Errors
                    </div>
                </button>
            </nav>
        </div>
    </div>

    <!-- Content -->
    <div class="max-w-7xl mx-auto py-6 sm:px-6 lg:px-8">
        @if ($activeTab === 'upload')
            <livewire:employee-file-upload />
        @elseif ($activeTab === 'progress')
            <livewire:import-progress :import-job-id="$currentImportJobId" />
        @elseif ($activeTab === 'errors')
            <livewire:import-errors :import-job-id="$currentImportJobId" />
        @endif
    </div>
</div>
