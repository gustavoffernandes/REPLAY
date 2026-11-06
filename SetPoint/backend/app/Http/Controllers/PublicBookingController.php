<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Court;
use App\Services\BookingService;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Agendamento online (sem login) por clientes avulsos, com sinal via Pix.
 * Não expõe dados de clientes: a disponibilidade mostra apenas livre/ocupado.
 */
class PublicBookingController extends Controller
{
    private const MAX_HOURS = 3;          // duração máxima de uma reserva online
    private const MAX_DAYS_AHEAD = 60;    // até quantos dias à frente
    private const MAX_OPEN_HOLDS = 3;     // reservas aguardando sinal por cliente (evita travar a agenda)

    public function company(string $slug)
    {
        $company = Company::where('slug', $slug)->where('active', true)->firstOrFail();

        return [
            'company' => ['name' => $company->name, 'slug' => $company->slug, 'deposit_percent' => $company->deposit_percent],
            'branches' => Branch::where('company_id', $company->id)->where('active', true)
                ->orderBy('name')->get(['id', 'name', 'slug', 'city', 'address']),
        ];
    }

    public function availability(Request $request, Branch $branch)
    {
        $this->assertOpen($branch);
        $date = $request->validate(['date' => ['required', 'date_format:Y-m-d']])['date'];
        $this->assertDateRange($date);
        Booking::expireStale();

        $courts = Court::where('branch_id', $branch->id)->where('active', true)->orderBy('name')->get();

        $busy = [];
        Booking::whereIn('court_id', $courts->pluck('id'))->where('booking_date', $date)->blocking()
            ->get(['court_id', 'start_hour', 'end_hour'])
            ->each(function ($b) use (&$busy) {
                for ($h = $b->start_hour; $h < $b->end_hour; $h++) {
                    $busy[$b->court_id][$h] = true;
                }
            });

        $today = now()->toDateString();
        $nowHour = (int) now()->format('G');

        return [
            'date' => $date,
            'courts' => $courts->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'price_per_hour' => (float) $c->price_per_hour,
                'slots' => collect(range($c->open_hour, $c->close_hour - 1))->map(fn ($h) => [
                    'hour' => $h,
                    // online: a partir da próxima hora cheia
                    'free' => ! isset($busy[$c->id][$h]) && ! ($date === $today && $h <= $nowHour),
                ])->values(),
            ])->values(),
        ];
    }

    public function store(Request $request, Branch $branch, BookingService $bookings, PaymentService $payments): JsonResponse
    {
        $this->assertOpen($branch);

        $d = $request->validate([
            'court_id' => ['required', 'integer'],
            'sport' => ['required', Rule::in(Booking::SPORTS)],
            'date' => ['required', 'date_format:Y-m-d'],
            'start_hour' => ['required', 'integer', 'between:0,23'],
            'end_hour' => ['required', 'integer', 'between:1,24', 'gt:start_hour'],
            'customer_name' => ['required', 'string', 'min:2', 'max:120'],
            'customer_phone' => ['required', 'string', 'max:30', function ($attr, $value, $fail) {
                $n = strlen(preg_replace('/\D/', '', $value));
                if ($n < 10 || $n > 11) {
                    $fail('Informe um telefone válido com DDD.');
                }
            }],
            'customer_email' => ['nullable', 'email', 'max:120'],
        ]);

        $this->assertDateRange($d['date']);
        if ($d['end_hour'] - $d['start_hour'] > self::MAX_HOURS) {
            abort(422, 'Máximo de ' . self::MAX_HOURS . ' horas por reserva online');
        }
        if ($d['date'] === now()->toDateString() && $d['start_hour'] <= (int) now()->format('G')) {
            abort(422, 'Escolha um horário a partir da próxima hora');
        }

        $court = Court::where('id', $d['court_id'])->where('branch_id', $branch->id)->where('active', true)->firstOrFail();
        $company = $branch->company;

        Booking::expireStale();
        $this->assertHoldLimit($company->id, $d['customer_phone']);

        $price = (float) $court->price_per_hour * ($d['end_hour'] - $d['start_hour']);
        $deposit = round($price * $company->deposit_percent / 100, 2);

        $booking = $bookings->create($court->id, $d, [
            'status' => $deposit > 0 ? 'awaiting_payment' : 'confirmed',
            'source' => 'online',
            'hold_minutes' => config('payments.hold_minutes'),
        ]);

        if ($deposit > 0) {
            try {
                $payments->chargeDeposit($booking, $deposit);
            } catch (Throwable $e) {
                report($e);
                $booking->update(['status' => 'cancelled', 'notes' => 'Falha ao gerar a cobrança']);
                abort(502, 'Não foi possível gerar o pagamento agora. Tente novamente em instantes.');
            }
        }

        return response()->json($this->payload($booking, $payments), 201);
    }

    public function show(string $code, PaymentService $payments)
    {
        Booking::expireStale();

        return $this->payload($this->byCode($code), $payments);
    }

    /** Somente em desenvolvimento (gateway simulado): confirma o sinal sem pagar. */
    public function simulatePayment(string $code, PaymentService $payments)
    {
        abort_unless($payments->isSimulated() && ! app()->isProduction(), 404);
        Booking::expireStale();

        $booking = $this->byCode($code);
        $pending = $booking->payments()->where('status', 'pending')->latest('id')->first()
            ?? abort(409, 'Não há pagamento pendente para esta reserva');
        $payments->markPaid($pending, 'simulated');

        return $this->payload($booking->refresh(), $payments);
    }

    private function byCode(string $code): Booking
    {
        return Booking::where('public_code', strtoupper($code))->firstOrFail();
    }

    private function assertOpen(Branch $branch): void
    {
        abort_unless($branch->active && $branch->company()->where('active', true)->exists(), 404);
    }

    private function assertDateRange(string $date): void
    {
        if ($date < now()->toDateString() || $date > now()->addDays(self::MAX_DAYS_AHEAD)->toDateString()) {
            abort(422, 'Escolha uma data entre hoje e os próximos ' . self::MAX_DAYS_AHEAD . ' dias');
        }
    }

    private function assertHoldLimit(int $companyId, string $phone): void
    {
        $digits = preg_replace('/\D/', '', $phone);
        $open = Booking::where('status', 'awaiting_payment')->where('expires_at', '>', now())
            ->whereIn('customer_id', fn ($q) => $q->select('id')->from('customers')->where('company_id', $companyId)
                ->whereRaw("REGEXP_REPLACE(phone, '[^0-9]', '') = ?", [$digits]))
            ->count();

        if ($open >= self::MAX_OPEN_HOLDS) {
            abort(422, 'Você já tem reservas aguardando pagamento. Conclua ou aguarde expirarem para reservar de novo.');
        }
    }

    private function payload(Booking $b, PaymentService $payments): array
    {
        $b->loadMissing('court:id,name,branch_id', 'customer:id,name');
        $branch = Branch::with('company:id,name,slug')->find($b->court->branch_id);
        $payment = $b->payments()->latest('id')->first();

        $holding = $b->status === 'awaiting_payment' && $b->expires_at?->isFuture();
        $status = $b->status === 'awaiting_payment' && ! $holding || ($b->status === 'cancelled' && str_starts_with((string) $b->notes, 'Expirada'))
            ? 'expired' : $b->status;

        return [
            'code' => $b->public_code,
            'status' => $status,
            'sport' => $b->sport,
            'date' => $b->booking_date->toDateString(),
            'start_hour' => $b->start_hour,
            'end_hour' => $b->end_hour,
            'court' => $b->court->name,
            'customer_name' => $b->customer?->name,
            'branch' => ['name' => $branch->name, 'slug' => $branch->slug, 'city' => $branch->city, 'address' => $branch->address],
            'company' => ['name' => $branch->company->name, 'slug' => $branch->company->slug],
            'total' => (float) $b->price,
            'deposit' => (float) ($payment?->amount ?? 0),
            'paid' => (float) $b->paid_amount,
            'remaining' => round((float) $b->price - (float) $b->paid_amount, 2),
            'expires_at' => $holding ? $b->expires_at->toIso8601String() : null,
            'seconds_left' => $holding ? max(0, now()->diffInSeconds($b->expires_at, false)) : 0,
            'pix_code' => $holding ? $payment?->pix_code : null,
            'simulation' => $holding && $payments->isSimulated() && ! app()->isProduction(),
        ];
    }
}
