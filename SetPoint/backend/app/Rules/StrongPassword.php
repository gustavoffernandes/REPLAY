<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Http;

/**
 * Política de senha forte (mesmas regras mostradas no front):
 * 10 a 64 caracteres, minúscula, maiúscula, número e símbolo, sem sequências óbvias
 * e sem conter o e-mail. Opcionalmente confere vazamentos (PASSWORD_CHECK_BREACHED=true).
 */
class StrongPassword implements ValidationRule
{
    public const MIN = 10;

    public const MAX = 64; // bcrypt só considera os primeiros 72 bytes

    private const COMMON = ['password', 'senha', 'qwerty', '12345678', '123456789', 'abcdefgh', 'admin', 'setpoint', 'letmein', 'iloveyou'];

    public function __construct(private ?string $email = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $v = (string) $value;
        $missing = [];

        if (mb_strlen($v) < self::MIN) {
            $missing[] = 'no mínimo ' . self::MIN . ' caracteres';
        }
        if (! preg_match('/\p{Ll}/u', $v)) {
            $missing[] = 'uma letra minúscula';
        }
        if (! preg_match('/\p{Lu}/u', $v)) {
            $missing[] = 'uma letra maiúscula';
        }
        if (! preg_match('/\d/', $v)) {
            $missing[] = 'um número';
        }
        if (! preg_match('/[^\p{L}\d]/u', $v)) {
            $missing[] = 'um símbolo (ex.: ! @ # $ %)';
        }

        if ($missing) {
            $fail('A senha precisa ter ' . implode(', ', $missing) . '.');

            return;
        }
        if (mb_strlen($v) > self::MAX) {
            $fail('A senha pode ter no máximo ' . self::MAX . ' caracteres.');

            return;
        }

        $lower = mb_strtolower($v);
        foreach (self::COMMON as $word) {
            if (str_contains($lower, $word)) {
                $fail('Evite senhas comuns como "' . $word . '". Escolha algo menos previsível.');

                return;
            }
        }
        if (preg_match('/(.)\1{3,}/u', $v)) {
            $fail('Evite repetir o mesmo caractere várias vezes seguidas.');

            return;
        }
        if ($this->email && ($local = strstr(mb_strtolower($this->email), '@', true)) && mb_strlen($local) >= 4 && str_contains($lower, $local)) {
            $fail('A senha não pode conter o seu e-mail.');

            return;
        }
        if (config('app.check_breached_passwords') && $this->isBreached($v)) {
            $fail('Esta senha apareceu em vazamentos de dados. Escolha outra.');
        }
    }

    /** Consulta k-anonimato do Have I Been Pwned (só os 5 primeiros caracteres do hash saem do servidor). Falha aberta. */
    private function isBreached(string $password): bool
    {
        try {
            $sha = strtoupper(sha1($password));
            $body = Http::timeout(3)->get('https://api.pwnedpasswords.com/range/' . substr($sha, 0, 5))->body();

            return (bool) preg_match('/^' . substr($sha, 5) . ':/mi', $body);
        } catch (\Throwable) {
            return false;
        }
    }
}
