<?php

namespace App\Services\Payments;

use App\Models\Booking;
use Illuminate\Support\Str;

/** Pix simulado para desenvolvimento. Não cobra nada de verdade. */
class FakeGateway implements PaymentGateway
{
    public function createPixCharge(Booking $booking, float $amount): array
    {
        $reference = 'FAKE-' . Str::upper(Str::random(12));
        $value = number_format($amount, 2, '.', '');

        // Parece um "copia e cola" Pix, mas é apenas ilustrativo (não é pagável).
        $code = '00020126580014BR.GOV.BCB.PIX0136' . Str::uuid()
            . '5204000053039865406' . $value . '5802BR5909SETPOINT6009SAO PAULO62070503***6304' . Str::upper(Str::random(4));

        return ['reference' => $reference, 'pix_code' => $code];
    }

    public function isSimulated(): bool
    {
        return true;
    }
}
