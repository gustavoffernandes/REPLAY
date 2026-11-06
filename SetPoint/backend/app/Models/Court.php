<?php

namespace App\Models;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Court extends Model
{
    protected $fillable = ['branch_id', 'name', 'price_per_hour', 'open_hour', 'close_hour', 'active', 'replay_url'];

    // O token da câmera nunca sai na API (só é mostrado uma vez, ao ser gerado).
    protected $hidden = ['replay_token', 'replay_token_hash'];

    protected $appends = ['replay_ready'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'price_per_hour' => 'decimal:2', 'replay_token' => 'encrypted'];
    }

    /** A quadra tem câmera de replay configurada (endereço + token). */
    public function getReplayReadyAttribute(): bool
    {
        return ! empty($this->attributes['replay_url']) && ! empty($this->attributes['replay_token_hash']);
    }

    protected static function booted(): void
    {
        static::addGlobalScope('tenant', function (Builder $q) {
            if ($t = TenantContext::current()) {
                $q->whereIn('courts.branch_id', $t->branchIds());
            }
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}
