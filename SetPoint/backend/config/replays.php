<?php

return [
    // Tamanho máximo do vídeo recebido do serviço de captura, em KB (512 MB).
    // O PHP também precisa aceitar: upload_max_filesize e post_max_size no php.ini.
    'max_upload_kb' => (int) env('REPLAY_MAX_UPLOAD_KB', 512000),

    // Validade dos links assinados dos vídeos (minutos). A tela gera links novos a cada atualização.
    'video_url_ttl' => (int) env('REPLAY_VIDEO_URL_TTL', 180),

    // Tempo máximo para falar com o serviço de captura (segundos).
    'capture_timeout' => (int) env('REPLAY_CAPTURE_TIMEOUT', 5),
];
