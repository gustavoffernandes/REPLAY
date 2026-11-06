#!/usr/bin/env bash
# Sobe o portal em http://127.0.0.1:8000
#
# Por que não "php artisan serve"? O PHP vem limitado a uploads de 2 MB e um
# replay de 30s em Full HD tem dezenas de MB. O artisan serve não repassa
# esses limites ao servidor, então chamamos o servidor embutido do PHP
# diretamente, com o mesmo roteador que o artisan usa.
set -euo pipefail
cd "$(dirname "$0")/portal/public"

HOST="${HOST:-127.0.0.1}"
PORTA="${PORTA:-8000}"
echo "Portal em http://$HOST:$PORTA  (Ctrl+C para parar)"
exec php \
  -d upload_max_filesize=512M \
  -d post_max_size=520M \
  -d memory_limit=512M \
  -d max_execution_time=300 \
  -S "$HOST:$PORTA" \
  ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
