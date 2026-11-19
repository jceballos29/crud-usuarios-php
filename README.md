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
| MVP 4. Crear (Create) | Hecho |
| MVP 5. Editar (Update) | Hecho |
| MVP 6. Eliminar (Delete) | Hecho |
| MVP 7. Revisión | Hecho (etiqueta `v1-crud-base`) |
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
| `php` | php:8.4.27-fpm, versión de parche fijada (Dockerfile propio) | Ejecuta PHP (FastCGI, puerto interno 9000) | solo desde nginx |
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
cp .env.example .env     # y cambia al menos las claves (DB_PASSWORD y DB_ROOT_PASSWORD); el resto del ejemplo ya funciona
docker compose up -d --build
docker compose ps        # los 4 servicios en running; db en healthy
```

- Diagnóstico del stack: http://localhost:8080/status.php (solo con `APP_ENV=local`; en cualquier otro entorno responde 404 para no revelar versiones)
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
| Pruebas unitarias (sin BD) | `docker compose exec php php tests/unit.php` |
| Pruebas de punta a punta | `tests/smoke.sh` (añade `--with-db-stop` para parar y levantar la BD) |
| Composer | `docker compose exec php composer <comando>` |
| Ejecutar un `.sql` como root | `docker compose exec -T db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" crud_usuarios' < archivo.sql` |
| Apagar (conserva datos) | `docker compose down` |
| Apagar y borrar la BD | `docker compose down -v` |

## Pruebas

Hay dos niveles y no necesitan instalar nada más que lo que ya usa el proyecto:

- **Unitarias** (`src/tests/unit.php`): las funciones puras (validación, id, origen, fechas, escape). No usan BD ni servidor. `docker compose exec php php tests/unit.php`.
- **De punta a punta** (`tests/smoke.sh`): el CRUD completo contra la aplicación levantada, con `curl`: códigos HTTP, redirecciones, cabeceras de seguridad, origen en los tres POST, escape, zona horaria, ediciones simultáneas, configuración según `APP_ENV` y limpieza. Con `--with-db-stop` para y vuelve a levantar la BD para comprobar los 503. **Solo crea y borra usuarios `smoke-*@example.com`**; los demás no se tocan.

Ambos terminan con código 0 si todo pasa y con 1 si algo falla, así que sirven también para automatizarlos. Si cambias el puerto o el origen, usa `BASE_URL` y `ORIGIN`.

## Estructura

```
crud-usuarios-docker/
├── plan.md                  # Guía paso a paso con checks
├── README.md
├── docker-compose.yml       # Servicios: php, nginx, db, adminer
├── .env / .env.example      # Variables (.env no se sube a git)
├── docker/                  # Dockerfile de PHP, php.ini (seguro), php.local.ini (solo desarrollo), xdebug.ini, nginx (cabeceras y CSP)
├── tests/
│   └── smoke.sh             # Pruebas de punta a punta con curl
├── database/
│   ├── 01-schema.sql        # Tabla users
│   └── 02-seed.sql          # SET NAMES utf8mb4 + 3 usuarios de prueba
└── src/                     # Montado en /var/www/html
    ├── public/              # Raíz web: lo único que ve el navegador
    │   ├── index.php        # Listado de usuarios (MVP 3)
    │   ├── create.php       # Alta de usuarios (MVP 4)
    │   ├── edit.php         # Edición de usuarios (MVP 5)
    │   ├── delete.php       # Borrado, solo POST y solo desde el propio sitio (MVP 6)
    │   ├── status.php       # Diagnóstico del stack (solo APP_ENV=local)
    │   └── js/confirm-submit.js   # Pide confirmación en los formularios con data-confirm
    ├── includes/            # Código compartido, no accesible por URL
    │   ├── connection.php               # Crea $pdo (PDO)
    │   ├── helpers.php                  # environment(), escape(), log_exception()
    │   ├── user-validation.php          # Campos, limpieza y validación (compartido)
    │   ├── origin.php                   # Funciones puras: comprueban Origin/Referer contra APP_ORIGIN
    │   ├── request-guard.php            # reject_foreign_origin(): el 403 de create, edit y delete
    │   ├── DatabaseConnectionException.php
    │   ├── layout/                      # header.php, footer.php
    │   └── partials/                    # users-list.php, user-form.php, user-not-found.php, action-error.php
    ├── tests/               # Pruebas unitarias (unit.php); fuera de public/
    └── scripts/             # Solo consola
        ├── db-check.php
        └── info.php
```

Estructura objetivo al terminar el CRUD base: ver el Anexo E de [`plan.md`](plan.md).

## Origen de la aplicación (`APP_ORIGIN`)

Los POST de `create.php`, `edit.php` y `delete.php` solo se aceptan si vienen del propio sitio: `reject_foreign_origin()` (en `includes/request-guard.php`) compara la cabecera `Origin` (o, si falta, `Referer`) con `APP_ORIGIN` y responde **403** si no coincide, si falta o si es `null`, **antes** de mirar lo que se envía (ni siquiera se valida el id ni los campos). Es una capa contra peticiones enviadas desde otras páginas; no sustituye al token CSRF del módulo M6. Los GET no se comprueban.

- `APP_ORIGIN` es el origen público de la aplicación: **esquema + host + puerto**, sin ruta (`http://localhost:8080`, `https://sitio.com`).
- Es opcional: por defecto se deriva de `APP_PORT` (`http://localhost:${APP_PORT}`). Fíjalo en `.env` si usas otro host o HTTPS.
- `localhost` y `127.0.0.1` son orígenes **distintos**: abre la aplicación con el mismo que `APP_ORIGIN`.
- Si falta o no es un origen válido, las tres páginas responden 500 (error de configuración, registrado como `[config]`), no 403.
- Tras cambiarlo, recrea el contenedor: `docker compose up -d`.

## Entornos (`APP_ENV`)

`APP_ENV` (por defecto `local`) decide dos cosas:

- **Qué configuración de PHP se carga.** `docker/php/php.ini` es **segura por defecto**: `display_errors` y `display_startup_errors` apagados (los errores van solo al log y un fallo grave responde 500 con el cuerpo vacío). `docker/php/php.local.ini` (errores en pantalla y revalidación de archivos) solo se carga con `APP_ENV=local`, mediante `PHP_INI_SCAN_DIR` en `docker-compose.yml`. Cualquier otro valor, o uno mal escrito, queda en modo seguro.
- **Si existe `status.php`**, que enseña versiones: solo en `local`.

Tras cambiar `APP_ENV` en `.env`, recrea el contenedor con `docker compose up -d`. En `local` un error fatal se muestra en pantalla (y PHP responde 200: así se comporta con `display_errors` activo); en producción responde 500 sin cuerpo y el detalle queda en `docker compose logs php`.

## Ediciones simultáneas

El formulario de edición lleva un campo oculto `row_token`: una huella (sha256) del contenido de la fila al abrirlo. Al guardar se compara con la fila **tal como está ahora**; si no coincide, otra persona la modificó mientras tanto y la respuesta es un **409** que no guarda nada, conserva lo escrito y pide recargar. Si otra persona guarda exactamente los mismos valores no hay conflicto. Un envío sin `row_token` (por ejemplo con `curl`) se acepta como siempre. Queda un margen de milisegundos entre la comprobación y el `UPDATE`; cerrarlo del todo exigiría una columna de versión en la tabla.

## Fechas y zona horaria

Las fechas se **guardan en UTC** y se **muestran en la zona de la aplicación** (`date.timezone` de `docker/php/php.ini`, hoy `America/Bogota`):

- El servidor de MySQL arranca con `--default-time-zone=+00:00` y cada conexión de la aplicación ejecuta `SET time_zone = '+00:00'`, así que no depende de la configuración del servidor.
- `created_at` es `DATETIME` (no `TIMESTAMP`, que no admite fechas posteriores a 2038) y se convierte con `format_utc_datetime()` al mostrarlo.
- Adminer y el cliente `mysql` enseñan la hora **en UTC**: es la que hay guardada.

## Cabeceras de seguridad

Nginx añade `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy` y una `Content-Security-Policy` en **todas** las respuestas (también en los errores), y oculta su versión. La CSP solo permite JavaScript y CSS propios o de `cdn.jsdelivr.net`, y **no permite scripts ni estilos en línea**: por eso el botón Eliminar usa `data-confirm` y `js/confirm-submit.js` en lugar de un `onsubmit`. Si añades otra CDN, un script o un `<style>` en línea, hay que actualizar la política en `docker/nginx/default.conf` (y recargar con `docker compose exec nginx nginx -s reload`). Si algo no carga, la consola del navegador dice `Refused to ...` con la directiva que lo bloquea.

## Manejo de errores

El visitante siempre ve un mensaje genérico; el detalle va al log del contenedor (`docker compose logs php`).

| Categoría | Excepción | HTTP | Etiqueta en el log |
|-----------|-----------|------|--------------------|
| Conexión (clave mala, BD apagada) | `DatabaseConnectionException` | 503 | `[connection]` |
| Consulta (SQL inválido) | `PDOException` | 500 | `[query]` |
| Configuración (falta una variable) | `RuntimeException` | 500 | `[config]` |

Códigos que devuelve la aplicación (regla: si el envío no se completó, nunca es 200):

| HTTP | Cuándo |
|------|--------|
| 200 | Páginas normales |
| 302 | Tras crear, editar o eliminar: redirección a `index.php?msg=...` |
| 400 | `delete.php` con un id mal formado |
| 403 | Un POST de `create.php`, `edit.php` o `delete.php` desde un origen que no es el propio sitio |
| 404 | `edit.php` con un id inválido o inexistente; `status.php` fuera de `APP_ENV=local` |
| 405 | `delete.php` con un método que no es POST (cabecera `Allow: POST`) |
| 409 | Correo duplicado (el `UNIQUE` de la BD) o edición simultánea (otra persona modificó al usuario) |
| 422 | Datos del formulario inválidos |
| 500 | Fallo de consulta o de configuración |
| 503 | La BD no está disponible |

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

**Guardar, actualizar o eliminar responden "Origen no permitido" (403).** Estás abriendo la aplicación con un origen distinto de `APP_ORIGIN` (por ejemplo `http://127.0.0.1:8080` en lugar de `http://localhost:8080`, o un puerto distinto). Abre la dirección que coincide, o ajusta `APP_ORIGIN` en `.env` y ejecuta `docker compose up -d`.

**La BD se creó antes del cambio de `created_at` (sigue siendo `TIMESTAMP`).** Los scripts de `database/` solo corren con el volumen vacío. Para migrarla sin perder datos: `docker compose exec -T db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' <<< "ALTER TABLE users MODIFY created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;"` y `docker compose up -d` para que MySQL arranque en UTC. Los valores no cambian.

**Tildes como `Ã³` al cargar datos.** El cliente `mysql` del contenedor se conecta como `latin1`. El seed lleva `SET NAMES utf8mb4;` al inicio; tras corregirlo hay que recrear la BD.

**Una URL mal escrita muestra el listado en vez de un 404.** Nginx envía todo lo que no existe a `index.php` (comportamiento de *front controller*).

**`Failed opening required ...` y la página responde 200.** Linux distingue mayúsculas y nombres exactos de archivo; con `display_errors` activo PHP muestra el fallo con estado 200.

**Xdebug no conecta con VS Code.** Pon `XDEBUG_MODE=debug` en `.env`, ejecuta `docker compose up -d`, arranca "Escuchar Xdebug (Docker)" (puerto 9003) y revisa el firewall.
