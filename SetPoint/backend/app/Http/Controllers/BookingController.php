<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Services\BookingService;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BookingController extends Controller
{
    public function index(Request $request)
    {
        Booking::expireStale();

        $f = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', Rule::in(Booking::STATUSES)],
        ]);

        return Booking::query()
            ->join('courts', 'courts.id', '=', 'bookings.court_id')
            ->leftJoin('customers', 'customers.id', '=', 'bookings.customer_id')
            ->leftJoin('class_groups', 'class_groups.id', '=', 'bookings.class_group_id')
            ->selectRaw('bookings.*, courts.name as court_name, COALESCE(customers.name, class_groups.name) as customer_name,
                customers.phone as customer_phone, customers.is_fixed as customer_is_fixed')
            ->when($f['date'] ?? null, fn ($q, $v) => $q->where('booking_date', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('booking_date', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('booking_date', '<=', $v))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('bookings.status', $v))
            ->orderByDesc('booking_date')->orderBy('start_hour')
            ->limit(500)
            ->get();
    }

    /** Detalhes de uma reserva (usado pelo modal da agenda). */
    public function show(Booking $booking)
    {
        Booking::expireStale();
        $booking->refresh()->load('court:id,name', 'customer:id,name,phone,email,is_fixed', 'classGroup:id,name,instructor', 'payments');

        return array_merge($booking->toArray(), [
            'origin' => $booking->class_group_id ? 'class' : ($booking->schedule_id ? 'fixed' : 'single'),
        ]);
    }

    /** Reserva no balcão: confirmada na hora, sem sinal. */
    public function store(Request $request, BookingService $service): JsonResponse
    {
        $d = $request->validate([
            'court_id' => ['required', 'integer'],
            'sport' => ['required', Rule::in(Booking::SPORTS)],
            'date' => ['required', 'date_format:Y-m-d'],
            'start_hour' => ['required', 'integer', 'between:0,23'],
            'end_hour' => ['required', 'integer', 'between:1,24', 'gt:start_hour'],
            'customer_name' => ['required', 'string', 'max:120'],
            'customer_phone' => ['required', 'string', 'max:30'],
            'customer_email' => ['nullable', 'email', 'max:120'],
            'status' => ['nullable', Rule::in(['confirmed', 'pending'])],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        if ($d['end_hour'] - $d['start_hour'] > 4) {
            abort(422, 'Máximo de 4 horas por reserva');
        }

        Booking::expireStale();
        $booking = $service->create($d['court_id'], $d, ['status' => $d['status'] ?? 'confirmed', 'source' => 'counter']);

        return response()->json(['id' => $booking->id, 'price' => $booking->price], 201);
    }

    public function updateStatus(Request $request, Booking $booking, PaymentService $payments)
    {
        $status = $request->validate(['status' => ['required', Rule::in(Booking::MANUAL_STATUSES)]])['status'];
        // Cancelar e confirmar/concluir são permissões distintas
        abort_unless($request->user()->hasPermission($status === 'cancelled' ? 'bookings.cancel' : 'bookings.confirm'), 403, 'Você não tem permissão para esta ação');

        if ($booking->status === 'awaiting_payment') {
            // Atendente pode confirmar manualmente (sinal recebido por outro meio) ou cancelar a reserva provisória.
            $pending = $booking->payments()->where('status', 'pending')->latest('id')->first();
            if ($status === 'confirmed' && $pending) {
                $payments->markPaid($pending, 'manual');

                return ['ok' => true];
            }
            if ($status === 'cancelled') {
                $payments->closePending($booking);
            } elseif ($status !== 'confirmed') {
                abort(422, 'Reserva aguardando sinal só pode ser confirmada ou cancelada');
            }
        }

        $booking->update(['status' => $status, 'expires_at' => null]);

        return ['ok' => true];
    }
}
