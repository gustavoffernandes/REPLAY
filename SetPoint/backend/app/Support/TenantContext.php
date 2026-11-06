<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Escopo de acesso da requisição atual: quais empresas e filiais o usuário enxerga.
 * Os models de domínio usam isto em global scopes, então nenhuma query escapa do tenant.
 * Fora de requisição autenticada (seeders, comandos) não há contexto e nada é filtrado.
 */
class TenantContext
{
    private static ?self $current = null;

    private ?array $accessible = null;

    public function __construct(public readonly User $user, public readonly ?int $requestedBranchId = null) {}

    public static function current(): ?self
    {
        return self::$current;
    }

    public static function set(?self $context): void
    {
        self::$current = $context;
    }

    /** Todas as filiais que o usuário pode acessar. */
    public function accessibleBranchIds(): array
    {
        return $this->accessible ??= $this->computeAccessible();
    }

    /** Filiais do escopo atual: a selecionada (X-Branch-Id) ou todas as acessíveis. */
    public function branchIds(): array
    {
        return $this->requestedBranchId ? [$this->requestedBranchId] : $this->accessibleBranchIds();
    }

    /** Empresas acessíveis (para listar/gerenciar). */
    public function accessibleCompanyIds(): array
    {
        if ($this->user->isSuperAdmin()) {
            return Company::withoutGlobalScopes()->pluck('id')->all();
        }

        return $this->companiesOf($this->accessibleBranchIds());
    }

    /** Empresas do escopo atual (usado para clientes, que pertencem à empresa). */
    public function companyIds(): array
    {
        if ($this->requestedBranchId) {
            return $this->companiesOf($this->branchIds());
        }

        return $this->accessibleCompanyIds();
    }

    /** A filial precisa estar no escopo atual (selecionada ou acessível). */
    public function assertBranch(int $branchId): int
    {
        in_array($branchId, $this->branchIds(), true) || abort(403, 'Sem acesso a esta filial');

        return $branchId;
    }

    /**
     * Define a empresa de um novo registro: a única do escopo, ou a informada (se acessível).
     * Com $acrossBranches, considera todas as empresas acessíveis (ignora a filial selecionada).
     */
    public function resolveCompanyId(?int $requested = null, bool $acrossBranches = false): int
    {
        $allowed = $acrossBranches ? $this->accessibleCompanyIds() : $this->companyIds();

        if ($requested !== null) {
            in_array($requested, $allowed, true) || abort(403, 'Sem acesso a esta empresa');

            return $requested;
        }
        count($allowed) === 1 || abort(422, 'Informe a empresa');

        return $allowed[0];
    }

    private function companiesOf(array $branchIds): array
    {
        return Branch::withoutGlobalScopes()->whereIn('id', $branchIds)->distinct()->pluck('company_id')->all();
    }

    private function computeAccessible(): array
    {
        $q = Branch::withoutGlobalScopes();

        if ($this->user->isSuperAdmin()) {
            return $q->pluck('id')->all();
        }

        $q->where('company_id', $this->user->company_id)
            ->where('active', true)
            ->whereIn('company_id', Company::withoutGlobalScopes()->where('active', true)->select('id'));

        if ($this->user->roleScope() === 'branches') {
            $q->whereIn('id', DB::table('branch_user')->where('user_id', $this->user->id)->select('branch_id'));
        }

        return $q->pluck('id')->all();
    }
}
