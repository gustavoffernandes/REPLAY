<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Payment;
use App\Services\Payments\PaymentGateway;
use Illuminate\Support\Facades\DB;

/** Cobrança do sinal e baixa de pagamentos. */
class PaymentService
{
    public function __construct(private PaymentGateway $gateway) {}

    public function isSimulated(): bool
    {
        return $this->gateway->isSimulated();
    }

    public function chargeDeposit(Booking $booking, float $amount): Payment
    {
        $charge = $this->gateway->createPixCharge($booking, $amount);

        return Payment::create([
            'booking_id' => $booking->id,
            'provider' => config('payments.driver'),
            'provider_ref' => $charge['reference'],
            'method' => 'pix',
            'amount' => $amount,
            'status' => 'pending',
            'pix_code' => $charge['pix_code'],
        ]);
    }

    /** Confirma o pagamento e a reserva. Idempotente. */
    public function markPaid(Payment $payment, ?string $method = null): void
    {
        DB::transaction(function () use ($payment, $method) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $booking = Booking::withoutGlobalScopes()->whereKey($payment->booking_id)->lockForUpdate()->firstOrFail();

            if ($payment->status === 'paid') {
                return;
            }
            if ($booking->status !== 'awaiting_payment') {
                abort(409, 'Esta reserva expirou ou foi cancelada');
            }

            $payment->update(['status' => 'paid', 'paid_at' => now(), 'method' => $method ?? $payment->method]);
            $booking->update(['status' => 'confirmed', 'paid_amount' => $payment->amount, 'expires_at' => null]);
        });
    }

    /** Encerra cobranças pendentes de uma reserva cancelada ou expirada. */
    public function closePending(Booking $booking, string $to = 'cancelled'): void
    {
        Payment::where('booking_id', $booking->id)->where('status', 'pending')->update(['status' => $to]);
    }
}
