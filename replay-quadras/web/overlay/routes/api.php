<?php

use App\Http\Controllers\Api\ReplayUploadController;
use Illuminate\Support\Facades\Route;

// Recebe os replays enviados pelo serviço Python (Watchdog).
Route::post('/replays', [ReplayUploadController::class, 'store'])->name('api.replays.store');
