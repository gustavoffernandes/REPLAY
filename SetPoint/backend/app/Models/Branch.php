<?php

namespace App\Models;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Branch extends Model
{
    protected $fillable = ['company_id', 'name', 'slug', 'city', 'address', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    protected static function booted(): void
    {
        // Lista todas as filiais que o usuário pode acessar (não só a filial selecionada no momento).
        static::addGlobalScope('tenant', function (Builder $q) {
            if ($t = TenantContext::current()) {
                $q->whereIn('branches.id', $t->accessibleBranchIds());
            }
        });

        // Slug público, único dentro da empresa.
        static::creating(function (Branch $b) {
            if (! $b->slug) {
                $base = Str::slug($b->name) ?: 'filial';
                $slug = $base;
                for ($i = 2; static::withoutGlobalScopes()->where('company_id', $b->company_id)->where('slug', $slug)->exists(); $i++) {
                    $slug = "$base-$i";
                }
                $b->slug = $slug;
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function courts(): HasMany
    {
        return $this->hasMany(Court::class);
    }
}
