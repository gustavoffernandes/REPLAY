<?php

use App\Http\Controllers\ReplayController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ReplayController::class, 'index'])->name('replays.index');
Route::get('/replays/lista', [ReplayController::class, 'lista'])->name('replays.lista');
Route::get('/captura/status', [ReplayController::class, 'status'])->name('captura.status');

// Botoeira virtual (botão "Salvar vídeo" da página).
Route::post('/replays/disparar', [ReplayController::class, 'disparar'])->name('replays.disparar');
