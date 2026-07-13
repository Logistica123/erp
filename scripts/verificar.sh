#!/usr/bin/env bash
#
# Verificación completa del ERP — tarea 2.2 del plan de remediación.
#
# ÚNICO comando confiable antes de un commit/deploy:
#
#     ./scripts/verificar.sh            # backend + frontend
#     ./scripts/verificar.sh backend    # solo suite PHP
#     ./scripts/verificar.sh frontend   # solo build con chequeo de tipos
#
# Diseñado para FALLAR DE VERDAD (lecciones de la auditoría 2026-07-12):
#  - `php artisan test` SIN pipe: un `| tail` se tragaba el exit code y
#    reportaba verde con la suite rota.
#  - El frontend se verifica con `npm run build` (= `tsc -b` + vite build).
#    `tsc --noEmit` es VACUO con project references — pasaba con 11 errores
#    de tipos que rompían 5 páginas en runtime.
#  - `set -euo pipefail`: el primer paso que falle corta el script con su
#    exit code real.
#
# La suite debe correr verde ADEMÁS contra el clon del esquema de prod
# (servidor dev). Para eso:
#     rsync -az tests/ root@VPS:/var/www/erp-dev-backend/tests/
#     ssh root@VPS 'cd /var/www/erp-dev-backend && sudo -u www-data HOME=/tmp php artisan test'

set -euo pipefail
cd "$(dirname "$0")/.."

MODO="${1:-todo}"

if [[ "$MODO" == "todo" || "$MODO" == "backend" ]]; then
    echo "==> Suite backend (php artisan test, sin pipe)"
    php artisan test
fi

if [[ "$MODO" == "todo" || "$MODO" == "frontend" ]]; then
    echo "==> Build frontend (tsc -b + vite)"
    cd frontend
    npm run build
    cd ..
fi

echo "==> VERIFICACIÓN OK"
