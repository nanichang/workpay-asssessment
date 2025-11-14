<?php

use Illuminate\Support\Facades\Route;
use App\Livewire\EmployeeImportDashboard;

Route::get('/', EmployeeImportDashboard::class)->name('dashboard');

Route::get('/employee-import', EmployeeImportDashboard::class)->name('employee-import.dashboard');
