@echo off
REM Sobe o portal em http://127.0.0.1:8000
REM Nao usa "php artisan serve" porque o PHP vem limitado a uploads de 2 MB e
REM um replay em Full HD passa disso. Aqui os limites sao liberados (512 MB).
cd /d "%~dp0portal\public"
if errorlevel 1 (
  echo Pasta portal nao encontrada. Rode instalar.bat primeiro.
  exit /b 1
)

echo Portal em http://127.0.0.1:8000  (Ctrl+C para parar)
php -d upload_max_filesize=512M -d post_max_size=520M -d memory_limit=512M -d max_execution_time=300 -S 127.0.0.1:8000 ..\vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php
