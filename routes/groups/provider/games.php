<?php

use App\Http\Controllers\Api\Games\GameController;
use Illuminate\Support\Facades\Route;

// VOXELGATOR
Route::post('777pro', [GameController::class, 'webhookVeniXMethod']);
