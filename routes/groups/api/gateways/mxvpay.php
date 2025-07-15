<?php
use App\Http\Controllers\Gateway\MXVPAYController;
use Illuminate\Support\Facades\Route;


Route::prefix('mxv')
    ->group(function ()
    {
        Route::post('qrcode-pix', [MXVPAYController::class, 'getQRCodePix']);
        Route::post('consult-status-transaction', [MXVPAYController::class, 'consultStatusTransactionPix']);
    });




