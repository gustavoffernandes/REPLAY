<?php

namespace App\Models;

use App\Support\Permissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Role extends Model
{
    protected $fillable = ['key', 'name', 'description', 'scope', 'is_system'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    /** Cache por requisição (cada usuário/checagem consultaria o banco de novo). */
    private static array $cache = [];

    private ?array $permissionCache = null;

    public static function forKey(?string $key): ?self
    {
        if ($key === null) {
            return null;
        }

        return self::$cache[$key] ??= self::where('key', $key)->first();
    }

    public static function flushCache(): void
    {
        self::$cache = [];
    }

    /** Papéis que o usuário pode atribuir: administrador, todos; demais, só os restritos a filiais. */
    public static function assignableBy(User $actor): Builder
    {
        $q = self::query()->orderByDesc('is_system')->orderBy('name');

        return $actor->isSuperAdmin() ? $q : $q->where('scope', 'branches');
    }

    /** @return string[] */
    public function permissionKeys(): array
    {
        if ($this->scope === 'all') {
            return Permissions::all();
        }

        return $this->permissionCache ??= DB::table('role_permissions')->where('role_id', $this->id)->pluck('permission')->all();
    }

    public function allows(string $permission): bool
    {
        return in_array($permission, $this->permissionKeys(), true);
    }

    /** Substitui as permissões (já normalizadas) e limpa os caches. */
    public function syncPermissions(array $permissions): void
    {
        DB::transaction(function () use ($permissions) {
            DB::table('role_permissions')->where('role_id', $this->id)->delete();
            DB::table('role_permissions')->insert(array_map(fn ($p) => ['role_id' => $this->id, 'permission' => $p], $permissions));
        });
        $this->permissionCache = null;
        self::flushCache();
    }
}
