<?php

namespace App\Support;

/**
 * Catálogo de permissões (fixo no código, pois cada uma protege rotas/ações reais) e
 * os padrões dos papéis do sistema. Quais permissões cada papel tem fica no banco
 * e é editado pelo administrador.
 *
 * Grupos "platform" (empresas e papéis) são exclusivos do administrador da plataforma
 * e nunca podem ser concedidos a outros papéis.
 */
class Permissions
{
    public const CATALOG = [
        ['key' => 'dashboard', 'label' => 'Dashboard', 'items' => [
            ['dashboard.view', 'Ver o dashboard'],
        ]],
        ['key' => 'bookings', 'label' => 'Agenda e reservas', 'items' => [
            ['bookings.view', 'Ver agenda e reservas'],
            ['bookings.create', 'Criar reservas no balcão'],
            ['bookings.confirm', 'Confirmar, concluir e confirmar sinal'],
            ['bookings.cancel', 'Cancelar reservas'],
        ]],
        ['key' => 'customers', 'label' => 'Clientes', 'items' => [
            ['customers.view', 'Ver clientes'],
            ['customers.create', 'Cadastrar clientes'],
            ['customers.update', 'Editar clientes'],
            ['customers.recurring', 'Gerenciar horários fixos (mensalistas)'],
        ]],
        ['key' => 'classes', 'label' => 'Turmas', 'items' => [
            ['classes.view', 'Ver turmas'],
            ['classes.create', 'Criar turmas'],
            ['classes.update', 'Editar turmas'],
            ['classes.delete', 'Excluir turmas'],
            ['classes.students', 'Matricular e remover alunos'],
            ['classes.schedules', 'Gerenciar horários das turmas'],
        ]],
        ['key' => 'courts', 'label' => 'Quadras', 'items' => [
            ['courts.view', 'Ver quadras'],
            ['courts.create', 'Cadastrar quadras'],
            ['courts.update', 'Editar e ativar/desativar quadras'],
        ]],
        ['key' => 'replays', 'label' => 'Replays', 'items' => [
            ['replays.view', 'Ver e baixar replays'],
            ['replays.create', 'Salvar replays (botoeira virtual)'],
            ['replays.delete', 'Excluir replays'],
        ]],
        ['key' => 'branches', 'label' => 'Filiais', 'items' => [
            ['branches.view', 'Ver filiais'],
            ['branches.create', 'Criar filiais'],
            ['branches.update', 'Editar e ativar/desativar filiais'],
        ]],
        ['key' => 'users', 'label' => 'Usuários', 'items' => [
            ['users.view', 'Ver usuários'],
            ['users.create', 'Criar usuários'],
            ['users.update', 'Editar e ativar/desativar usuários'],
        ]],
        ['key' => 'companies', 'label' => 'Empresas', 'platform' => true, 'items' => [
            ['companies.view', 'Ver empresas'],
            ['companies.create', 'Criar empresas'],
            ['companies.update', 'Editar empresas'],
        ]],
        ['key' => 'roles', 'label' => 'Papéis e permissões', 'platform' => true, 'items' => [
            ['roles.view', 'Ver papéis e permissões'],
            ['roles.manage', 'Criar, editar e excluir papéis'],
        ]],
    ];

    /** Todas as chaves de permissão. */
    public static function all(): array
    {
        return collect(self::CATALOG)->flatMap(fn ($g) => array_column($g['items'], 0))->values()->all();
    }

    /** Permissões que podem ser concedidas a papéis comuns (fora dos grupos exclusivos do administrador). */
    public static function assignable(): array
    {
        return collect(self::CATALOG)->reject(fn ($g) => ! empty($g['platform']))
            ->flatMap(fn ($g) => array_column($g['items'], 0))->values()->all();
    }

    /** Garante que quem recebe qualquer ação de uma área também possa vê-la. */
    public static function withDependencies(array $permissions): array
    {
        $set = array_fill_keys($permissions, true);
        foreach (self::CATALOG as $group) {
            $keys = array_column($group['items'], 0);
            if (array_intersect($keys, $permissions)) {
                $set[$group['key'] . '.view'] = true;
            }
        }

        return array_values(array_intersect(self::all(), array_keys($set)));
    }

    /** Papéis do sistema: chave => [nome, descrição, escopo, permissões padrão]. */
    public static function systemRoles(): array
    {
        $ops = [
            'dashboard.view',
            'bookings.view', 'bookings.create', 'bookings.confirm', 'bookings.cancel',
            'customers.view', 'customers.create', 'customers.update', 'customers.recurring',
        ];
        $classes = ['classes.view', 'classes.create', 'classes.update', 'classes.delete', 'classes.students', 'classes.schedules'];
        $courts = ['courts.view', 'courts.create', 'courts.update'];
        $replays = ['replays.view', 'replays.create', 'replays.delete'];

        return [
            'super_admin' => ['Administrador', 'Acesso total à plataforma, todas as empresas e filiais.', 'all', []],
            'owner' => ['Proprietário', 'Dono da empresa contratante: todas as filiais dela.', 'company', array_merge(
                $ops, $classes, $courts, $replays,
                ['branches.view', 'branches.create', 'branches.update', 'users.view', 'users.create', 'users.update'],
            )],
            'manager' => ['Gerente', 'Opera as filiais atribuídas.', 'branches', array_merge($ops, $classes, $courts, $replays)],
            'staff' => ['Atendente', 'Agenda, reservas e clientes das filiais atribuídas.', 'branches', array_merge($ops, ['classes.view', 'courts.view', 'replays.view', 'replays.create'])],
        ];
    }
}
