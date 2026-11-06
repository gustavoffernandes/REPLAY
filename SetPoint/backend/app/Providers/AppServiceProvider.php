<?php

namespace App\Providers;

use App\Services\Payments\PaymentGateway;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Gateway de pagamento escolhido em config/payments.php (PAYMENT_DRIVER).
        $this->app->bind(PaymentGateway::class, function ($app) {
            $driver = config('payments.driver');
            $class = config("payments.drivers.$driver") ?? throw new \RuntimeException("Gateway de pagamento desconhecido: $driver");

            return $app->make($class);
        });
    }

    public function boot(): void
    {
        $this->configureRateLimits();
        $this->configurePasswordReset();
    }

    /**
     * Limitadores nomeados e independentes (o `throttle:N,M` genérico compartilha um único contador por IP entre rotas).
     * Os valores são por minuto, salvo indicação.
     */
    private function configureRateLimits(): void
    {
        // Teto por IP para tentativas de login (o bloqueio por conta/IP após falhas fica no AuthController).
        RateLimiter::for('login', fn (Request $r) => Limit::perMinute(20)->by($r->ip()));

        // Recuperação de senha: poucas por IP e poucas por e-mail (evita spam de e-mails e enumeração).
        RateLimiter::for('forgot-password', fn (Request $r) => [
            Limit::perMinute(5)->by('ip|' . $r->ip()),
            Limit::perHour(5)->by('email|' . strtolower((string) $r->input('email'))),
        ]);
        RateLimiter::for('reset-password', fn (Request $r) => Limit::perMinute(10)->by($r->ip()));

        // Troca de senha logado: protege contra tentativa de adivinhar a senha atual.
        RateLimiter::for('sensitive', fn (Request $r) => Limit::perMinute(5)->by($r->user()?->id ?: $r->ip()));

        // API autenticada: leitura folgada, escrita mais restrita, por usuário.
        RateLimiter::for('api', function (Request $r) {
            $who = $r->user()?->id ?: $r->ip();

            return $r->isMethodSafe()
                ? Limit::perMinute(300)->by("r|$who")
                : Limit::perMinute(90)->by("w|$who");
        });

        // Agendamento online (público).
        RateLimiter::for('public-read', fn (Request $r) => Limit::perMinute(120)->by($r->ip()));
        RateLimiter::for('public-book', fn (Request $r) => Limit::perMinute(10)->by($r->ip()));

        // Replays: envio de vídeos pelo serviço de captura e reprodução (o player faz várias requisições "Range").
        RateLimiter::for('replay-upload', fn (Request $r) => Limit::perMinute(30)->by($r->ip()));
        RateLimiter::for('replay-video', fn (Request $r) => Limit::perMinute(600)->by($r->ip()));
    }

    /** O link do e-mail aponta para a tela do front e o texto fica em português. */
    private function configurePasswordReset(): void
    {
        // Atenção: toMailUsing recebe o TOKEN (não a URL); o link para a tela do front é montado aqui.
        ResetPassword::toMailUsing(function ($notifiable, string $token) {
            $url = rtrim(config('app.frontend_url'), '/')
                . '/redefinir-senha?token=' . $token . '&email=' . urlencode($notifiable->getEmailForPasswordReset());
            $minutes = config('auth.passwords.' . config('auth.defaults.passwords') . '.expire');

            return (new MailMessage)
                ->subject('Redefinição de senha — SetPoint')
                ->greeting('Olá, ' . $notifiable->name . '!')
                ->line('Recebemos um pedido para redefinir a senha da sua conta.')
                ->action('Criar nova senha', $url)
                ->line("Este link expira em {$minutes} minutos e só pode ser usado uma vez.")
                ->line('Se você não fez este pedido, ignore este e-mail: sua senha continua a mesma.')
                ->salutation('Equipe SetPoint');
        });
    }
}
