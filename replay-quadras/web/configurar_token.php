<?php

/**
 * Gera o token compartilhado entre o Laravel e o serviço de captura.
 * Usado pelo instalar.bat (Windows), que não tem perl/bash.
 *
 * Uso: php configurar_token.php <pasta_do_portal> <caminho_do_captura_env>
 */

if ($argc < 3) {
    fwrite(STDERR, "Uso: php configurar_token.php <portal> <captura/.env>\n");
    exit(1);
}

[, $portal, $capturaEnv] = $argv;
$portalEnv = rtrim($portal, '/\\').DIRECTORY_SEPARATOR.'.env';

if (! is_file($portalEnv)) {
    fwrite(STDERR, "Arquivo nao encontrado: $portalEnv\n");
    exit(1);
}

$conteudo = file_get_contents($portalEnv);
if (preg_match('/^REPLAY_TOKEN=(.+)$/m', $conteudo, $m)) {
    $token = trim($m[1]);
} else {
    $token = bin2hex(random_bytes(24));
    $conteudo = rtrim($conteudo)."\n\n# --- Sistema de replays ---\nCAPTURA_URL=http://127.0.0.1:8765\nREPLAY_TOKEN=$token\n";
    file_put_contents($portalEnv, $conteudo);
}

if (! is_file($capturaEnv)) {
    $exemplo = dirname($capturaEnv).DIRECTORY_SEPARATOR.'.env.example';
    copy($exemplo, $capturaEnv);
}

$captura = file_get_contents($capturaEnv);
$captura = preg_replace('/^REPLAY_TOKEN=.*$/m', 'REPLAY_TOKEN='.$token, $captura);
file_put_contents($capturaEnv, $captura);

echo "Token gravado em $portalEnv e $capturaEnv\n";
