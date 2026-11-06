<?php

namespace App\Models;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Company extends Model
{
    protected $fillable = ['name', 'slug', 'document', 'email', 'phone', 'deposit_percent', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'deposit_percent' => 'integer'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('tenant', function (Builder $q) {
            if ($t = TenantContext::current()) {
                $q->whereIn('companies.id', $t->accessibleCompanyIds());
            }
        });

        // Slug público (URL do agendamento online), único e estável.
        static::creating(function (Company $c) {
            if (! $c->slug) {
                $base = Str::slug($c->name) ?: 'empresa';
                $slug = $base;
                for ($i = 2; static::withoutGlobalScopes()->where('slug', $slug)->exists(); $i++) {
                    $slug = "$base-$i";
                }
                $c->slug = $slug;
            }
        });
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }
}
