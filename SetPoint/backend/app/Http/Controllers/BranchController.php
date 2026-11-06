<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    public function index()
    {
        return Branch::with('company:id,name,slug')->withCount('courts')
            ->orderBy('company_id')->orderBy('name')->get();
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'company_id' => ['nullable', 'integer'],
            ...$this->rules(),
        ]);
        $companyId = TenantContext::current()->resolveCompanyId($d['company_id'] ?? null, acrossBranches: true);

        $branch = Branch::create($this->fields($d) + ['company_id' => $companyId]);

        return response()->json(['id' => $branch->id], 201);
    }

    public function update(Request $request, Branch $branch)
    {
        $branch->update($this->fields($request->validate($this->rules())));

        return ['ok' => true];
    }

    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:80'],
            'address' => ['nullable', 'string', 'max:160'],
            'active' => ['boolean'],
        ];
    }

    private function fields(array $d): array
    {
        return array_merge(['active' => true], collect($d)->only(['name', 'city', 'address', 'active'])->all());
    }
}
