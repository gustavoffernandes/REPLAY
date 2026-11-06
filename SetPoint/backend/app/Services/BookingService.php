<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Court;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;

/** Criação de reservas avulsas (balcão e online): mesmas regras, em transação, com trava na quadra. */
class BookingService
{
    /**
     * @param  array{date: string, start_hour: int, end_hour: int, sport: string, customer_name: string, customer_phone: string, customer_email?: ?string, notes?: ?string}  $d
     * @param  array{status?: string, source?: string, hold_minutes?: ?int}  $opts
     *   status: confirmed (balcão) | awaiting_payment (online);  hold_minutes: validade da reserva provisória
     */
    public function create(int $courtId, array $d, array $opts = []): Booking
    {
        $status = $opts['status'] ?? 'confirmed';
        $source = $opts['source'] ?? 'counter';

        return DB::transaction(function () use ($courtId, $d, $status, $source, $opts) {
            $court = Court::where('id', $courtId)->where('active', true)->lockForUpdate()->first()
                ?? abort(404, 'Quadra não encontrada ou inativa');

            if ($d['start_hour'] < $court->open_hour || $d['end_hour'] > $court->close_hour) {
                abort(422, 'Fora do horário de funcionamento da quadra');
            }
            if (now()->parse($d['date'])->setHour($d['start_hour'])->lt(now()->startOfHour())) {
                abort(422, 'Não é possível reservar horários passados');
            }
            if (Booking::overlapping($court->id, $d['date'], $d['start_hour'], $d['end_hour'])->exists()) {
                abort(409, 'Horário indisponível: já existe reserva neste período');
            }

            $companyId = $court->branch()->withoutGlobalScopes()->value('company_id');
            $customer = $this->resolveCustomer($companyId, $d, $source === 'online');

            return Booking::create([
                'court_id' => $court->id,
                'customer_id' => $customer->id,
                'sport' => $d['sport'],
                'booking_date' => $d['date'],
                'start_hour' => $d['start_hour'],
                'end_hour' => $d['end_hour'],
                'status' => $status,
                'source' => $source,
                'price' => $court->price_per_hour * ($d['end_hour'] - $d['start_hour']),
                'expires_at' => $status === 'awaiting_payment' ? now()->addMinutes($opts['hold_minutes'] ?? 15) : null,
                'public_code' => $source === 'online' ? $this->newCode() : null,
                'notes' => $d['notes'] ?? null,
            ]);
        });
    }

    /**
     * Acha o cliente da empresa pelo telefone (ignorando máscara) ou cria.
     * No online, nunca sobrescreve dados de um cliente existente (a página é pública).
     */
    public function resolveCustomer(int $companyId, array $d, bool $isPublic): Customer
    {
        $digits = preg_replace('/\D/', '', $d['customer_phone']);

        $customer = Customer::withoutGlobalScopes()->where('company_id', $companyId)
            ->whereRaw("REGEXP_REPLACE(phone, '[^0-9]', '') = ?", [$digits])->first();

        if (! $customer) {
            return Customer::withoutGlobalScopes()->create([
                'company_id' => $companyId,
                'name' => $d['customer_name'],
                'phone' => $d['customer_phone'],
                'email' => $d['customer_email'] ?? null,
            ]);
        }

        if ($isPublic) {
            if (! $customer->email && ! empty($d['customer_email'])) {
                $customer->update(['email' => $d['customer_email']]);
            }
        } else {
            $customer->update(['name' => $d['customer_name']] + (! empty($d['customer_email']) ? ['email' => $d['customer_email']] : []));
        }

        return $customer;
    }

    private function newCode(): string
    {
        do {
            $code = strtoupper(\Illuminate\Support\Str::random(8));
        } while (Booking::withoutGlobalScopes()->where('public_code', $code)->exists());

        return $code;
    }
}
