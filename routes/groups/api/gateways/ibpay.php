<?php
use App\Http\Controllers\Gateway\iBPayController;
use Illuminate\Support\Facades\Route;


Route::prefix('ibpay')
    ->group(function ()
    {
        Route::post('qrcode-pix', [iBPayController::class, 'getQRCodePix']);
        Route::post('consult-status-transaction', [iBPayController::class, 'consultStatusTransactionPix']);
    });




