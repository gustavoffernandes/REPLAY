<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Rules\StrongPassword;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Gestão de usuários: administrador (todos) e quem tiver permissão (somente da própria empresa). */
class UserController extends Controller
{
    public function index()
    {
        return User::with('company:id,name', 'branches:id,name')->orderBy('name')->get()
            ->map(fn ($u) => $u->toArray() + [
                'role_label' => $u->roleName(),
                'role_scope' => $u->roleScope(),
                'branch_ids' => $u->branches->pluck('id'),
            ]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('users.create'), 403, 'Você não tem permissão para esta ação');

        $d = $this->validated($request);
        $user = User::create(collect($d)->except('branch_ids')->all());
        $user->branches()->sync($d['branch_ids']);

        return response()->json(['id' => $user->id], 201);
    }

    public function update(Request $request, User $user)
    {
        abort_unless($request->user()->hasPermission('users.update'), 403, 'Você não tem permissão para esta ação');

        $actor = $request->user();
        $isSelf = $user->is($actor);

        if (! $isSelf && ! $actor->isSuperAdmin() && ! $this->assignableKeys($actor)->contains($user->role)) {
            abort(403, 'Você não pode alterar este usuário');
        }

        if ($isSelf) {
            // Não permite alterar o próprio papel/empresa/filiais nem se desativar:
            // fixa esses campos antes da validação (que depende do papel).
            $request->merge([
                'role' => $user->role,
                'company_id' => $user->company_id,
                'active' => true,
                'branch_ids' => $user->branches()->pluck('branches.id')->all(),
            ]);
        }

        $d = $this->validated($request, $user);
        if (empty($d['password'])) {
            unset($d['password']);
        }

        $user->update(collect($d)->except('branch_ids')->all());
        $user->branches()->sync($d['branch_ids']);

        return ['ok' => true];
    }

    /** Chaves dos papéis que o usuário logado pode atribuir. */
    private function assignableKeys(User $actor)
    {
        return Role::assignableBy($actor)->pluck('key');
    }

    private function validated(Request $request, ?User $user = null): array
    {
        $actor = $request->user();
        $allowed = $user && $user->is($actor) ? Role::pluck('key') : $this->assignableKeys($actor);

        $d = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:120', Rule::unique('users', 'email')->ignore($user?->id)],
            'password' => [$user ? 'nullable' : 'required', 'string', new StrongPassword($request->input('email'))],
            'role' => ['required', Rule::in($allowed->all())],
            'company_id' => ['nullable', 'integer'],
            'active' => ['boolean'],
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => ['integer'],
        ]);
        $d['active'] ??= true;
        $scope = Role::forKey($d['role'])->scope;

        // Empresa: papéis de plataforma não têm; o administrador escolhe; os demais usam a própria.
        if ($scope === 'all') {
            $d['company_id'] = null;
        } elseif ($actor->isSuperAdmin()) {
            $d['company_id'] ?? abort(422, 'Informe a empresa do usuário');
            TenantContext::current()->resolveCompanyId((int) $d['company_id'], acrossBranches: true);
        } else {
            $d['company_id'] = $actor->company_id;
        }

        // Papéis restritos a filiais precisam de ao menos uma filial da empresa.
        if ($scope === 'branches') {
            $ids = array_values(array_unique($d['branch_ids'] ?? []));
            $ids || abort(422, 'Selecione ao menos uma filial para este usuário');
            Branch::whereIn('id', $ids)->where('company_id', $d['company_id'])->count() === count($ids)
                || abort(422, 'Filial inválida para esta empresa');
            $d['branch_ids'] = $ids;
        } else {
            $d['branch_ids'] = [];
        }

        return $d;
    }
}
