#!/usr/bin/env bash
#
# Comprueba la migración 001 (Mejora 3) sin tocar la base de datos real: crea dos bases de prueba,
#   migration_old  -> el esquema ANTERIOR a la mejora 3 con 3 usuarios, al que se aplica la migración
#   migration_new  -> el esquema de instalación nueva (database/01-schema.sql)
# y comprueba que (1) los usuarios antiguos conservan sus datos y reciben los valores por defecto,
# (2) las dos bases acaban con las MISMAS columnas e índices (así 01-schema.sql y la migración no se
# desincronizan) y (3) aplicar la migración dos veces falla sin cambiar nada. Usa root (crea bases) y
# borra las dos bases al terminar. Sale con 0 si todo pasa y con 1 si algo falla.
#
#   tests/migration-001.sh

set -u
cd "$(dirname "$0")/.." || exit 2
PASS=0; FAIL=0
root() { docker compose exec -T db sh -c 'mysql -N --default-character-set=utf8mb4 -uroot -p"$MYSQL_ROOT_PASSWORD" "$@"' sh "$@" 2>/dev/null; }
ok() { if [ "$2" = 0 ]; then PASS=$((PASS + 1)); echo "  ok    $1"; else FAIL=$((FAIL + 1)); echo "  FALLO $1"; fi; }
cleanup() { printf 'DROP DATABASE IF EXISTS migration_old; DROP DATABASE IF EXISTS migration_new;\n' | root >/dev/null; }
trap cleanup EXIT
cleanup

printf 'CREATE DATABASE migration_old CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE DATABASE migration_new CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n' | root >/dev/null
# Esquema anterior a la mejora 3 (el de los MVP y las mejoras 1 y 2).
root migration_old <<'SQL' >/dev/null
CREATE TABLE users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(254) NOT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY unique_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SET NAMES utf8mb4;
INSERT INTO users (name, last_name, email, phone, created_at) VALUES
    ('Laura', 'Gómez', 'laura.gomez@example.com', '3001234567', '2026-01-02 03:04:05'),
    ('Andrés', 'Martínez', 'andres.martinez@example.com', '3109876543', '2026-02-03 04:05:06'),
    ('Valentina', 'Rojas', 'valentina.rojas@example.com', NULL, '2026-03-04 05:06:07');
SQL
root migration_new < database/01-schema.sql >/dev/null
BEFORE=$(printf 'SELECT id,name,last_name,email,IFNULL(phone,"NULL"),created_at FROM users ORDER BY id;\n' | root migration_old)

echo "== Aplicar la migración sobre una BD con datos"
root migration_old < database/migrations/001-more-fields.sql >/dev/null; [ $? = 0 ]; ok "la migración se aplica sin error" $?
AFTER=$(printf 'SELECT id,name,last_name,email,IFNULL(phone,"NULL"),created_at FROM users ORDER BY id;\n' | root migration_old)
[ "$BEFORE" = "$AFTER" ]; ok "los 3 usuarios conservan sus datos (incluida la fecha de creación)" $?
[ "$(printf 'SELECT COUNT(*) FROM users WHERE document IS NULL AND birth_date IS NULL AND role="user";\n' | root migration_old)" = 3 ]; ok "los 3 reciben documento NULL, fecha NULL y rol «user»" $?

echo "== La BD migrada y la instalación nueva son idénticas"
STRUCT="SELECT ORDINAL_POSITION, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, IFNULL(COLUMN_DEFAULT,'NULL'), COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='%s' AND TABLE_NAME='users' ORDER BY ORDINAL_POSITION;"
IDX="SELECT INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME, NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='%s' AND TABLE_NAME='users' ORDER BY INDEX_NAME, SEQ_IN_INDEX;"
A=$(printf "$STRUCT\n" migration_old | root); B=$(printf "$STRUCT\n" migration_new | root)
[ -n "$A" ] && [ "$A" = "$B" ]; ok "mismas columnas, tipos, nulos, valores por defecto y orden" $?
A=$(printf "$IDX\n" migration_old | root); B=$(printf "$IDX\n" migration_new | root)
[ -n "$A" ] && [ "$A" = "$B" ]; ok "mismos índices (PRIMARY, unique_email, unique_document)" $?
[ "$(printf 'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA="migration_new" AND INDEX_NAME="unique_document" AND NON_UNIQUE=0;\n' | root)" = 1 ]; ok "el documento es UNIQUE" $?

echo "== Después de migrar, la BD se comporta como se espera"
printf 'INSERT INTO users (name,last_name,email) VALUES ("N1","A","n1@example.com"),("N2","B","n2@example.com");\n' | root migration_old >/dev/null; [ $? = 0 ]; ok "varios usuarios sin documento conviven en el UNIQUE (NULL)" $?
printf 'INSERT INTO users (name,last_name,email,document) VALUES ("D1","A","d1@example.com",""),("D2","B","d2@example.com","");\n' | root migration_old >/dev/null 2>&1; [ $? != 0 ]; ok "...pero dos documentos '' sí chocarían (por eso la aplicación guarda NULL)" $?
printf 'INSERT INTO users (name,last_name,email,role) VALUES ("R","A","r@example.com","superadmin");\n' | root migration_old >/dev/null 2>&1; [ $? != 0 ]; ok "un rol fuera del ENUM lo rechaza MySQL (modo estricto)" $?
printf 'INSERT INTO users (name,last_name,email,birth_date) VALUES ("F","A","f@example.com","2023-02-30");\n' | root migration_old >/dev/null 2>&1; [ $? != 0 ]; ok "una fecha inexistente la rechaza MySQL (modo estricto)" $?
printf 'INSERT INTO users (name,last_name,email,document) VALUES ("U1","A","u1@example.com","ab-12345"),("U2","B","u2@example.com","AB-12345");\n' | root migration_old >/dev/null 2>&1; [ $? != 0 ]; ok "el UNIQUE del documento ignora mayúsculas (la collation)" $?

echo "== Aplicarla dos veces"
SNAP1=$(printf 'SHOW CREATE TABLE users;\n' | root migration_old)
root migration_old < database/migrations/001-more-fields.sql >/dev/null 2>&1; [ $? != 0 ]; ok "la segunda vez falla (Duplicate column name)" $?
[ "$SNAP1" = "$(printf 'SHOW CREATE TABLE users;\n' | root migration_old)" ]; ok "...sin cambiar nada" $?

echo; echo "Resultado: $PASS correctas, $FAIL fallidas"
[ "$FAIL" = 0 ]
