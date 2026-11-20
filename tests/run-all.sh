#!/usr/bin/env bash
#
# Ejecuta TODAS las pruebas del proyecto, una tras otra, y resume el resultado. Úsalo antes de etiquetar una versión.
#
#   tests/run-all.sh                  # todo, salvo parar la BD
#   tests/run-all.sh --with-db-stop   # además comprueba los 503 parando y levantando la BD (el smoke tarda más)
#
# Requisitos: la aplicación levantada (docker compose up -d) y el usuario de migraciones en .env (M7).
# Sale con 0 si todo pasa y con 1 si algo falla. Solo crea y borra datos de prueba (smoke-*, bases temporales).

set -u
cd "$(dirname "$0")/.." || exit 2
STOP_FLAG="${1:-}"
FAILED=0
SUMMARY=()

run() { # run "nombre" comando...
    local name="$1"; shift
    local out code line
    out=$("$@" 2>&1); code=$?
    line=$(printf '%s\n' "$out" | grep -E '^Resultado:' | tail -1)
    [ -n "$line" ] || line="(sin resumen)"
    if [ "$code" = 0 ]; then SUMMARY+=("  ok    $name — ${line#Resultado: }"); else FAILED=1; SUMMARY+=("  FALLO $name — ${line#Resultado: } (código $code)"); printf '%s\n' "$out" | grep -E 'FALLO' | head -10; fi
}

run "unitarias" docker compose exec -T php php tests/unit.php
run "arquitectura" docker compose exec -T php php tests/architecture.php
run "integración" bin/as-migrator php tests/integration.php
run "migración 001" tests/migration-001.sh
run "migración 002" tests/migration-002.sh
run "migración 003" tests/migration-003.sh
run "migración 004" tests/migration-004.sh
run "permisos de la BD" tests/privileges.sh
run "punta a punta" tests/smoke.sh $STOP_FLAG

echo
echo "== Resumen"
printf '%s\n' "${SUMMARY[@]}"
[ "$FAILED" = 0 ] && echo "Todo correcto." || echo "HAY FALLOS."
exit "$FAILED"
