<?php

namespace Database\Seeders;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\ClassGroup;
use App\Models\Company;
use App\Models\Court;
use App\Models\Customer;
use App\Models\User;
use App\Services\ScheduleService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /** Senha de todos os usuários de exemplo. */
    private const PASSWORD = 'password';

    /** Dados de exemplo, sempre relativos à data de hoje. */
    public function run(): void
    {
        mt_srand(42);

        User::create([
            'name' => 'Administrador SetPoint', 'email' => 'admin@setpoint.test', 'password' => self::PASSWORD,
            'role' => 'super_admin', 'active' => true,
        ]);

        // ── Empresa 1: duas filiais ──────────────────────────────────────────
        $arena = Company::create(['name' => 'Arena Beach Sul', 'document' => '12345678000190', 'email' => 'contato@arenabeach.test']);
        $centro = Branch::create(['company_id' => $arena->id, 'name' => 'Unidade Centro', 'city' => 'São Paulo']);
        $praia = Branch::create(['company_id' => $arena->id, 'name' => 'Unidade Praia', 'city' => 'Santos']);

        $this->user('Paulo Arena', 'owner@arena.test', 'owner', $arena);
        $this->user('Gabriela Gerente', 'gerente@arena.test', 'manager', $arena, [$centro]);
        $this->user('André Atendente', 'atendente@arena.test', 'staff', $arena, [$praia]);

        [$courts, $customers] = $this->seedCompany($arena, [
            [$centro, 'Quadra 1', 90, 8, 22],
            [$centro, 'Quadra 2', 90, 8, 22],
            [$centro, 'Quadra 3', 100, 8, 23],
            [$praia, 'Quadra 1', 100, 8, 23],
            [$praia, 'Quadra 2', 110, 8, 23],
        ], [
            'Lucas Almeida', 'Mariana Costa', 'Rafael Souza', 'Camila Rocha', 'Bruno Lima', 'Fernanda Dias',
            'Thiago Martins', 'Juliana Pires', 'Gustavo Ramos', 'Patrícia Nunes', 'André Barbosa', 'Larissa Freitas',
        ], 1000);
        $this->seedRecurring($courts, $customers, $centro, $praia);

        // ── Empresa 2: uma filial (prova o isolamento entre empresas) ──────────
        $norte = Company::create(['name' => 'Areia Norte Esportes', 'document' => '98765432000110', 'email' => 'oi@areianorte.test']);
        $matriz = Branch::create(['company_id' => $norte->id, 'name' => 'Matriz', 'city' => 'Fortaleza']);
        $this->user('Marta Norte', 'owner@areianorte.test', 'owner', $norte);

        $this->seedCompany($norte, [
            [$matriz, 'Quadra A', 80, 8, 22],
            [$matriz, 'Quadra B', 80, 8, 22],
        ], ['Helena Duarte', 'Otávio Braga', 'Sabrina Teles', 'Vitor Cunha', 'Elisa Moura'], 5000);
    }

    private function user(string $name, string $email, string $role, Company $company, array $branches = []): void
    {
        $user = User::create([
            'name' => $name, 'email' => $email, 'password' => self::PASSWORD,
            'role' => $role, 'company_id' => $company->id, 'active' => true,
        ]);
        $user->branches()->sync(collect($branches)->pluck('id')->all());
    }

    /** @return array{0: Collection, 1: Collection} quadras e clientes criados */
    private function seedCompany(Company $company, array $courtSpecs, array $names, int $phoneBase): array
    {
        $courts = collect($courtSpecs)->map(fn ($c) => Court::create([
            'branch_id' => $c[0]->id, 'name' => $c[1], 'price_per_hour' => $c[2], 'open_hour' => $c[3], 'close_hour' => $c[4],
        ]));

        $customers = collect($names)->map(fn ($n, $i) => Customer::create([
            'company_id' => $company->id,
            'name' => $n,
            'phone' => sprintf('(11) 9%04d-%04d', $phoneBase + $i * 37, 2000 + $i * 91),
            'email' => str_replace(' ', '.', strtolower(Str::ascii($n))) . '@email.com',
        ]));

        $used = [];
        for ($d = -10; $d <= 7; $d++) {
            $date = now()->addDays($d)->toDateString();
            $n = mt_rand(4, 3 * $courts->count());
            for ($k = 0; $k < $n; $k++) {
                $court = $courts[mt_rand(0, $courts->count() - 1)];
                $dur = mt_rand(1, 2);
                $start = mt_rand(8, 21 - $dur);

                $free = true;
                for ($h = $start; $h < $start + $dur; $h++) {
                    $free = $free && ! isset($used["{$court->id}|$date|$h"]);
                }
                if (! $free) {
                    continue;
                }
                for ($h = $start; $h < $start + $dur; $h++) {
                    $used["{$court->id}|$date|$h"] = true;
                }

                $status = $d < 0
                    ? (mt_rand(1, 10) > 1 ? 'completed' : 'cancelled')
                    : (mt_rand(1, 5) > 1 ? 'confirmed' : 'pending');

                Booking::create([
                    'court_id' => $court->id,
                    'sport' => ['volei', 'futvolei', 'beachtenis'][mt_rand(0, 2)],
                    'customer_id' => $customers[mt_rand(0, $customers->count() - 1)]->id,
                    'booking_date' => $date,
                    'start_hour' => $start,
                    'end_hour' => $start + $dur,
                    'status' => $status,
                    'price' => $court->price_per_hour * $dur,
                ]);
            }
        }

        return [$courts, $customers];
    }

    /** Clientes fixos e turmas da empresa 1 (horários semanais que geram reservas nas próximas semanas). */
    private function seedRecurring(Collection $courts, Collection $customers, Branch $centro, Branch $praia): void
    {
        $service = app(ScheduleService::class);

        // [cliente, quadra, dia da semana (0=dom), início, fim, esporte]
        foreach ([[0, 0, 2, 19, 20, 'volei'], [1, 1, 4, 20, 21, 'futvolei'], [2, 3, 6, 9, 11, 'beachtenis']] as [$c, $q, $wd, $s, $e, $sport]) {
            $customers[$c]->update(['is_fixed' => true, 'notes' => 'Mensalista']);
            $service->create([
                'customer_id' => $customers[$c]->id, 'court_id' => $courts[$q]->id, 'sport' => $sport,
                'weekday' => $wd, 'start_hour' => $s, 'end_hour' => $e, 'price' => null,
            ]);
        }

        // [nome, esporte, filial, professor, capacidade, mensalidade, alunos, horários [quadra, dia, início, fim]]
        $groups = [
            ['Futevôlei Iniciante', 'futvolei', $centro, 'Rafael Mendes', 10, 280, [3, 4, 5, 6, 7], [[2, 1, 18, 19], [2, 3, 18, 19]]],
            ['Beach Tennis Adulto', 'beachtenis', $praia, 'Carla Nogueira', 8, 320, [8, 9, 10], [[4, 2, 19, 20], [4, 4, 19, 20]]],
            ['Vôlei Misto', 'volei', $praia, 'Diego Prado', 12, 240, [11, 0, 1, 3], [[3, 6, 14, 15]]],
        ];
        foreach ($groups as [$name, $sport, $branch, $instructor, $capacity, $fee, $students, $slots]) {
            $group = ClassGroup::create([
                'branch_id' => $branch->id, 'name' => $name, 'sport' => $sport, 'instructor' => $instructor,
                'capacity' => $capacity, 'monthly_fee' => $fee,
            ]);
            $group->students()->attach(collect($students)->map(fn ($i) => $customers[$i]->id)->all());
            foreach ($slots as [$q, $wd, $s, $e]) {
                $service->create([
                    'class_group_id' => $group->id, 'court_id' => $courts[$q]->id, 'sport' => $sport,
                    'weekday' => $wd, 'start_hour' => $s, 'end_hour' => $e, 'price' => 0,
                ]);
            }
        }
    }
}
