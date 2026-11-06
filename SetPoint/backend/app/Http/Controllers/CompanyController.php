<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Rules\StrongPassword;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Gestão de empresas contratantes: somente administrador (permissão companies.manage). */
class CompanyController extends Controller
{
    public function index()
    {
        return Company::withCount(['branches', 'branches as active_branches_count' => fn ($q) => $q->where('active', true)])
            ->orderBy('name')->get()
            ->map(function ($c) {
                $c->users_count = User::withoutGlobalScopes()->where('company_id', $c->id)->count();

                return $c;
            });
    }

    /** Cria a empresa, a primeira filial e, opcionalmente, o usuário proprietário. */
    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'document' => ['nullable', 'string', 'max:20', 'unique:companies,document'],
            'email' => ['nullable', 'email', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'deposit_percent' => ['nullable', 'integer', 'between:0,100'],
            'branch_name' => ['nullable', 'string', 'max:120'],
            'owner_name' => ['nullable', 'required_with:owner_email', 'string', 'max:120'],
            'owner_email' => ['nullable', 'required_with:owner_name', 'email', 'max:120', 'unique:users,email'],
            'owner_password' => ['nullable', 'required_with:owner_email', 'string', new StrongPassword($request->input('owner_email'))],
        ]);

        $company = DB::transaction(function () use ($d) {
            $company = Company::create(collect($d)->only(['name', 'document', 'email', 'phone', 'deposit_percent'])->filter(fn ($v) => $v !== null)->all() + ['active' => true]);
            Branch::create(['company_id' => $company->id, 'name' => $d['branch_name'] ?? 'Matriz', 'active' => true]);

            if (! empty($d['owner_email'])) {
                User::create([
                    'name' => $d['owner_name'], 'email' => $d['owner_email'], 'password' => $d['owner_password'],
                    'role' => 'owner', 'company_id' => $company->id, 'active' => true,
                ]);
            }

            return $company;
        });

        return response()->json(['id' => $company->id], 201);
    }

    public function update(Request $request, Company $company)
    {
        $company->update($request->validate([
            'name' => ['required', 'string', 'max:120'],
            'document' => ['nullable', 'string', 'max:20', Rule::unique('companies', 'document')->ignore($company->id)],
            'email' => ['nullable', 'email', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'deposit_percent' => ['sometimes', 'required', 'integer', 'between:0,100'],
            'active' => ['boolean'],
        ]));

        return ['ok' => true];
    }
}
