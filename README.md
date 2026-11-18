# CRUD de usuarios en PHP + MySQL (con Docker)

Proyecto de **aprendizaje**: construir desde cero, paso a paso, un CRUD de usuarios
en PHP puro (sin frameworks) sobre un stack Docker (Nginx + PHP-FPM + MySQL + Adminer)
y luego aplicarle las "posibles mejoras" de la guía técnica original (XAMPP).

> El objetivo **no** es tener el código terminado, sino **entender cada pieza**.
> El código lo escribe el estudiante; el avance, las explicaciones y los experimentos
> están en [`plan.md`](plan.md).

## Estado actual

| Fase | Estado |
|------|--------|
| 0. Entorno Docker | Hecha |
| MVP 1. Base de datos (`users`) | Hecho |
| MVP 2. Conexión PDO y manejo de errores | Hecho |
| MVP 3. Listar (Read) | Hecho |
| MVP 4. Crear (Create) | Hecho (pendiente commit) |
| MVP 5. Editar (Update) | Siguiente |
| MVP 6 y 7. Delete, revisión | Pendientes |
| Mejoras 1 a 8 | Pendientes |

## Qué se construye

| Operación | SQL | Archivo |
|-----------|-----|---------|
| Create | `INSERT` | `create.php` |
| Read | `SELECT` | `index.php` |
| Update | `UPDATE` | `edit.php` |
| Delete | `DELETE` | `delete.php` |

Tabla `users` (`database/01-schema.sql`):

| Columna | Tipo | Notas |
|---------|------|-------|
| `id` | `INT UNSIGNED` | PK, autoincremental |
| `name`, `last_name` | `VARCHAR(100)` | obligatorios |
| `email` | `VARCHAR(254)` | obligatorio, **único** |
| `phone` | `VARCHAR(20)` | opcional (`NULL`) |
| `created_at` | `TIMESTAMP` | `DEFAULT CURRENT_TIMESTAMP` |

## Convenciones

- Código, tablas y nombres de archivo en **inglés**; textos para el usuario en **español**.
- `declare(strict_types=1);` en todos los archivos PHP.
- Toda salida a HTML pasa por `escape()` (`src/includes/helpers.php`).
- Credenciales **solo** por variables de entorno (`.env`), nunca en el código.
- Lo que no debe ser accesible por URL vive fuera de `src/public/`.

## Material de referencia

Está en la carpeta superior (`../`), fuera de este proyecto a propósito:

| Archivo | Para qué sirve |
|---------|----------------|
| `Guía técnica del CRUD de usuarios en PHP y MySQL (XAMPP).pdf` | Teoría del CRUD y lista de mejoras (§11) |
| `php-docker-stack.zip` | Plantilla del entorno Docker usada como base |
| `crud_usuarios.zip` | Solución original en XAMPP. **No abrir hasta el MVP 7**, para comparar con la versión propia |

## Stack

| Servicio | Imagen | Rol | Acceso |
|----------|--------|-----|--------|
| `nginx` | nginx 1.30 alpine | Recibe HTTP y sirve `src/public` | http://localhost:8080 |
| `php` | php:8.4-fpm (Dockerfile propio) | Ejecuta PHP (FastCGI, puerto interno 9000) | solo desde nginx |
| `db` | mysql:8.4 | Base de datos | solo red interna (host `db`, 3306) |
| `adminer` | adminer 6.3 | Cliente web de BD (equivale a phpMyAdmin) | http://localhost:8081 |

Nginx y Adminer solo se publican en `127.0.0.1`: no son accesibles desde la red local.

### Equivalencias XAMPP a Docker

| En la guía (XAMPP) | En este proyecto (Docker) |
|--------------------|---------------------------|
| Panel de control: Start Apache y MySQL | `docker compose up -d` |
| `C:\xampp\htdocs\crud_usuarios` | `src/public/` (raíz web) |
| `http://localhost/crud_usuarios/` | `http://localhost:8080/` |
| MySQL en `localhost`, usuario `root` sin clave | Host `db`, usuario propio con clave (`.env`) |
| phpMyAdmin | Adminer |
| Importar `database.sql` a mano | Se carga solo la primera vez desde `database/` |
| Credenciales escritas en `conexion.php` | Variables de entorno (`getenv`) |

## Puesta en marcha

Requisitos: Docker, Docker Compose y Git.

```bash
cp .env.example .env     # y edita: DB_DATABASE=crud_usuarios, DB_USERNAME, claves
docker compose up -d --build
docker compose ps        # los 4 servicios en running; db en healthy
```

- Diagnóstico del stack: http://localhost:8080/status.php
- Adminer: http://localhost:8081 (sistema MySQL, servidor `db`, credenciales de `.env`)

> El script SQL de `database/` solo se ejecuta cuando el volumen de MySQL está vacío.
> Si cambias el esquema, las claves de `.env` o `02-seed.sql`, recrea la BD:
> `docker compose down -v && docker compose up -d` (**borra los datos**).

## Comandos útiles

| Acción | Comando |
|--------|---------|
| Ver logs (todos / PHP) | `docker compose logs -f` / `docker compose logs -f php` |
| Shell en PHP | `docker compose exec php sh` |
| Comprobar conexión a MySQL | `docker compose exec php php scripts/db-check.php` |
| Composer | `docker compose exec php composer <comando>` |
| Ejecutar un `.sql` como root | `docker compose exec -T db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" crud_usuarios' < archivo.sql` |
| Apagar (conserva datos) | `docker compose down` |
| Apagar y borrar la BD | `docker compose down -v` |

## Estructura

```
crud-usuarios-docker/
├── plan.md                  # Guía paso a paso con checks
├── README.md
├── docker-compose.yml       # Servicios: php, nginx, db, adminer
├── .env / .env.example      # Variables (.env no se sube a git)
├── docker/                  # Dockerfile de PHP, php.ini, xdebug.ini, nginx
├── database/
│   ├── 01-schema.sql        # Tabla users
│   └── 02-seed.sql          # SET NAMES utf8mb4 + 3 usuarios de prueba
└── src/                     # Montado en /var/www/html
    ├── public/              # Raíz web: lo único que ve el navegador
    │   ├── index.php        # Listado de usuarios (MVP 3)
    │   ├── create.php       # Alta de usuarios (MVP 4)
    │   └── status.php       # Diagnóstico del stack (solo desarrollo)
    ├── includes/            # Código compartido, no accesible por URL
    │   ├── connection.php               # Crea $pdo (PDO)
    │   ├── helpers.php                  # environment(), escape(), log_exception()
    │   ├── DatabaseConnectionException.php
    │   ├── layout/                      # header.php, footer.php
    │   └── partials/                    # users-list.php (vista del listado)
    └── scripts/             # Solo consola
        ├── db-check.php
        └── info.php
```

Estructura objetivo al terminar el CRUD base: ver el Anexo E de [`plan.md`](plan.md).

## Manejo de errores

El visitante siempre ve un mensaje genérico; el detalle va al log del contenedor (`docker compose logs php`).

| Categoría | Excepción | HTTP | Etiqueta en el log |
|-----------|-----------|------|--------------------|
| Conexión (clave mala, BD apagada) | `DatabaseConnectionException` | 503 | `[connection]` |
| Consulta (SQL inválido) | `PDOException` | 500 | `[query]` |
| Configuración (falta una variable) | `RuntimeException` | 500 | `[config]` |

## Hoja de ruta

- **Fase 0** Entorno Docker funcionando
- **MVP 1** Base de datos
- **MVP 2** Conexión PDO y manejo de errores
- **MVP 3** Listar (Read)
- **MVP 4** Crear (Create)
- **MVP 5** Editar (Update)
- **MVP 6** Eliminar (Delete)
- **MVP 7** Revisión, errores comunes y comparación con la guía
- **Mejoras** 1 Búsqueda · 2 Paginación · 3 Más campos · 4 Borrado lógico · 5 Inicio de sesión · 6 CSRF · 7 Usuario de BD dedicado · 8 Separación en capas

## Solución de problemas

**Los archivos creados desde el contenedor pertenecen a otro usuario.** El UID/GID del contenedor no coincide con el tuyo. Ejecuta `id -u` e `id -g`, ajusta `HOST_UID` y `HOST_GID` en `.env` y reconstruye: `docker compose up -d --build`.

**Error de conexión o puerto ocupado.**
- `php` espera a que MySQL esté sano (`healthcheck`); el primer arranque puede tardar. Revisa `docker compose ps` y `docker compose logs db`.
- Si cambias las credenciales en `.env` después del primer arranque, MySQL conserva las anteriores: `docker compose down -v` (**borra los datos**).
- Si `8080` u `8081` están ocupados, cambia `APP_PORT` o `ADMINER_PORT` en `.env`.
- Si cambias una variable de `.env`, recrea los contenedores con `docker compose up -d`.

**Tildes como `Ã³` al cargar datos.** El cliente `mysql` del contenedor se conecta como `latin1`. El seed lleva `SET NAMES utf8mb4;` al inicio; tras corregirlo hay que recrear la BD.

**Una URL mal escrita muestra el listado en vez de un 404.** Nginx envía todo lo que no existe a `index.php` (comportamiento de *front controller*).

**`Failed opening required ...` y la página responde 200.** Linux distingue mayúsculas y nombres exactos de archivo; con `display_errors` activo PHP muestra el fallo con estado 200.

**Xdebug no conecta con VS Code.** Pon `XDEBUG_MODE=debug` en `.env`, ejecuta `docker compose up -d`, arranca "Escuchar Xdebug (Docker)" (puerto 9003) y revisa el firewall.
