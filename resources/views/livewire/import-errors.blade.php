<div class="max-w-6xl mx-auto p-6">
    @if ($importJobId)
        <div class="bg-white rounded-lg shadow-md">
            <!-- Header -->
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <h2 class="text-2xl font-bold text-gray-900">Import Errors</h2>
                    <button 
                        wire:click="refreshErrors"
                        class="inline-flex items-center px-3 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
                    >
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                        </svg>
                        Refresh
                    </button>
                </div>
            </div>

            <!-- Error Summary -->
            @if (!empty($errorSummary))
                <div class="px-6 py-4 bg-gray-50 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900 mb-3">Error Summary</h3>
                    <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
                        @foreach ($errorSummary as $type => $count)
                            <div class="text-center p-3 bg-white rounded-lg border">
                                <div class="text-xl font-bold text-{{ $this->errorTypeColor[$type] ?? 'gray' }}-600">
                                    {{ number_format($count) }}
                                </div>
                                <div class="text-xs text-gray-600 capitalize">
                                    {{ str_replace('_', ' ', $type) }}
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <!-- Filters -->
            <div class="px-6 py-4 bg-gray-50 border-b border-gray-200">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <!-- Error Type Filter -->
                    <div>
                        <label for="error-type" class="block text-sm font-medium text-gray-700 mb-1">Error Type</label>
                        <select 
                            wire:model.live="selectedErrorType"
                            id="error-type"
                            class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                        >
                            @foreach ($this->getErrorTypeOptions() as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Search -->
                    <div>
                        <label for="search" class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                        <input 
                            wire:model.live.debounce.300ms="searchTerm"
                            type="text" 
                            id="search"
                            placeholder="Search error messages..."
                            class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                        >
                    </div>

                    <!-- Per Page -->
                    <div>
                        <label for="per-page" class="block text-sm font-medium text-gray-700 mb-1">Per Page</label>
                        <select 
                            wire:model.live="perPage"
                            id="per-page"
                            class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                        >
                            <option value="10">10</option>
                            <option value="25">25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                    </div>

                    <!-- Clear Filters -->
                    <div class="flex items-end">
                        <button 
                            wire:click="clearFilters"
                            class="w-full px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
                        >
                            Clear Filters
                        </button>
                    </div>
                </div>
            </div>

            <!-- Errors Table -->
            <div class="overflow-x-auto">
                @if (!empty($errors))
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th 
                                    wire:click="sortBy('row_number')"
                                    class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100"
                                >
                                    <div class="flex items-center space-x-1">
                                        <span>Row #</span>
                                        @if ($sortBy === 'row_number')
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                @if ($sortDirection === 'asc')
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/>
                                                @else
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                                @endif
                                            </svg>
                                        @endif
                                    </div>
                                </th>
                                <th 
                                    wire:click="sortBy('error_type')"
                                    class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100"
                                >
                                    <div class="flex items-center space-x-1">
                                        <span>Type</span>
                                        @if ($sortBy === 'error_type')
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                @if ($sortDirection === 'asc')
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/>
                                                @else
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                                @endif
                                            </svg>
                                        @endif
                                    </div>
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Error Message
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Row Data
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @foreach ($errors as $error)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                        {{ $error['row_number'] }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-{{ $this->errorTypeColor[$error['error_type']] ?? 'gray' }}-100 text-{{ $this->errorTypeColor[$error['error_type']] ?? 'gray' }}-800">
                                            {{ ucfirst(str_replace('_', ' ', $error['error_type'])) }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-900">
                                        <div class="max-w-xs truncate" title="{{ $error['error_message'] }}">
                                            {{ $error['error_message'] }}
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-500">
                                        @if (!empty($error['row_data']))
                                            <div class="max-w-md">
                                                <details class="cursor-pointer">
                                                    <summary class="text-blue-600 hover:text-blue-800">View Data</summary>
                                                    <div class="mt-2 p-2 bg-gray-100 rounded text-xs font-mono">
                                                        @foreach ($error['row_data'] as $key => $value)
                                                            <div><strong>{{ $key }}:</strong> {{ $value }}</div>
                                                        @endforeach
                                                    </div>
                                                </details>
                                            </div>
                                        @else
                                            <span class="text-gray-400">No data</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    <!-- Pagination -->
                    @if (!empty($paginationData) && ($paginationData['total'] ?? 0) > ($paginationData['per_page'] ?? 10))
                        <div class="px-6 py-4 border-t border-gray-200">
                            <div class="flex items-center justify-between">
                                <div class="text-sm text-gray-700">
                                    Showing {{ $paginationData['from'] ?? 1 }} to {{ $paginationData['to'] ?? count($errors) }} of {{ $paginationData['total'] ?? count($errors) }} results
                                </div>
                                <div class="flex space-x-2">
                                    @if (($paginationData['current_page'] ?? 1) > 1)
                                        <button 
                                            wire:click="previousPage"
                                            class="px-3 py-2 text-sm font-medium text-gray-500 bg-white border border-gray-300 rounded-md hover:bg-gray-50"
                                        >
                                            Previous
                                        </button>
                                    @endif
                                    
                                    @if (($paginationData['current_page'] ?? 1) < ($paginationData['last_page'] ?? 1))
                                        <button 
                                            wire:click="nextPage"
                                            class="px-3 py-2 text-sm font-medium text-gray-500 bg-white border border-gray-300 rounded-md hover:bg-gray-50"
                                        >
                                            Next
                                        </button>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endif

                @else
                    <!-- No Errors -->
                    <div class="text-center py-12">
                        <div class="mx-auto w-16 h-16 text-green-400 mb-4">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                        </div>
                        <h3 class="text-lg font-medium text-gray-900 mb-2">No Errors Found</h3>
                        <p class="text-sm text-gray-500">
                            @if ($selectedErrorType || $searchTerm)
                                No errors match your current filters.
                            @else
                                This import completed without any errors!
                            @endif
                        </p>
                    </div>
                @endif
            </div>
        </div>
    @else
        <!-- No Import Job -->
        <div class="bg-white rounded-lg shadow-md p-6 text-center">
            <div class="mx-auto w-16 h-16 text-gray-400 mb-4">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <h3 class="text-lg font-medium text-gray-900 mb-2">No Import Selected</h3>
            <p class="text-sm text-gray-500">Select an import job to view error details.</p>
        </div>
    @endif
</div>
