<?php

namespace App\Models;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'role', 'company_id', 'active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Usuários visíveis: administrador vê todos; proprietário só os da sua empresa.
        static::addGlobalScope('tenant', function (Builder $q) {
            if ($t = TenantContext::current()) {
                if (! $t->user->isSuperAdmin()) {
                    $q->where('users.company_id', $t->user->company_id);
                }
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_user');
    }

    public function roleModel(): ?Role
    {
        return Role::forKey($this->role);
    }

    /** Escopo do papel: all (plataforma), company (todas as filiais da empresa) ou branches (atribuídas). */
    public function roleScope(): ?string
    {
        return $this->roleModel()?->scope;
    }

    public function roleName(): string
    {
        return $this->roleModel()?->name ?? $this->role;
    }

    public function isSuperAdmin(): bool
    {
        return $this->roleScope() === 'all';
    }

    public function hasPermission(string $permission): bool
    {
        return $this->roleModel()?->allows($permission) ?? false;
    }

    /** @return string[] */
    public function permissionList(): array
    {
        return $this->isSuperAdmin() ? ['*'] : ($this->roleModel()?->permissionKeys() ?? []);
    }
}
