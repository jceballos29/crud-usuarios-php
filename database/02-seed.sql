SET NAMES utf8mb4;
INSERT INTO users (name, last_name, email, phone) VALUES
    ('Laura', 'Gómez', 'laura.gomez@example.com', '3001234567'),
    ('Andrés', 'Martínez', 'andres.martinez@example.com', '3109876543'),
    ('Valentina', 'Rojas', 'valentina.rojas@example.com', NULL);

-- Administrador de DESARROLLO para poder entrar en la aplicacion (Mejora 5). Correo: admin@example.com;
-- la contrasena esta en el README (seccion "Inicio de sesion"). Es una cuenta de demostracion con una
-- contrasena conocida: NO la uses fuera de tu maquina. En cualquier otro entorno borra esta fila
-- (DELETE FROM admins WHERE email = 'admin@example.com') y crea el tuyo con src/scripts/create-admin.php.
-- Lo que se guarda es el hash bcrypt, no la contrasena. INSERT IGNORE: se puede volver a ejecutar a mano.
INSERT IGNORE INTO admins (email, password_hash) VALUES
    ('admin@example.com', '$2y$12$KEdM5az7CefKgstekEcyiedLtLWKRYZ2LB83kp2Dvf8TtdH2uvTKq');
