<?php

return [
    /*
    | Gateway de pagamento do sinal. "fake" gera um Pix simulado (somente desenvolvimento):
    | permite testar o fluxo completo sem cobrança real. Para produção, implemente
    | App\Services\Payments\PaymentGateway para o provedor escolhido e registre aqui.
    */
    'driver' => env('PAYMENT_DRIVER', 'fake'),

    'drivers' => [
        'fake' => App\Services\Payments\FakeGateway::class,
    ],

    // Minutos que o horário fica reservado aguardando o pagamento do sinal.
    'hold_minutes' => (int) env('PAYMENT_HOLD_MINUTES', 15),
];
