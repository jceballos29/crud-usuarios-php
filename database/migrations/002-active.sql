-- Migracion 002 (Mejora 4): borrado logico.
--
-- Eliminar un usuario pasa a ser UPDATE users SET active = 0: la fila se conserva y deja de verse en el
-- listado. Se aplica UNA sola vez (la segunda falla con "Duplicate column name" sin cambiar nada).
-- 01-schema.sql lleva lo mismo para las instalaciones nuevas: hay que mantener AMBOS.
--
--   docker compose exec -T db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" crud_usuarios' < database/migrations/002-active.sql
--
-- Usuarios existentes: active = 1 (el DEFAULT), todos siguen visibles.
-- El indice (active, id) sirve las dos vistas: WHERE active = ? ORDER BY id DESC LIMIT n recorre solo las
-- filas de esa vista, sin leer las de la otra. Los UNIQUE de email y document NO cambian: siguen
-- cubriendo tambien a los eliminados (ver plan.md, M4).
ALTER TABLE users
    ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1 AFTER role,
    ADD KEY idx_active_id (active, id);
