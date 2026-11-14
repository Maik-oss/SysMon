@echo off
setlocal
cd /d "%~dp0"
python "%~dp0collector.py" --status --corrigir
if errorlevel 1 (
    echo.
    echo ---------- Diagnostico da conexao com o painel ----------
    python "%~dp0collector.py" --diagnostico
)
echo.
pause
