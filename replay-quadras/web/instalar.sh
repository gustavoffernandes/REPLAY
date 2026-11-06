#!/usr/bin/env bash
# Cria o portal Laravel e aplica os arquivos do projeto (pasta overlay/).
# Pré-requisitos: PHP 8.2+ e Composer.   Uso:  ./instalar.sh
set -euo pipefail

cd "$(dirname "$0")"
DESTINO="portal"

command -v php >/dev/null      || { echo "PHP não encontrado (macOS: brew install php)"; exit 1; }
command -v composer >/dev/null || { echo "Composer não encontrado (macOS: brew install composer)"; exit 1; }

if [ ! -d "$DESTINO" ]; then
  echo ">> Criando projeto Laravel em web/$DESTINO ..."
  composer create-project laravel/laravel:^12.0 "$DESTINO"
fi

echo ">> Copiando arquivos do sistema de replays (overlay/) ..."
cp -R overlay/. "$DESTINO"/

cd "$DESTINO"

# Token compartilhado entre o Laravel e o serviço Python.
if ! grep -q '^REPLAY_TOKEN=' .env; then
  TOKEN="$(php -r 'echo bin2hex(random_bytes(24));')"
  {
    echo ""
    echo "# --- Sistema de replays ---"
    echo "CAPTURA_URL=http://127.0.0.1:8765"
    echo "REPLAY_TOKEN=$TOKEN"
  } >> .env
else
  TOKEN="$(grep '^REPLAY_TOKEN=' .env | cut -d= -f2-)"
fi

# Leva o mesmo token para o .env do serviço de captura.
CAPTURA_ENV="../../captura/.env"
if [ ! -f "$CAPTURA_ENV" ]; then
  cp ../../captura/.env.example "$CAPTURA_ENV"
fi
TOKEN="$TOKEN" perl -pi -e 's/^REPLAY_TOKEN=.*/REPLAY_TOKEN=$ENV{TOKEN}/' "$CAPTURA_ENV"

echo ">> Banco de dados e link público dos vídeos ..."
php artisan migrate --force
php artisan storage:link 2>/dev/null || true

echo ""
echo "Pronto! Token gravado em web/portal/.env e captura/.env."
echo "Suba o portal com:  ./servir.sh"
