<?php

return [
    // Endereço do serviço Python de captura (gatilho HTTP). Mesmo valor de
    // GATILHO_HOST/GATILHO_PORTA no captura/.env.
    'captura_url' => env('CAPTURA_URL', 'http://127.0.0.1:8765'),

    // Token compartilhado com o serviço Python (REPLAY_TOKEN nos dois .env).
    'token' => env('REPLAY_TOKEN', ''),

    // Tamanho máximo aceito no upload, em KB (512 MB).
    'tamanho_maximo_kb' => (int) env('REPLAY_TAMANHO_MAXIMO_KB', 512000),
];
