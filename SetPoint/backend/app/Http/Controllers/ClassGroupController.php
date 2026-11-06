<?php

namespace App\Http\Controllers;

use App\Http\Requests\ScheduleRequest;
use App\Models\Booking;
use App\Models\ClassGroup;
use App\Models\Customer;
use App\Support\TenantContext;
use App\Services\ScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ClassGroupController extends Controller
{
    public function index()
    {
        return ClassGroup::query()
            ->withCount('students')
            ->with('schedules.court:id,name', 'branch:id,name')
            ->orderByDesc('active')->orderBy('name')
            ->get();
    }

    public function show(ClassGroup $group)
    {
        return $group->load('schedules.court:id,name', 'students:id,name,phone', 'branch:id,name,company_id')->loadCount('students');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        TenantContext::current()->assertBranch($data['branch_id']);
        $group = ClassGroup::create($data);

        return response()->json(['id' => $group->id], 201);
    }

    public function update(Request $request, ClassGroup $group, ScheduleService $service)
    {
        $data = $this->validated($request);
        unset($data['branch_id']); // a turma não muda de filial
        if ($data['capacity'] < $group->students()->count()) {
            abort(422, 'A capacidade não pode ser menor que o número de alunos matriculados');
        }
        $group->update($data);

        if (! $group->active) {
            $group->schedules->each(fn ($s) => $service->deactivate($s));
        }

        return ['ok' => true];
    }

    /**
     * Exclui a turma (exclusão lógica): cancela as aulas futuras, desvincula os alunos e tira a turma das listas.
     * As aulas já realizadas continuam no histórico com o nome da turma.
     */
    public function destroy(ClassGroup $group, ScheduleService $service)
    {
        $cancelled = DB::transaction(function () use ($group, $service) {
            $n = $group->schedules->sum(fn ($s) => $service->deactivate($s));
            $group->students()->detach();
            $group->delete();

            return $n;
        });

        return ['ok' => true, 'cancelled' => $cancelled];
    }

    public function addStudent(Request $request, ClassGroup $group)
    {
        $data = $request->validate(['customer_id' => ['required', 'integer']]);
        $customer = Customer::findOrFail($data['customer_id']);
        if ($customer->company_id !== $group->branch->company_id) {
            abort(422, 'O cliente pertence a outra empresa');
        }

        if (! $group->students()->whereKey($data['customer_id'])->exists()) {
            if ($group->students()->count() >= $group->capacity) {
                abort(422, 'Turma lotada');
            }
            $group->students()->attach($data['customer_id']);
        }

        return ['ok' => true];
    }

    public function removeStudent(ClassGroup $group, Customer $customer)
    {
        $group->students()->detach($customer->id);

        return ['ok' => true];
    }

    public function addSchedule(ScheduleRequest $request, ClassGroup $group, ScheduleService $service): JsonResponse
    {
        if (! $group->active) {
            abort(422, 'Turma inativa');
        }
        if (\App\Models\Court::whereKey($request->integer('court_id'))->value('branch_id') !== $group->branch_id) {
            abort(422, 'A quadra precisa ser da mesma filial da turma');
        }
        $r = $service->create(array_merge($request->validated(), [
            'class_group_id' => $group->id,
            'sport' => $group->sport,
            'price' => 0,
        ]));

        return response()->json(['id' => $r['schedule']->id, 'created' => $r['created'], 'skipped' => $r['skipped']], 201);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:80'],
            'sport' => ['required', Rule::in(Booking::SPORTS)],
            'instructor' => ['nullable', 'string', 'max:80'],
            'capacity' => ['required', 'integer', 'between:1,60'],
            'monthly_fee' => ['nullable', 'numeric', 'min:0'],
            'active' => ['boolean'],
        ]);
        $data['monthly_fee'] ??= 0;
        $data['active'] ??= true;

        return $data;
    }
}
