-- Migracion 003 (Mejora 5): administradores que pueden entrar en la aplicacion.
--
-- Tabla APARTE de `users`: los usuarios del CRUD son los datos que se gestionan; los administradores son
-- quienes los gestionan. Mezclarlos daria a cada persona registrada la posibilidad de tener contrasena.
-- Se aplica UNA sola vez ("Table 'admins' already exists" la segunda vez; no cambia nada).
-- 01-schema.sql lleva lo mismo para las instalaciones nuevas: hay que mantener AMBOS.
--
--   docker compose exec -T db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" crud_usuarios' < database/migrations/003-admins.sql
--
-- No hay ningun administrador al crearla: se crean desde la consola con src/scripts/create-admin.php.
-- password_hash guarda el HASH (bcrypt = 60 caracteres hoy), nunca la contrasena. VARCHAR(255) es lo que
-- recomienda PHP para poder cambiar de algoritmo (PASSWORD_DEFAULT) sin migrar la columna.
CREATE TABLE admins (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(254) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY unique_admin_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
