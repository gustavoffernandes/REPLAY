<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Manutenção de papéis e permissões: somente administrador da plataforma (roles.*). */
class RoleController extends Controller
{
    public function index()
    {
        return [
            'catalog' => Permissions::CATALOG,
            'roles' => Role::orderByDesc('is_system')->orderBy('id')->get()->map(fn ($r) => $this->present($r))->values(),
        ];
    }

    /** Papéis que o usuário logado pode atribuir (usado no cadastro de usuários). */
    public function assignable(Request $request)
    {
        return Role::assignableBy($request->user())->get(['id', 'key', 'name', 'scope', 'is_system']);
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:80', 'unique:roles,name'],
            'description' => ['nullable', 'string', 'max:255'],
            'scope' => ['required', 'in:company,branches'],
            'permissions' => ['array'],
            'permissions.*' => ['string'],
        ]);

        $key = $base = 'custom_' . (Str::slug($d['name'], '_') ?: 'papel');
        for ($i = 2; Role::where('key', $key)->exists(); $i++) {
            $key = "{$base}_$i";
        }

        $role = Role::create(['key' => $key, 'name' => $d['name'], 'description' => $d['description'] ?? null, 'scope' => $d['scope'], 'is_system' => false]);
        $role->syncPermissions($this->normalize($d['permissions'] ?? []));

        return response()->json(['id' => $role->id], 201);
    }

    public function update(Request $request, Role $role)
    {
        $role->scope !== 'all' || abort(403, 'O papel de Administrador não pode ser alterado');

        $d = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:80', 'unique:roles,name,' . $role->id],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['array'],
            'permissions.*' => ['string'],
        ]);

        // Papéis do sistema mantêm o nome; só descrição e permissões mudam.
        $role->update(collect($d)->only($role->is_system ? ['description'] : ['name', 'description'])->all());
        if (array_key_exists('permissions', $d)) {
            $role->syncPermissions($this->normalize($d['permissions']));
        }

        return ['ok' => true];
    }

    public function destroy(Role $role)
    {
        $role->is_system && abort(403, 'Papéis do sistema não podem ser excluídos');
        $count = User::withoutGlobalScopes()->where('role', $role->key)->count();
        $count === 0 || abort(422, "Existem $count usuário(s) com este papel. Troque o papel deles antes de excluir.");

        $role->delete();
        Role::flushCache();

        return ['ok' => true];
    }

    /** Mantém só permissões válidas e concedíveis, e inclui o "ver" das áreas com alguma ação marcada. */
    private function normalize(array $permissions): array
    {
        return Permissions::withDependencies(array_values(array_intersect($permissions, Permissions::assignable())));
    }

    private function present(Role $r): array
    {
        return [
            'id' => $r->id, 'key' => $r->key, 'name' => $r->name, 'description' => $r->description,
            'scope' => $r->scope, 'is_system' => $r->is_system,
            'permissions' => $r->permissionKeys(),
            'users_count' => User::withoutGlobalScopes()->where('role', $r->key)->count(),
        ];
    }
}
