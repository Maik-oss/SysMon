@echo off
REM ============================================================
REM Instalador do agente SysMon.
REM
REM O que este script faz:
REM   1) Instala as dependencias Python (wmi, pywin32, mysql-connector)
REM   2) Cria uma Tarefa Agendada no Windows para iniciar o agente
REM      sozinho, toda vez que o computador ligar - sem precisar
REM      abrir nada manualmente depois disso.
REM
REM Recomendado: clique com o botao direito neste arquivo e escolha
REM "Executar como administrador" (necessario para o passo 2 rodar
REM mesmo sem ninguem logado no Windows).
REM ============================================================

setlocal
cd /d "%~dp0"

echo.
echo [1/3] Instalando dependencias Python...
python -m pip install -r requirements.txt
if errorlevel 1 (
    echo.
    echo ERRO ao instalar dependencias. Verifique se o Python esta
    echo instalado e marcado no PATH ^(reinstale marcando "Add to PATH"
    echo se necessario^).
    pause
    exit /b 1
)

echo.
echo [2/3] Localizando o interpretador Python...
set "PYW="
for /f "delims=" %%P in ('where pythonw 2^>nul') do (
    if not defined PYW set "PYW=%%P"
)
if not defined PYW (
    echo Nao encontrei pythonw.exe no PATH.
    echo Instale o Python em https://python.org marcando "Add python.exe to PATH".
    pause
    exit /b 1
)
echo Encontrado: %PYW%

echo.
echo [3/3] Registrando tarefa agendada "SysMonAgent"...
schtasks /create /tn "SysMonAgent" /tr "\"%PYW%\" \"%~dp0collector.py\"" /sc onstart /rl highest /f

if errorlevel 1 (
    echo.
    echo Nao foi possivel criar a tarefa automaticamente. Isso costuma
    echo acontecer se este script nao foi executado como Administrador.
    echo Voce ainda pode rodar o agente manualmente com:
    echo     python collector.py
) else (
    echo.
    echo Pronto! O agente vai iniciar sozinho a cada vez que o Windows
    echo ligar. Para testar agora, sem reiniciar o PC, rode:
    echo     schtasks /run /tn "SysMonAgent"
    echo Para conferir se esta rodando, abra o Gerenciador de Tarefas
    echo e procure por "pythonw.exe", ou veja o dashboard no navegador.
)

echo.
pause
