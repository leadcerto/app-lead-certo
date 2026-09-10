@echo off
echo Corrigindo permissoes do servidor (log e storage)...
ssh -i "%USERPROFILE%\.ssh\leadcerto_vps" root@103.199.186.134 "cd /var/www/leadcerto && chown -R www-data:www-data storage bootstrap/cache && chmod -R 775 storage bootstrap/cache"

echo.
echo Limpando o log antigo para podermos ver o erro real...
ssh -i "%USERPROFILE%\.ssh\leadcerto_vps" root@103.199.186.134 "echo '' > /var/www/leadcerto/storage/logs/laravel.log"

echo.
echo PRONTO! AS PERMISSOES FORAM CORRIGIDAS!
echo Agora, va no sistema, clique no botao azul "Publicar Agora" para gerar o erro novamente.
echo Depois que der o erro 500, volte aqui e pressione ENTER.
pause

echo.
echo Buscando o erro de verdade...
ssh -i "%USERPROFILE%\.ssh\leadcerto_vps" root@103.199.186.134 "tail -n 50 /var/www/leadcerto/storage/logs/laravel.log"
echo.
pause
