<?php

namespace App\Http\Controllers;

use App\Http\Requests\ScheduleRequest;
use App\Models\Booking;
use App\Models\Customer;
use App\Services\ScheduleService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        return Customer::query()
            ->when($request->query('fixed') !== null && $request->query('fixed') !== '', fn ($query) => $query->where('is_fixed', (bool) $request->query('fixed')))
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->where('name', 'like', "%$q%")->orWhere('phone', 'like', "%$q%")))
            ->with('company:id,name')
            ->withCount('bookings')
            ->withSum(['bookings as total_spent' => fn ($b) => $b->whereIn('status', ['confirmed', 'completed'])], 'price')
            ->orderByDesc('is_fixed')->orderBy('name')
            ->get()
            ->each(fn ($c) => $c->total_spent = (float) ($c->total_spent ?? 0));
    }

    public function show(Customer $customer)
    {
        return $customer->load('schedules.court:id,name', 'classGroups:id,name,sport');
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = $this->companyFor($request);
        $customer = Customer::create($this->validated($request, null, $companyId) + ['company_id' => $companyId]);

        return response()->json(['id' => $customer->id], 201);
    }

    public function update(Request $request, Customer $customer)
    {
        $data = $this->validated($request, $customer);
        if (! $data['is_fixed'] && $customer->schedules()->exists()) {
            abort(422, 'Remova os horários fixos antes de tornar o cliente avulso');
        }
        $customer->update($data);

        return ['ok' => true];
    }

    public function addSchedule(ScheduleRequest $request, Customer $customer, ScheduleService $service): JsonResponse
    {
        if (! $customer->is_fixed) {
            abort(422, 'Apenas clientes fixos possuem horários recorrentes');
        }
        $data = $request->validated();
        $data['sport'] ?? abort(422, 'Informe o esporte');
        $r = $service->create($data + ['customer_id' => $customer->id, 'price' => null]);

        return response()->json(['id' => $r['schedule']->id, 'created' => $r['created'], 'skipped' => $r['skipped']], 201);
    }

    private function companyFor(Request $request): int
    {
        return TenantContext::current()->resolveCompanyId($request->integer('company_id') ?: null);
    }

    private function validated(Request $request, ?Customer $customer = null, ?int $companyId = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:30', Rule::unique('customers', 'phone')->where('company_id', $customer?->company_id ?? $companyId)->ignore($customer?->id)],
            'email' => ['nullable', 'email', 'max:120'],
            'is_fixed' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
        $data['is_fixed'] ??= false;

        return $data;
    }
}
