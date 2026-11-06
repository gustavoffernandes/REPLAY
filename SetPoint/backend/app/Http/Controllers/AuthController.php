<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\User;
use App\Rules\StrongPassword;
use App\Support\TenantContext;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /** Falhas de login permitidas por e-mail+IP antes do bloqueio temporário. */
    private const MAX_FAILURES = 5;

    private const LOCK_SECONDS = 900; // 15 min

    public function login(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:120'], 'password' => ['required', 'string', 'max:255']]);

        $key = 'login-fail|' . strtolower($data['email']) . '|' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, self::MAX_FAILURES)) {
            $secs = RateLimiter::availableIn($key);

            return response()->json([
                'message' => 'Muitas tentativas de login. Tente novamente em ' . max(1, (int) ceil($secs / 60)) . ' minuto(s).',
            ], 429)->header('Retry-After', $secs);
        }

        $user = User::where('email', $data['email'])->first();
        // Confere o hash mesmo sem usuário, para o tempo de resposta não revelar se o e-mail existe
        $valid = Hash::check($data['password'], $user->password ?? '$2y$12$usesomesillystringforsaltthatdoesnotexistxxxxxxxxxxxxxxxxxxx') && $user;

        if (! $valid) {
            RateLimiter::hit($key, self::LOCK_SECONDS);
            throw ValidationException::withMessages(['email' => 'E-mail ou senha incorretos']);
        }
        RateLimiter::clear($key);
        $this->ensureActive($user);

        return ['token' => $user->createToken('web')->plainTextToken] + $this->payload($user);
    }

    public function me(Request $request)
    {
        $this->ensureActive($request->user());

        return $this->payload($request->user());
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return ['ok' => true];
    }

    /** Resposta sempre igual, exista o e-mail ou não (não revela quais e-mails estão cadastrados). */
    public function forgotPassword(Request $request)
    {
        $email = $request->validate(['email' => ['required', 'email', 'max:120']])['email'];

        $user = User::where('email', $email)->first();
        if ($user && $user->active && ($user->isSuperAdmin() || $user->company()->withoutGlobalScopes()->where('active', true)->exists())) {
            Password::sendResetLink(['email' => $email]);
        }

        return ['message' => 'Se o e-mail estiver cadastrado, enviaremos as instruções para redefinir a senha.'];
    }

    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', new StrongPassword($request->input('email'))],
        ]);

        $status = Password::reset($data + ['password_confirmation' => $request->input('password_confirmation')], function (User $user, string $password) {
            $user->forceFill(['password' => $password])->save();
            $user->tokens()->delete(); // encerra todas as sessões abertas
            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'Link inválido ou expirado. Solicite um novo.']);
        }

        return ['message' => 'Senha redefinida. Entre com a nova senha.'];
    }

    public function changePassword(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', new StrongPassword($user->email)],
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'A senha atual está incorreta.']);
        }
        if (Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => 'A nova senha precisa ser diferente da atual.']);
        }

        $user->update(['password' => $data['password']]);
        // Mantém só a sessão atual; derruba as demais (outros dispositivos)
        $user->tokens()->where('id', '!=', $user->currentAccessToken()->id)->delete();

        return ['ok' => true];
    }

    private function ensureActive(User $user): void
    {
        $user->active || abort(403, 'Usuário inativo');

        if (! $user->isSuperAdmin() && ! $user->company()->withoutGlobalScopes()->where('active', true)->exists()) {
            abort(403, 'Empresa inativa. Entre em contato com o suporte.');
        }
    }

    /** Dados da sessão: usuário, permissões e filiais acessíveis (para o seletor de filial). */
    private function payload(User $user): array
    {
        $branches = Branch::withoutGlobalScopes()
            ->with('company:id,name')
            ->whereIn('id', (new TenantContext($user))->accessibleBranchIds())
            ->orderBy('company_id')->orderBy('name')
            ->get(['id', 'company_id', 'name', 'city', 'active'])
            ->map(fn ($b) => ['id' => $b->id, 'name' => $b->name, 'city' => $b->city, 'active' => $b->active,
                'company_id' => $b->company_id, 'company_name' => $b->company->name]);

        return [
            'user' => [
                'id' => $user->id, 'name' => $user->name, 'email' => $user->email,
                'role' => $user->role, 'role_label' => $user->roleName(), 'role_scope' => $user->roleScope(),
                'company' => $user->company()->withoutGlobalScopes()->first(['id', 'name']),
            ],
            'permissions' => $user->permissionList(),
            'branches' => $branches,
        ];
    }
}
