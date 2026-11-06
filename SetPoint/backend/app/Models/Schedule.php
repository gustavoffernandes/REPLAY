<?php

namespace App\Models;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Schedule extends Model
{
    protected $fillable = ['class_group_id', 'customer_id', 'court_id', 'sport', 'weekday', 'start_hour', 'end_hour', 'price', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'price' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        // Herda a filial pela quadra.
        static::addGlobalScope('tenant', function (Builder $q) {
            if ($t = TenantContext::current()) {
                $q->whereIn('schedules.court_id', fn ($s) => $s->select('id')->from('courts')->whereIn('branch_id', $t->branchIds()));
            }
        });
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
}
