#!/usr/bin/env bash
# Deploy do Lead Certo: local -> GitHub -> VPS.
# Trava: aborta se a VPS tiver qualquer arquivo fora do git (editado direto, sem passar
# pelo fluxo local -> commit -> push). Isso evita perder trabalho feito "escondido" na
# VPS e evita que um `git pull` sobrescreva silenciosamente uma edição direta.
set -euo pipefail

SSH_KEY=~/.ssh/leadcerto_vps
VPS_HOST=root@103.199.186.134
VPS_PATH=/var/www/leadcerto

# Modo de manutenção: fica marcado como "ligado" só depois que o `php artisan down`
# via ssh realmente é executado com sucesso. O trap abaixo garante que, se qualquer
# comando falhar depois disso (migration, build, cache), o site volta do ar sozinho
# em vez de ficar preso em manutenção — sem isso, um erro no meio do deploy deixaria
# todo mundo (inclusive os webhooks do WhatsApp) fora do ar até alguém notar e rodar
# `php artisan up` manualmente.
MANUTENCAO_LIGADA=0

restaurar_site_em_caso_de_saida() {
  if [ "$MANUTENCAO_LIGADA" -eq 1 ]; then
    echo "==> Restaurando site (php artisan up) antes de sair..." >&2
    ssh -i "$SSH_KEY" "$VPS_HOST" "cd $VPS_PATH && php artisan up" || true
    MANUTENCAO_LIGADA=0
  fi
}
trap restaurar_site_em_caso_de_saida EXIT

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

echo "==> Colocando a VPS em modo de manutenção (php artisan down)..."
# --retry=15 vai no header Retry-After da resposta 503 (instrução pros clientes/load
# balancer tentarem de novo em 15s) — não bloqueia nem espera nada aqui no script.
ssh -i "$SSH_KEY" "$VPS_HOST" "cd $VPS_PATH && php artisan down --retry=15"
MANUTENCAO_LIGADA=1

echo "==> Puxando na VPS..."
ssh -i "$SSH_KEY" "$VPS_HOST" "cd $VPS_PATH && git pull origin main"

echo "==> Rodando migrations..."
ssh -i "$SSH_KEY" "$VPS_HOST" "cd $VPS_PATH && php artisan migrate --force"

echo "==> Recompilando assets (Tailwind/Vite)..."
# O build busca fontes em fonts.bunny.net (plugin laravel-vite-plugin/fonts) — já falhou
# por timeout de rede transitório na VPS. Tenta até 3x antes de desistir.
BUILD_OK=0
for tentativa in 1 2 3; do
  if ssh -i "$SSH_KEY" "$VPS_HOST" "cd $VPS_PATH && npm ci --no-audit --no-fund && npm run build"; then
    BUILD_OK=1
    break
  fi
  echo "Build de assets falhou (tentativa $tentativa/3), tentando de novo em 5s..." >&2
  sleep 5
done
if [ "$BUILD_OK" -ne 1 ]; then
  echo "ERRO: build de assets (npm run build) falhou 3x. Deploy abortado ANTES do cache rebuild." >&2
  echo "O site continua no ar com os assets antigos (build não foi corrompido). Rode ./deploy.sh de novo quando a rede estabilizar." >&2
  exit 1
fi

echo "==> Reconstruindo caches..."
ssh -i "$SSH_KEY" "$VPS_HOST" "cd $VPS_PATH && php artisan config:cache && php artisan route:cache && php artisan view:cache"

echo "==> Tirando a VPS do modo de manutenção (php artisan up)..."
ssh -i "$SSH_KEY" "$VPS_HOST" "cd $VPS_PATH && php artisan up"
MANUTENCAO_LIGADA=0

echo "==> Deploy concluído:"
ssh -i "$SSH_KEY" "$VPS_HOST" "cd $VPS_PATH && git log --oneline -1"
