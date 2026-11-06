<?php

namespace App\Services\Payments;

use App\Models\Booking;

/** Contrato de um provedor de pagamento Pix. Implemente para integrar um gateway real. */
interface PaymentGateway
{
    /**
     * Cria a cobrança Pix do sinal.
     *
     * @return array{reference: string, pix_code: string} reference = id da cobrança no provedor;
     *                                                    pix_code = payload "copia e cola" (também usado para gerar o QR)
     */
    public function createPixCharge(Booking $booking, float $amount): array;

    /** True quando o provedor é simulado (habilita o botão "simular pagamento" no ambiente local). */
    public function isSimulated(): bool;
}
