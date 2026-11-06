<?php

namespace App\Http\Controllers;

use App\Models\Court;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CourtController extends Controller
{
    public function index()
    {
        return Court::with('branch:id,name,company_id')->orderBy('branch_id')->orderBy('name')->get();
    }

    public function store(Request $request): JsonResponse
    {
        $court = Court::create($this->validated($request));

        return response()->json(['id' => $court->id], 201);
    }

    public function update(Request $request, Court $court)
    {
        $data = $this->validated($request);
        unset($data['branch_id']); // a quadra não muda de filial
        $court->update($data);

        return ['ok' => true];
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:80'],
            'price_per_hour' => ['required', 'numeric', 'min:0'],
            'open_hour' => ['required', 'integer', 'between:0,23'],
            'close_hour' => ['required', 'integer', 'between:1,24', 'gt:open_hour'],
            'active' => ['boolean'],
            // Serviço de captura de replays desta quadra (opcional)
            'replay_url' => ['nullable', 'url:http,https', 'max:255'],
        ]);
        $data['active'] ??= true;
        TenantContext::current()->assertBranch($data['branch_id']);

        return $data;
    }
}
