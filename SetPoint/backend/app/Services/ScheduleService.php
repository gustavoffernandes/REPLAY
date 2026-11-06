<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Court;
use App\Models\Schedule;
use Illuminate\Support\Facades\DB;

/** Cria horários recorrentes (turmas e clientes fixos) e materializa as reservas das próximas semanas. */
class ScheduleService
{
    public const WEEKS = 12;

    /** @return array{schedule: Schedule, created: int, skipped: string[]} */
    public function create(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $court = Court::where('id', $data['court_id'])->where('active', true)->lockForUpdate()->first()
                ?? abort(404, 'Quadra não encontrada ou inativa');

            if ($data['start_hour'] < $court->open_hour || $data['end_hour'] > $court->close_hour) {
                abort(422, 'Fora do horário de funcionamento da quadra');
            }

            if (! empty($data['customer_id'])) {
                $customerCompany = \App\Models\Customer::withoutGlobalScopes()->whereKey($data['customer_id'])->value('company_id');
                if ($customerCompany !== $court->branch()->withoutGlobalScopes()->value('company_id')) {
                    abort(422, 'A quadra pertence a outra empresa');
                }
            }

            $data['price'] ??= $court->price_per_hour * ($data['end_hour'] - $data['start_hour']);
            $schedule = Schedule::create($data + ['active' => true]);

            return ['schedule' => $schedule] + $this->generate($schedule);
        });
    }

    /** Gera as reservas que faltam até o horizonte. Datas em conflito são puladas e reportadas. */
    public function generate(Schedule $s): array
    {
        $created = 0;
        $skipped = [];
        $until = today()->addWeeks(self::WEEKS);

        $d = today();
        while ($d->dayOfWeek !== $s->weekday) {
            $d->addDay();
        }

        for (; $d->lte($until); $d->addWeek()) {
            if ($d->isToday() && $s->start_hour < (int) now()->format('G')) {
                continue;
            }
            $date = $d->toDateString();

            if (Booking::where('schedule_id', $s->id)->where('booking_date', $date)->exists()) {
                continue;
            }
            if (Booking::overlapping($s->court_id, $date, $s->start_hour, $s->end_hour)->exists()) {
                $skipped[] = $date;

                continue;
            }

            Booking::create([
                'court_id' => $s->court_id,
                'customer_id' => $s->customer_id,
                'class_group_id' => $s->class_group_id,
                'schedule_id' => $s->id,
                'sport' => $s->sport,
                'booking_date' => $date,
                'start_hour' => $s->start_hour,
                'end_hour' => $s->end_hour,
                'status' => 'confirmed',
                'price' => $s->price,
            ]);
            $created++;
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /** Estende todos os horários ativos até o horizonte (usado pelo comando agendado). */
    public function extendAll(): int
    {
        $total = 0;
        Schedule::where('active', true)->whereHas('court', fn ($q) => $q->where('active', true))
            ->each(function (Schedule $s) use (&$total) {
                $total += $this->generate($s)['created'];
            });

        return $total;
    }

    /** Desativa o horário e cancela as ocorrências futuras. Retorna quantas foram canceladas. */
    public function deactivate(Schedule $s): int
    {
        $s->update(['active' => false]);

        return Booking::where('schedule_id', $s->id)
            ->where('booking_date', '>=', today()->toDateString())
            ->whereIn('status', ['confirmed', 'pending'])
            ->update(['status' => 'cancelled']);
    }
}
