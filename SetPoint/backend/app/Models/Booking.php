<?php

namespace App\Models;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Booking extends Model
{
    public const SPORTS = ['volei', 'futvolei', 'beachtenis'];

    public const STATUSES = ['awaiting_payment', 'confirmed', 'pending', 'cancelled', 'completed'];

    /** Status que o atendente pode definir manualmente (awaiting_payment só nasce no agendamento online). */
    public const MANUAL_STATUSES = ['confirmed', 'pending', 'cancelled', 'completed'];

    /** Status contabilizados em indicadores (dashboard). Reservas aguardando sinal não entram. */
    public const BLOCKING = ['confirmed', 'pending', 'completed'];

    protected $fillable = [
        'court_id', 'customer_id', 'class_group_id', 'schedule_id', 'sport', 'booking_date', 'start_hour', 'end_hour',
        'status', 'source', 'price', 'paid_amount', 'expires_at', 'public_code', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'booking_date' => 'date:Y-m-d',
            'price' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Herda a filial pela quadra.
        static::addGlobalScope('tenant', function (Builder $q) {
            if ($t = TenantContext::current()) {
                $q->whereIn('bookings.court_id', fn ($s) => $s->select('id')->from('courts')->whereIn('branch_id', $t->branchIds()));
            }
        });
    }

    /** Reservas que ocupam o horário na agenda: ativas, concluídas e provisórias ainda não expiradas. */
    public function scopeBlocking(Builder $q): Builder
    {
        return $q->where(fn ($w) => $w->whereIn('bookings.status', self::BLOCKING)->orWhere(fn ($h) => $this->hold($h)));
    }

    /** Reservas ativas que se sobrepõem ao intervalo [start, end) na quadra/data (inclui provisórias vigentes). */
    public function scopeOverlapping(Builder $q, int $courtId, string $date, int $start, int $end): Builder
    {
        return $q->where('bookings.court_id', $courtId)
            ->where('booking_date', $date)
            ->where(fn ($w) => $w->whereIn('bookings.status', ['confirmed', 'pending'])->orWhere(fn ($h) => $this->hold($h)))
            ->where('start_hour', '<', $end)
            ->where('end_hour', '>', $start);
    }

    private function hold(Builder $q): Builder
    {
        return $q->where('bookings.status', 'awaiting_payment')->where('bookings.expires_at', '>', now());
    }

    /** Cancela reservas provisórias cujo prazo de pagamento do sinal acabou. Retorna quantas. */
    public static function expireStale(): int
    {
        $ids = static::withoutGlobalScopes()
            ->where('status', 'awaiting_payment')->where('expires_at', '<=', now())
            ->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        static::withoutGlobalScopes()->whereIn('id', $ids)
            ->update(['status' => 'cancelled', 'notes' => 'Expirada: sinal não pago a tempo']);
        Payment::whereIn('booking_id', $ids)->where('status', 'pending')->update(['status' => 'expired']);

        return $ids->count();
    }

    public function court(): BelongsTo
    {
        return $this->belongsTo(Court::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function classGroup(): BelongsTo
    {
        return $this->belongsTo(ClassGroup::class)->withTrashed();
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
