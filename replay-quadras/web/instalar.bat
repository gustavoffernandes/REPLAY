@echo off
REM Cria o portal Laravel e aplica os arquivos do projeto (pasta overlay\).
REM Pre-requisitos: PHP 8.2+ e Composer no PATH.   Uso: instalar.bat
cd /d "%~dp0"

where php >nul 2>nul
if errorlevel 1 (
  echo PHP nao encontrado. Instale o PHP e abra um NOVO Prompt de Comando.
  exit /b 1
)
where composer >nul 2>nul
if errorlevel 1 (
  echo Composer nao encontrado. Instale o Composer e abra um NOVO Prompt de Comando.
  exit /b 1
)

if not exist portal (
  echo ^>^> Criando projeto Laravel em web\portal ...
  call composer create-project "laravel/laravel:^12.0" portal
  if errorlevel 1 exit /b 1
)

echo ^>^> Copiando arquivos do sistema de replays (overlay) ...
xcopy overlay portal /E /Y /Q >nul
if errorlevel 1 exit /b 1

echo ^>^> Gerando token compartilhado ...
php configurar_token.php portal ..\captura\.env
if errorlevel 1 exit /b 1

cd portal
echo ^>^> Banco de dados e link publico dos videos ...
php artisan migrate --force
if errorlevel 1 exit /b 1
php artisan storage:link

echo.
echo Pronto! Token gravado em web\portal\.env e captura\.env.
echo Suba o portal com:  servir.bat
