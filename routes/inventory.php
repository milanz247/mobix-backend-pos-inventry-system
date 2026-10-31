<?php

use App\Http\Controllers\Inventory\DamagedStockController;
use App\Http\Controllers\Inventory\InventoryOverviewController;
use App\Http\Controllers\Inventory\InventoryOperationReportController;
use App\Http\Controllers\Inventory\LocationInventoryController;
use App\Http\Controllers\Inventory\OpeningStockController;
use App\Http\Controllers\Inventory\ReorderLevelController;
use App\Http\Controllers\Inventory\SerializedDeviceController;
use App\Http\Controllers\Inventory\StockAdjustmentController;
use App\Http\Controllers\Inventory\StockMovementController;
use App\Http\Controllers\Inventory\StockOverviewReportController;
use App\Http\Controllers\Inventory\StockReceivingController;
use App\Http\Controllers\Inventory\StockTransferController;
use Illuminate\Support\Facades\Route;

Route::prefix('inventory')->name('inventory.')->middleware('auth')->group(function (): void {
    Route::get('reorder', [ReorderLevelController::class, 'index'])->middleware('can:inventory.view')->name('reorder.index');
    Route::put('reorder', [ReorderLevelController::class, 'update'])->middleware(['can:inventory.view', 'can:inventory.adjust'])->name('reorder.update');
    Route::get('damaged', [DamagedStockController::class, 'index'])->middleware('can:damaged-stock.view')->name('damaged.index');
    Route::post('damaged', [DamagedStockController::class, 'store'])->middleware(['can:damaged-stock.view', 'can:damaged-stock.create'])->name('damaged.store');
    Route::post('damaged/{entry}/resolve', [DamagedStockController::class, 'resolve'])->middleware('can:damaged-stock.view')->name('damaged.resolve');
    Route::get('/', InventoryOverviewController::class)->middleware('can:inventory.view')->name('index');
    Route::get('locations', LocationInventoryController::class)->middleware('can:inventory.view')->name('locations.index');

    Route::get('receiving', [StockReceivingController::class, 'create'])->middleware('can:inventory.receive')->name('receiving.create');
    Route::post('receiving', [StockReceivingController::class, 'store'])->middleware('can:inventory.receive')->name('receiving.store');
    Route::get('opening-stock', [OpeningStockController::class, 'create'])->middleware('can:inventory.opening')->name('opening.create');
    Route::post('opening-stock', [OpeningStockController::class, 'store'])->middleware('can:inventory.opening')->name('opening.store');
    Route::get('adjustments', [StockAdjustmentController::class, 'create'])->middleware('can:inventory.adjust')->name('adjustments.create');
    Route::post('adjustments', [StockAdjustmentController::class, 'store'])->middleware('can:inventory.adjust')->name('adjustments.store');
    Route::get('transfers', [StockTransferController::class, 'create'])->middleware('can:inventory.transfer')->name('transfers.create');
    Route::post('transfers', [StockTransferController::class, 'store'])->middleware('can:inventory.transfer')->name('transfers.store');

    Route::get('devices', [SerializedDeviceController::class, 'index'])->middleware('can:serialized-devices.view')->name('devices.index');
    Route::patch('devices/{serializedDevice}/status', [SerializedDeviceController::class, 'updateStatus'])->middleware('can:serialized-devices.manage')->name('devices.status.update');
    Route::get('movements', StockMovementController::class)->middleware('can:stock-movements.view')->name('movements.index');
    Route::get('reports/stock-overview', StockOverviewReportController::class)->middleware('can:inventory.view')->name('reports.stock-overview');
    Route::get('reports/stock-overview/excel', [StockOverviewReportController::class, 'excel'])->middleware('can:inventory.view')->name('reports.stock-overview.excel');
    Route::get('reports/stock-overview/pdf', [StockOverviewReportController::class, 'pdf'])->middleware('can:inventory.view')->name('reports.stock-overview.pdf');
    Route::get('reports/{type}', InventoryOperationReportController::class)->whereIn('type', ['adjustments', 'transfers'])->middleware('can:inventory.view')->name('reports.operations');
});
