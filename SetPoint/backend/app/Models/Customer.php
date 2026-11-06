<?php

namespace App\Models;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $fillable = ['company_id', 'name', 'phone', 'email', 'is_fixed', 'notes'];

    protected function casts(): array
    {
        return ['is_fixed' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('tenant', function (Builder $q) {
            if ($t = TenantContext::current()) {
                $q->whereIn('customers.company_id', $t->companyIds());
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class)->where('active', true);
    }

    public function classGroups(): BelongsToMany
    {
        return $this->belongsToMany(ClassGroup::class, 'class_group_customer');
    }
}
