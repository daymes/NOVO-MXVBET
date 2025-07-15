<?php


use App\Http\Controllers\Gateway\MXVPAYController;
use Illuminate\Support\Facades\Route;

Route::prefix('mxv')
    ->group(function ()
    {
        Route::post('callback', [MXVPAYController::class, 'callbackMethod']);
        Route::post('payment', [MXVPAYController::class, 'callbackMethodPayment']);

        Route::middleware(['admin.filament'])
            ->group(function ()
            {
                Route::get('withdrawal/{id}/{action}', [MXVPAYController::class, 'withdrawalFromModal'])->name('mxvpay.withdrawal');
                Route::get('cancelwithdrawal/{id}/{action}', [MXVPAYController::class, 'cancelWithdrawalFromModal'])->name('mxvpay.cancelwithdrawal');
            });
    });
