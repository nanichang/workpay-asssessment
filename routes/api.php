<?php

use App\Http\Controllers\EmployeeImportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Employee Import API Routes
Route::prefix('employee-import')->group(function () {
    Route::post('/upload', [EmployeeImportController::class, 'upload'])->name('employee-import.upload');
    Route::get('/{importId}/progress', [EmployeeImportController::class, 'getProgress'])->name('employee-import.progress');
    Route::get('/{importId}/errors', [EmployeeImportController::class, 'getErrors'])->name('employee-import.errors');
    Route::get('/{importId}/summary', [EmployeeImportController::class, 'getSummary'])->name('employee-import.summary');
});