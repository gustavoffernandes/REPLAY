<?php

namespace App\Http\Controllers;

use App\Models\Schedule;
use App\Services\ScheduleService;
use Illuminate\Http\Request;

class ScheduleController extends Controller
{
    /** Remove o horário recorrente e cancela as ocorrências futuras. */
    public function destroy(Request $request, Schedule $schedule, ScheduleService $service)
    {
        $needed = $schedule->class_group_id ? 'classes.schedules' : 'customers.recurring';
        abort_unless($request->user()->hasPermission($needed), 403, 'Você não tem permissão para esta ação');

        return ['cancelled' => $service->deactivate($schedule)];
    }
}
