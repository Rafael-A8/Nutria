#!/bin/bash
set -e

echo "=== Nutria - Deploy de Produção ==="

# Build e sobe os containers
echo "[1/6] Subindo containers..."
docker compose -f compose.prod.yaml up -d --build

# Instala dependências PHP (sem dev)
echo "[2/6] Instalando dependências PHP..."
docker compose -f compose.prod.yaml exec app composer install --no-dev --optimize-autoloader --no-interaction

# Instala dependências Node e builda assets
echo "[3/6] Buildando assets frontend..."
docker compose -f compose.prod.yaml exec app npm ci
docker compose -f compose.prod.yaml exec app npm run build

# Roda migrations
echo "[4/6] Rodando migrations..."
docker compose -f compose.prod.yaml exec app php artisan migrate --force

# Otimiza caches (config, routes, views, events)
echo "[5/6] Otimizando caches..."
docker compose -f compose.prod.yaml exec app php artisan optimize

# Gera rotas Wayfinder
echo "[6/6] Gerando rotas Wayfinder..."
docker compose -f compose.prod.yaml exec app php artisan wayfinder:generate

echo ""
echo "=== Deploy concluído! ==="
echo "App rodando em: https://nutria.wincodev.com.br"
echo ""
echo "Comandos úteis:"
echo "  docker compose -f compose.prod.yaml logs -f app    # Ver logs da app"
echo "  docker compose -f compose.prod.yaml exec app supervisorctl status  # Status dos processos"
echo "  docker compose -f compose.prod.yaml exec app php artisan queue:restart  # Reiniciar queue worker"
