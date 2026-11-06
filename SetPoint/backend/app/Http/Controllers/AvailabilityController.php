<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Court;
use Illuminate\Http\Request;

class AvailabilityController extends Controller
{
    /** Grade de disponibilidade: por quadra ativa, cada hora livre/ocupada/passada. */
    public function __invoke(Request $request)
    {
        $data = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $date = $data['date'] ?? now()->toDateString();
        Booking::expireStale();

        $courts = Court::with('branch:id,name')->where('active', true)
            ->orderBy('branch_id')->orderBy('name')->get();

        $map = [];
        Booking::with('customer:id,name', 'classGroup:id,name')
            ->where('booking_date', $date)
            ->blocking()
            ->get()
            ->each(function ($b) use (&$map) {
                for ($h = $b->start_hour; $h < $b->end_hour; $h++) {
                    $map[$b->court_id][$h] = ['booking_id' => $b->id, 'customer' => $b->customer?->name ?? $b->classGroup?->name, 'is_class' => $b->class_group_id !== null, 'status' => $b->status, 'sport' => $b->sport];
                }
            });

        $today = now()->toDateString();
        $nowHour = (int) now()->format('G');

        $courts = $courts->map(function ($c) use ($map, $date, $today, $nowHour) {
            $row = $c->toArray();
            $row['slots'] = [];
            for ($h = $c->open_hour; $h < $c->close_hour; $h++) {
                $row['slots'][] = [
                    'hour' => $h,
                    'past' => $date < $today || ($date === $today && $h < $nowHour),
                    'booking' => $map[$c->id][$h] ?? null,
                ];
            }

            return $row;
        });

        return ['date' => $date, 'courts' => $courts];
    }
}
