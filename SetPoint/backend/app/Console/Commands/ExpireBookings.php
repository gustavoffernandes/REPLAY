<?php

namespace App\Console\Commands;

use App\Models\Booking;
use Illuminate\Console\Command;

class ExpireBookings extends Command
{
    protected $signature = 'bookings:expire';

    protected $description = 'Libera os horários de reservas online cujo sinal não foi pago a tempo';

    public function handle(): int
    {
        $this->info(Booking::expireStale() . ' reservas expiradas.');

        return self::SUCCESS;
    }
}
