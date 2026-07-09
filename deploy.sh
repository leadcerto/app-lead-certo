#!/usr/bin/env bash
# Deploy do Lead Certo: local -> GitHub -> VPS.
# Trava: aborta se a VPS tiver qualquer arquivo fora do git (editado direto, sem passar
# pelo fluxo local -> commit -> push). Isso evita perder trabalho feito "escondido" na
# VPS e evita que um `git pull` sobrescreva silenciosamente uma edição direta.
set -euo pipefail

SSH_KEY=~/.ssh/leadcerto_vps
VPS_HOST=root@103.199.186.134
VPS_PATH=/var/www/leadcerto

echo "==> Verificando estado local..."
if [ -n "$(git status --porcelain)" ]; then
  echo "ERRO: há mudanças não commitadas localmente. Commit antes de fazer deploy." >&2
  exit 1
fi

echo "==> Enviando para o GitHub..."
git push origin main

echo "==> Verificando se a VPS está limpa (sem edições fora do git)..."
DIRTY=$(ssh -i "$SSH_KEY" "$VPS_HOST" "cd $VPS_PATH && git status --porcelain")
if [ -n "$DIRTY" ]; then
  echo "ERRO: a VPS tem arquivos modificados/não versionados fora do git:" >&2
  echo "$DIRTY" >&2
  echo "" >&2
  echo "Deploy abortado. Alguém mexeu direto na VPS. Antes de continuar:" >&2
  echo "  1. Entre na VPS e veja o que mudou (git diff / cat no arquivo)" >&2
  echo "  2. Se for algo bom: traga para local, commite e rode este script de novo" >&2
  echo "  3. Se for lixo/teste: git checkout -- <arquivo> ou git clean -fd (com cuidado)" >&2
  exit 1
fi

echo "==> Puxando na VPS..."
ssh -i "$SSH_KEY" "$VPS_HOST" "cd $VPS_PATH && git pull origin main"

echo "==> Rodando migrations..."
ssh -i "$SSH_KEY" "$VPS_HOST" "cd $VPS_PATH && php artisan migrate --force"

echo "==> Recompilando assets (Tailwind/Vite)..."
ssh -i "$SSH_KEY" "$VPS_HOST" "cd $VPS_PATH && npm ci --no-audit --no-fund && npm run build"

echo "==> Reconstruindo caches..."
ssh -i "$SSH_KEY" "$VPS_HOST" "cd $VPS_PATH && php artisan config:cache && php artisan route:cache && php artisan view:cache"

echo "==> Deploy concluído:"
ssh -i "$SSH_KEY" "$VPS_HOST" "cd $VPS_PATH && git log --oneline -1"
