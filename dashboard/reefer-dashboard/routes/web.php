<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

// Halaman utama dashboard
Route::get('/', [DashboardController::class, 'index'])
    ->name('dashboard');

Route::prefix('dashboard')->group(function () {

    // Monitoring suhu
    Route::get('latest', [DashboardController::class, 'latest'])
        ->name('dashboard.latest');

    Route::get('history', [DashboardController::class, 'history'])
        ->name('dashboard.history');

    Route::get('events', [DashboardController::class, 'events'])
        ->name('dashboard.events');

    // Kontrol perangkat
    Route::post('control', [DashboardController::class, 'control'])
        ->name('dashboard.control');

    // Scan lama (tetap dipertahankan)
    Route::post('scan', [DashboardController::class, 'scan'])
        ->name('dashboard.scan');

    // Data barang lama (tetap dipertahankan)
    Route::post('items', [DashboardController::class, 'saveItem'])
        ->name('dashboard.items');

    // Validasi UID barcode box
    Route::get('boxes/{uid}/validate', [DashboardController::class, 'validateBox'])
        ->name('dashboard.boxes.validate');

    // Transaksi pengiriman
    Route::post('shipments', [DashboardController::class, 'createShipment'])
        ->name('dashboard.shipments.store');

    // Riwayat seluruh transaksi pengiriman
    Route::get('shipments', [DashboardController::class, 'listShipments'])
        ->name('dashboard.shipments.index');

    // Ambil transaksi terakhir
    // Harus berada sebelum route shipments/{id}
    Route::get('shipments/latest', [DashboardController::class, 'latestShipment'])
        ->name('dashboard.shipments.latest');

    // Detail transaksi berdasarkan ID
    Route::get('shipments/{id}', [DashboardController::class, 'getShipment'])
        ->name('dashboard.shipments.show');

    // Edit transaksi (maksimal satu kali, dibatasi backend Go)
    Route::put('shipments/{id}', [DashboardController::class, 'updateShipment'])
        ->name('dashboard.shipments.update');

    
    // Pengelolaan data box
    Route::get('boxes', [DashboardController::class, 'listBoxes'])
        ->name('dashboard.boxes.index');

    Route::post('boxes/bulk', [DashboardController::class, 'createBoxesBulk'])
        ->name('dashboard.boxes.bulk');

    Route::put('boxes/{uid}', [DashboardController::class, 'updateBox'])
        ->name('dashboard.boxes.update');

});
