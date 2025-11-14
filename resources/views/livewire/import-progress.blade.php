<div 
    x-data="{ 
        autoRefresh: @entangle('autoRefresh'),
        refreshInterval: @entangle('refreshInterval')
    }"
    x-init="
        setInterval(() => {
            if (autoRefresh) {
                $wire.loadProgress();
            }
        }, refreshInterval);
    "
    class="max-w-4xl mx-auto p-6"
>
    @if ($importJobId)
        <div class="bg-white rounded-lg shadow-md p-6">
            <div class="flex items-center justify-between mb-6">
                <h2 class="text-2xl font-bold text-gray-900">Import Progress</h2>
                <div class="flex items-center space-x-3">
                    <!-- Auto-refresh toggle -->
                    <button 
                        wire:click="toggleAutoRefresh"
                        class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium {{ $autoRefresh ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800' }}"
                    >
                        <div class="w-2 h-2 rounded-full {{ $autoRefresh ? 'bg-green-400' : 'bg-gray-400' }} mr-2"></div>
                        {{ $autoRefresh ? 'Auto-refresh ON' : 'Auto-refresh OFF' }}
                    </button>
                    
                    <!-- Manual refresh button -->
                    <button 
                        wire:click="refreshProgress"
                        class="inline-flex items-center px-3 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
                    >
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                        </svg>
                        Refresh
                    </button>
                </div>
            </div>

            @if ($importJob)
                <!-- Job Info -->
                <div class="mb-6 p-4 bg-gray-50 rounded-lg">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <p class="text-sm font-medium text-gray-500">Job ID</p>
                            <p class="text-sm font-mono text-gray-900">{{ $importJobId }}</p>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-500">File</p>
                            <p class="text-sm text-gray-900">{{ $importJob->filename ?? 'Unknown' }}</p>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-500">Status</p>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-{{ $this->statusColor }}-100 text-{{ $this->statusColor }}-800">
                                {{ $this->statusText }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Progress Bar -->
                <div class="mb-6">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-sm font-medium text-gray-700">Progress</span>
                        <span class="text-sm font-medium text-gray-900">{{ number_format($progress, 1) }}%</span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-3">
                        <div 
                            class="bg-{{ $this->statusColor }}-600 h-3 rounded-full transition-all duration-300 ease-in-out"
                            style="width: {{ $progress }}%"
                        ></div>
                    </div>
                </div>

                <!-- Statistics -->
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                    <div class="text-center p-4 bg-blue-50 rounded-lg">
                        <div class="text-2xl font-bold text-blue-600">{{ number_format($importJob->total_rows ?? 0) }}</div>
                        <div class="text-sm text-blue-600">Total Rows</div>
                    </div>
                    <div class="text-center p-4 bg-green-50 rounded-lg">
                        <div class="text-2xl font-bold text-green-600">{{ number_format($importJob->successful_rows ?? 0) }}</div>
                        <div class="text-sm text-green-600">Successful</div>
                    </div>
                    <div class="text-center p-4 bg-red-50 rounded-lg">
                        <div class="text-2xl font-bold text-red-600">{{ number_format($importJob->error_rows ?? 0) }}</div>
                        <div class="text-sm text-red-600">Errors</div>
                    </div>
                    <div class="text-center p-4 bg-gray-50 rounded-lg">
                        <div class="text-2xl font-bold text-gray-600">{{ number_format($importJob->processed_rows ?? 0) }}</div>
                        <div class="text-sm text-gray-600">Processed</div>
                    </div>
                </div>

                <!-- Time Information -->
                @if ($importJob->started_at)
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                        <div class="p-4 bg-gray-50 rounded-lg">
                            <p class="text-sm font-medium text-gray-500 mb-1">Started At</p>
                            <p class="text-sm text-gray-900">{{ \Carbon\Carbon::parse($importJob->started_at)->format('M j, Y g:i A') }}</p>
                        </div>
                        @if ($this->estimatedTime && !$isComplete)
                            <div class="p-4 bg-blue-50 rounded-lg">
                                <p class="text-sm font-medium text-blue-600 mb-1">Estimated Time Remaining</p>
                                <p class="text-sm text-blue-900">{{ $this->estimatedTime }}</p>
                            </div>
                        @endif
                        @if ($importJob->completed_at)
                            <div class="p-4 bg-green-50 rounded-lg">
                                <p class="text-sm font-medium text-green-600 mb-1">Completed At</p>
                                <p class="text-sm text-green-900">{{ \Carbon\Carbon::parse($importJob->completed_at)->format('M j, Y g:i A') }}</p>
                            </div>
                        @endif
                    </div>
                @endif

                <!-- Completion Message -->
                @if ($isComplete)
                    <div class="p-4 rounded-lg {{ $importJob->status === 'completed' ? 'bg-green-50 border border-green-200' : 'bg-red-50 border border-red-200' }}">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                @if ($importJob->status === 'completed')
                                    <svg class="w-5 h-5 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                @else
                                    <svg class="w-5 h-5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                @endif
                            </div>
                            <div class="ml-3">
                                <h3 class="text-sm font-medium {{ $importJob->status === 'completed' ? 'text-green-800' : 'text-red-800' }}">
                                    Import {{ $importJob->status === 'completed' ? 'Completed Successfully' : 'Failed' }}
                                </h3>
                                <div class="mt-2 text-sm {{ $importJob->status === 'completed' ? 'text-green-700' : 'text-red-700' }}">
                                    @if ($importJob->status === 'completed')
                                        <p>Successfully imported {{ number_format($importJob->successful_rows ?? 0) }} employees.</p>
                                        @if (($importJob->error_rows ?? 0) > 0)
                                            <p class="mt-1">{{ number_format($importJob->error_rows) }} rows had errors and were skipped.</p>
                                        @endif
                                    @else
                                        <p>The import process encountered an error and could not be completed.</p>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                @endif

            @else
                <!-- Loading State -->
                <div class="text-center py-8">
                    <svg class="animate-spin mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <p class="mt-4 text-sm text-gray-500">Loading import progress...</p>
                </div>
            @endif
        </div>
    @else
        <!-- No Import Job -->
        <div class="bg-white rounded-lg shadow-md p-6 text-center">
            <div class="mx-auto w-16 h-16 text-gray-400 mb-4">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                </svg>
            </div>
            <h3 class="text-lg font-medium text-gray-900 mb-2">No Import in Progress</h3>
            <p class="text-sm text-gray-500">Upload a file to start tracking import progress.</p>
        </div>
    @endif
</div>
