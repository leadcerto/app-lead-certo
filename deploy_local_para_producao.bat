@echo off
echo ===================================================
echo   INICIANDO DEPLOY AUTOMATICO - LEAD CERTO
echo ===================================================
echo.

cd /d "c:\Users\PICHAU\Desktop\- LEAD CERTO\Antigravity\leadcerto\core\app-painel"

echo [1/3] Salvando alteracoes no Git...
git add .
git commit -m "fix: remove segunda leva de escopos invalidos da Meta (branded content, marketplace discovery, threads_business_basic, oembed_read)"
git push

echo.
echo [2/3] Atualizando o servidor de producao (VPS)...
ssh -i "%USERPROFILE%\.ssh\leadcerto_vps" root@103.199.186.134 "cd /var/www/leadcerto && git pull origin main && php artisan migrate --force && php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan queue:restart"

echo.
echo [3/3] Corrigindo contatos antigos no Google Contacts...
ssh -i "%USERPROFILE%\.ssh\leadcerto_vps" root@103.199.186.134 "cd /var/www/leadcerto && php artisan google:enriquecer"

echo.
echo ===================================================
echo   DEPLOY FINALIZADO!
echo ===================================================
pause
