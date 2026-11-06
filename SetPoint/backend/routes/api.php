<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\AvailabilityController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\ClassGroupController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CourtController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PublicBookingController;
use App\Http\Controllers\ReplayController;
use App\Http\Controllers\ReplayIngestController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:forgot-password');
Route::post('auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:reset-password');

// Agendamento online para clientes avulsos (sem login).
Route::prefix('public')->group(function () {
    Route::middleware('throttle:public-read')->group(function () {
        Route::get('companies/{slug}', [PublicBookingController::class, 'company']);
        Route::get('branches/{branch}/availability', [PublicBookingController::class, 'availability']);
        Route::get('bookings/{code}', [PublicBookingController::class, 'show']);
    });
    Route::post('branches/{branch}/bookings', [PublicBookingController::class, 'store'])->middleware('throttle:public-book');
    Route::post('bookings/{code}/simulate-payment', [PublicBookingController::class, 'simulatePayment'])->middleware('throttle:public-read');
});

// Replays fora do painel: o serviço de captura envia o vídeo (token da câmera no header)
// e o navegador toca/baixa o vídeo por link assinado e temporário (gerado na listagem).
Route::post('replays/upload', [ReplayIngestController::class, 'upload'])->middleware('throttle:replay-upload');
Route::get('replays/{replay}/video', [ReplayIngestController::class, 'video'])
    ->name('replays.video')->middleware(['signed:relative', 'throttle:replay-video']);

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/password', [AuthController::class, 'changePassword'])->middleware('throttle:sensitive');
    Route::post('auth/logout', [AuthController::class, 'logout']);

    // Tudo abaixo roda dentro do escopo de empresa/filial do usuário (header X-Branch-Id opcional).
    // Cada rota exige uma permissão por ação (ver, criar, editar...), definida por papel em Administração → Papéis.
    Route::middleware('tenant')->group(function () {
        Route::get('dashboard', DashboardController::class)->middleware('permission:dashboard.view');
        Route::get('availability', AvailabilityController::class)->middleware('permission:bookings.view');

        // Quadras
        Route::get('courts', [CourtController::class, 'index'])->middleware('permission:courts.view');
        Route::post('courts', [CourtController::class, 'store'])->middleware('permission:courts.create');
        Route::put('courts/{court}', [CourtController::class, 'update'])->middleware('permission:courts.update');
        Route::post('courts/{court}/replay-token', [ReplayController::class, 'rotateToken'])->middleware('permission:courts.update');

        // Replays (câmera por quadra; "salvar" é a botoeira virtual)
        Route::get('replays', [ReplayController::class, 'index'])->middleware('permission:replays.view');
        Route::get('replay-cameras', [ReplayController::class, 'cameras'])->middleware('permission:replays.view');
        Route::delete('replays/{replay}', [ReplayController::class, 'destroy'])->middleware('permission:replays.delete');
        Route::get('courts/{court}/replay-status', [ReplayController::class, 'status'])->middleware('permission:replays.view');
        Route::post('courts/{court}/replays', [ReplayController::class, 'trigger'])->middleware('permission:replays.create');

        // Agenda e reservas (a permissão de confirmar/cancelar é checada pelo status pedido)
        Route::get('bookings', [BookingController::class, 'index'])->middleware('permission:bookings.view');
        Route::get('bookings/{booking}', [BookingController::class, 'show'])->middleware('permission:bookings.view');
        Route::post('bookings', [BookingController::class, 'store'])->middleware('permission:bookings.create');
        Route::patch('bookings/{booking}/status', [BookingController::class, 'updateStatus'])->middleware('permission:bookings.view');

        // Clientes
        Route::get('customers', [CustomerController::class, 'index'])->middleware('permission:customers.view');
        Route::get('customers/{customer}', [CustomerController::class, 'show'])->middleware('permission:customers.view');
        Route::post('customers', [CustomerController::class, 'store'])->middleware('permission:customers.create');
        Route::put('customers/{customer}', [CustomerController::class, 'update'])->middleware('permission:customers.update');
        Route::post('customers/{customer}/schedules', [CustomerController::class, 'addSchedule'])->middleware('permission:customers.recurring');

        // Turmas
        Route::get('classes', [ClassGroupController::class, 'index'])->middleware('permission:classes.view');
        Route::get('classes/{group}', [ClassGroupController::class, 'show'])->middleware('permission:classes.view');
        Route::post('classes', [ClassGroupController::class, 'store'])->middleware('permission:classes.create');
        Route::put('classes/{group}', [ClassGroupController::class, 'update'])->middleware('permission:classes.update');
        Route::delete('classes/{group}', [ClassGroupController::class, 'destroy'])->middleware('permission:classes.delete');
        Route::post('classes/{group}/students', [ClassGroupController::class, 'addStudent'])->middleware('permission:classes.students');
        Route::delete('classes/{group}/students/{customer}', [ClassGroupController::class, 'removeStudent'])->middleware('permission:classes.students');
        Route::post('classes/{group}/schedules', [ClassGroupController::class, 'addSchedule'])->middleware('permission:classes.schedules');

        // Remover horário recorrente: a permissão depende do tipo (turma ou cliente fixo), checada no controller
        Route::delete('schedules/{schedule}', [ScheduleController::class, 'destroy']);

        // Administração
        Route::get('branches', [BranchController::class, 'index'])->middleware('permission:branches.view');
        Route::post('branches', [BranchController::class, 'store'])->middleware('permission:branches.create');
        Route::put('branches/{branch}', [BranchController::class, 'update'])->middleware('permission:branches.update');

        Route::get('users', [UserController::class, 'index'])->middleware('permission:users.view');
        Route::post('users', [UserController::class, 'store'])->middleware('permission:users.view'); // users.create checado no controller
        Route::put('users/{user}', [UserController::class, 'update'])->middleware('permission:users.view'); // users.update checado no controller

        Route::get('roles/assignable', [RoleController::class, 'assignable'])->middleware('permission:users.view');

        // Somente administrador da plataforma
        Route::get('companies', [CompanyController::class, 'index'])->middleware('permission:companies.view');
        Route::post('companies', [CompanyController::class, 'store'])->middleware('permission:companies.create');
        Route::put('companies/{company}', [CompanyController::class, 'update'])->middleware('permission:companies.update');

        Route::get('roles', [RoleController::class, 'index'])->middleware('permission:roles.view');
        Route::post('roles', [RoleController::class, 'store'])->middleware('permission:roles.manage');
        Route::put('roles/{role}', [RoleController::class, 'update'])->middleware('permission:roles.manage');
        Route::delete('roles/{role}', [RoleController::class, 'destroy'])->middleware('permission:roles.manage');
    });
});
