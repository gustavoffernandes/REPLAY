<?php

use Illuminate\Support\Facades\Schedule;

// Mantém as reservas de turmas e clientes fixos geradas sempre até 12 semanas à frente.
// Requer o agendador rodando: `php artisan schedule:work` (dev) ou cron `schedule:run` (produção).
Schedule::command('schedules:extend')->dailyAt('03:00');

// Libera horários de reservas online cujo sinal não foi pago a tempo.
Schedule::command('bookings:expire')->everyMinute();
