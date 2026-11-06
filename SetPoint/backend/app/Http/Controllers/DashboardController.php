<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Court;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __invoke()
    {
        $today = now()->toDateString();
        $monthStart = now()->startOfMonth()->toDateString();
        $paid = ['confirmed', 'completed'];

        $slotsToday = (int) Court::where('active', true)->sum(DB::raw('close_hour - open_hour'));
        $usedToday = (int) Booking::where('booking_date', $today)->whereIn('status', Booking::BLOCKING)
            ->sum(DB::raw('end_hour - start_hour'));

        $since = now()->subDays(13)->toDateString();
        $byDate = Booking::selectRaw("booking_date as date, COUNT(*) as bookings,
                COALESCE(SUM(CASE WHEN status IN ('confirmed','completed') THEN price END),0) as revenue")
            ->whereBetween('booking_date', [$since, $today])->where('status', '<>', 'cancelled')
            ->groupBy('booking_date')->get()->keyBy('date');

        $daily = collect(range(13, 0))->map(function ($i) use ($byDate) {
            $d = now()->subDays($i)->toDateString();

            return ['date' => $d, 'bookings' => $byDate[$d]->bookings ?? 0, 'revenue' => $byDate[$d]->revenue ?? 0];
        })->values();

        $bySport = Booking::where('booking_date', '>=', $monthStart)->whereIn('status', $paid)
            ->selectRaw('sport, COUNT(*) as bookings, COALESCE(SUM(price),0) as revenue')
            ->groupBy('sport')->get();

        $peak = Booking::where('booking_date', '>=', now()->subDays(30)->toDateString())->where('status', '<>', 'cancelled')
            ->selectRaw('start_hour as hour, COUNT(*) as bookings')->groupBy('start_hour')->orderBy('start_hour')->get();

        $upcoming = Booking::join('courts', 'courts.id', '=', 'bookings.court_id')
            ->leftJoin('customers', 'customers.id', '=', 'bookings.customer_id')
            ->leftJoin('class_groups', 'class_groups.id', '=', 'bookings.class_group_id')
            ->whereIn('bookings.status', ['confirmed', 'pending'])
            ->where(fn ($q) => $q->where('booking_date', '>', $today)
                ->orWhere(fn ($q) => $q->where('booking_date', $today)->where('end_hour', '>', (int) now()->format('G'))))
            ->selectRaw('bookings.*, courts.name as court_name, COALESCE(customers.name, class_groups.name) as customer_name')
            ->orderBy('booking_date')->orderBy('start_hour')->limit(8)->get();

        return [
            'kpis' => [
                'courts' => Court::where('active', true)->count(),
                'bookings_today' => Booking::where('booking_date', $today)->whereIn('status', Booking::BLOCKING)->count(),
                'occupancy_today' => $slotsToday ? (int) round($usedToday / $slotsToday * 100) : 0,
                'revenue_today' => (float) Booking::where('booking_date', $today)->whereIn('status', $paid)->sum('price'),
                'revenue_month' => (float) Booking::where('booking_date', '>=', $monthStart)->whereIn('status', $paid)->sum('price'),
                'pending' => Booking::where('status', 'pending')->where('booking_date', '>=', $today)->count(),
            ],
            'daily' => $daily,
            'by_sport' => $bySport,
            'peak_hours' => $peak,
            'upcoming' => $upcoming,
        ];
    }
}
