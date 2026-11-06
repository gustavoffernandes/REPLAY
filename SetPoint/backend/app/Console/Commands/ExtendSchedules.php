<?php

namespace App\Console\Commands;

use App\Services\ScheduleService;
use Illuminate\Console\Command;

class ExtendSchedules extends Command
{
    protected $signature = 'schedules:extend';

    protected $description = 'Gera as reservas das turmas e clientes fixos para as próximas semanas';

    public function handle(ScheduleService $service): int
    {
        $this->info($service->extendAll() . ' reservas geradas.');

        return self::SUCCESS;
    }
}
