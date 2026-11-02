<?php

use App\Http\Controllers\Storefront\StaffBankTransferController;
use App\Http\Controllers\Storefront\StaffWebOrderController;
use App\Http\Controllers\Storefront\StaffWebOrderHandoverController;
use App\Http\Controllers\Storefront\StaffWebOrderPreparationController;
use App\PermissionName;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'can:'.PermissionName::StorefrontViewOrders->value])
    ->prefix('storefront/orders')->name('storefront-orders.')->group(function (): void {
        Route::get('/', [StaffWebOrderController::class, 'index'])->name('index');
        Route::get('{reference}', [StaffWebOrderController::class, 'show'])->name('show');
        Route::post('{reference}/calls', [StaffWebOrderController::class, 'recordCall'])
            ->middleware(['can:'.PermissionName::StorefrontConfirmOrders->value, 'throttle:20,1'])->name('calls.store');
        Route::post('{reference}/confirm-cod', [StaffWebOrderController::class, 'confirm'])
            ->middleware(['can:'.PermissionName::StorefrontConfirmOrders->value, 'throttle:10,1'])->name('confirm-cod');
        Route::post('{reference}/confirm-bank', [StaffWebOrderController::class, 'confirmBank'])
            ->middleware(['can:'.PermissionName::StorefrontConfirmBankOrders->value, 'throttle:10,1'])->name('confirm-bank');
        Route::post('{reference}/cancel-declined', [StaffWebOrderController::class, 'cancelDeclined'])
            ->middleware(['can:'.PermissionName::StorefrontCancelOrders->value, 'throttle:10,1'])->name('cancel-declined');
        Route::post('{reference}/preparation/start', [StaffWebOrderPreparationController::class, 'start'])
            ->middleware(['can:'.PermissionName::StorefrontPrepareOrders->value, 'throttle:10,1'])->name('preparation.start');
        Route::post('{reference}/preparation/checks/{reservation}', [StaffWebOrderPreparationController::class, 'check'])
            ->middleware(['can:'.PermissionName::StorefrontVerifyPicks->value, 'throttle:30,1'])->name('preparation.check');
        Route::post('{reference}/preparation/ready', [StaffWebOrderPreparationController::class, 'ready'])
            ->middleware(['can:'.PermissionName::StorefrontMarkReady->value, 'throttle:10,1'])->name('preparation.ready');
        Route::post('{reference}/handover/pickup', [StaffWebOrderHandoverController::class, 'pickup'])
            ->middleware(['can:'.PermissionName::StorefrontHandoverOrders->value, 'throttle:10,1'])->name('handover.pickup');
        Route::get('{reference}/bank-proofs/{attempt}', [StaffBankTransferController::class, 'download'])
            ->middleware(['can:'.PermissionName::StorefrontViewPaymentProofs->value, 'throttle:30,1'])->name('bank-proofs.download');
        Route::post('{reference}/bank-proofs/{attempt}/review', [StaffBankTransferController::class, 'review'])
            ->middleware(['can:'.PermissionName::StorefrontVerifyPayments->value, 'throttle:10,1'])->name('bank-proofs.review');
    });
