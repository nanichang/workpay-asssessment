<div class="max-w-2xl mx-auto p-6">
    <div class="bg-white rounded-lg shadow-md p-6">
        <h2 class="text-2xl font-bold text-gray-900 mb-6">Upload Employee Data</h2>
        
        @if (!$uploadComplete)
            <!-- File Upload Area -->
            <div 
                x-data="{ 
                    isDragging: false,
                    handleDrop(e) {
                        console.log('Drop event triggered');
                        this.isDragging = false;
                        const files = e.dataTransfer.files;
                        console.log('Files dropped:', files.length);
                        if (files.length > 0) {
                            console.log('Setting file:', files[0].name);
                            @this.set('file', files[0]);
                        }
                    }
                }"
                @dragover.prevent="isDragging = true"
                @dragleave.prevent="isDragging = false"
                @drop.prevent="handleDrop"
                :class="{ 'border-blue-500 bg-blue-50': isDragging }"
                class="border-2 border-dashed border-gray-300 rounded-lg p-8 text-center transition-colors duration-200 hover:border-gray-400"
            >
                <div class="space-y-4">
                    <!-- Upload Icon -->
                    <div class="mx-auto w-16 h-16 text-gray-400">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                        </svg>
                    </div>
                    
                    <!-- Upload Text -->
                    <div>
                        <p class="text-lg font-medium text-gray-900">
                            Drop your CSV or Excel file here
                        </p>
                        <p class="text-sm text-gray-500 mt-1">
                            or click to browse files
                        </p>
                    </div>
                    
                    <!-- File Input -->
                    <div>
                        <input 
                            type="file" 
                            wire:model="file" 
                            accept=".csv,.xlsx,.xls"
                            class="hidden"
                            id="file-upload"
                            onchange="console.log('File input changed:', this.files[0]?.name)"
                        >
                        <label 
                            for="file-upload" 
                            class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 cursor-pointer"
                        >
                            Choose File
                        </label>
                    </div>
                    
                    <!-- File Requirements -->
                    <div class="text-xs text-gray-500">
                        <p>Supported formats: CSV, Excel (.xlsx, .xls)</p>
                        <p>Maximum file size: 20MB</p>
                        <p>Maximum rows: 50,000</p>
                    </div>
                </div>
            </div>

            <!-- Selected File Info -->
            @if ($file)
                <div class="mt-4 p-4 bg-gray-50 rounded-lg">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-3">
                            <div class="flex-shrink-0">
                                <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-gray-900">{{ $file->getClientOriginalName() }}</p>
                                <p class="text-xs text-gray-500">{{ number_format($file->getSize() / 1024, 1) }} KB</p>
                            </div>
                        </div>
                        <button 
                            wire:click="resetUpload"
                            class="text-red-600 hover:text-red-800 text-sm font-medium"
                        >
                            Remove
                        </button>
                    </div>
                </div>
            @endif

            <!-- Validation Errors -->
            @if ($errorMessage)
                <div class="mt-4 p-4 bg-red-50 border border-red-200 rounded-lg">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <svg class="w-5 h-5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm text-red-800">{{ $errorMessage }}</p>
                        </div>
                    </div>
                </div>
            @endif



            <!-- Upload Button -->
            @if ($file && !$errorMessage)
                <div class="mt-6">
                    <button 
                        wire:click="uploadFile"
                        wire:loading.attr="disabled"
                        class="w-full flex justify-center py-3 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        <span wire:loading.remove wire:target="uploadFile">
                            Start Import
                        </span>
                        <span wire:loading wire:target="uploadFile" class="flex items-center">
                            <svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-white" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            Uploading...
                        </span>
                    </button>
                </div>
            @endif

        @else
            <!-- Upload Success -->
            <div class="text-center py-8">
                <div class="mx-auto w-16 h-16 text-green-500 mb-4">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <h3 class="text-lg font-medium text-gray-900 mb-2">File Uploaded Successfully!</h3>
                <p class="text-sm text-gray-600 mb-4">
                    Import Job ID: <span class="font-mono text-xs bg-gray-100 px-2 py-1 rounded">{{ $importJobId }}</span>
                </p>
                <button 
                    wire:click="resetUpload"
                    class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
                >
                    Upload Another File
                </button>
            </div>
        @endif

        <!-- Loading Indicator -->
        <div wire:loading wire:target="file" class="mt-4">
            <div class="flex items-center justify-center">
                <svg class="animate-spin h-5 w-5 text-blue-600" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span class="ml-2 text-sm text-gray-600">Processing file...</span>
            </div>
        </div>
    </div>
</div>
