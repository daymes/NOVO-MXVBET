<?php


use App\Http\Controllers\Gateway\iBPayController;
use Illuminate\Support\Facades\Route;

Route::prefix('ibpay')
    ->group(function ()
    {
        Route::post('callback', [iBPayController::class, 'callbackMethod']);
        Route::post('payment', [iBPayController::class, 'callbackMethodPayment']);

        Route::middleware(['admin.filament'])
            ->group(function ()
            {
                Route::get('withdrawal/{id}/{action}', [iBPayController::class, 'withdrawalFromModal'])->name('ibpay.withdrawal');
                Route::get('cancelwithdrawal/{id}/{action}', [iBPayController::class, 'cancelWithdrawalFromModal'])->name('ibpay.cancelwithdrawal');
            });
    });
