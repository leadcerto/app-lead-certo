<#
.SYNOPSIS
    Deploy Seguro do Lead Certo para VPS de Produção (PowerShell / Windows).
.DESCRIPTION
    Executa o ciclo completo de deploy com travas de segurança:
    1. Valida se está na pasta correta do Lead Certo.
    2. Realiza o commit e push local para o GitHub (origin/main).
    3. Conecta via SSH na VPS oficial (103.199.186.134).
    4. Atualiza o código via git pull.
    5. Executa migrations pendentes (--force).
    6. Recompila caches do Laravel (config, routes, views).
    7. Reinicia os workers de fila do Supervisor (queue:restart).
    8. Restaura o site do modo de manutenção.
.EXAMPLE
    .\deploy.ps1
    .\deploy.ps1 -Mensagem "fix: correcao no disparo de sequencia do kanban"
#>

[CmdletBinding()]
param (
    [Parameter(Position = 0)]
    [string]$Mensagem = "deploy: atualizacoes do sistema Lead Certo"
)

$ErrorActionPreference = "Stop"

# --- TRAVA DE SEGURANÇA 1: Validação de Diretório ---
$CurrentDir = (Get-Location).Path
$ArtisanPath = Join-Path $CurrentDir "artisan"

if (-not (Test-Path $ArtisanPath)) {
    Write-Host "`n[ERRO CRÍTICO] Você NÃO está na pasta do Lead Certo (artisan não encontrado)." -ForegroundColor Red
    Write-Host "Execute o comando a partir de: leadcerto\core\app-painel`n" -ForegroundColor Yellow
    exit 1
}

$SSH_KEY = "$HOME\.ssh\leadcerto_vps"
$VPS_HOST = "root@103.199.186.134"
$VPS_PATH = "/var/www/leadcerto"

Write-Host "`n==================================================" -ForegroundColor Cyan
Write-Host " [DEPLOY SEGURO] LEAD CERTO PRODUCAO" -ForegroundColor Cyan
Write-Host " Servidor: $VPS_HOST ($VPS_PATH)" -ForegroundColor Cyan
Write-Host "==================================================`n" -ForegroundColor Cyan

# --- ETAPA 1: Commit e Push Local ---
Write-Host "==> [1/3] Verificando alteracoes locais no Git..." -ForegroundColor Yellow
$Status = git status --porcelain

if ($Status) {
    Write-Host "--> Salvando alteracoes locais: '$Mensagem'..." -ForegroundColor Gray
    git add .
    git commit -m "$Mensagem"
} else {
    Write-Host "--> Nenhuma alteracao pendente de commit local." -ForegroundColor Gray
}

Write-Host "--> Enviando para o GitHub (origin main)..." -ForegroundColor Yellow
git push origin main

# --- ETAPA 2: Deploy na VPS via SSH ---
Write-Host "`n==> [2/3] Conectando na VPS e atualizando codigo..." -ForegroundColor Yellow

$RemoteCmd = "cd $VPS_PATH && php artisan down --retry=15 && git pull origin main && php artisan migrate --force && php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan queue:restart && php artisan up && git log -1 --oneline"

ssh -i $SSH_KEY $VPS_HOST $RemoteCmd

if ($LASTEXITCODE -eq 0) {
    Write-Host "`n==================================================" -ForegroundColor Green
    Write-Host " [SUCESSO] DEPLOY CONCLUIDO NA VPS DO LEAD CERTO!" -ForegroundColor Green
    Write-Host " URL: https://app.leadcerto.app.br" -ForegroundColor Green
    Write-Host "==================================================`n" -ForegroundColor Green
} else {
    Write-Host "`n[ERRO] Ocorreu uma falha durante a execucao na VPS." -ForegroundColor Red
    exit 1
}
