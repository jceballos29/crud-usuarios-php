CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(254) NOT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    -- Mejora 3 (misma definicion que database/migrations/001-more-fields.sql: mantener ambos).
    -- Texto y no numero: admite ceros a la izquierda y letras (pasaportes). UNIQUE admite varios NULL.
    document VARCHAR(20) DEFAULT NULL,
    birth_date DATE DEFAULT NULL,
    -- Los roles validos tambien estan en user_roles() (src/includes/user-validation.php); una prueba los compara.
    role ENUM('user', 'editor', 'admin') NOT NULL DEFAULT 'user',
    -- Fecha en UTC (la conexión de la app y el servidor trabajan en UTC). DATETIME y no TIMESTAMP:
    -- TIMESTAMP no admite fechas posteriores al 2038-01-19.
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY unique_email (email),
    UNIQUE KEY unique_document (document)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
