<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

// Tambahkan middleware 'auth' di sini kalau dashboard butuh login.
Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

Route::prefix('dashboard')->group(function () {
    Route::get('latest', [DashboardController::class, 'latest'])->name('dashboard.latest');
    Route::get('history', [DashboardController::class, 'history'])->name('dashboard.history');
    Route::get('events', [DashboardController::class, 'events'])->name('dashboard.events');
    Route::post('control', [DashboardController::class, 'control'])->name('dashboard.control');
    Route::post('scan', [DashboardController::class, 'scan'])->name('dashboard.scan');
    Route::post('items', [DashboardController::class, 'saveItem'])->name('dashboard.items');
});