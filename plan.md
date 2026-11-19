# Plan de aprendizaje: CRUD de usuarios en PHP + MySQL con Docker

Cómo usar este documento:

- Las fases se hacen **en orden**. Cada una termina en algo que funciona y se prueba en el navegador.
- Marca `- [x]` **solo** cuando la prueba de la fase pase.
- Cada paso explica **qué** hacer y **por qué**. El **cómo** (el código) lo escribes tú.
- Si un paso no se entiende, no se sigue: se pregunta. Entender > avanzar.
- Al final de cada fase hay preguntas de comprobación. Si puedes responderlas con tus palabras, la fase está aprendida.
- Referencias `(Guía §X)` apuntan a la sección del PDF.

### Convención de nombres (decisión: código en inglés)

La guía original usa español; **tu código va en inglés** y el plan ya usa tus nombres (`users`, `create.php`...). Equivalencias con la guía:

| Guía / plan | Tu proyecto |
|-------------|-------------|
| `usuarios` | `users` |
| `nombre`, `apellido`, `correo`, `telefono`, `creado_en` | `name`, `last_name`, `email`, `phone`, `created_at` |
| `crear.php`, `editar.php`, `eliminar.php` | `create.php`, `edit.php`, `delete.php` |
| `conexion.php` | `connection.php` |
| `$usuarios`, `$errores`, `$datos` | `$users`, `$errors`, `$data` |

El texto que ve el usuario final sigue en español.

## Progreso general

| Fase | Resultado | Estado |
|------|-----------|--------|
| 0 | Entorno Docker corriendo | [x] |
| 1 | Tabla `users` con datos de prueba | [x] |
| 2 | PHP habla con MySQL y maneja errores | [x] |
| 3 | Listado de usuarios (Read) | [x] |
| 4 | Crear usuario (Create) | [x] |
| 5 | Editar usuario (Update) | [x] |
| 6 | Eliminar usuario (Delete) | [x] |
| 7 | Revisión final del CRUD | [x] |
| M1 | Búsqueda y filtros | [ ] |
| M2 | Paginación | [ ] |
| M3 | Más campos | [ ] |
| M4 | Borrado lógico | [ ] |
| M5 | Inicio de sesión | [ ] |
| M6 | Tokens CSRF | [ ] |
| M7 | Usuario de BD dedicado | [ ] |
| M8 | Separación en capas | [ ] |

---

# PARTE A. El CRUD base

## Fase 0. Preparar el entorno Docker

**Objetivo:** tener Nginx + PHP + MySQL + Adminer corriendo y entender qué hace cada pieza.
**Resultado:** `http://localhost:8080` muestra la página del stack con "Conectado a MySQL ...".

### 0.1 Conceptos previos

En XAMPP todo vive en tu máquina. Con Docker cada servicio corre en su **contenedor** (un mini-sistema aislado) y se comunican por una red interna:

```
Navegador ──HTTP──> nginx (:8080) ──FastCGI──> php (php-fpm :9000) ──SQL──> db (MySQL :3306)
                                                                              ^
                                                     adminer (:8081) ────────┘
```

- **Nginx** reemplaza a Apache: recibe la petición. Si es un `.php`, se la pasa a PHP-FPM; si es un archivo estático (CSS), lo entrega él.
- **PHP-FPM** reemplaza al módulo de PHP de Apache: ejecuta el código y devuelve HTML.
- **MySQL** corre en el contenedor `db`. Desde PHP **no** se llama `localhost`, se llama `db` (Docker resuelve el nombre del servicio).
- **Volúmenes**: `./src` de tu máquina se "monta" dentro del contenedor, así que editas en tu editor y el cambio se ve al instante. Los datos de MySQL viven en un volumen aparte (`db_data`) y sobreviven a `docker compose down`.
- **`.env`**: archivo con las variables (puertos, usuarios, claves). `docker-compose.yml` las lee y las entrega a los contenedores como variables de entorno.

### 0.2 Verificar requisitos

- [x] Ejecuta `docker --version` y `docker compose version`. Ambos deben responder.
- [x] Ejecuta `docker ps`. Si da error de permisos, tu usuario no está en el grupo `docker` (pregunta y lo resolvemos).
- [x] Verifica que los puertos 8080 y 8081 estén libres: `ss -ltn | grep -E ':(8080|8081)'` (si no imprime nada, están libres).

### 0.3 Traer el stack base al proyecto

La carpeta del proyecto ya existe (`crud-usuarios-docker/`). Hay que copiar dentro el contenido de `php-docker-stack.zip`.

- [x] Descomprime el zip en una carpeta temporal, **no** dentro del proyecto (investiga `unzip -d`).
- [x] Copia **el contenido** de `php-docker-stack/` dentro de `crud-usuarios-docker/` (cuidado: copiar el *contenido*, no la carpeta; piensa qué diferencia hay entre `cp -r origen/ destino/` y `cp -r origen/. destino/`).
- [x] **No** copies la carpeta `.git` del zip ni el `.env` que trae: tu proyecto tendrá su propio historial y sus propias claves.
- [x] Inicia tu propio repositorio: `git init` dentro de `crud-usuarios-docker/`.

### 0.4 Leer el stack antes de ejecutarlo

Abre y lee cada archivo. Para cada uno, escribe en una línea (en un cuaderno o en comentarios tuyos) qué hace:

- [x] `docker-compose.yml`: ¿qué 4 servicios define? ¿qué significa `depends_on` con `condition: service_healthy`? ¿por qué los puertos van como `127.0.0.1:8080:80`?
- [x] `docker/php/Dockerfile`: ¿qué extensión de PHP es la que permite conectarse a MySQL? ¿para qué sirven `HOST_UID` y `HOST_GID`?
- [x] `docker/nginx/default.conf`: ¿cuál es el `root`? ¿qué hace `try_files`? ¿por qué se bloquean los archivos que empiezan por punto?
- [x] `docker/php/php.ini`: ¿qué hace `display_errors = On` y por qué solo es adecuado en desarrollo?

> Dato clave para después: Nginx está configurado para que **si un archivo no existe, se ejecute `index.php`**. Por eso, si escribes mal una URL (`/creaar.php`) verás el listado en vez de un 404. No es un bug, es el comportamiento del *front controller*.

### 0.5 Configurar `.env`

- [x] Copia `.env.example` a `.env`.
- [x] Cambia `DB_DATABASE` a `crud_usuarios`.
- [x] Cambia `DB_USERNAME` a `crud` (o el nombre que prefieras).
- [x] Pon claves reales (distintas entre sí) en `DB_PASSWORD` y `DB_ROOT_PASSWORD`.
- [x] Ejecuta `id -u` e `id -g` y pon esos valores en `HOST_UID` / `HOST_GID` si no son 1000.
- [x] Comprueba que `.env` aparece en `.gitignore` (¿por qué no se sube a git?).

### 0.6 Levantar

- [x] `docker compose up -d --build` (la primera vez tarda: construye la imagen de PHP).
- [x] `docker compose ps`: los 4 servicios deben estar `running` y `db` en `healthy`.
- [x] Si algo falla: `docker compose logs <servicio>`.

### 0.7 Probar

- [x] Abre la página de diagnóstico del stack (al inicio era `http://localhost:8080`; tras el MVP 2 vive en `http://localhost:8080/status.php`) → versión de PHP, extensiones con ✔ y "Conectado a MySQL".
- [x] Si `opcache` sale con ✘: OPcache se registra como **`Zend OPcache`**; corrige el nombre en la lista `$requeridas` de la página (bug de la plantilla).
- [x] Abre http://localhost:8081 (Adminer). Inicia sesión: sistema **MySQL**, servidor `db`, usuario y clave de tu `.env`, base `crud_usuarios`. Debe entrar y mostrar "No tables".
- [x] Ejecuta `docker compose exec php php scripts/db-check.php` → lista las bases de datos.
- [x] Primer commit: `.gitignore` ya excluye `.env`; verifica con `git status` que `.env` no aparece, y haz `git add` + `git commit`.

### Preguntas de comprobación

1. ¿Por qué PHP se conecta a `db` y no a `localhost`?

    _Porque en el network que crear docker el localhost en este caso sería phppor eso hay que conectarse a db_

2. ¿Qué pasa con tus datos si haces `docker compose down`? ¿Y con `down -v`?

    _Con down se borrá las instancias pero se mantienen los volúmenes, si se vuelve a correr el build los datos guardados permanecen, con down -v se borra todo incluyendo los volúmenes, entonces si corre el build serían instancias totalmente nuevas y limpias_

3. ¿Qué contenedor ejecuta el código PHP y cuál entrega el CSS?

    _El PHP lo ejecuta phpy el css lo entrega nginx_

4. Cambia el texto de `<h1>` en `src/public/index.php`, guarda y recarga el navegador. ¿Hizo falta reiniciar algo? ¿Por qué no?

    _No hubo necesidad, porque docker ya está entregando lo que están en mi carpeta src_

---

## MVP 1. La base de datos

**Objetivo:** tabla `users` con 3 usuarios de prueba, cargada automáticamente por Docker.
**Resultado:** ves la tabla y sus datos en Adminer.
**Guía:** §5.

### 1.1 Conceptos

- Una **tabla** define columnas y reglas (tipo, obligatoriedad, unicidad). La base de datos es quien **garantiza** esas reglas, aunque PHP falle.
- `INT UNSIGNED AUTO_INCREMENT PRIMARY KEY`: identificador numérico, positivo, que MySQL genera solo.
- `UNIQUE`: la BD rechaza dos filas con el mismo valor (lo usaremos para el correo).
- `utf8mb4`: codificación que admite tildes, ñ y emojis.
- `NULL` vs cadena vacía: "no hay dato" vs "hay un dato vacío". El teléfono opcional será `NULL`.

### 1.2 Diferencia importante con la guía

La guía empieza con `CREATE DATABASE` + `USE`. **Aquí no se necesita**: Docker ya crea la base `crud_usuarios` por `MYSQL_DATABASE`, y tu usuario `crud` no tiene permiso para crear otras bases. Tu script solo creará la **tabla**.

### 1.3 Diseñar la tabla (primero en papel)

Antes de escribir SQL, rellena esta tabla con el tipo y restricciones que usarías, y luego compárala con la Guía §5.2:

| Columna | Tipo | Restricciones | ¿Por qué? |
|---------|------|---------------|-----------|
| id | INT | UNSIGNED NOT NULL AUTO_INCREMENT | Porque es el campo de identificación, se va a generar automáticamente, no puede ser null y no puede tomar números negativos |
| name | VARCHAR(100) | NOT NULL | El nombre es requisito para la creación del usuario y estima que los nombre no superaran los 100 caractees |
| last_name | VARCHAR(100) | NOT NULL | El apellido es requisito para la creación del usuario y estima que los nombre no superaran los 100 caractees |
| email | VARCHAR(254) | NOT NULL | Es un campo obligatorio para la creación de usuario y se toman 255 partiendo el máximo de caracteres de SMTP que liminta a 256 incluyendo los corchetes, entonces es suficiente |
| password | VARCHAR(255) | NOT NULL | Porque guarda el hash de la constraseña |
| phone | VARCHAR(20) | DEFAUTL NULL | Es un campo opcional, entonces puede tomar NULL, se elige 20 caracteres partiendo del estandar ITU-T E.164 que da 15 dígitos, se da un margen de 5 caracteres por si se agregan espacios o `+` |
| created_at | TIMESTAMP | NOT NULL DEFAULT CURRENT_TIMESTAMP | Porque debe guardar el instante exacto en el que se crea el usuario, al definir el DEFAULT se hace de manera automática |

> Decisiones tomadas: nombres en inglés (`users`, `name`, `last_name`, `email`, `phone`, `created_at`) y **sin** columna `password` (el CRUD no la usa; irá en una tabla aparte en M5).

### 1.4 Probar el SQL a mano primero

- [x] En Adminer → **SQL command**, escribe tú el `CREATE TABLE users (...)` con `ENGINE=InnoDB` y charset `utf8mb4`. Ejecútalo.
- [x] Escribe un `INSERT` con un usuario (sin `phone`). Consulta con `SELECT * FROM users;`.
- [x] Prueba el `UNIQUE`: inserta otra vez el mismo correo y **lee el error** (código 1062 / SQLSTATE `23000`; `23000` es una familia de violaciones de integridad, el número exacto está en `errorInfo[1]`). Lo volverás a ver en PHP.
- [x] Prueba qué pasa al insertar sin `name` (error 1364, SQLSTATE `HY000`), con `name = ''` (**se guarda**: la cadena vacía no es `NULL`, así que "no vacío" lo valida PHP) y con `NULL` explícito (error 1048, SQLSTATE `23000`).
- [x] Borra la tabla (`DROP TABLE users;`): vamos a hacerlo de forma automática.

### 1.5 Dejarlo como script versionado

- [x] Crea la carpeta `database/` en la raíz del proyecto.
- [x] Crea `database/01-schema.sql` con tu `CREATE TABLE IF NOT EXISTS ...` (sin `CREATE DATABASE` ni `USE`).
- [x] Crea `database/02-seed.sql` con 3 `INSERT` (uno con teléfono `NULL`, y al menos un nombre con tilde o ñ).
- [x] Piensa por qué se numeran los archivos (pista: orden alfabético, y el seed necesita que la tabla ya exista).

### 1.6 Que Docker lo ejecute solo

La imagen de MySQL ejecuta todo `.sql` que haya en `/docker-entrypoint-initdb.d/` **únicamente la primera vez** (cuando el volumen está vacío).

- [x] En `docker-compose.yml`, servicio `db`, añade un volumen que monte `./database` en `/docker-entrypoint-initdb.d` en modo solo lectura (`:ro`). Fíjate en cómo están escritos los otros volúmenes.
- [x] Como el volumen `db_data` ya existe, el script **no se ejecutará** solo. Reinicia la BD desde cero: `docker compose down -v` y luego `docker compose up -d`.
- [x] Mira `docker compose logs db`: busca las líneas donde ejecuta tus archivos.

### 1.7 Probar

- [x] En Adminer aparece la tabla `users` con 3 filas.
- [x] Las tildes y la ñ se ven bien (si no, revisa charset).
- [x] El tercer usuario muestra `NULL` en teléfono.
- [x] `SELECT name, HEX(name) FROM users WHERE id = 2;` muestra `C3A9` (2 bytes) para la `é`, no `C383C2A9`.
- [x] Commit de la fase.

### 1.8 Lección: la conexión también tiene charset

Al cargar el seed por primera vez las tildes salieron como `GÃ³mez`. El archivo era UTF-8 y la tabla `utf8mb4`; el fallo estaba en la **conexión**: el cliente `mysql` del contenedor se declara `latin1` (`LANG` vacío) y MySQL "re-codifica" los bytes. Solución: `SET NAMES utf8mb4;` al inicio de `02-seed.sql`. Hay **tres** charsets en juego: archivo, tabla y conexión. (PDO usa `charset=utf8mb4` en el DSN, por eso desde PHP no ocurre.)

Después de corregir el seed hay que recrear la BD (`down -v` + `up -d`) porque los scripts de init solo corren con el volumen vacío.

### Preguntas de comprobación

1. ¿Por qué el correo debe tener `UNIQUE` en la BD y no basta con validarlo en PHP?
2. ¿Por qué el script de init no se vuelve a ejecutar al hacer `docker compose up`?
3. ¿Qué diferencia hay entre `down` y `down -v`, y cuándo usarías cada uno?
4. ¿Qué ventaja tiene guardar el SQL en archivos y no solo ejecutarlo en Adminer? (reproducibilidad, historial en git, revisión)
5. ¿Por qué `NOT NULL` no impide guardar un nombre vacío?
6. ¿Qué tres charsets intervienen entre un archivo `.sql` y una columna?

---

## MVP 2. La conexión PDO

**Objetivo:** que PHP se conecte a MySQL, lea los usuarios y maneje los errores de forma ordenada (todavía sin diseño).
**Resultado:** una página de prueba (`test.php`) que lista los usuarios y, si algo falla, responde con el código HTTP y el mensaje adecuados.
**Guía:** §6.

### 2.1 Conceptos

- **PDO**: capa estándar de PHP para hablar con bases de datos. Su gran valor: **consultas preparadas**.
- **DSN**: texto que dice a qué conectarse: `mysql:host=...;port=...;dbname=...;charset=utf8mb4`.
- **Opciones** que usamos (explica cada una con tus palabras al terminar):
  - `ATTR_ERRMODE => ERRMODE_EXCEPTION`: un error SQL se convierte en una excepción capturable (desde PHP 8 ya es el modo por defecto; dejarlo explícito es buena práctica).
  - `ATTR_DEFAULT_FETCH_MODE => FETCH_ASSOC`: cada fila llega como arreglo asociativo (`$row['name']`).
  - `ATTR_EMULATE_PREPARES => false`: MySQL prepara la consulta de verdad: primero recibe la **estructura** y después los **valores**, por separado.
- **Variables de entorno**: en vez de escribir claves en el código (como en la guía), se leen con `getenv('DB_HOST')`.
- **Excepciones**: cuando algo falla, PHP "lanza" una excepción; quien llama decide qué hacer con `try/catch`. Los `catch` se evalúan **en orden** y gana el primero que coincide: el más específico va primero.

### 2.2 Estructura de carpetas

- [x] Crea `src/includes/` (código compartido, **fuera** de la raíz web `src/public/`). Reflexión: ¿qué pasaría si `connection.php` estuviera dentro de `public/` y PHP dejara de funcionar un día?
- [x] Crea `src/scripts/` para los scripts de consola y mueve ahí `db-check.php` e `info.php` (con `git mv`).
- [x] Mueve el `index.php` del stack (página de diagnóstico) a `public/status.php`: tu `index.php` será el listado. (La página de diagnóstico revela versión de PHP y entorno: en producción habría que protegerla con `APP_ENV` o eliminarla.)

Ver la estructura completa en el [Anexo E](#e-estructura-de-carpetas).

### 2.3 Escribir `src/includes/connection.php`

- [x] Lee `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` con `getenv()` mediante una función `environment()` que lanza `RuntimeException` si falta alguna.
- [x] Construye el DSN con `sprintf`.
- [x] Define el arreglo de opciones PDO con las tres de arriba.
- [x] Crea `$pdo` dentro de `try`. **No muestres** el mensaje técnico: si falla, lanza una excepción propia (ver 2.6).
- [x] Sin etiqueta de cierre `?>` al final. Razón: cualquier espacio o línea en blanco después de `?>` se envía al navegador y provoca "headers already sent" cuando luego uses `header()`.
- [x] `declare(strict_types=1);` al inicio: PHP deja de convertir tipos en silencio y lanza `TypeError`.
- [x] Hecho: `environment()` vive en `helpers.php` para que `connection.php` solo haga **una cosa** (conectar). Se necesitará también para leer `APP_ENV`.

### 2.4 Página de prueba temporal

- [x] `src/public/test.php`: incluye `helpers.php` y `connection.php` con `require_once __DIR__ . '/../includes/...'`. (`__DIR__` es la carpeta del archivo actual; evita errores de ruta relativa según desde dónde se ejecute.)
- [x] `SELECT id, name, last_name, email, created_at FROM users ORDER BY id DESC` con `$pdo->query(...)->fetchAll()`.
- [x] Mostrar los usuarios en una tabla con **todos** los valores escapados (`escape()` de `helpers.php`).
- [x] `strict_types` + `htmlspecialchars()`: `$user['id']` llega como `int` y `htmlspecialchars()` exige `string`. Solución adoptada: `escape(mixed $value): string` con `(string)`.
- [x] Experimento `FETCH_ASSOC`: comenta esa línea de `$options`, haz `var_dump` de la primera fila y describe qué cambia. **Restaura la línea y quita el `var_dump`**.

### 2.5 Provocar errores a propósito (aprendes más rompiéndolo)

Haz cada uno, **predice** qué pasará, mira el navegador y `docker compose logs php`, y **restáuralo**:

- [x] **a)** `DB_PASSWORD` incorrecto en `.env` + `docker compose up -d` → `1045 Access denied`.
- [x] **b)** `docker compose stop db` → `2002 getaddrinfo for db failed` (con el contenedor apagado ni se resuelve el nombre en el DNS de Docker). Luego `docker compose start db`.
- [x] **c)** Nombre de tabla mal escrito → `1146` (SQLSTATE `42S02`): la conexión funcionó, falló la consulta.
- [x] **d)** Quitar `DB_HOST` del `environment` de `docker-compose.yml` → `RuntimeException`: "Falta la variable de entorno DB_HOST". Después restaura el archivo y haz `docker compose up -d`.

### 2.6 Distinguir los errores (excepción propia)

El `try/catch` de `test.php` envuelve conexión **y** consulta, y ambas lanzan `PDOException`. Para distinguirlas:

- [x] Crea `src/includes/DatabaseConnectionException.php`: `class DatabaseConnectionException extends RuntimeException {}`. Una excepción "marcadora" no necesita cuerpo: su valor está en el **tipo**. El archivo se llama como la clase (convención PSR-4 que usará Composer en M8).
- [x] En `connection.php`, captura solo el `PDOException` del `new PDO(...)` y lanza `DatabaseConnectionException` pasando la original como `previous` (encadenar). Así no pierdes el detalle técnico y ganas el significado.
- [x] En `helpers.php`, `log_exception(string $step, Throwable $error)`: registra `[paso] ruta: (código) detalle`, usando la excepción original (`getPrevious()`). Cuidado: en errores de consulta `getCode()` devuelve el SQLSTATE como **texto** (`'42S02'`), así que usa `%s`, no `%d`.
- [x] En `test.php`, tres `catch` en este orden: `DatabaseConnectionException` (503), `PDOException` (500), `RuntimeException` (500).

| Categoría | Tipo | HTTP | Etiqueta del log |
|-----------|------|------|------------------|
| Conexión (clave mala, BD apagada) | `DatabaseConnectionException` | 503 | `[connection]` |
| Consulta (SQL mal escrito) | `PDOException` | 500 | `[query]` |
| Configuración (falta variable) | `RuntimeException` | 500 | `[config]` |

El **visitante** ve un mensaje genérico (sin detalles internos); el **log** y el **código HTTP** sí distinguen. 503 = temporal, se puede reintentar (solo seguro en peticiones idempotentes como `GET`); 500 = fallo nuestro.

### 2.7 Cerrar el MVP

- [x] **Corregir:** `connection.php` hace `require_once` de `database-connection-exception.php`, pero el archivo se llama `DatabaseConnectionException.php` (Linux distingue nombres). Mientras esté así, `test.php` falla con "Failed opening required" (y responde 200 porque `display_errors` está en `On`).
- [x] Completar el experimento de `FETCH_ASSOC` y escribir una frase por cada opción de PDO.
- [x] `http://localhost:8080/test.php` lista los 3 usuarios.
- [x] Con la BD detenida: mensaje genérico y estado 503, sin clave ni host.
- [x] Marcar los checks y hacer commit. (`test.php` se borrará en el MVP 3, cuando su contenido pase a `index.php`.)

### Preguntas de comprobación

1. ¿Qué es un DSN y qué contiene el tuyo? _Es el nombre de origen de datos, Data Sourcee Name, es la cadeda de texto que contiene la información necesario para la conexión a la base de datos, la mira tiene el purte, el nombre de la base de datos y el host._
2. ¿Qué haría PDO sin `ERRMODE_EXCEPTION` (en PHP < 8)? _No lanzaría errores de la base de datos, fallaría en silencio dificultando la depuración de errores._
3. ¿Qué cambia con y sin `FETCH_ASSOC`? _Sin `FETCH_ASSOC` devolvería cada fila como un array híbrido, una vez por el nombre de la columna y otra vez por la posición, con `FETCH_ASSOC` devuelve un array asociativo limpio donde la calves son exclusivamente los nombres de las columnas._
4. ¿Qué ventaja tiene leer las credenciales de variables de entorno? _Seguridad al no escribir contraseñas ni datos sensibles directamente en el código y se puede utilizar el mismo código en diferentes entornos sólo cambiando un archivo._
5. ¿Qué diferencia hay entre `require` y `require_once`? ¿Por qué importa con una función declarada en el archivo? _`require` incluye y ejecuta el archivo cada vez que se le incova y `require_once` verifica si ya se cargó el archivo durente la ejecución actual para no cargarlo dos veces y producir errores._
6. ¿Por qué `connection.php` no lleva `?>` al final? _En archivos que contienen sólo PHP omitir ?> evita cualquier espacio o salto de línea erroneo después del cierre se envíe al navegador_
7. ¿Por qué el orden de los `catch` importa? ¿Qué pasaría si `RuntimeException` fuera el primero? _Porque se evalúan de arriba hacia abajo, jerarquía de herencia. Si se tuviera el `RuntimeException` de primero, `DatabaseConnectionException` y `PDOException` que heredan de `RuntimeException` nunca se ejecutaría el catch._
8. ¿Por qué encadenar la excepción original (`previous`) en vez de lanzar una nueva sin ella? _Permite mostrar una excepción limpia sin perder la traza del error técnico interno_
9. ¿Cuándo un 503 y cuándo un 500? ¿Por qué el visitante no debe ver la causa exacta? _503 se usa para indicar que la app está bien pero hay un servicio externo indespensable caído o saturado temporalmente y 500 para indicar que está ocurriendo un fallo interno inesperado en el código o en la lógica. Porque un mensaje técnico puede revelar la estrucutra de la app o de la base de datos, dando pistas valiosas a un posible atacante._

---

## MVP 3. Listar usuarios (Read)

**Objetivo:** pantalla principal con la tabla de usuarios, estilos y mensajes de confirmación.
**Resultado:** `http://localhost:8080/` muestra el listado.
**Guía:** §4.1, §7.1, §7.2, §7.7.

Se hace en sub-pasos pequeños; prueba en el navegador después de cada uno.

### 3.1 Cabecera y pie compartidos

Concepto: todas las páginas repiten el mismo inicio/fin de HTML. Se extrae a `header.php` y `footer.php` para no copiarlo en cada archivo. Van en `src/includes/layout/`.

- [x] `src/includes/layout/header.php`: `<!DOCTYPE html>`, `<html lang="es">`, `<head>` con `charset UTF-8`, `viewport`, `<title>`, enlace al CSS; abre `<body>`, un encabezado con el título del sitio y abre `<main>`.
- [x] `src/includes/layout/footer.php`: cierra `</main>`, `</body>`, `</html>`.
- [x] Ruta del CSS: usa `/css/style.css` (con barra inicial) para que funcione desde cualquier página. ¿Qué pasaría con `css/style.css` si luego una página estuviera en una subcarpeta?
- [x] `public/index.php` hoy es una página de bienvenida provisional: reemplázala. `require` de header, un texto de prueba, `require` de footer. Recarga y revisa el HTML generado con "Ver código fuente" (`Ctrl+U`): ¿ves PHP? (Guía §4.1: el navegador nunca ve el PHP).

> Regla que vas a necesitar en Crear/Editar: **la lógica va antes del `require` del header**, porque `header()` (redirecciones) no funciona si ya se imprimió HTML.

### 3.2 Tabla con datos de verdad

- [x] Traslada el contenido de `test.php` a `index.php`. **Arriba**: `require_once` de `helpers.php` y `connection.php` y la consulta con `$pdo->query(...)->fetchAll()` a `$users`. Orden: `ORDER BY id DESC` (más nuevos primero).
- [x] Reutiliza el manejo de errores del MVP 2 (503 / 500 y `log_exception`). Observa que ese bloque se repetirá en cada página: es una pista para M8.
- [x] Debajo del header: título "Listado de usuarios" con el conteo `count($users)`.
- [x] Tabla con columnas ID, Nombre (nombre + apellido), Correo, Teléfono, Registro, Acciones.
- [x] Usa `foreach ($users as $u): ... endforeach;` (sintaxis alternativa para mezclar con HTML) y `<?= ... ?>` para imprimir.
- [x] Teléfono `NULL`: muestra `—` usando el operador `??`. Investiga qué hace `??`.

### 3.3 Escapar la salida (seguridad: XSS)

- [x] Envuelve **cada** valor impreso en `htmlspecialchars()`.
- [x] **Experimento:** primero comprueba el ataque. Inserta desde Adminer un usuario con nombre `<script>alert(1)</script>`. Con `htmlspecialchars` quitado en esa celda, recarga: se ejecuta el script. Vuelve a ponerlo: se ve como texto. Mira el código fuente para ver las entidades (`&lt;`).
- [x] Borra ese usuario de prueba (hazlo con SQL en Adminer).
- [x] Ya tienes `escape()` en `includes/helpers.php` (flags `ENT_QUOTES | ENT_SUBSTITUTE`, `UTF-8`, y `(string)` para valores `int`/`null`). Úsala en cada valor del listado y explica cada flag.

### 3.4 Estado vacío

- [x] Si `$users` está vacío, muestra "No hay usuarios registrados." en vez de la tabla (usa `empty()` o `count()`).
- [x] Pruébalo vaciando la tabla en Adminer (`TRUNCATE users` o DELETE) y vuelve a cargar el seed con `docker compose exec -T db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" crud_usuarios' < database/02-seed.sql`.

### 3.5 Mensajes de confirmación por URL

Concepto: las demás páginas redirigirán a `index.php?msg=created`. El listado mostrará un aviso.

- [x] Define un arreglo asociativo `$mensajes` con las claves permitidas (`created`, `updated`, `deleted`) y su texto.
- [x] Lee `$_GET['msg'] ?? ''`.
- [x] Muestra el mensaje **solo si la clave existe** en `$mensajes`. Pruébalo: `/?msg=created` muestra el aviso; `/?msg=<b>hola</b>` no muestra nada. Pregúntate por qué esa lista blanca es más segura que imprimir `$_GET['msg']` directo.

### 3.6 Estilos (`public/css/style.css`)

El CSS no es el foco del curso. Clases que usa la guía (§7.7): `.contenedor`, `.btn`, `.btn-claro`, `.btn-sm`, `.mensaje`, `.errores`, `.formulario`, `.campo`, `.vacio`.

- [x] Decisión actual: Bootstrap 6.0.0-alpha.1 (CSS y JS) + Bootstrap Icons por CDN (en `header.php`). Compromisos: depende de internet y de un tercero; investiga el atributo `integrity` (SRI) en las etiquetas `<link>`/`<script>` para verificar que el archivo no fue alterado.
- [ ] Opcional: CSS propio en `public/css/style.css` (estilo de la guía: blanco y negro, Arial). Si lo haces, verifica que Nginx lo sirve: http://localhost:8080/css/style.css.

### 3.7 Botones (aún sin función)

- [x] Enlace "Nuevo usuario" → `create.php`.
- [x] En cada fila: enlace "Editar" → `edit.php?id=<id>`.
- [x] En cada fila: **formulario** `method="post" action="delete.php"` con `<input type="hidden" name="id">` y botón "Eliminar". Añade `onsubmit="return confirm('¿Eliminar este usuario?');"`.
- [x] Pregunta: ¿por qué Eliminar es un formulario POST y no un enlace como Editar? (se explica en MVP 6, ve pensándolo).

### 3.8 Probar

- [x] Muestra los 3 usuarios con el más nuevo primero.
- [x] El teléfono NULL se ve como `—`.
- [x] `/?msg=created` muestra el mensaje; un valor raro no muestra nada.
- [x] `<script>` en un nombre NO se ejecuta.
- [x] Tabla vacía muestra "No hay usuarios registrados."
- [x] Hay estilos aplicados.
- [x] Borra `test.php` (su contenido ya está en `index.php`).
- [x] Commit.

### Preguntas de comprobación

1. ¿Qué es XSS y qué función lo evita? ¿Dónde se aplica: al guardar o al mostrar? _XSS (Cross-Site Scripting) es cuando un atacante consigue que se ejecute su código JavaScript en el navegador de otra persona. Eso pasa si la página imprime datos externos sin escapar. Se evita con htmlspecialchars(), que en tu proyecto está envuelta en escape(). Se aplica al mostrar, no al guardar._
2. ¿Por qué `query()` es seguro aquí sin consulta preparada? ¿Cuándo dejaría de serlo? _Es seguro porque la consulta es un texto fijo, sin ningún dato del usuario (SELECT ... FROM users ORDER BY id DESC). Dejaría de serlo en cuanto se concatene o interpole un valor externo en el SQL, como `$_GET['id']`, un filtro de búsqueda o un orden elegido por el usuario. En ese caso hay que usar prepare() con parámetros._
3. ¿Qué hace `<?= ?>`? _Es la forma corta de <?php echo ... ?>. Evalúa la expresión y la imprime en el HTML._
4. ¿Por qué `$mensajes[$msg]` en vez de imprimir `$msg`? _Porque msg viene de la URL y cualquiera puede escribir lo que quiera. Con un mapa de mensajes fijos, el usuario solo elige una clave y el texto que se muestra lo decide el servidor. Así nadie puede inyectar contenido propio en la página._

---

## MVP 4. Crear usuario (Create)

**Objetivo:** formulario de alta con validación en servidor y guardado en BD.
**Resultado:** puedes crear usuarios desde el navegador y verlos en el listado.
**Guía:** §7.3, §7.4, §8.1.

Se construye en capas; no escribas todo de golpe.

### 4.1 Conceptos

- **GET** pide una página; **POST** envía datos. Un mismo archivo (`create.php`) hace las dos cosas distinguiendo con `$_SERVER['REQUEST_METHOD']`.
- **Validación en servidor**: los atributos HTML (`required`, `type="email"`) son comodidad; cualquiera los salta (herramientas del navegador, `curl`). Solo cuenta lo que valida PHP.
- **Consulta preparada**: se envía primero la **estructura** del SQL con marcadores (`:name`) y luego los **valores** por separado; MySQL nunca interpreta los valores como código. Esto elimina la inyección SQL.
- **Post/Redirect/Get (PRG)**: tras guardar, se redirige con `header('Location: ...')` + `exit`, para que recargar no reenvíe el formulario.

### 4.2 Paso a paso

**4.2.1 Solo el formulario (GET)**

- [x] Crea `public/create.php`. Lógica arriba (aún vacía), luego `require` del header, el formulario y `require` del footer.
- [x] Formulario `method="post" action="create.php"` con campos `name`, `last_name`, `email` (`type="email"`), `phone`; cada uno con `<label for>` enlazado al `id` del input, `maxlength` acorde a la tabla y `required` donde corresponda.
- [x] Botones Guardar y Cancelar (enlace a `index.php`).
- [x] Prueba: el botón "Nuevo usuario" del listado abre el formulario.

**4.2.2 Recibir el POST y verlo**

- [x] Arriba: `if ($_SERVER['REQUEST_METHOD'] === 'POST') { ... }`. Dentro, haz un `var_dump($_POST); exit;` temporal.
- [x] Envía el formulario y observa `$_POST`. Fíjate en que las claves son los atributos `name` de los inputs.
- [x] Quita el `var_dump`.

**4.2.3 Limpiar los datos**

- [x] Prepara un arreglo `$data` con las 4 claves vacías (también sirve para rellenar el formulario la primera vez).
- [x] En el POST, recorre `$data` con `foreach` y reemplaza cada valor por `trim($_POST[$campo] ?? '')`. Entiende qué hace `trim` y por qué el `?? ''` evita una advertencia.

**4.2.4 Validar**

- [x] Crea `$errors = []` al inicio.
- [x] Reglas (Guía §7.3): nombre y apellido no vacíos; correo con `filter_var($x, FILTER_VALIDATE_EMAIL)`; teléfono opcional pero, si viene, que cumpla la expresión regular de 7 a 20 caracteres entre dígitos, `+`, espacios y `-` (investiga `preg_match` y desglosa la regex carácter por carácter).
- [x] Cada fallo agrega un mensaje a `$errors`.
- [x] En el formulario, si `$errors` no está vacío, muéstralos en una lista dentro de un recuadro `.errores` (con `htmlspecialchars`).
- [x] Cada input debe conservar lo escrito: `value="<?= ... $data['name'] ... ?>"` (¿por qué escapado?).
- [x] Prueba: manda el formulario con campos vacíos, con correo malo y con teléfono `abc`. Los valores válidos se conservan.
- [x] **Experimento:** en el navegador (F12) quita el atributo `required` del input y envía vacío: la validación del servidor lo detiene. Ese es el motivo de validar en PHP.

**4.2.5 Insertar con consulta preparada**

- [x] Si `$errors` está vacío: `$pdo->prepare('INSERT INTO users (...) VALUES (:name, ...)')` y `execute([...])` con un arreglo de pares `:marcador => valor`.
- [x] Teléfono vacío debe guardarse como `null`, no como `''`. (Pista: operador ternario.)
- [x] Prueba: crea un usuario válido y mira en Adminer que se guardó. Crea otro sin teléfono y verifica que sea `NULL`.
- [x] **Experimento de seguridad:** crea un usuario con nombre `'; DROP TABLE users; --`. Comprueba que se guarda como texto y la tabla sigue viva. Explica por qué (los valores viajan separados de la estructura).

**4.2.6 Redirigir (PRG)**

- [x] Tras `execute`, `header('Location: index.php?msg=created'); exit;`
- [x] Prueba: tras guardar se ve el listado con el mensaje verde. Recarga (F5): **no** se crea un duplicado.
- [x] **Experimento:** quita temporalmente el `exit` y mira que el resto del script sigue ejecutándose. Entiende por qué es indispensable. Luego restáuralo.
- [x] Provoca el error clásico: pon texto antes de `<?php` (o un `echo` antes de `header()`) y lee "headers already sent". (Guía §10.)

**4.2.7 Correo duplicado**

- [x] Envuelve el insert en `try/catch (PDOException $e)`.
- [x] Distingue el error: `$e->getCode() === '23000'` agrupa **cualquier** violación de integridad (UNIQUE 1062, NOT NULL 1048, ...). El número exacto está en `$e->errorInfo[1]`; decide cuál usar y por qué. Si es el duplicado, agrega "Ya existe un usuario con ese correo."; si es otro error, mensaje genérico y `log_exception(...)` (del MVP 2). (La guía muestra el detalle técnico; es útil en desarrollo, malo en producción: decide tú cómo manejarlo.)
- [x] Prueba: crea dos veces el mismo correo.
- [x] Piensa: ¿por qué es mejor capturar el 23000 que hacer primero un `SELECT` para ver si existe el correo? (pista: dos usuarios a la vez.)

### 4.3 Probar (flujo completo, Guía §8.1)

- [x] Nuevo usuario válido → aparece primero en el listado con mensaje.
- [x] Campos inválidos → errores y datos conservados.
- [x] Correo repetido → mensaje claro.
- [x] F5 después de guardar no duplica.
- [x] Nombres con tildes/ñ se guardan y se ven bien.
- [x] Commit.

### 4.4 Línea base de respuestas de `create.php`, `edit.php` y `delete.php`

Medido con `curl -i` contra `http://localhost:8080/create.php` (tabla siguiente) y, más abajo, `edit.php`. Sirve de referencia para la **prueba de no regresión** del refactor del MVP 5 (5.2.0): después de compartir código, `create.php` debe responder igual.

| # | Caso | Código esperado | Código obtenido | Qué se ve |
|---|---|---|---|---|
| 1 | GET | 200 | **200 OK** | Formulario vacío |
| 2 | POST vacío | 422 | **422** | Errores en nombre, apellido y email |
| 3 | Teléfono inválido (`-------`) | 422 | **422** | "El teléfono debe tener entre 7 y 15 dígitos." |
| 4 | Correo duplicado | 409 | **409 Conflict** | "Ya existe un usuario registrado con ese correo." bajo el campo Email |
| 5 | POST válido | 302 | **302 Found** | `Location: index.php?msg=created` |
| 6 | BD detenida | 503 | **503 Service Unavailable** | "No se pudo conectar a la base de datos. Inténtalo más tarde." |

Regla de códigos: si el envío no se completó, nunca es 200. **422** = datos inválidos, **409** = chocan con lo ya guardado (email duplicado), **5xx** = fallo del servidor (503 sin conexión, 500 error de consulta o inesperado).

> **Nota (2026-11-19): los POST de `create.php` y `edit.php` ahora exigen `Origin` o `Referer`.** Las tablas de abajo se midieron antes de ese cambio, con `curl` sin cabeceras. Para repetirlas hay que añadir `-H "Origin: http://localhost:8080"` (el valor de `APP_ORIGIN`); sin ella cualquier POST a esas páginas responde **403**, antes de validar nada. Los casos de la tabla no cambian en lo demás, salvo uno nuevo en `edit.php`: **409** cuando otra persona modificó al usuario mientras se editaba (ver `row_token` en el README). `tests/smoke.sh` ya envía el `Origin` en todos sus POST.

Notas:
- En los 422, `curl` muestra `HTTP/1.1 422 ` sin texto de razón: PHP no lo añade para ese código. Es válido.
- El caso 5 crea un usuario real: borra la fila de prueba después. El caso 6 se prueba parando el contenedor (`docker compose stop db`) y levantándolo de nuevo (`docker compose start db`).
- Con la BD detenida, `index.php` también responde 503.

**`edit.php`** (medido con `curl -i` contra `http://localhost:8080/edit.php`, con un usuario temporal que se borra al terminar). Orden de las comprobaciones: primero el `id` (404), después los campos (422) y por último la BD (409, 503, 500).

| # | Caso | Código esperado | Código obtenido | Qué se ve |
|---|---|---|---|---|
| 1 | GET con `id` válido y existente | 200 | **200 OK** | Formulario con los datos actuales, `id` oculto y el teléfono `NULL` pintado como vacío |
| 2 | GET sin `id`, `?id=abc`, `?id[]=1`, `?id=0`, `?id=9999`, `?id=+14`, ` 14`, `14 ` o `014` | 404 | **404 Not Found** | "Usuario no encontrado" con enlace al listado |
| 3 | POST sin `id` o con un `id` inexistente | 404 | **404 Not Found** | Igual que el caso 2 |
| 4 | POST con datos inválidos (nombre vacío, teléfono `-------`) | 422 | **422** | Errores bajo cada campo; conserva lo escrito y el `id` oculto |
| 5 | POST con el correo de otro usuario | 409 | **409 Conflict** | "Ya existe otro usuario registrado con ese correo." bajo el campo Email; la BD no cambia |
| 6 | POST válido que solo cambia el teléfono (mismo correo, también en mayúsculas) | 302 | **302 Found** | `Location: index.php?msg=updated`; no da error de duplicado |
| 7 | POST con el teléfono vacío | 302 | **302 Found** | El teléfono queda en `NULL` (comprobado con `IS NULL`) |
| 8 | GET con la BD detenida | 503 | **503 Service Unavailable** | Solo el aviso "No se pudo conectar a la base de datos..."; sin formulario (no hay nada que conservar) |
| 9 | POST con la BD detenida | 503 | **503 Service Unavailable** | El formulario se conserva con lo escrito y el `id` oculto, más el aviso de conexión |
| 10 | GET del `id` de un usuario ya borrado | 404 | **404 Not Found** | Igual que el caso 2 |
| 11 | POST que no cambia ningún valor | 302 | **302 Found** | `Location: index.php?msg=updated`; no se confunde con un usuario inexistente |
| 12 | POST mientras otra sesión borra al usuario (el `SELECT` lo ve, el `UPDATE` no) | 404 | **404 Not Found** | "Usuario no encontrado", no "actualizado" |

Notas de `edit.php`:
- Resultado de la última ejecución, que incluye también los seis casos de `create.php` y `index.php` con la BD detenida: **24 comprobaciones con `curl`, 24 correctas**, más **20 de las decisiones de la revisión** (id canónico, `rowCount()` con `FOUND_ROWS` y BD caída al enviar), también correctas. `create.php` responde igual que antes de añadir `edit.php` y de activar `FOUND_ROWS`.
- El `id` inválido y el inexistente comparten el mismo 404; por eso las pruebas de 5.3 sobre `/edit.php`, `?id=abc` y `?id=9999` esperan 404 y no una redirección (ver la observación de 5.2).
- Con la BD caída: en el GET solo se muestra el aviso; en el POST se conserva el formulario con lo escrito (decisión 2 de la revisión de `edit.php`).

**`delete.php`** (medido con `curl -i`, sobre un usuario de prueba creado desde el formulario). Orden de las comprobaciones: método (405), id (400) y por último la BD.

| # | Caso | Código esperado | Código obtenido | Qué se ve |
|---|---|---|---|---|
| 1 | GET, PUT o DELETE (con o sin `Origin`) | 405 | **405 Method Not Allowed** | `Allow: POST`, texto plano; no borra |
| 1b | POST con origen ajeno, `Origin: null` o **sin `Origin` ni `Referer`** | 403 | **403 Forbidden** | "Origen no permitido." en texto plano; no borra y no valida el id ni abre la BD |
| 2 | POST con `Origin` correcto y sin `id`, `abc`, `id[]=1`, `0` o `+ID` | 400 | **400 Bad Request** | Texto plano; no abre la BD (responde 400 también con la BD detenida) |
| 3 | POST con el `id` de un usuario existente | 302 | **302 Found** | `Location: index.php?msg=deleted`; la fila desaparece |
| 4 | POST repetido o con un `id` válido que no existe | 302 | **302 Found** | `Location: index.php?msg=missing`; no es un error (borrar es idempotente) |
| 5 | POST válido con la BD detenida | 503 | **503 Service Unavailable** | Página HTML con el aviso y "Volver al listado"; sin redirección |

Nota de `delete.php`: orden **405 → 403 → 400 → BD**. Todos los POST de las pruebas llevan `-H "Origin: http://localhost:8080"` (sin él darían 403). 32 comprobaciones del borrado y 23 del origen, todas correctas; `create.php` y `edit.php` responden igual que antes (24 de 24 y 20 de 20).

### Preguntas de comprobación

1. ¿Qué diferencia hay entre `prepare` y `execute`? ¿Qué ve MySQL en cada paso? _En prepare() MySQL recibe solo la plantilla del SQL con marcadores (`INSERT INTO users (...) VALUES (:name, ...)`), sin ningún dato; la analiza y fija su estructura. En execute() recibe los valores por separado, como datos, y los coloca en los huecos sin volver a interpretarlos como SQL. Por eso un nombre como `'; DROP TABLE users; --` se guarda como texto y no cambia la consulta. Esto es real porque en connection.php está `PDO::ATTR_EMULATE_PREPARES => false`; con la emulación activada PDO sustituiría y escaparía los valores en el cliente. Si se concatenara el nombre en el SQL, los datos se mezclarían con el código antes de que MySQL lo lea y el usuario podría alterar la consulta (incluso un `O'Brien` legítimo la rompería)._
2. ¿Qué pasa si falta `exit` tras `header('Location')`? _header() solo añade una cabecera a la respuesta, no detiene el script: PHP sigue ejecutando el resto. El navegador sigue la redirección, pero la respuesta también lleva el cuerpo con todo lo que se imprimió después, y cualquiera con curl lo puede leer. En una página protegida sin sesión eso filtra el contenido privado; además, el código siguiente (un INSERT, un borrado, un correo) se ejecuta igualmente. Lo probé con una página de prueba: la respuesta fue `302` con `Location: login.php` y, aun así, el cuerpo traía `DATOS SECRETOS`. Por eso siempre `exit;` justo después de `header('Location: ...')`._
3. ¿Por qué se valida en el servidor si ya hay `required` en el HTML? _Porque los atributos HTML los controla el cliente y cualquiera puede saltárselos: quitando `required` con F12, cambiando el `type`, o enviando el POST directo con curl, Postman o un script, sin pasar por el formulario. El servidor no sabe de dónde vino la petición, así que solo lo que valida PHP cuenta. `required`, `type="email"` y `maxlength` se mantienen como comodidad para el usuario honesto, no como seguridad._
4. ¿Por qué el teléfono vacío se guarda como `NULL`? _Porque el teléfono es opcional y `NULL` significa "no hay dato", mientras que `''` es un valor (una cadena vacía) que se confunde con un teléfono real. La columna es `DEFAULT NULL`, así que `NULL` es su forma natural de decir "sin teléfono". Además simplifica el resto: se consulta con `IS NULL`, y la vista muestra el guion con `$user['phone'] ?? "-"`, que solo reacciona ante `NULL` y no ante una cadena vacía. Se consigue con un ternario: `$data['phone'] !== '' ? $data['phone'] : null`._
5. ¿Por qué la lógica va antes del `require` del header? _Porque header(), http_response_code() y las redirecciones modifican las cabeceras HTTP, y estas deben enviarse antes que el cuerpo. En cuanto PHP imprime el primer byte de HTML (el header del layout, o incluso un espacio o una línea en blanco), las cabeceras ya salieron y fallan con "headers already sent". Por eso create.php valida, guarda, fija el código de estado (422, 409, 503, 500) y redirige antes de hacer `require` del header; solo después se renderiza la vista. Es el mismo orden que usa index.php: primero la lógica y los errores, luego el HTML._

---

## MVP 5. Editar usuario (Update)

**Objetivo:** modificar un usuario existente.
**Resultado:** botón "Editar" abre el formulario relleno y guarda cambios.
**Guía:** §7.5, §8.2.

### 5.1 Conceptos

`edit.php` es `create.php` con **tres diferencias**: (1) identificar el registro, (2) cargarlo desde la BD, (3) usar `UPDATE ... WHERE id = :id`.

### 5.2 Paso a paso

**5.2.0 Refactor previo: compartir lo común (decidido al empezar el MVP 5)**

Antes de escribir `edit.php`, sacar de `create.php` lo que va a repetirse. Regla: lo que no toca la BD se comparte; lo que toca la BD se queda en cada página.

- [x] Compartir: la definición de campos, la validación y el dibujo del formulario (nombres de archivos y funciones: decide tú, en `src/includes/`).
- [x] Se queda en cada página: conexión, `INSERT`/`UPDATE`, códigos HTTP del guardado y redirección.
- [x] **Prueba de no regresión:** `create.php` se comporta **igual** que antes (mismos mensajes, mismos códigos 200/422/409/503, mismos datos conservados).
- [x] Commit del refactor, aparte del resto del MVP.

**Preguntas de diseño del refactor (responder antes de escribir código)**

1. ¿Qué necesita la validación como entrada y qué devuelve? ¿Lee `$_POST` por dentro o recibe un arreglo? _Conviene que reciba un arreglo y que no toque la superglobal. Entrada: los valores crudos como arreglo (`$_POST` en la página, pero podría ser cualquier otro) y la definición de campos. Salida: los datos ya limpios (`trim`, email en minúsculas, valores no string tratados como vacío) y el arreglo `$errors` (campo => mensaje). Así es una función pura: mismo arreglo de entrada, mismo resultado, sin depender de una petición HTTP, y se prueba con arreglos literales (`validate_user(['name' => '', ...])`) sin navegador ni formulario. Además la página decide de dónde salen los datos: en edit.php el GET rellena `$data` desde la BD y el POST desde lo enviado, y la validación no tiene que enterarse. Lo que hoy es el `foreach` con `trim($_POST[$campo] ?? '')` pasa a ser una función que recibe `$source` en vez de leer `$_POST`._
2. ¿Qué parte de "correo duplicado" pertenece a la validación y cuál a la BD? ¿La regla distinta de edit.php confirma dónde debe vivir? _A la validación le toca todo lo que se decide con los datos enviados y nada más: obligatorio, longitud, formato. A la BD le toca el duplicado, porque depende del estado de la tabla y solo el `UNIQUE` lo comprueba de forma atómica (ver 4.2.7: un `SELECT` previo tiene la carrera de dos peticiones simultáneas). Sí, edit.php lo confirma: si el duplicado viviera en la validación como un `SELECT ... WHERE email = :email`, edit.php necesitaría `AND id <> :id` para no chocar consigo mismo, y la validación tendría que conocer el id y tener acceso a la BD (dejaría de ser pura). Con el `UNIQUE` no hay nada que cambiar: un `UPDATE` que deja el correo igual en su propia fila no viola el índice, y si el correo es de otro usuario salta el 1062, que cada página traduce a su mensaje ("ya existe un usuario con ese correo" en create, "otro usuario" en edit). Por eso la comprobación se queda en cada página, junto al `INSERT`/`UPDATE`, tal como dice la regla de 5.2.0._
3. El formulario necesita la URL de destino, el texto del botón y si lleva un `id` oculto. ¿Cómo se le pasa esa información al archivo compartido? _Como variables que la página define justo antes del `require`, documentadas en un docblock `@var` al inicio del parcial, igual que `partials/users-list.php`. Propuesta: `$fields` (definición de campos), `$data` (valores a pintar), `$errors`, `$action` (URL de destino, `create.php` o `edit.php`), `$submitLabel` ("Registrar" o "Actualizar") y `$userId` (`int|null`: si no es `null`, el parcial pinta `<input type="hidden" name="id">`; en create vale `null`). Todo lo que cambia entre páginas entra por esas variables y el parcial no pregunta en qué página está. La alternativa sería una función `render_user_form(array $options)` con parámetros explícitos: es más estricta y no depende del ámbito, pero el precedente del proyecto es el parcial con variables, y el docblock `@var` también apaga los avisos del IDE (`Undefined variable`)._
4. ¿Dónde viven los archivos nuevos? ¿Una función con la lógica es una "vista parcial"? _Propuesta: la lógica (definición de campos, lectura/limpieza del arreglo y validación) va en `includes/user-validation.php`, junto a `helpers.php` y `connection.php`: son funciones de PHP puro, sin HTML y sin BD (las funciones y datos del dominio). El dibujo del formulario va en `includes/partials/user-form.php`, porque es un fragmento de HTML que lee variables, como `users-list.php`. `includes/layout/` se queda solo con el marco de la página (`header.php` y `footer.php`). No, una función con lógica no es una vista parcial: la función recibe parámetros, devuelve valores, no imprime nada y se puede probar sola; el parcial imprime HTML y toma las variables del ámbito de quien lo incluye, y se prueba mirando el resultado. Mezclarlos haría que cambiar un mensaje de error obligue a tocar HTML, o que cambiar el HTML pueda romper la validación. La regla práctica para ubicar un archivo es: ¿devuelve un valor? va en `includes/`; ¿pinta HTML de una zona de la página? va en `partials/`; ¿es el marco común de todas las páginas? va en `layout/`._

**Preguntas de diseño de edit.php (responder antes de escribir código)**

1. ¿De dónde sale el `id`? ¿Qué riesgo hay en aceptar el `id` de GET o de POST indistintamente? _La fuente debe depender del método: en GET se lee solo `$_GET['id']` (al abrir) y en POST solo `$_POST['id']` (al enviar), nunca `$_GET['id'] ?? $_POST['id']`. Con el `??` mezclado, una petición `POST edit.php?id=5` con cuerpo `id=7` es ambigua: según qué línea del código lea cuál, se podría cargar y mostrar al usuario 5 y hacer el `UPDATE` sobre el 7 (o al revés), es decir, modificar un registro distinto del que se está viendo. Un enlace o formulario manipulado puede provocar esa mezcla. Y el id debe leerse en un solo sitio, una vez, y reutilizarse la variable ya validada (no volver a leer `$_GET`/`$_POST` más abajo). Esto es un problema de integridad, no de permisos: hasta el MVP 9 (login) cualquiera puede editar cualquier id de todas formas._
2. ¿Qué tipo de dato llega? ¿Qué hace `filter_var` con un arreglo y con `'+5'`? ¿Qué rango tiene sentido? _Lo probé en `docker compose exec php php -r '...'`. Con `?id[]=1`, `$_GET['id']` es un arreglo y `filter_var(['1'], FILTER_VALIDATE_INT)` devuelve `false` (también con `[]` y con `null`), así que un arreglo se rechaza solo. `'+5'` devuelve `5`, y también `' 5'` y `'5 '` (acepta signo `+` y espacios en los bordes, igual que `'-5'` devuelve `-5`); en cambio rechaza `'05'`, `'5.0'`, `'1e3'`, `'0x1A'`, `''` y `'abc'`. Sin rango, `'0'`, `'-1'` y `'4294967296'` salen como enteros válidos, y eso no sirve: la columna es `INT UNSIGNED AUTO_INCREMENT`, es decir 0 a 4294967295 pero el primer id real es 1. Por eso se usa `filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]])`, y se comprueba con `=== false`, que es la forma explícita de detectar el fallo (sin el rango, el `0` sería un entero válido pero `!$id` lo trataría como error). Detalle: `'+5'`, `' 5'` y `'5'` son tres URLs para el mismo usuario; es inofensivo y lo dejo así, pero si se quisiera una sola URL canónica habría que exigir que `(string) $id === $raw`._
3. ¿Qué hacer con un `id` inválido o inexistente: redirigir o 404? _404 con una página que explique "Usuario no encontrado" y un enlace al listado, para el inválido y para el inexistente. Justificación: el visitante sí ve una explicación en vez de caer en el listado sin saber qué pasó; un rastreador o un comprobador de enlaces recibe un 404 real y deja de pedir esa URL, mientras que con una redirección ve un 302 seguido de un 200 y cree que el recurso existe; y los registros y las pruebas automáticas distinguen "no existe" de un acceso normal, que es la misma regla que ya sigue create.php (si no se completó, nunca es 200). Además, la comprobación (el `SELECT` con `fetch() === false`) se hace también al enviar el POST, para que un usuario borrado entre que se abrió el formulario y se envió dé 404 y no un "guardado" falso: un `UPDATE` sobre un id inexistente no falla, solo afecta a 0 filas._

> **Observación (decisión aceptada): `id` inválido o inexistente responde 404, no redirige.** Esta decisión sustituye a las instrucciones "redirige al listado" de los puntos *Identificar* y *Cargar* de abajo y a la prueba de 5.3 sobre `/edit.php`, `?id=abc` y `?id=9999`; esos textos se dejan como estaban y esta observación prevalece sobre ellos. Cómo se hará:
>
> 1. **Un solo camino para los dos casos.** El `id` se lee según el método (GET: `$_GET['id']`, POST: `$_POST['id']`) y se valida con `filter_var(..., FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]])`. Si da `false` (falta, arreglo, texto, fuera de rango) o si el `SELECT ... WHERE id = :id` devuelve `false` (no existe), se llega al mismo punto: `http_response_code(404)`, el título de página "Usuario no encontrado", el mensaje con enlace a `index.php` y `exit`.
> 2. **La página de error se dibuja con un parcial,** `includes/partials/user-not-found.php` (HTML sin lógica, como el resto de `partials/`), entre el `require` del header y el del footer. Así la lógica sigue antes del header, como en `create.php`, y el 404 se reutilizará en `delete.php` si hace falta.
> 3. **La comprobación se repite en el POST.** Al enviar, `edit.php` vuelve a validar el `id` y a hacer el `SELECT` antes del `UPDATE`; si el usuario se borró entre que se abrió el formulario y se envió, responde 404 y no un guardado falso (un `UPDATE` sobre un id inexistente no falla, afecta a 0 filas).
> 4. **Orden de las comprobaciones:** primero el `id` (404), después la validación de campos (422) y por último la BD (409 duplicado, 503/500 fallos). Un `id` inexistente nunca llega a validar el formulario.
> 5. **Códigos que debe devolver `edit.php`** (se sumarán a la línea base de 4.4): GET con `id` válido y existente 200; GET sin `id`, con `?id=abc`, `?id[]=1`, `?id=0` o `?id=9999` 404; POST con campos inválidos 422; POST con correo de otro usuario 409; POST válido 302 a `index.php?msg=updated`; BD detenida 503.
> 6. **Nginx no interfiere:** `docker/nginx/default.conf` no define `error_page` ni `fastcgi_intercept_errors`, así que el cuerpo del 404 generado por PHP llega al navegador, igual que ya ocurre con 409 y 422.

- [x] Reutiliza lo compartido en 5.2.0; no copies `create.php`.
- [x] **Identificar:** el `id` llega por la URL (GET, al abrir) o por un campo oculto (POST, al enviar). Léelo con `$_GET['id'] ?? $_POST['id'] ?? null` y valídalo con `filter_var(..., FILTER_VALIDATE_INT)`. Si no es válido: redirige al listado y `exit`.
- [x] **Cargar:** `SELECT` preparado `WHERE id = :id` + `fetch()`. Si devuelve `false` (no existe), redirige al listado.
- [x] **Rellenar:** la primera vez (GET), `$data` se llena con lo cargado de la BD. Si hay POST, se llena con lo enviado.
- [x] Formulario con `<input type="hidden" name="id" value="...">` y el mismo conjunto de campos, mismas validaciones y mensajes de error. Botón "Actualizar".
- [x] **UPDATE:** `UPDATE users SET nombre = :name, ... WHERE id = :id`, con `execute` (ahora son 5 parámetros).
- [x] Correo duplicado: el mismo `catch` 23000 con mensaje "otro usuario". Piensa por qué guardar sin cambiar el correo **no** da error (el registro no choca consigo mismo).
- [x] Redirige a `index.php?msg=updated` con `exit`.
- [x] Reflexión: ¿qué quedó repetido aun después del refactor (el patrón `try/catch` de errores, por ejemplo)? Anótalo; lo mejoraremos en M8. _Quedó repetido: (1) el bloque `try/catch` de errores, casi idéntico en `index.php`, `create.php` y `edit.php`: `DatabaseConnectionException` → 503 + aviso de conexión, `PDOException` → 500 + `log_exception('query', ...)`, `RuntimeException` → 500 + `log_exception('config', ...)`; solo cambian los textos y la rama del 1062. (2) El preámbulo de cada página: `require` de `helpers.php`, `$errors = []`, `$errorMessage = null`, `$submitted`, `require connection.php` dentro del `try`. (3) La correspondencia de `$data` con los parámetros SQL, escrita dos veces (`INSERT` en create y `UPDATE` en edit), incluida la regla "teléfono vacío → `NULL`": si se añade un campo hay que tocar `user_fields()`, el esquema y los dos SQL. (4) La interpretación del error 1062, que es un código de MySQL: las páginas conocen detalles del motor. Cómo atacarlo en M8: (a) `UserRepository` (8.4) con `find(int $id)`, `create(array $data)` y `update(int $id, array $data)` como único lugar con SQL y con el mapeo `''` → `NULL`, recibiendo el `PDO` por constructor; (b) el repositorio traduce el 1062 a una excepción propia (`DuplicateEmailException`), así las páginas no conocen códigos de MySQL (con PostgreSQL sería otro código y solo cambiaría el repositorio) y distinguen el caso por el **tipo** de la excepción, como ya hace `DatabaseConnectionException` (8.4b); (c) el manejo de errores en un solo sitio que traduce tipo de excepción → código HTTP + mensaje (503, 409, 500, más el `log_exception`), idealmente el `try/catch` único del front controller del reto final, de modo que las páginas no tengan ningún `catch`; (d) `UserValidator` (8.5) en lugar de las funciones de `user-validation.php` y las vistas compartidas (8.6) en lugar de `partials/user-form.php`._

> **Decisiones tras revisar `edit.php`** (las tres se implementaron y se probaron):
>
> 1. **El `UPDATE` que no afecta a nadie.** `rowCount()` normal no sirve para detectar el borrado: en MySQL devuelve 0 tanto si la fila no existe como si existe pero los valores no cambian (comprobado: 0 y 0). Con `Pdo\Mysql::ATTR_FOUND_ROWS => true` cuenta las filas que coinciden con el `WHERE` (1 y 0; la constante `PDO::MYSQL_ATTR_FOUND_ROWS` es su alias, vale lo mismo y está obsoleta desde PHP 8.5, y `Pdo\Mysql` existe desde PHP 8.4, la versión que fija el Dockerfile), y entonces 0 significa solo "ya no existe". **Se cubre**, no se documenta como riesgo: cuesta una opción en `connection.php` y un `if` en `edit.php`, y sin ello el visitante vería "usuario actualizado" sobre un usuario que ya no existe. Ahora responde 404. La carrera se reprodujo de verdad (otra sesión de MySQL borra la fila y retiene el bloqueo unos segundos mientras `edit.php` ya hizo su `SELECT`) y guardar sin cambiar ningún valor sigue dando 302. Efecto lateral: la opción afecta a todos los `rowCount()` de `UPDATE` de la aplicación (hoy solo lo usa `edit.php`).
> 2. **BD caída al enviar.** No era una diferencia consciente: salió de leer `$_POST` después del `SELECT`, y al fallar la conexión se perdía lo escrito. **Se prefiere conservar lo escrito**, como en `create.php`: ahora `$data` se lee antes de tocar la BD y, con un POST y la BD caída, se muestra el formulario con lo enviado, el `id` oculto y el aviso 503 (sin la línea "Editando a", que necesita datos de la BD). El riesgo de "sobrescribir sin datos cargados" no aplica a ese caso: el visitante escribió esos valores a propósito y el siguiente envío vuelve a validar el `id` y a comprobar que el usuario existe. En un GET con la BD caída no hay nada que conservar y solo se muestra el aviso.
> 3. **`?id=+14`.** Sí importa un poco: no es un riesgo de seguridad, pero cada usuario deja de tener una única URL (`14`, `+14`, ` 14`), lo que ensucia registros, cachés y rastreadores. **Se cubre** con una comprobación antes de `filter_var`: `is_string($rawId) && ctype_digit($rawId)`, que descarta el signo, los espacios y los arreglos; `filter_var` queda para el rango 1 a 4294967295 y rechaza los ceros a la izquierda. `+14`, ` 14`, `14 ` y `014` responden 404, también en el POST.


### 5.3 Pruebas

- [x] Editar abre el formulario con datos actuales.
- [x] Cambiar solo el teléfono guarda bien (no da error de correo duplicado).
- [x] Cambiar el correo a uno de otro usuario da el mensaje de duplicado.
- [x] Vaciar el teléfono lo deja en `NULL`.
- [x] `/edit.php` (sin id), `/edit.php?id=abc` y `/edit.php?id=9999` redirigen al listado sin error.
- [x] **Experimento guiado (en una transacción, sin perder datos):** en Adminer, `START TRANSACTION; UPDATE users SET name = 'X'; SELECT id, name FROM users; ROLLBACK;`. El `UPDATE` sin `WHERE` dejó las 9 filas de entonces con `X` y el `ROLLBACK` lo deshizo. Así se entiende por qué la cláusula es "crítica".
    - **Explica con tus palabras qué hizo `ROLLBACK` y por qué lo permiten las tablas InnoDB.** _(a) **Qué se comprobó después:** con `SELECT id, name FROM users;` tras el `ROLLBACK`, las 9 filas de entonces tenían otra vez sus nombres originales (0 con `X`); como comprobación extra, una huella MD5 de `id:nombre` de todas las filas dio el mismo valor antes del experimento y después del `ROLLBACK`, y distinta mientras la transacción seguía abierta. Dentro de la transacción el `SELECT` sí mostraba esas 9 filas con `X`: cada sesión ve sus propios cambios aunque aún no estén confirmados. (b) **Sin `START TRANSACTION`:** MySQL trabaja con `autocommit = 1` (se comprueba con `SELECT @@autocommit;`), es decir, cada sentencia se confirma sola al terminar. Entonces el `UPDATE ... SET name = 'X'` habría sido definitivo en el acto y el `ROLLBACK` posterior no tendría nada que deshacer: en una tabla temporal de prueba, `UPDATE` + `ROLLBACK` sin transacción dejó `X,X`, mientras que con `START TRANSACTION` volvió a `Ana,Luis`. Importa con datos reales porque la transacción es la red de seguridad para probar sentencias peligrosas, y porque en Adminer hay que ejecutarlo **todo junto** (la transacción vive en una sola conexión; si se ejecuta por partes, el `UPDATE` puede quedar confirmado). Una regla práctica: `START TRANSACTION`, la sentencia, un `SELECT` para ver el efecto y `ROLLBACK`; solo se cambia por `COMMIT` cuando el resultado es el deseado. (c) **Por qué lo permite InnoDB:** por la **atomicidad** (la A de ACID): una transacción se aplica entera o no se aplica. InnoDB guarda en el *undo log* la versión anterior de cada fila que modifica, y `ROLLBACK` reaplica esas versiones; mientras tanto las demás sesiones siguen viendo los valores antiguos (MVCC), por eso el `UPDATE` sin `COMMIT` no es visible fuera. El motor MyISAM no tiene transacciones: en una tabla temporal MyISAM, el mismo `ROLLBACK` dejó `X,X` y no deshizo nada. (d) **Qué otras situaciones cubre, además de un error de la aplicación:** una sentencia que falla a mitad (un `UPDATE` de dos filas que choca con un `UNIQUE` en la segunda dejó **también la primera** como estaba: el resultado fue `1=10,2=20,3=30`); una caída del servidor o un corte de luz antes del `COMMIT` (al reiniciar, InnoDB recupera con su *redo log* y descarta lo no confirmado); una conexión que se corta a medias; una operación de varios pasos que debe ir junta (restar a una cuenta y sumar a otra); y un *deadlock*, en el que MySQL deshace una de las transacciones. Es el mismo mecanismo con el que se reprodujo la carrera de `edit.php`: la sesión que borraba la fila no era visible para las demás hasta su `COMMIT`. Ojo con la red de seguridad: un `UPDATE` sin `WHERE` solo se detiene por casualidad cuando choca con un `UNIQUE` (probado: no cambia nada), y cuando no choca cambia **todas** las filas (probado: `101=10,102=20,103=30`)._
- [x] Commit.

### Preguntas de comprobación

1. ¿Cómo viaja el `id` al abrir el formulario y cómo al enviarlo? _Al abrir viaja por la URL, en una petición GET: el enlace del listado es `edit.php?id=14` y la página lo lee de `$_GET['id']`. Al enviar viaja en el cuerpo de una petición POST, dentro de un campo oculto (`<input type="hidden" name="id">`) que el parcial del formulario pinta cuando `$userId` no es `null`; el `action` del formulario es `edit.php` sin parámetros, así que el POST solo trae el `id` en el cuerpo y se lee de `$_POST['id']`. La fuente depende del método y nunca se mezclan (`$_GET['id'] ?? $_POST['id']` sería ambiguo si llegara `POST edit.php?id=5` con cuerpo `id=7`), y se lee una sola vez._
2. ¿Por qué se valida el `id` si viene de nuestro propio enlace? _Porque el enlace solo es una sugerencia y todo lo que llega del cliente es entrada no confiable: se puede editar la URL a mano, cambiar el campo oculto con F12 o enviar un POST directo con `curl`, y el usuario pudo haber sido borrado desde que se generó el enlace. Por eso `edit.php` comprueba en dos pasos: primero el formato (solo dígitos con `ctype_digit` y rango 1 a 4294967295 con `filter_var`, que rechaza `abc`, `0`, `+14`, arreglos como `?id[]=1`), y después que exista de verdad con el `SELECT` (si `fetch()` devuelve `false`, 404). En ambos casos responde 404 en lugar de fallar con un error o editar a quien no se quería. La consulta preparada evita la inyección SQL, pero no sustituye a la validación: un `id` inválido no es un ataque, es un dato incorrecto que hay que rechazar con un mensaje claro._
3. ¿Qué pasa sin `WHERE` en un `UPDATE`? _El `UPDATE` se aplica a todas las filas de la tabla: todos los usuarios quedarían con el mismo nombre, apellido, correo y teléfono, y, con el autocommit de MySQL, no hay deshacer salvo restaurar una copia; por eso el experimento de 5.3 se hace dentro de una transacción que se deshace con `ROLLBACK`, sin tocar los datos reales. Por eso `WHERE id = :id` es la cláusula crítica: es la que limita el cambio a un solo registro. En este proyecto el `UNIQUE` del correo frenaría por accidente ese `UPDATE` con un error 1062 (no puede haber el mismo correo en varias filas) y la sentencia no cambiaría nada, pero eso es suerte, no una protección: un `UPDATE` que solo modificara el teléfono sí pisaría a todos. La defensa real son la revisión del código y comprobar que el `WHERE` va siempre con su parámetro, y después del `UPDATE` mirar `rowCount()` (con `FOUND_ROWS` activado, 0 significa que no coincidió ninguna fila)._
4. ¿Qué cambió respecto a `create.php`? _Las tres diferencias del plan: (1) identificar el registro, con el `id` leído de GET o de POST según el método y validado con `ctype_digit` y `filter_var`; (2) cargarlo desde la BD con un `SELECT ... WHERE id = :id` y `fetch()`, rellenando `$data` con lo guardado en el GET (el `phone` `NULL` se convierte en texto vacío) y con lo enviado en el POST; y (3) guardar con `UPDATE ... WHERE id = :id` (5 parámetros en vez de 4) y redirigir a `index.php?msg=updated`. De ahí salieron otros cambios: un `id` inválido o inexistente responde 404 con una página que lo explica (también en el POST, por si el usuario se borró entre tanto); el 1062 significa "lo usa otro usuario", y guardar sin cambiar el correo no da error porque la fila no choca consigo misma; `rowCount()` con `Pdo\Mysql::ATTR_FOUND_ROWS` detecta que el usuario se borró entre el `SELECT` y el `UPDATE`; con la BD caída al enviar se conserva lo escrito; y el título añade "Editando a ..." con el nombre escapado. Lo demás se reutiliza de lo compartido en 5.2.0: `user_fields()`, `read_user_input()`, `validate_user()` y el parcial del formulario, al que `edit.php` le pasa `$action`, `$submitLabel` y `$userId`._

---

## MVP 6. Eliminar usuario (Delete)

**Objetivo:** borrar un usuario de forma segura.
**Resultado:** el botón "Eliminar" pide confirmación y borra.
**Guía:** §7.6, §8.3.

### 6.1 Conceptos

- Las peticiones **GET** deben ser **solo lectura**: buscadores, extensiones y precargas de navegador las ejecutan sin permiso. Un enlace `delete.php?id=3` podría borrar datos sin que nadie lo decida.
- Las acciones que modifican datos usan **POST**.
- `delete.php` no muestra pantalla: ejecuta y redirige.

### 6.2 Paso a paso

**Preguntas de diseño de delete.php (responder antes de escribir código)**

1. ¿Qué debe hacer `delete.php` si lo abre alguien con un GET (escribiendo la URL)? ¿Qué código HTTP devolverías? Hay uno específico para "método no permitido", con una cabecera asociada. _Responder **405 Method Not Allowed** con la cabecera **`Allow: POST`** y no hacer nada más: sin leer `$_POST`, sin abrir la BD y sin borrar. El 405 es el código específico de "la URL existe pero no admite este método", y el estándar HTTP exige acompañarlo de `Allow` con los métodos que sí acepta (aquí solo `POST`). Se comprueba al principio del archivo con `$_SERVER['REQUEST_METHOD'] !== 'POST'` (así también quedan cubiertos PUT, DELETE, etc.): `http_response_code(405)`, `header('Allow: POST')`, un texto corto en `text/plain` y `exit`. Redirigir al listado (lo que dice el plan en "Siempre (sea POST o no)") esconde el problema: quien escribió la URL ve el listado como si nada hubiera pasado, y un rastreador o una prueba automática recibe un 302 y luego un 200. Es la misma regla de códigos del resto del proyecto: si la operación no se hizo, nunca parece un éxito._
2. Aún no tienes protección CSRF (M6). Hoy cualquiera podría crear una página con un formulario oculto que haga POST a tu `delete.php`. ¿Qué parte de esa defensa puedes tener ya, solo con lo que has aprendido? _Primero, una aclaración honesta: hoy no hay login ni sesión, así que no hay nada que "robar": cualquiera puede borrar con un POST directo (`curl`), sin necesidad de engañar a nadie. El CSRF solo empieza a tener sentido cuando exista una sesión (la cookie que el navegador envía sola), y su defensa completa es el token de M6 más la cookie `SameSite`. Lo que sí se puede tener ya: (a) **solo POST**, que ya está en la respuesta 1 y elimina los ataques con un simple enlace o una imagen (`<img src="delete.php?id=3">`), pero no el formulario oculto; y (b) **comprobar de dónde viene la petición** con las cabeceras que el navegador añade solo: `Origin` (y `Referer` si falta), que se leen de `$_SERVER['HTTP_ORIGIN']` y `$_SERVER['HTTP_REFERER']`. Si alguna está presente y su servidor y puerto no coinciden con `$_SERVER['HTTP_HOST']` (se compara con `parse_url`), se responde **403** y no se borra. Esto es útil contra CSRF porque el atacante no controla esas cabeceras: las pone el navegador de la víctima. Límites: si no llega ninguna de las dos (algunos clientes y configuraciones de privacidad), no se puede decidir y el borrado se permite, y un cliente que no es un navegador (`curl`) puede enviar lo que quiera, aunque ese cliente no tiene la sesión de la víctima. Por eso es una capa extra y no sustituye al token de M6, que también cubre esos huecos. Una alternativa moderna es la cabecera `Sec-Fetch-Site` (valor `cross-site` para peticiones de otro sitio), con el mismo límite de que no todos los clientes la envían._
3. Después de borrar, ¿qué muestras si el usuario ya no existía? ¿Es un error, o el estado deseado ya se cumple? _**No es un error: el estado deseado ya se cumple.** Borrar es idempotente: repetir la petición deja el sistema igual que hacerla una vez, y el objetivo ("que ese usuario no exista") es verdadero tanto si lo borra esta petición como si lo borró otra antes (doble clic, dos pestañas, otro administrador). Por eso no se responde 404, a diferencia de `edit.php`, donde el visitante quería **ver o modificar** algo que no está y ahí el objetivo no se puede cumplir. Lo que sí hay que evitar es mentir: con `$stmt->rowCount()` (que con `Pdo\Mysql::ATTR_FOUND_ROWS` ya cuenta filas encontradas) se distingue si esta petición borró 1 fila o 0. Con 1 se redirige a `index.php?msg=deleted`; con 0 se redirige igualmente al listado (misma salida, el mismo código de redirección), pero con otro mensaje neutral de la lista blanca de `index.php`, por ejemplo `msg=missing`: "El usuario ya no existía." No se dice "eliminado" si no se eliminó nada. Distinto es un `id` mal formado (no es un entero válido): eso sí es una petición incorrecta y no un usuario "ya borrado", y se decidirá su código al implementar._

> **Decisiones aceptadas (MVP 6).** Las tres respuestas de arriba mandan sobre el plan original: un GET responde **405 con `Allow: POST`** (no redirige), borrar un usuario inexistente **no es error** (se distingue con `rowCount()` y un mensaje neutral `missing` en la lista blanca de `index.php`) y se añade una comprobación de origen como capa extra (no sustituye al token de M6).

**Tramos (cada uno se prueba antes del siguiente)**

- [x] **6.2.1 Esqueleto:** `public/delete.php` sin HTML ni layout. Si el método no es POST: 405 + `Allow: POST` + texto corto en `text/plain` + `exit`. Prueba con `curl -i` (GET, PUT y POST).
    - **Predicción y resultado (`curl -i`):** GET, PUT y DELETE → `405 Method Not Allowed` con `Allow: POST` y `Content-Type: text/plain; charset=UTF-8`, tal como se predijo (nginx reenvía las cabeceras de PHP-FPM sin tocarlas). POST → `200 OK` con cuerpo vacío y `Content-Type: text/html`: el esqueleto pasa la comprobación, no hace nada y PHP responde 200 por defecto; es un "éxito" falso que solo se tolera en este tramo y desaparece en 6.2.3. Además `?id=1` por GET responde 405 y el número de usuarios no cambia.
    - **Riesgo temporal y cómo se cubrió:** con solo el esqueleto, un POST respondía `200 OK` con el cuerpo vacío, un "éxito" sin haber hecho nada (pulsar Eliminar dejaba la página en blanco). Para que el estado intermedio no mienta si se hace commit, `delete.php` lleva un **marcador temporal**: el POST responde `501 Not Implemented` en `text/plain` ("Borrado aún no implementado."). Comprobado: GET → 405, POST → 501, y un POST con el `id` de un usuario real no borra nada (el número de usuarios no cambia). El marcador se elimina en 6.2.3.
    - **¿Por qué `!== 'POST'` en vez de `=== 'GET'`?** _Porque es una lista de permitidos (se deniega todo salvo lo que se acepta) en vez de una lista de prohibidos. Con `=== 'GET'` solo se bloquearía ese método y PUT, DELETE, PATCH, HEAD, OPTIONS o uno inventado seguirían de largo hacia la lógica de borrado; que luego no borren sería casualidad (por ejemplo, porque `$_POST` llega vacío) y no una decisión. Con `!== 'POST'` pasa solo el método para el que se diseñó la página, y cualquier método nuevo o desconocido queda rechazado sin tocar el código. Es la misma idea de la lista blanca de `msg` en `index.php`._
    - **Con `display_errors` activo, ¿qué pasaría si `delete.php` imprimiera algo antes de `header('Allow: POST')`?** _Se probó con una copia fuera del proyecto y la configuración del contenedor (`display_errors=1`, `output_buffering=0`): el primer `echo` envía las cabeceras con el código 200, así que `http_response_code(405)` falla con "Cannot set response code - headers already sent" y los dos `header()` con "Cannot modify header information", todos con "output started at ...:4" señalando la línea culpable. El cliente recibe **200 OK**, sin `Allow` y con `Content-Type: text/html`, y las advertencias se imprimen dentro del cuerpo con las rutas del servidor (una fuga de información que en producción se evita con `display_errors = Off` y `log_errors`). El texto "Método no permitido" aparece igualmente, pero el código es mentira. Es el mismo error que en `create.php`: toda salida (un `echo`, un espacio o un BOM antes de `<?php`) debe ir después de las cabeceras._
- [x] **6.2.2 Id compartido:** `edit.php` ya valida el id en línea (`ctype_digit` + `filter_var` con rango). Extráelo a una función en `includes/user-validation.php` y úsala en `edit.php` y `delete.php`. No regresión: `edit.php` responde igual que antes.
    - **Hecho:** `parse_user_id(mixed $raw): ?int` en `includes/user-validation.php`, con constantes `USER_ID_MIN = 1` y `USER_ID_MAX = 4294967295` y docblock con ejemplos; `edit.php` la usa en lugar de las líneas en línea (`$userId = parse_user_id($rawId); $notFound = $userId === null;`). `delete.php` la usará en 6.2.3.
    - **Entrada: ¿qué tipo de parámetro admite string, null y arreglo con `strict_types`? ¿Por qué no `string`?** _`mixed`. Se comprobó: con `string $raw` y `strict_types=1`, pasar `null`, un arreglo (o un int) lanza `TypeError` antes de que la función pueda validarlos, y justamente `null` (no llegó) y los arreglos (`?id[]=1`) son los casos que hay que rechazar con elegancia y no con un error fatal. Con `mixed` la función recibe cualquier cosa y decide ella: solo un `string` de dígitos puede ser un id. Consecuencia que queda documentada: un `int` real también da `null`, porque de una petición nunca llega un int y la función es para entrada cruda._
    - **Salida: `false`, `null` o excepción.** _`?int`, con `null` para "no válido". Una excepción no encaja: un id inválido es el resultado normal y frecuente de datos no confiables (cualquiera escribe `?id=abc`), no una situación excepcional, y obligaría a un `try/catch` y a una clase propia en cada página para algo que se resuelve con un `if`. `false` era la convención de `filter_var`, pero mezcla un `bool` dentro de un resultado entero (`int|false`) y es fácil confundirlo con un valor válido en un `if ($id)`. `null` es la forma estándar de "no hay valor", se lee en el tipo (`?int`) y combina con `??` y `=== null`. Coste: el código que antes preguntaba `=== false` (solo `edit.php`) pasó a `=== null`; el `false` de `fetch()` se mantiene porque es otra API._
    - **El 4294967295: ¿literal o constante? ¿Cuántos sitios si la columna pasa a BIGINT?** _Constante con nombre (`USER_ID_MAX`, junto a `USER_ID_MIN`) y un comentario que apunta a `database/01-schema.sql`. Antes el número estaba escrito en `edit.php` (y habría tenido que copiarse en `delete.php`): dos sitios de PHP más la migración de la BD. Ahora es **uno** en PHP (la constante) más el esquema. Aviso para ese día: el máximo de `BIGINT UNSIGNED` (18446744073709551615) supera a `PHP_INT_MAX` (9223372036854775807), y `filter_var` lo rechaza (comprobado), así que el tope real en PHP sería `PHP_INT_MAX` y no el de la columna._
    - **¿Dónde vive?** _En `includes/user-validation.php`, junto a las demás funciones de validación de usuarios, y no en otro archivo. Es lógica de validación de la misma entidad, el archivo no tiene efectos secundarios (solo declara funciones y constantes, así que `delete.php` puede incluirlo sin cargar nada pesado) y un archivo nuevo para una sola función sería más estructura que beneficio. Lo único que se actualizó fue su comentario de cabecera, que ahora menciona a `delete.php`. En M8 pasará al `UserValidator` o al código que lee la petición._
    - **Pruebas.** La función sola (`docker compose exec php php -r '...'`): `"14"` → `int(14)`, `null` → `NULL`, `["1"]` → `NULL`; 24 entradas más (`"+14"`, `" 14"`, `"014"`, `"0"`, `"4294967295"` válido, `"4294967296"`, `"1e3"`, `"٣"`, `true`...) con el resultado esperado. No regresión de `edit.php`: 16 casos (sin id, `abc`, `9999`, `id[]=1`, `0`, `4294967295`, `4294967296`, `+14`, un id válido, y los mismos por POST) con el **mismo código y el mismo HTML** antes y después. Además las baterías anteriores: 24 de 24 y 20 de 20.
- [x] **6.2.3 Borrar:** id inválido → decide el código (¿400 o 404?) y justifícalo. Con id válido: `DELETE ... WHERE id = :id` preparado, errores de BD con el mismo patrón (503 / 500 + `log_exception`), `rowCount()` 1 → `index.php?msg=deleted`, 0 → `index.php?msg=missing`, siempre con `exit`.
    - **Decisión 1. `id` mal formado: ¿400 o 404?** _**400 Bad Request**, en `text/plain`. El 404 dice "el recurso no existe" y el 400 dice "la petición está mal hecha"; un `id` que no es un entero válido (`abc`, `0`, `id[]=1`, ausente) no identifica a ningún usuario: el fallo está en cómo se pidió, no en que falte algo. Importa por lo que se hace con el caso contrario: un `id` bien formado de un usuario que ya no existe es un **éxito idempotente** (`msg=missing`), así que tratar también lo mal formado como "ya no existe" mezclaría un doble clic inofensivo con una petición defectuosa o manipulada, que merece verse en los registros como un 4xx distinto. La diferencia con `edit.php` (404 para los dos): allí `edit.php?id=14` es una página que identifica a un usuario y un id inválido significa lo mismo que uno inexistente ("esa página no existe"); en `delete.php` el id es un parámetro de una acción y la acción solo tiene sentido si se pidió bien. Se valida **antes** de abrir la BD, así que responde 400 aunque la BD esté caída (y se comprueba así)._
    - **Decisión 2. Si la BD falla, ¿qué ve el visitante? (la tensión con "nunca 200")** _Se responde con el código honesto (**503** sin conexión, **500** si falla la consulta o la configuración) y **esa misma respuesta lleva una página HTML** con el layout normal, el aviso y un enlace "Volver al listado" (parcial nuevo `partials/action-error.php`, hermano de `user-not-found.php`). No se redirige. La tensión: `delete.php` "no muestra pantalla", pero un `text/plain` pelado deja a quien pulsó un botón en un callejón sin salida, y redirigir al listado con un mensaje de error devuelve 302 y luego 200, con lo que en el registro de accesos un borrado fallido se ve igual que uno correcto (ambos 302, solo cambia la query). La tercera vía cumple la regla "si no se completó, nunca es 200" y además el visitante ve algo útil. El `text/plain` se mantiene para el 405 y el 400, que no los provoca un clic normal en la interfaz sino una petición manual o manipulada. Con la BD caída redirigir tampoco serviría: el propio `index.php` respondería 503._
    - **Decisión 3. ¿302 o 303 tras un POST?** _**302 Found** significa "el recurso está en otra URL (temporalmente)" y, por razones históricas, el estándar permite que el navegador cambie POST por GET al seguirlo (todos lo hacen). **303 See Other** significa expresamente "tu petición ya se procesó; para ver el resultado pide esa otra URL con GET", que es el significado exacto del patrón Post/Redirect/Get. Para un navegador no cambia nada; la diferencia es de precisión. Decisión: **se mantiene 302 en los tres archivos** (`create.php`, `edit.php`, `delete.php`) y se anota para M8. Cambiar solo `delete.php` dejaría códigos distintos para lo mismo, y cambiar los tres toca dos archivos ya probados, la línea base de 4.4 y las baterías de pruebas por un beneficio que hoy no se ve; el momento natural es M8, con una única función `redirect()` que fije el 303 en un solo sitio._
    - **Recordatorio (cumplido):** el marcador temporal `501` ya se quitó de `delete.php` y se comprobó que no queda ninguna ruta que responda 200 sin haber borrado ni redirigido: GET → 405, id mal formado → 400, borrado → 302, BD caída → 503/500.
    - **Hecho:** el marcador `501` se quitó y `delete.php` valida el método (405), después el id con `parse_user_id()` (400, antes de abrir la BD) y borra con `DELETE FROM users WHERE id = :id` preparado; `rowCount()` 1 → `index.php?msg=deleted`, 0 → `index.php?msg=missing`, con `exit` tras cada respuesta. Errores de BD con el patrón de `edit.php` (503 / 500 + `log_exception`) y la página `partials/action-error.php`.
    - **Resultado de las pruebas** (usuario `O'Brien` creado desde el formulario; 32 comprobaciones con `curl`, 32 correctas): POST válido → `302 Location: index.php?msg=deleted` y la fila desaparece (comprobado en la BD); el **mismo POST repetido** → `302 ... msg=missing`, sin error; un id válido que nunca existió (`4294967295`) → `msg=missing`; sin id, `abc`, `id[]=1`, `0` y `+ID` → **400** y la tabla no cambia; GET → 405; con la **BD detenida**, los POST con id mal formado siguen dando 400 (prueba de que no se abrió la conexión) y el POST válido da **503** con una página HTML, el aviso, el enlace "Volver al listado", sin redirección y sin detalles técnicos. El diálogo de confirmación de `O'Brien`: el atributo HTML es `confirm(&quot;¿Eliminar a O\u0027Brien Prueba?&quot;)` y, una vez decodificado el HTML y el literal JavaScript, el texto es exactamente `¿Eliminar a O'Brien Prueba?`. Recorrido completo con `curl -L` (POST y seguir la redirección): 1.ª vez aviso verde "eliminado", 2.ª vez aviso azul "ya no existía". Baterías anteriores: 24 de 24 y 20 de 20.
- [x] **6.2.4 Mensajes:** añade `missing` a la lista blanca de `index.php`. Pregunta: ese aviso no es un éxito, ¿cómo cambias el estilo del `alert` sin romper la estructura actual del arreglo?
    - **Decisión 4. Estilo del aviso sin romper el arreglo.** _`$messages` (clave => texto) **no se toca**; al lado se añade `$messageTypes` solo con las excepciones (`'missing' => 'info'`) y `'success'` como valor por defecto: `theme-<?= escape($messageTypes[$message] ?? 'success') ?>`. Lo existente no cambia ni una línea y lo común (éxito) no hay que repetirlo. Se usa `theme-info` y no `theme-warning` porque no es un fallo ni una advertencia, es información: el estado deseado ya se cumplía. Las clases `theme-success`, `theme-info`, `theme-warning` y `theme-danger` existen en este Bootstrap 6 (comprobado en el CSS). Coste: dos arreglos que podrían desincronizarse; la alternativa (`clave => ['texto' => ..., 'tipo' => ...]`) es más limpia pero obliga a cambiar el arreglo y su único uso. En M8 el catálogo de mensajes pasará a una clase con texto y tipo juntos._
    - **Hecho:** `missing` añadido a `$messages` ("El usuario ya no existía, no se eliminó nada.") y `$messageTypes` con `'missing' => 'info'`; el `alert` usa `theme-<?= escape($messageTypes[$message] ?? 'success') ?>`. Comprobado: `?msg=missing` → `theme-info`; `?msg=deleted` y `?msg=created` siguen en `theme-success`; un valor raro (`<b>x</b>`) no muestra aviso.
- [x] **6.2.5 Origen:** función reutilizable (no código pegado en `delete.php`) que compruebe `Origin`/`Referer` contra el host. Decide qué haces si llegan ausentes, si llega `null` y si el host sale de `$_SERVER['HTTP_HOST']` o de una variable de entorno.
    - **Decisiones previas sobre el origen (se implementan en este tramo):**
      1. **¿De dónde sale el host esperado?** _De una variable de entorno, como la BD (por ejemplo `APP_ORIGIN=http://localhost:8080`, leída con `environment()`), y no de `$_SERVER['HTTP_HOST']`. `HTTP_HOST` lo manda el cliente y, detrás de un proxy, puede ser otro; la configuración es la fuente fiable de "cuál es mi sitio". Para el CSRF `HTTP_HOST` funcionaría (el navegador de la víctima pone el real), pero comparar contra un valor fijo es más estricto y no depende de la red. Se compara el origen completo (esquema + host + puerto), no solo el host, así `http://` contra `https://` también cuenta como ajeno. Coste: añadir la variable a `docker-compose.yml` y documentarla; si falta, `environment()` ya lanza `RuntimeException` y la página responde 500, que es lo correcto._
      2. **Cabeceras ausentes.** _Rechazar con 403 (fallar cerrado) en una acción destructiva. Orden de decisión: si llega `Origin`, manda él (igual al esperado → sigue; distinto → 403); si no llega `Origin` pero sí `Referer`, se compara su origen; si no llega ninguna, 403. Se gana cerrar un hueco real: una página atacante puede quitar el `Referer` con `Referrer-Policy: no-referrer` y, si "ausente" se permitiera, esa sería la forma de saltarse la comprobación. Se pierde la comodidad de los clientes que no son navegadores (`curl`, scripts de prueba, que ahora tienen que enviar `-H "Origin: http://localhost:8080"`) y algún navegador antiguo o una extensión de privacidad que las quite, un coste aceptable aquí porque `delete.php` solo lo usa el formulario del listado. Límite que se mantiene: un cliente que no es navegador puede inventar cualquier cabecera, pero no tiene la sesión de la víctima; el token de M6 sigue siendo la defensa completa._
      3. **`Origin: null`.** _Se trata como origen ajeno y se rechaza con 403. Es literalmente la cadena `null` y la mandan contextos sin origen propio: iframes con `sandbox`, páginas `data:` o `file://` y algunas redirecciones entre sitios. Un atacante puede provocarlo a propósito (un formulario dentro de un iframe aislado), así que aceptarlo abriría un atajo. Nuestro formulario legítimo se envía desde una página de `http://localhost:8080`, que manda su origen real y nunca `null`._
    - **Decisiones de diseño de la función (antes de escribirla):**
      1. **`Referer` trae la ruta completa: ¿cómo se compara?** _`parse_url()` separa `scheme`, `host`, `port`, `path`, `query`... Se reconstruye el origen como `esquema://host[:puerto]` en minúsculas (`normalize_origin()`), se descartan la ruta y la query del `Referer`, y se compara ese resultado con el origen esperado, que se normaliza igual. Así `http://localhost:8080/index.php?msg=x` y `http://localhost:8080` dan el mismo origen. Para la cabecera `Origin` se es más estricto: un `Origin` real nunca lleva ruta, así que si la trae se considera inválido._
      2. **Igualdad estricta, no `str_contains` ni `str_starts_with`.** _Un prefijo o una subcadena aceptan cualquier cosa añadida: `http://localhost:8080.evil.com` y `http://localhost:8080@evil.com` empiezan igual que el origen esperado, y `http://evil.com/?http://localhost:8080` lo contiene. La igualdad `===` entre dos orígenes ya normalizados no tiene ese margen: o coinciden esquema, host y puerto completos, o no coinciden, y ninguna cola añadida pasa. Además se parsea **antes** de comparar, de modo que lo que se compara es el host real, no un texto que se parece. Se rechazan también las URL con usuario y contraseña (`user@host`) y los espacios o caracteres de control._
      3. **Puerto por defecto.** _Se cubre: al normalizar se omite el puerto cuando es el de por defecto del esquema (80 para `http`, 443 para `https`), así `https://sitio.com` y `https://sitio.com:443` dan el mismo origen, y también se ignoran las mayúsculas (`HTTP://LOCALHOST:8080`). Un esquema distinto (`http` frente a `https`) cuenta como origen ajeno._
      4. **Orden dentro de `delete.php`: 405 → 403 → 400 → BD.** _El 405 va primero porque depende del método y no de quién pregunta (un GET de otro sitio solo recibe un 405 inofensivo). El origen va **antes** del 400 porque responde a "¿quién pide?" y el 400 a "¿qué pide?": una petición que no se sabe de dónde viene no debe obtener nada, ni siquiera que se le valide el id (un 400 le confirmaría cómo se parsean los datos), y es lo más barato de comprobar. La BD va al final, como ya se probó con el 400. Consecuencia: **las pruebas con `curl` sin `Origin` ahora dan 403**, incluidas las de id mal formado, y se actualizan las baterías para enviar la cabecera._
      5. **¿Dónde vive?** _En un archivo propio, `includes/origin.php`, y no en `helpers.php`. Son funciones puras (reciben texto y devuelven un resultado, sin leer `$_SERVER` ni el entorno: eso lo hace la página) que se prueban solas; `helpers.php` mezcla utilidades con efectos (`environment()`, `log_exception()`) y esta es una política de seguridad que se reutilizará en `create.php`, `edit.php` y en el token de M6. Un valor de `APP_ORIGIN` que no sea un origen válido es un error de configuración: la función lanza `UnexpectedValueException` (una `RuntimeException`), que `delete.php` ya traduce en un 500 con `log_exception`, y no un 403 que culparía al visitante._
      6. **`APP_ORIGIN` por defecto.** _En `docker-compose.yml`: `APP_ORIGIN: ${APP_ORIGIN:-http://localhost:${APP_PORT:-8080}}`. Se deriva de `APP_PORT` para que cambiar el puerto no lo deje desfasado, y se puede fijar a mano en `.env`. Ojo: `http://localhost:8080` y `http://127.0.0.1:8080` son orígenes distintos; hay que abrir la aplicación con el mismo que `APP_ORIGIN`._
    - **Hecho:** `includes/origin.php` con dos funciones puras, `normalize_origin()` e `is_allowed_origin()`; `delete.php` las usa con `environment('APP_ORIGIN')` en el orden 405 → 403 → 400 → BD. `APP_ORIGIN` en `docker-compose.yml` (por defecto derivado de `APP_PORT`; comprobado con `docker compose config` con el puerto por defecto, con `APP_PORT=9090` y fijado a mano), documentada en `.env.example` y en el `README.md` (sección propia, estructura y solución de problemas).
    - **Hallazgo: `parse_url()` es permisivo.** Acepta `http://localhost:80a` como puerto 80 y no protesta por un espacio al final, así que parsear y comparar no basta. La función exige además que el texto original empiece exactamente por `esquema://host[:puerto]` y que lo que sigue sea lo permitido (nada para un `Origin`; vacío o `/ ? #` para un `Referer`). `http://localhost:8080.evil.com` ni siquiera se parsea (`parse_url` devuelve `false`), y `http://localhost:8080@evil.com` se rechaza por llevar usuario y porque su host real es `evil.com`.
    - **Pruebas.** La función sola (`docker compose exec php php -r '...'`): **33 de 33** (22 de origen y cabeceras, 7 de puerto por defecto y barra final, 4 de `APP_ORIGIN` inválido que lanzan `UnexpectedValueException`). Con `curl` contra `delete.php`: **23 de 23** — `Origin: http://evil.example`, `Origin: null`, sin cabeceras, `localhost:8080.evil.com`, `localhost:8080@evil.com`, `127.0.0.1:8080`, `https://localhost:8080`, origen ajeno con `Referer` correcto y los dos `Referer` ajenos → **403** y la fila sigue existiendo; solo `Referer` correcto (con ruta y query) y `Origin` correcto, incluso en mayúsculas → **302** y se borra; GET sin `Origin` → 405 (antes que el 403); `id=abc` sin `Origin` → 403 y con `Origin` → 400; con la BD detenida, origen ajeno → 403 (se comprueba antes de la BD) y origen correcto → 503. `APP_ORIGIN` inválido (contenedor recreado a propósito y restaurado) → **500** con el aviso genérico, sin detalles técnicos en la página y con `[config] ... APP_ORIGIN no es un origen válido` en el log. Baterías anteriores actualizadas para enviar `Origin`: borrado 32 de 32, general 24 de 24, `edit.php` 20 de 20; en todas, la tabla termina con los mismos usuarios con que empezó.
    - **`Origin` presente pero vacío (rama sin prueba propia hasta ahora).** `normalize_origin('')` devuelve `null`, así que `is_allowed_origin('', 'http://localhost:8080/', ...)` da **`false`**: si llega `Origin`, decide él solo y un valor vacío cae en "falla cerrado" aunque el `Referer` sea correcto (también con `'   '`, y sin `Referer`). Control: con `null` (cabecera ausente) y el mismo `Referer`, da `true`. De extremo a extremo con `curl`: **`-H "Origin;"` envía la cabecera vacía** (`-H "Origin:"` la elimina), y con un `Referer` correcto → **403**, mientras que sin la cabecera y con el mismo `Referer` → 302 (se usó un `id` inexistente para no borrar nada).
- [x] **Detalle de seguridad: `expose_php`.** Las respuestas llevaban `X-Powered-By: PHP/8.4.27`, la versión exacta de PHP visible para cualquiera (*information disclosure*, el mismo principio de la pregunta 9 del MVP 2: quien sabe la versión sabe qué vulnerabilidades buscar). _Decisión: **se apaga ahora** (`expose_php = Off` en `docker/php/php.ini`) y no se deja para M7: es una línea, no afecta a ninguna función y no hay nada que posponer. Aviso: `php.ini` se copia dentro de la imagen (`COPY php.ini /usr/local/etc/php/conf.d/99-app.ini` en el Dockerfile), no es un volumen, así que hay que reconstruir con `docker compose up -d --build php`. No es una defensa por sí sola (la defensa real es actualizar PHP); solo quita una pista gratuita. El mismo principio aplica a nginx, que manda `Server: nginx/1.30.6`: `server_tokens off;` queda anotado para M7._
    - **Hecho:** `expose_php = Off` en `docker/php/php.ini` y `docker compose up -d --build php`. Comprobado: `ini_get('expose_php')` vacío y `curl -I` ya no muestra `X-Powered-By`; sigue `Server: nginx/1.30.6` (anotado para M7: `server_tokens off;`). Las baterías de pruebas pasaron con la imagen reconstruida.
- [x] Verifica que el formulario del listado ya tiene `onsubmit="return confirm(...)"` (ya está desde el MVP 3). (Comprobado con `O'Brien` en 6.2.3: el diálogo muestra el texto exacto.)

### 6.3 Pruebas

- [x] Clic en Eliminar → aparece el cuadro de confirmación; "Cancelar" no borra.
- [x] "Aceptar" borra y muestra el mensaje.
- [x] **Con el origen real del navegador (manual):** el botón Eliminar sigue funcionando abriendo `http://localhost:8080` (el navegador manda `Origin: http://localhost:8080`, igual a `APP_ORIGIN`). Y abriendo `http://127.0.0.1:8080`, el mismo botón responde **403 "Origen no permitido"** y no borra: es otro origen. (Verificado a mano por el usuario; Claude no pudo hacerlo porque la extensión del navegador no estaba conectada.)
- [x] Abrir `http://localhost:8080/delete.php?id=1` en la barra de direcciones **no** borra y responde 405 con `Allow: POST`.
- [x] Probar con un `id` inventado no produce error. (Un id bien formado que no existe → `msg=missing`; uno mal formado → 400.)
- [x] Commit.

### Preguntas de comprobación

1. ¿Por qué un borrado con `<a href>` es una mala idea? _Porque un enlace es una petición GET, y GET debe ser solo lectura (segura e idempotente): todo lo que no es una persona decidiendo puede seguirla o repetirla sin permiso. Rastreadores de buscadores, precargadores de enlaces del navegador, extensiones, escáneres de enlaces de los programas de correo y vistas previas de chats siguen los enlaces. Un `<a href="delete.php?id=3">` en el listado haría que un rastreador, al recorrerlos, borrara todos los usuarios sin que nadie lo decidiera. Además la URL queda en el historial y en los registros, se guarda como marcador y se repite con "atrás" o recargando, y cualquier página ajena puede lanzarla con una imagen (`<img src=".../delete.php?id=3">`) sin necesidad de un formulario. Por eso el borrado es un formulario POST y `delete.php` responde 405 con `Allow: POST` a cualquier otro método (probado: `GET /delete.php?id=...` no borra nada)._
2. ¿La confirmación de `confirm()` protege de verdad? (No: es comodidad. ¿Qué lo impediría realmente? → M6 CSRF). _No: es comodidad para el usuario honesto, no una protección. Corre en el navegador, que controla quien lo usa: se salta quitando el `onsubmit` con F12, desactivando JavaScript o enviando el POST sin navegador (las pruebas con `curl` borraron usuarios sin mostrar ningún diálogo), y una página ajena con su propio formulario oculto tampoco pasa por él. Lo que lo impide de verdad lo decide el servidor: aceptar solo POST (hecho), comprobar de dónde viene la petición (`Origin`/`Referer` contra `APP_ORIGIN`, hecho en `delete.php` como capa extra), el **token CSRF** en cada formulario (M6) y la **autenticación** (M5: sin sesión, nadie debería poder borrar). Y para que borrar no sea irreversible, el borrado lógico (M4)._
3. ¿Qué hace el usuario eliminado a nivel de datos? ¿Es recuperable? (→ M4). _`DELETE FROM users WHERE id = :id` elimina la fila físicamente de la tabla InnoDB. Con el autocommit de MySQL (sin `START TRANSACTION`) la sentencia se confirma sola, así que es definitivo en el acto: la aplicación no tiene papelera ni "deshacer". Solo se recuperaría desde una copia de seguridad (este proyecto no configura ninguna, y `docker compose down -v` borra el volumen). Tampoco se reutiliza el id: el contador `AUTO_INCREMENT` sigue avanzando (en las pruebas, los ids de usuarios creados y borrados no se repitieron), así que el 14 borrado no volverá a ser el 14. Y el correo queda libre para registrar a otro usuario. La alternativa recuperable es el **borrado lógico** (M4): una columna como `deleted_at` y filtrar en las consultas, de modo que "eliminar" solo marca la fila._

---

## MVP 7. Revisión final del CRUD base

**Objetivo:** consolidar, comprobar todo y comparar con la guía.

### 7.1 Recorrido completo

- [x] Crear → listar → editar → eliminar, sin errores PHP en pantalla.
    - **Resultado (2026-11-18, con `curl` siguiendo las redirecciones; no desde el navegador):** crear (`302` → `index.php?msg=created`, aviso verde, correo guardado en minúsculas), listar (aparece el primero, con teléfono), editar (formulario con los datos actuales; `POST` con apellido nuevo y teléfono vacío → `msg=updated` y `NULL` en la BD) y eliminar con el origen del sitio (`msg=deleted`): **cero errores de PHP** (`Warning`, `Notice`, `Deprecated`, `Fatal error`, `Stack trace`, `on line N`) en cada respuesta. Lo mismo en `/`, `/create.php`, `/edit.php?id=9999` (404 esperado), `/status.php` y `/?msg=missing`. Al terminar, el número de usuarios era el mismo que al empezar (se borra la fila de prueba).
- [x] `docker compose logs php` no muestra errores ni warnings.
    - **Resultado, con un límite que hay que decir:** el log del contenedor solo cubre desde que arrancó (hace ~36 minutos, no incluye mis pruebas fallidas anteriores) y lo componen 34 líneas de acceso de php-fpm (`"POST /create.php" 302`...) y 4 avisos de arranque de PHP-FPM (`NOTICE: [pool www] 'user' directive is ignored when FPM is not running as root`, `fpm is running`, `ready to handle connections`), que son normales. **Ningún `Warning`, `Deprecated`, `Fatal` ni `Uncaught`**; nginx sin `[error]` y sin respuestas 5xx. Ojo con cómo se lee: un `grep -i notice` o `grep -i error` sobre este log da falsos positivos por esos avisos de arranque, y los fallos provocados a propósito (BD detenida, `APP_ORIGIN` inválido) sí dejan líneas `[connection]` / `[config]` por diseño (`log_exception`), que no son un defecto.
- [x] Empezar desde cero: `docker compose down -v && docker compose up -d --build` → la BD se recrea con los 3 usuarios y la app funciona.
    - **Resultado (ejecutado literalmente sobre este proyecto, con permiso: no había datos de interés):** antes había 7 usuarios y el volumen `crud-usuarios-docker_db_data` (creado el 2026-11-17). `docker compose down -v` eliminó contenedores, red y **volumen** (0 volúmenes del proyecto después) y `docker compose up -d --build` lo recreó: volumen nuevo (2026-11-19), los 4 servicios en `running` y `db` en `healthy`. La BD quedó con **exactamente 3 usuarios** del seed (`Laura Gómez`, `Andrés Martínez`, `Valentina Rojas`; el tercero con teléfono `NULL`), tabla `users` en InnoDB, base en `utf8mb4`, índice único en `email` y `AUTO_INCREMENT` reiniciado (el siguiente id era el 4). La app funciona: listado con `(3)` y tildes correctas (sin `Ã`), `status.php` con "Conectado a MySQL 8.4.13", Adminer respondiendo, `APP_ORIGIN=http://localhost:8080`, `expose_php` apagado (la imagen se reconstruyó con `--build`) y el recorrido crear (id 4) → editar → eliminar sin errores de PHP, dejando de nuevo 3 usuarios. El `.env` no se toca con `down -v` (solo se borran los volúmenes). Log de php desde el arranque limpio: 4 avisos de arranque de PHP-FPM y 9 accesos, **0** `Warning`/`Deprecated`/`Fatal`/`Uncaught`, y 0 respuestas 5xx en nginx. Antes ya se había comprobado el mismo mecanismo en un clon aislado (con otro volumen y los puertos 8090 y 8091), como contraste.
- [x] Un colaborador (tú mismo en otra carpeta) puede clonar el repo, copiar `.env.example` y levantar todo siguiendo solo el README.
    - **Resultado:** `git clone` del repositorio en otra carpeta (31 archivos versionados, **sin** `.env`), `cp .env.example .env`, `docker compose up -d --build` y los 4 servicios en `running` con `db` en `healthy`. Se probó de dos maneras: (a) siguiendo el README al pie de la letra (`DB_DATABASE=crud_usuarios`, `DB_USERNAME=crud`, claves nuevas) y (b) con el **`.env.example` sin editar**, cambiando solo los puertos para no chocar con este proyecto: **ambas funcionan**. Además `APP_ORIGIN` se derivó solo de `APP_PORT` (`http://localhost:8090`): el borrado con el origen del otro proyecto (`8080`) dio 403 y con el propio dio `msg=deleted`. Todo se retiró después (`docker compose down -v --rmi local` y la carpeta del clon).
    - **Hallazgo del README (por corregir):** la línea de "Puesta en marcha" dice `cp .env.example .env     # y edita: DB_DATABASE=crud_usuarios, DB_USERNAME, claves`, pero el propio `.env.example` ya trae `DB_DATABASE=app` y `DB_USERNAME=app`, y con esos valores sin editar la aplicación funciona (se comprobó). El README sugiere un cambio que no es necesario y da a entender que `crud_usuarios` es obligatorio. Hay que decidir cuál es la fuente de verdad y alinear los dos: o el ejemplo pasa a `crud_usuarios`/`crud`, o el README dice "cambia al menos las claves (`DB_PASSWORD`, `DB_ROOT_PASSWORD`); el resto de valores del ejemplo funciona".
    - **Otros detalles que vio el colaborador:** el cliente `mysql` de línea de comandos muestra las tildes como `�` aunque la página las pinta bien (ya está en "Solución de problemas": es el cliente en `latin1`, no los datos); y para tener dos copias a la vez hay que cambiar `APP_PORT` y `ADMINER_PORT`.

### 7.2 Repaso de errores comunes (Guía §10, adaptado a Docker)

Provoca y resuelve:

> **Cómo se hizo (2026-11-19):** todos los errores se provocaron en un **clon aislado** del repositorio (otro proyecto de Compose, otro volumen, puertos 8090 a 8092), no sobre este proyecto, y el clon se eliminó al terminar; los archivos de este proyecto siguen idénticos al commit. No había navegador conectado, así que lo que depende de ver la página (por ejemplo, "sin estilos") se dedujo de las cabeceras HTTP y no se observó con los ojos. Nota de método: en dos puntos mi primer script falló (un detector de fugas que comparaba una entrada vacía y un filtro de fechas en la zona horaria equivocada) y se repitió con el script corregido y un control positivo; los resultados de abajo son los de las repeticiones.


- [x] Puerto 8080 ocupado (cambia `APP_PORT`).
    - **Provocado:** un servidor de mentira escuchando en el 8090 y `APP_PORT=8090`. `docker compose up -d --build` terminó con código **1** y este mensaje: `Error response from daemon: failed to set up container networking: ... failed to bind host port 127.0.0.1:8090/tcp: address already in use`. **Estado a medias:** `adminer`, `db` y `php` quedaron en `running`, pero `nginx` se quedó en `created` (no arrancó), y el 8090 seguía respondiendo `200` con el listado de archivos del otro programa, es decir, "algo responde" pero no es la aplicación.
    - **Cómo se resuelve:** (1) averiguar quién ocupa el puerto (`ss -ltnp | grep :8090`) o (2) cambiar `APP_PORT` en `.env` (se usó 8092) y ejecutar otra vez `docker compose up -d`, sin `--build`. `APP_ORIGIN` se derivó solo (`http://localhost:8092`), así que el borrado sigue funcionando; si se hubiera fijado `APP_ORIGIN` a mano habría que cambiarlo también. A partir de ahí hay que abrir la aplicación en el puerto nuevo.
- [x] `Unknown database` / `Access denied` / conexión rechazada (ya los viste en el MVP 2).
    - **Provocados uno a uno** (editando `.env`, recreando PHP, mirando la respuesta **y** el log; después se restauró el `.env`). **En todos** el visitante recibe **HTTP 503** con el mismo mensaje genérico ("No se pudo conectar a la base de datos. Inténtalo más tarde.") tanto en `GET /` como en `POST /create.php`, y la página **no filtra** claves, rutas ni texto SQL (el detector se validó con un control positivo). Lo que cambia es el código en el log:

      | Causa provocada | Línea del log (`[connection]`) | Qué significa |
      |---|---|---|
      | `DB_PASSWORD` incorrecta | `(1045) ... Access denied for user 'app'@'172.19.0.4' (using password: YES)` | Usuario o clave mal |
      | `DB_DATABASE=noexiste` con el usuario de la app | `(1044) ... Access denied for user 'app'@'%' to database 'noexiste'` | **Sin permiso** sobre esa base; MySQL no revela si existe |
      | `DB_DATABASE=noexiste` como `root` | `(1049) ... Unknown database 'noexiste'` | La base no existe (solo lo ve quien puede listar todas) |
      | `DB_PORT=3307` | `(2002) ... Connection refused` | El host responde, pero nadie escucha en ese puerto |
      | `DB_HOST=basededatos` | `(2002) ... getaddrinfo for basededatos failed: Temporary failure in name resolution` | El nombre no existe en la red de Docker |
      | `docker compose stop db` | `(2002) ... getaddrinfo for db failed: ... name resolution` | Con el contenedor parado, el nombre `db` deja de resolverse |

    - **Lecciones:** (a) un `Unknown database` con el usuario de la aplicación **sale como 1044**, no como 1049: el síntoma es un acceso denegado a una base, y confirma el principio de mínimo privilegio. (b) "Conexión rechazada" y "BD parada" **no son el mismo error**: parar el contenedor da un fallo de resolución de nombre, no `Connection refused`. (c) Todo se diagnostica en `docker compose logs php`; la pantalla nunca da pistas, y así debe ser. (d) Todos los fallos de conexión se agrupan en 503, incluidas las credenciales malas, que son más bien un error de configuración: es una decisión de diseño discutible, no un error.
- [x] CSS que no carga (ruta equivocada).
    - **Provocado (solo con `curl`, sin ver la página):** hoy el CSS viene de la CDN, así que se añadió en `header.php` un `<link href="/css/style.css">` hacia un archivo que no existía. **`GET /css/style.css` → `HTTP 200` con `Content-Type: text/html` y el cuerpo empieza por `<!DOCTYPE html>`.** No es un 404: la regla `try_files ... /index.php` de nginx manda todo lo que no existe al front controller, así que el "CSS" es en realidad la página de inicio. En la pestaña de red todo parece correcto (200); la pista es el tipo de contenido (`text/html`, no `text/css`) y, en un navegador, el aviso de la consola de que el tipo MIME no es una hoja de estilos (**esto último no se observó**: no había navegador conectado).
    - **Variantes comprobadas:** al crear el archivo, `GET /css/style.css` → `200` con `Content-Type: text/css` (nginx lo sirve directo, sin PHP). **Mayúsculas:** `/css/Style.css` y `/CSS/style.css` → `200 text/html`: **Linux distingue mayúsculas y Windows/XAMPP no**, de modo que algo que "funcionaba en XAMPP" falla en Docker. **Typo en la CDN** (`bootstrap.min.cs`) → `404`, y con la URL correcta `200`. **Contraste:** un `.php` que no existe da un **404 de verdad** (`File not found.`), pero un `.html` que no existe vuelve a dar el listado con `200`.
    - **Cómo se resuelve:** comprobar en la pestaña de red el **tipo** de la respuesta y no solo el código, abrir la URL del CSS directamente en el navegador (si se ve el listado de usuarios, la ruta está mal), y respetar mayúsculas y minúsculas en las rutas. Un `integrity` (SRI) que no coincide también bloquea la hoja en el navegador; no se probó.
- [x] Tildes mal (quita `charset=utf8mb4` del DSN a ver qué pasa y devuélvelo).
    - **Resultado sorprendente: con este MySQL, quitar `charset=utf8mb4` del DSN no rompe nada.** Se midió qué negocia PHP (`@@character_set_client`, `connection` y `results`): sin charset en el DSN y con el servidor por defecto de MySQL 8.4 salió **`utf8mb4`** (mysqlnd toma el del servidor). Un alta de «Ángela» se guardó con los bytes `C3816E67656C61` (6 caracteres, correcto) y el listado se vio bien. La premisa del plan ("a ver qué pasa") solo da un error visible en otro escenario.
    - **El escenario que sí rompe: un servidor con `latin1` por defecto** (MySQL o MariaDB antiguos, o un XAMPP sin configurar), reproducido con `--character-set-server=latin1` en un `docker-compose.override.yml` del clon. Con el charset en el DSN, PHP seguía en `utf8mb4` (bien); **sin él, la conexión pasó a `latin1`** y: (1) las altas se guardan **codificadas dos veces**: «Ñandú» quedó como `C383E28098616E64C383C2BA` (7 caracteres) en lugar de `C391616E64C3BA` (5); (2) los usuarios del seed, bien guardados, se **leen** mal (`Gómez`, `Andrés`, `Martínez` salen con el carácter de reemplazo `�`); (3) y la trampa más peligrosa: **la fila corrupta «Ñandú» se veía bien en la página** (se escribe y se lee por la misma conexión `latin1`, así que el error se compensa) y solo delatan el daño otras herramientas, como Adminer.
    - **Lo que pasa al devolver el DSN a su sitio:** se leen bien las filas correctas (`Gómez`, `Andrés`...), pero **la fila ya corrupta sigue corrupta** y ahora se hace visible como `Ã‘andÃº`: corregir el DSN **no repara datos ya guardados mal**. Habría que convertirlos con un `UPDATE`, que no se probó aquí. Conclusión: dejar `charset=utf8mb4` **explícito** en el DSN no es una formalidad, es lo que hace que la aplicación no dependa de la configuración del servidor.
- [x] "Cannot modify header information".
    - **5a. Un `echo` antes de `header('Location')` en `create.php`:** respuesta `200 OK` en lugar de `302`, sin `Location`, y en pantalla `depuración` seguido de `Warning: Cannot modify header information - headers already sent by (output started at /var/www/html/public/create.php:31) in .../create.php on line 32`. **Efecto colateral:** el usuario **sí se creó**, pero el visitante no fue redirigido; si recarga (F5), el navegador reenvía el POST y obtiene un `409` por duplicado. Es justo lo que evita el patrón Post/Redirect/Get. Se resuelve quitando el `echo`: el alta siguiente volvió a dar `302`.
    - **5b. El silencioso: líneas en blanco después de un `?>` de cierre en un archivo solo de PHP** (se probó añadiendo `?>` y dos saltos de línea al final de `user-validation.php`): el síntoma aparece en **otro archivo**, `create.php` línea 31, pero el mensaje dice `output started at /var/www/html/includes/user-validation.php:167`. **El "output started at" señala al culpable**, no la línea que falla. Por eso los archivos que solo contienen PHP **no deben cerrarse con `?>`**.
    - **5c. El BOM de UTF-8** (`EF BB BF`, que algunos editores añaden al guardar) y también un **salto de línea antes de `<?php`**: en este proyecto no dan "headers already sent" sino un **`Fatal error: strict_types declaration must be the very first statement in the script`**, porque `declare(strict_types=1)` debe ser lo primero del archivo y cualquier byte anterior cuenta como contenido. Es un fallo más ruidoso y más fácil de ver (se descubrió al intentar el caso de la línea en blanco, que no daba lo esperado). El `.editorconfig` del repositorio declara `charset = utf-8` (sin BOM), así que un editor que lo respete no lo añade; conviene comprobar que el editor tenga activada la compatibilidad con EditorConfig.
    - **Lección común:** el aviso depende de `display_errors`; con él apagado (producción) la redirección falla igual, pero **en silencio**, y solo queda el log.

### 7.3 Comparación con la solución original

- [x] **Predicciones escritas antes de abrir el zip** (2026-11-18). El zip está en `../crud_usuarios.zip`, fuera del repositorio; **no se ha abierto ni listado su contenido**. Qué cuenta como pista legítima: solo lo que este `plan.md` ya dice de la guía (nombres en español `crear.php`/`eliminar.php`/`conexion.php` y `$errores`/`$datos` en las líneas 14 a 22, `CREATE DATABASE` en la 155, CSS propio con clases `.contenedor`, `.errores`, `.vacio`... en las 364 a 367, reglas de validación en la 434, `confirm()` con texto fijo en la 3.7, XAMPP con `root` sin clave en la 849, "errores técnicos en pantalla" en la 847). La confianza va en tres niveles: **Alta** (el plan lo dice casi literalmente), **Media** (se deduce de una pista) y **Baja** (apuesta). Al comparar, rellenar la última columna con ✔ (acerté), ✘ (fallé) o ~ (acerté a medias, con el matiz).

  **A. Qué creo que hizo diferente el original**

  | # | Predicción | Confianza | Resultado |
  |---|---|---|---|
  | A1 | Las credenciales de la BD están escritas dentro de `conexion.php` (host `localhost`, usuario `root` sin contraseña, el XAMPP de siempre), no en variables de entorno. | Alta | ✔ Exacto: `$host = 'localhost'`, `$usuario = 'root'`, `$clave = ''` y el comentario "XAMPP trae el usuario root sin contraseña". |
  | A2 | El script SQL empieza con `CREATE DATABASE` + `USE`, y no hay usuario de BD dedicado. | Alta | ✔ `database.sql` empieza con `CREATE DATABASE IF NOT EXISTS crud_usuarios` + `USE`, y la app conecta como `root`. |
  | A3 | El CSS es propio (`style.css`, blanco y negro, Arial), sin framework ni CDN, con las clases `.contenedor`, `.btn`, `.mensaje`, `.errores`, `.vacio`... | Alta | ✔ `css/style.css` con `#111111`/`#ffffff` y `font-family: Arial`; clases `.contenedor`, `.btn`, `.btn-claro`, `.btn-sm`, `.mensaje`, `.errores`, `.vacio` (y además `.barra`, `.campo`, `.formulario`, `.acciones`, `.encabezado`, `.botones`). |
  | A4 | Los errores de validación se muestran como una **lista** en un recuadro `.errores`, no como un mensaje por campo marcado. | Alta | ✔ `<div class="errores"><ul><li>...` con un arreglo `$errores[]` de mensajes; nada marcado por campo. |
  | A5 | El teléfono se valida con **una sola regex** de 7 a 20 caracteres entre `[0-9+ -]`, así que acepta `-------` (no cuenta dígitos reales). Nombre y apellido solo se comprueban como "no vacío": sin máximo ni juego de caracteres. El correo no se pasa a minúsculas. | Media | ✔ `/^[0-9+\s-]{7,20}$/`: acepta `-------`, `+ - - -` y, por el `\s`, hasta tabuladores y saltos de línea (probado). Nombre y apellido solo comparan `=== ''`: el límite lo pone únicamente el `maxlength` del HTML. El correo no se pasa a minúsculas. |
  | A6 | **Todas las respuestas son 200 o 302**: nunca 422, 409, 404, 400, 403 ni 503. Los errores se pintan con un 200, y un fallo de BD se muestra con el **detalle técnico en pantalla** (limitación de §9.1). | Alta | ✔ Cero `http_response_code` y cero `error_log` en todo el zip: nada devuelve 4xx ni 5xx a propósito, y `getMessage()` se vuelca en pantalla en tres sitios (`conexion.php:20` con `die(...)`, `crear.php:35` y `editar.php:58`). Matiz: no hay ningún log, así que ni siquiera queda el detalle en un archivo. |
  | A7 | `eliminar.php` **redirige siempre**, también con GET, y muestra "eliminado" aunque no se haya borrado nada (sin `rowCount()`, sin 405, sin comprobar el origen). | Alta | ✔ `eliminar.php` hace el `DELETE` solo si es POST con id válido y **después redirige siempre** a `msg=eliminado`, sin `rowCount()`. Matiz importante: ya era POST-only (comentario "evita borrados accidentales vía URL"); lo que no tiene es el 405. |
  | A8 | Para el correo duplicado captura `23000` (cualquier violación de integridad) **o** hace un `SELECT` previo; una de las dos, no el `1062`. | Media | ✔ `$e->getCode() === '23000'`, sin `SELECT` previo; el mensaje "Ya existe un usuario con ese correo" sale también para cualquier otra violación de integridad. |
  | A9 | `editar.php` lee el id con `FILTER_VALIDATE_INT` **sin rango** (acepta `0`, negativos y `+5`) y, si es inválido o no existe, **redirige al listado** en lugar de dar un 404. | Media | ✔ `FILTER_VALIDATE_INT` sin rango y `if (!$id)`: descarta `0`, pero deja pasar `-3` y `+5`; si es inválido o no existe, `header('Location: index.php')`. Extra: lee `$_GET['id'] ?? $_POST['id']`, es decir, mezcla las dos fuentes (el riesgo de mi pregunta de diseño 1 de `edit.php`). |
  | A10 | `crear.php` y `editar.php` **duplican** la validación y el formulario (código copiado y pegado). | Alta | ✔ Las líneas de validación de `crear.php` y `editar.php` son idénticas, y el formulario también, salvo el `id` oculto. |
  | A11 | El `confirm()` lleva un texto **fijo** (`¿Eliminar este usuario?`), sin el nombre; si llevara el nombre, se rompería con `O'Brien`. | Alta | ✔ `onsubmit="return confirm('¿Eliminar este usuario?');"`, texto fijo. |
  | A12 | Estructura simple: un `header.php` y un `footer.php` en `includes/`, sin parciales ni funciones compartidas de validación; el HTML del listado va entero en `index.php`. | Media | ✔ `includes/header.php` (14 líneas) y `includes/footer.php` (3 líneas); la tabla del listado va entera en `index.php`; sin parciales ni funciones compartidas. |
  | A13 | Los mensajes de éxito usan un arreglo `$mensajes` con la clave `msg` como lista blanca, igual que el mío. | Alta | ✔ `$mensajes` con `isset($mensajes[$msg])`. Matiz: con `?msg[]=x` esa línea lanza `TypeError: Cannot access offset of type array in isset or empty` (probado en el contenedor); mi versión lo valida con `is_string`. |
  | A14 | Las 5 limitaciones de §9.1 serán, más o menos: sin inicio de sesión, sin CSRF, usuario `root` de BD, errores técnicos en pantalla y una quinta más (borrado definitivo o sin paginación). | Baja | ~ **Con la guía a la vista (§9.1):** acerté **4 de 5 de lleno** (sin autenticación, sin CSRF, `root` de la BD y errores técnicos en pantalla). La quinta la repartí entre «borrado definitivo o sin paginación» y era **sin paginación**: la mitad de mi apuesta. Pesa poco como acierto, porque dos de las cuatro ya se deducían del plan (M5, M6 y M7 existían como mejoras). |
  | A15 | `htmlspecialchars()` escrito en línea, sin un helper, y posiblemente sin `ENT_QUOTES`. | Baja | ✔ `htmlspecialchars()` en línea en todo el zip, sin helper. Ninguna llamada pasa banderas (0 coincidencias de `ENT_QUOTES`): depende del valor por defecto, que escapa también las comillas simples solo desde PHP 8.1. |

  **B. Qué creo que hace mejor mi versión** (cada punto con la prueba que lo respalda)

  | # | Lo que creo que hago mejor | Respaldo | Resultado |
  |---|---|---|---|
  | B1 | Códigos HTTP honestos y uniformes: 422, 409, 404, 400, 403, 405, 503 y 500, y nunca un 200 si no se completó. | Líneas base de 4.4; baterías de `curl` | ✔ Confirmado: el original no usa ningún código distinto de 200/302 (0 `http_response_code`). |
  | B2 | Errores por campo y accesibles (`is-invalid`, `aria-invalid`, `aria-describedby`), y el formulario conserva lo escrito, incluso con la BD caída. | `edit.php`, parcial `user-form.php` | ~ **Matiz:** el original también conserva lo escrito al fallar la validación (`value="<?= htmlspecialchars($datos[...]) ?>"`), así que eso no es ventaja mía. Lo mío es el error **por campo**, los atributos `aria-*` y conservar el formulario con la BD caída (el original hace `die()` antes de pintar nada). |
  | B3 | Validación más exacta: dígitos reales en el teléfono, correo en minúsculas, id canónico (`parse_user_id`: sin `+`, espacios ni ceros a la izquierda). | `user-validation.php`; 33 y 20 comprobaciones | ✔ El original acepta teléfonos sin dígitos, `+5` como id y correos con mayúsculas; el mío no. |
  | B4 | Borrado idempotente: `rowCount()` con `FOUND_ROWS`, mensaje `missing` y detección del usuario borrado entre el `SELECT` y el `UPDATE`. | Carrera reproducida con un bloqueo | ✔ El original redirige a "eliminado" aunque no haya borrado nada (sin `rowCount()`). |
  | B5 | `delete.php` solo acepta POST (405 con `Allow`), comprueba el origen (`APP_ORIGIN`, 403) y valida el id antes de abrir la BD. | 23 y 32 comprobaciones | ~ **Matiz:** el original ya solo borra por POST. Lo que añade el mío es la respuesta honesta (405 con `Allow`, 403 por origen, 400 por id mal formado) y comprobar todo antes de abrir la BD. |
  | B6 | Sin duplicación: `user-validation.php` y los parciales sirven a `create.php` y `edit.php`. | 5.2.0 | ✔ El original duplica la validación y el formulario (A10); el mío las comparte. |
  | B7 | Entorno más seguro: credenciales por variables de entorno, usuario de BD dedicado, `expose_php` apagado y los detalles técnicos al log, no a la pantalla. | `connection.php`, `php.ini` | ✔ El original tiene la clave de `root` escrita, no usa `error_log` y vuelca `getMessage()` al visitante, incluido el usuario y el host de la BD en el `die()`. Comparación justa: está pensado para XAMPP local. |
  | B8 | El diálogo de confirmación con el nombre está bien escapado (JSON y luego HTML), y funciona con `O'Brien`. | Prueba del atributo decodificado | ~ **Matiz:** el original usa un texto fijo y por eso no puede romperse con `O'Brien`; mostrar el nombre es una función **extra** que yo añadí, y es la que obliga al escape en dos capas. No es una corrección de un fallo suyo. |

  **C. Qué creo que el original hace mejor o más sencillo** (autocrítica: dónde puede estar peor mi versión)

  | # | Posible debilidad de mi versión | Resultado |
  |---|---|---|
  | C1 | **Complejidad para quien aprende:** más archivos y capas (`user-validation.php`, `origin.php`, tres parciales, constantes, `$messageTypes` paralelo); el original probablemente se lee de arriba abajo en cada archivo. | ✔ Medido: el original tiene **7 archivos PHP y 253 líneas de código**; el mío, **16 archivos y 647 líneas** (2,6 veces más), sin contar comentarios ni líneas en blanco. Cada archivo del original se lee de arriba abajo sin saltar a otros. |
  | C2 | **Dependencias externas:** mi CSS es Bootstrap 6 en **versión alfa** por CDN (necesita internet, puede cambiar y ya dio un parpadeo sin CSS con la caché desactivada); un CSS propio no depende de nadie. | ✔ El original **no depende de nadie**: CSS propio (59 líneas), sin JavaScript salvo `confirm()` y sin CDN. El mío carga Bootstrap 6 **alfa** y Bootstrap Icons desde dos CDN, más el paquete de JavaScript. |
  | C3 | **Falsos rechazos:** mis reglas de nombre (solo letras, espacios, `'`, `.`, `-`) y de teléfono son más estrictas y podrían rechazar datos legítimos; "una validación que rechaza datos legítimos es un fallo". | ✔ Probado con mi propia validación: rechaza `Pere Cal·lís` (el punto medio de la ortografía catalana), `Smith, Jr.` (coma) y `Juan 2º` (dígito). Acepta `Jean-Luc`, `D'Angelo`, `Nguyễn`, `李雷`, `O’Neil` y `Ng`. El original acepta los tres rechazados. Es el coste de la regla que añadí sin que la BD la exigiera. |
  | C4 | **Pruebas fuera del repositorio:** mis baterías de `curl` viven en una carpeta temporal y no están versionadas; si el original tampoco las tiene, el defecto es compartido, pero el mío tiene más cosas que probar. | ✔ Defecto compartido: el zip no trae ninguna prueba. Las mías (baterías de `curl`) siguen fuera del repositorio. |
  | C5 | **Repetición que sigue ahí:** el bloque `try/catch` de 503 y 500 se repite en `index.php`, `create.php`, `edit.php` y `delete.php`, y solo `delete.php` comprueba el origen. | ✔ El mismo bloque `try/catch` de 503 y 500 está en 4 de mis páginas. Matiz: el original repite cosas mayores (todo el bloque de validación y el formulario, en dos archivos). |

  **D. Lo que no predije**
  1. **`eliminar.php` ya era POST-only** y lo decía en un comentario ("evita borrados accidentales vía URL"). Yo daba por hecho que el original lo dejaba abierto; en realidad lo que le falta es el 405 y la honestidad de la respuesta, no la protección contra GET.
  2. **`index.php?msg[]=x` rompe el listado del original** con un `TypeError` (probado). Cuando preguntaste cómo evitarlo, el original no lo había resuelto.
  3. **Sin validación de longitud en el servidor.** Solo hay `maxlength` en el HTML (80, 80, 120 y 20, menos que mis 100, 100, 254 y 20). Por lectura del código (no se ejecutó): un POST más largo llegaría a MySQL y el error técnico se volcaría en pantalla con `'Error al guardar: ' . $e->getMessage()`.
  4. **El charset ya estaba explícito en el DSN** (`charset=utf8mb4`), coherente con lo que se vio en 7.2: la configuración correcta no depende del servidor.
  5. **El `<title>` es fijo y el CSS se enlaza con una ruta relativa** (`href="css/style.css"`): funciona porque todas las páginas están en la misma carpeta. El mío pone un título por página.
  6. **No existe ningún log.** Los errores solo existen en pantalla, y el visitante ve, con el `die()` de `conexion.php`, el usuario y el host de la BD.
  7. **Se lee de arriba abajo.** Es el punto fuerte que menos esperaba valorar: lógica arriba, vista abajo, 14 a 99 líneas por archivo. Las capas que añadí a cambio de robustez tienen un coste real de legibilidad (C1).

  **Marcador final:** parte A: **14 de 15 de lleno y 1 a medias** (la A14, que solo se pudo resolver al tener la guía: 4 de las 5 limitaciones y la quinta repartida entre dos opciones) · parte B: **5 confirmadas y 3 con matiz** de 8 (B2, B5 y B8) · parte C: **5 de 5** debilidades confirmadas. **Cómo leer el 14 de 15:** las predicciones de confianza alta se apoyaban en pistas que el propio plan ya daba sobre la guía, así que acertarlas demuestra poco; las que de verdad arriesgaban algo (A5, A8, A9 y A15) salieron bien, y la A14 quedó a medias.

- [x] Ahora sí, descomprime `crud_usuarios.zip` en una carpeta temporal y **compara tu código con el original**: ¿qué hiciste diferente? ¿qué hace mejor cada versión?
    - **Cómo se hizo:** el zip (9 archivos, 12 KB, sin rutas sospechosas) se extrajo en una carpeta temporal nueva y se leyó entero. **Comparación estática: no se ejecutó nada del zip**; solo se probaron en el contenedor fragmentos del lenguaje (el `isset` con un arreglo, su regex de teléfono) y mi propia validación con nombres reales. Los resultados están en las tablas de predicciones de arriba.
    - **Veredicto: ¿qué hace mejor cada versión?**
      - **El original gana en:** *legibilidad y tamaño* (253 líneas en 7 archivos frente a mis 647 en 16; cada archivo se lee de arriba abajo), *cero dependencias externas* (CSS propio, sin CDN ni versión alfa) y *permisividad* (acepta nombres que mi regla rechaza, como `Pere Cal·lís` o `Smith, Jr.`). Para aprender el flujo CRUD es mejor.
      - **El mío gana en:** *corrección ante datos sucios* (teléfono sin dígitos, `+5` como id, `?msg[]=x`, correos con mayúsculas), *honestidad de las respuestas* (422, 409, 404, 400, 403, 405 y 503, frente a todo 200/302 y a un borrado que dice "eliminado" sin haber borrado), *no filtrar nada al visitante* (el original vuelca `getMessage()` y el usuario y el host de la BD, y no tiene ningún log), *entorno* (variables de entorno, usuario de BD dedicado, `expose_php` apagado) y *ausencia de duplicación* (validación y formulario compartidos).
      - **Lo que ninguno tiene:** inicio de sesión, token CSRF, pruebas versionadas y borrado lógico.
    - **Lo que me llevo:** buena parte de la distancia entre las dos versiones (el 2,6 de tamaño) la pagan cosas que **no hacía falta** para un CRUD base: la comprobación de origen, el 404 con parcial propio o el escape en dos capas del diálogo (B8). Fueron decisiones razonables como ejercicio, y conviene decir que son complejidad añadida, no una corrección de fallos del original.
- [x] Pídeme una revisión de tu código (puedes pegarlo o dejarlo en el proyecto): señalamos mejoras sin reescribirlo por ti.
    - **Revisión de código (2026-11-19).** Alcance: los 16 archivos PHP, `docker-compose.yml`, `Dockerfile`, `php.ini`, la configuración de nginx y los SQL. **No se modificó ningún archivo de código**: el plan pide señalar y que decidas tú. Cada hallazgo se comprobó con una prueba, no solo leyendo. **Lo que no se revisó:** rendimiento con muchos usuarios, comportamiento en navegadores y la configuración de Xdebug.
    - **Auditoría mecánica, lo que está bien:** (1) todas las salidas a HTML con datos de usuario pasan por `escape()`; las 4 que no (`user-form.php:39` y `:44`, `users-list.php:21`, `status.php:69`) son cadenas constantes o un `count()`; (2) las 7 sentencias SQL de la aplicación son consultas preparadas o consultas fijas, y ninguna interpola variables (0 coincidencias); (3) las 3 redirecciones llevan `exit`; (4) los datos de entrada entran por pocos sitios (`read_user_input`, `parse_user_id`, `is_allowed_origin`) y todos validan el tipo; (5) ningún archivo de solo PHP termina con `?>`.
    - **Hallazgos** (a rellenar la columna Decisión: aplicar, aplazar o descartar):

      | # | Gravedad | Dónde | Hallazgo y prueba | Dirección (sin reescribirlo) | Decisión |
      |---|---|---|---|---|---|
      | R1 | **Alta** | `users-list.php:47`, `01-schema.sql` | **La fecha de creación se muestra en UTC como si fuera hora local.** MySQL trabaja en UTC (`time_zone = SYSTEM`) y PHP en Bogotá (`date.timezone`): para un usuario recién creado el listado mostró `14:08:36` cuando en Bogotá eran las `09:08:36` (5 horas de error). Además la columna es `TIMESTAMP`, que no admite fechas posteriores a 2038. | Decidir una política: guardar en UTC y convertir al mostrar (`DateTimeImmutable` con zona), o fijar la zona de MySQL con la de la aplicación (variable `TZ` o `default-time-zone`). Valorar `DATETIME` si se quiere evitar el límite de 2038. | **Aplicado.** Política: se guarda en UTC y se muestra en hora local. `connection.php` ejecuta `SET time_zone = '+00:00'` en cada conexión, MySQL arranca con `--default-time-zone=+00:00`, `created_at` pasa a `DATETIME` (sin el límite de 2038) y `format_utc_datetime()` convierte al mostrar. Prueba: un usuario nuevo se guardó como `16:26:43` UTC y el listado mostró `11:26:43`, a 1 s de la hora real de Bogotá; la función pasa 8 de 8 casos (medianoche, año, verano, 2040, fecha y zona inválidas). La BD existente se migró con un `ALTER` y los valores no cambiaron. |
      | R2 | **Alta** | `status.php` | **Página pública que expone versiones.** Sin autenticación ni mirar `APP_ENV` imprime `PHP 8.4.27`, `Conectado a MySQL 8.4.13` y `Entorno: local`: justo lo que se ocultó con `expose_php = Off`. Además repite su propia función `e()` y su propia conexión PDO. | Que responda 404 si `APP_ENV` no es `local`, o moverla a `src/scripts/` (fuera de `public/`), o mostrar solo "ok/error" sin versiones. | **Aplicado.** Fuera de `APP_ENV=local` responde 404 con "No encontrado." y sin ninguna versión (probado recreando el contenedor con `APP_ENV=production`); usa `connection.php`, `escape()` y el layout, sin `e()` ni PDO propios; devuelve 503 sin detalles técnicos si falla la BD. |
      | R3 | Media | `docker/nginx/default.conf` | **Ninguna cabecera de seguridad.** Ausentes `X-Content-Type-Options`, `X-Frame-Options` (o `frame-ancestors`), `Referrer-Policy` y `Content-Security-Policy`. `nosniff` importa por lo visto en 7.2 (un CSS inexistente se sirve como `text/html`). Sin protección contra *clickjacking*, el formulario de eliminar se podría enmarcar. Sigue visible `Server: nginx/1.30.6`. | Añadir las cabeceras en nginx (`add_header ... always`) y `server_tokens off`, ya anotado para M7. Aviso de diseño: una CSP estricta chocaría con los `onsubmit` en línea; habría que mover el `confirm()` a un JavaScript externo (por ejemplo con `data-*`). | **Aplicado.** Nginx con `server_tokens off`, `X-Content-Type-Options`, `X-Frame-Options: DENY`, `Referrer-Policy: same-origin`, `Permissions-Policy` y una CSP (JavaScript y CSS solo propios o de `cdn.jsdelivr.net`, sin nada en línea), validado con `nginx -t`. Un verificador revisó las 7 páginas (listado, aviso, crear, crear con errores, editar, 404 y estado): ninguna tiene scripts, estilos o manejadores en línea y todos los recursos externos están permitidos; las cabeceras llegan también en los 404, 403, 405 y en los archivos estáticos. Para ajustar la política se miró qué piden Bootstrap (0 `url()`) y los iconos (2 fuentes de la CDN). **Límite: no se pudo comprobar en un navegador** (la extensión no estaba conectada). Conviene abrir la aplicación, mirar la consola por mensajes `Refused to...` y probar el botón Eliminar. |
      | R4 | Media | todo el repo | **No hay ninguna prueba versionada.** Mis baterías de `curl` (más de 150 comprobaciones entre la función de origen, el CRUD, los 4xx/5xx y la BD detenida) están en una carpeta temporal y se perderían. Las funciones puras (`user-validation.php`, `origin.php`) son fáciles de probar. | Versionar un `tests/` con scripts de `curl` y, para las funciones puras, PHPUnit (ya previsto como opcional en M8). | **Aplicado.** `src/tests/unit.php` (123 comprobaciones de las funciones puras) y `tests/smoke.sh` (84 de punta a punta, 97 con `--with-db-stop`), documentados en el README. Controles negativos: con una expectativa estropeada `unit.php` sale con código 1; con `ORIGIN` equivocado `smoke.sh` da 13 fallos y código 1; con la aplicación apagada sale con código 2. |
      | R5 | Media | `user-validation.php` | **Reglas que rechazan datos legítimos.** La regex de nombres rechaza `Pere Cal·lís`, `Smith, Jr.` y `Juan 2º` (la BD solo exige `NOT NULL`); `FILTER_VALIDATE_EMAIL` rechaza `ñandu@correo.com` (letras no ASCII) por defecto. Es la misma advertencia de "una validación que rechaza datos legítimos es un fallo" y quedó como debilidad C3. | Decidir la política: nombres = sin caracteres de control y con longitud máxima (más permisivo) o ampliar el juego de caracteres; correo = `FILTER_FLAG_EMAIL_UNICODE` o aceptar solo ASCII y documentarlo. | **Aplicado.** Nombres: al menos una letra y ningún carácter de control (`/^(?=.*\p{L})[^\p{Cc}]+$/u`); correo con `FILTER_FLAG_EMAIL_UNICODE`. 30 comprobaciones: ahora acepta `Pere Cal·lís`, `Smith, Jr.`, `Juan 2º`, `ñandu@correo.com` y un nombre persa con ZWNJ (que `\p{C}` habría rechazado), y sigue rechazando vacío, sin letras, saltos de línea, NUL, UTF-8 inválido y más de 100 caracteres. **Consecuencia aceptada:** un nombre como `<b>x</b>` ahora se guarda; se escapa al mostrarlo, que es lo que protege del XSS. |
      | R6 | Media | README, `plan.md` | **Documentación desactualizada.** El README dice `MVP 6 Hecho (pendiente commit)` aunque el commit `f7f3523` ya existe; su tabla de errores solo cubre 503 y 500, y la aplicación devuelve 200, 302, 400, 403, 404, 405, 409, 422, 500 y 503 (y la etiqueta `[json]`); el Anexo F dice "Bootstrap 5" y es 6.0.0-alpha.1; y la línea de `.env.example` del README (`DB_DATABASE=crud_usuarios`) sigue contradiciendo al ejemplo (`app`), pendiente desde 7.1. | Actualizar esos cuatro sitios y decidir la fuente de verdad del nombre de la BD. | **Aplicado.** README: estado de los MVP, tabla de códigos HTTP, secciones de pruebas, fechas y cabeceras, estructura y migración de una BD antigua (el comando se probó tal cual); `.env.example` alineado con el README y el plan (`crud_usuarios` y `crud`); Anexo F con Bootstrap 6.0.0-alpha.1 y las decisiones nuevas. |
      | R7 | Baja | `header.php:25`, `index.php:64`, `users-list.php:50` y `:54` | **Detalles de interfaz.** El nombre de la aplicación enlaza a `#` en lugar del listado; el botón de cerrar el aviso tiene `aria-label="Close"` en inglés en una interfaz en español; y los botones de icono de **todas** las filas se llaman igual ("Editar usuario", "Eliminar usuario"), así que un lector de pantalla no distingue a quién se refieren. | Enlazar a `index.php`, traducir a "Cerrar" y añadir el nombre del usuario (escapado) al `aria-label` de cada fila. | **Aplicado.** El nombre de la app enlaza a `index.php`, el aviso se cierra con "Cerrar", los botones de cada fila dicen a quién se refieren (`aria-label` y `title` con el nombre escapado) y los iconos son `aria-hidden`. Probado con `O'Brien "Pat" <b>Pérez</b>`: el atributo no se rompe y no hay HTML sin escapar. |
      | R8 | Baja | `header.php:18` | **Protección incoherente de la CDN.** El CSS y el JavaScript de Bootstrap llevan `integrity`, pero la hoja de Bootstrap Icons no: si esa CDN se viera comprometida, solo esta quedaría sin verificar. Sigue además el riesgo de depender de una versión alfa (C2) y de internet. | Añadir `integrity` y `crossorigin` a esa hoja, o alojar los archivos en el propio servidor. | **Aplicado.** La hoja de iconos lleva `integrity` y `crossorigin`; el hash se calculó con un método que antes reproduce exactamente los dos que ya tenía el proyecto. Las 3 CDN llevan `integrity`. Límite: SRI no cubre las fuentes que el CSS descarga después; de eso se ocupa la CSP. |
      | R9 | Baja | `index.php:16-22` | **Lógica de vista dentro del bloque de datos.** El texto del diálogo de confirmación (`$confirmTexts`) se construye dentro del `try` de la consulta, y por eso existe un `catch (JsonException)` entero. Como `JSON_INVALID_UTF8_SUBSTITUTE` impide que `json_encode` falle, esa rama es solo una red de seguridad que no se ejecuta nunca (no tiene prueba) y añade código a una página que debería ser simple. | Mover la construcción del texto al parcial (una función pequeña que reciba la fila) y valorar si hace falta `JSON_THROW_ON_ERROR`. | **Aplicado.** Fuera `$confirmTexts`, `json_encode`, `JSON_THROW_ON_ERROR` y el `catch (JsonException)`: el mensaje va en `data-confirm` y solo se escapa para HTML, con `js/confirm-submit.js` (probado con `node`: acepta, cancela, formulario sin `data-confirm` y evento que no es de un formulario). La búsqueda de `confirmTexts`, `JsonException` y `onsubmit=` en `src/` da 0 resultados. |
      | R10 | Baja | `connection.php:26` | **La conexión no tiene tiempo máximo.** `status.php` usa `PDO::ATTR_TIMEOUT => 3` y `connection.php` no: con la BD colgada (no parada), cada petición esperaría el tiempo por defecto y se agotarían los procesos de PHP-FPM. | Añadir `PDO::ATTR_TIMEOUT` (segundos de conexión) a las opciones. | **Aplicado.** `PDO::ATTR_TIMEOUT => 5`. Medido contra un host que no responde: **30,0 s antes y 5,0 s después**, con `DatabaseConnectionException` (un 503). |
      | R11 | Baja | `scripts/db-check.php:17-22` | **Argumento muerto.** El formato de `sprintf` tiene 2 `%s` y se le pasan 3 valores: `environment('DB_DATABASE')` se exige (el script falla si falta) pero no se usa, porque el script lista las bases sin seleccionar ninguna. | Quitar esa llamada o añadir `dbname=%s`, según la intención. | **Aplicado.** Quitada la llamada que no se usaba. Antes el script fallaba con "Falta la variable de entorno DB_DATABASE" sin necesitarla; ahora lista las bases con o sin ella, y sigue avisando si falta `DB_HOST`. |

    - **Verificación de conjunto de R1 a R11 (2026-11-19):**
      1. **Instalación desde cero del árbol de trabajo** (carpeta nueva, otro proyecto de Compose y puertos 8090 y 8091, siguiendo el README, con los cambios aún sin commit): la BD se creó con los **3 usuarios del seed**, `created_at` como `DATETIME` y el servidor en `+00:00`; las **123 unitarias** y las **97 de punta a punta, con la BD parada incluida, dieron 0 fallos**. Después se retiró todo (contenedores, volumen, red, imagen y carpeta).
      2. **Sobre este proyecto:** 123 unitarias y 84 de punta a punta, 0 fallos.
      3. **Baterías históricas** (carrera del `UPDATE` con bloqueo, 20 de 20; origen, 23 de 23; general, 23 de 24). El único fallo era de la propia prueba: esperaba un usuario `jacu29@gmail.com` que dejó de existir al recrear la BD con `down -v`, de modo que el "duplicado" se creó de verdad; se retiró ese usuario y `smoke.sh` ya no depende de un usuario concreto.
      4. **Errores míos en el camino, corregidos:** un script se detuvo a medias y dejó el listado usando `data-confirm` sin que el footer cargara el JavaScript (se detectó y se arregló en el mismo paso, antes de probar nada); y dos comprobaciones fallaron porque el cliente `mysql` de la consola habla `latin1` (los datos guardados estaban bien, comprobado por sus bytes), por lo que `smoke.sh` ahora usa `--default-character-set=utf8mb4`.
      5. **Sin comprobar en un navegador** (la extensión no estaba conectada): que la CSP no bloquee nada al ejecutarse el JavaScript de Bootstrap (la comprobación fue estática sobre el HTML y sobre el código de Bootstrap), que el botón Eliminar pida confirmación y que los iconos se vean. Hacerlo es abrir `http://localhost:8080`, mirar la consola por mensajes `Refused to...` y probar Eliminar con Aceptar y con Cancelar.
      6. **Archivos que cambian:** `.env.example`, `README.md`, `database/01-schema.sql`, `docker-compose.yml`, `docker/nginx/default.conf`, `plan.md`, `connection.php`, `helpers.php`, `footer.php`, `header.php`, `users-list.php`, `user-validation.php`, `index.php`, `status.php` y `db-check.php`; y nuevos: `src/public/js/`, `src/tests/` y `tests/`. Nada se ha commiteado.
    - **Verificación de conjunto de los menores (a a h):** (1) **instalación desde cero del árbol final** (carpeta nueva, otro proyecto de Compose, puertos 8090 y 8091, siguiendo el README, con la imagen reconstruida sobre `php:8.4.27-fpm` y el nuevo `php.ini`): BD con los 3 usuarios del seed, `DATETIME` y UTC, **135 unitarias y 144 de punta a punta (con la BD parada y la consulta fallida) sin ningún fallo**, y después se retiró todo; (2) sobre este proyecto: 135 unitarias y 121 de punta a punta sin parar la BD (144 con ella parada). (3) **Errores míos en el camino:** un parche de `smoke.sh` se detuvo en una aserción antes de escribir, por lo que una ejecución probó el script antiguo contra la guarda nueva (todos los POST sin `Origin` dieron 403, que resultó ser una prueba accidental de que la guarda funciona); luego recoloqué mal un bloque de pruebas y una comprobación leyó la respuesta de otra petición (corregido); y mi primer test de un fatal en producción dio 200 porque el script imprimía algo antes de fallar (con un script que falla antes de imprimir da 500, como debía). (4) **Sin comprobar en un navegador** (la extensión no estaba conectada): que `autocomplete="off"` y `type="tel"` se comporten como se espera en cada navegador, y que el aviso de conflicto de edición se vea bien.
    - **Menores (a a h): revisados uno a uno (2026-11-19).** De los ocho, seis se aplicaron (dos de ellos en parte) y dos **no se aplican a propósito**, con su motivo:

      | Ref | Hallazgo | Resultado | Qué se hizo y cómo se comprobó |
      |---|---|---|---|
      | a | `try/catch` repetido en 4 páginas y `$pdo` global | **Aplicado en parte** | `describe_failure()` en `helpers.php` traduce el fallo en código y aviso y lo registra: cada página pasa de 3 `catch` a **1** (12 a 4) y `log_exception()` solo se llama desde un sitio. Probado: `[connection]` → 503, `[query]` → 500 con el aviso propio de cada página (renombrando la tabla un instante) y `[config]` → 500, sin detalles técnicos. **No se toca el `$pdo` global:** se resuelve en M8 con el repositorio y la inyección de dependencias. |
      | b | CSRF en `create.php` y `edit.php` | **Aplicado en parte** | `reject_foreign_origin()` (`includes/request-guard.php`) la usan ahora las tres páginas con POST, con el mismo orden (403 antes que 404, 422 y 400). Probado: origen ajeno, `null`, vacío, ausente y solo `Referer` correcto, sin que se cree ni se modifique nada; con `APP_ORIGIN` inválido las tres dan 500. **Sigue pendiente el token CSRF** (M6, que necesita la sesión de M5): la comprobación de origen es una capa extra, no la defensa completa. |
      | c | `display_errors = On` en la imagen | **Aplicado** | `php.ini` pasa a ser seguro por defecto (errores solo al log) y `php.local.ini` (errores en pantalla) solo se carga con `APP_ENV=local`, mediante `PHP_INI_SCAN_DIR`. Probado en los dos entornos: en `local` `display_errors=1`, se carga `99-dev.ini` y un fatal se ve en pantalla; en `production` `display_errors` vacío, no se carga, y un fatal responde **500 con el cuerpo vacío** y el detalle en el log; un `APP_ENV` inventado queda en modo seguro. |
      | d | `?msg=` falsificable | **No aplicado, a propósito** | La solución correcta es llevar el aviso en la **sesión** (*flash*), y eso exige sesiones, que llegan en M5, con sus parámetros de cookie (`httponly`, `samesite`). Hacerlo ahora cambiaría el contrato de las redirecciones (`Location: index.php?msg=...`, que usan las pruebas, el plan y el README) y dejaría una cookie sin la configuración de M5. El riesgo de hoy es solo que alguien fabrique un enlace que muestre "eliminado" sin haber borrado nada. |
      | e | `FROM php:8.4-fpm` sin fijar el parche | **Aplicado** | `FROM php:8.4.27-fpm`, la misma versión que ya corría. `smoke.sh` comprueba que la etiqueta lleva el parche. **Coste:** hay que subir el número a mano para recibir parches de seguridad. |
      | f | Teléfono con `type="text"` y sin `autocomplete` | **Aplicado, con un matiz** | `type="tel"` (teclado numérico en el móvil). Para `autocomplete` **no** se añadieron las pistas habituales (`given-name`, `email`, `tel`): este formulario guarda datos de **otra persona**, y esas pistas invitan al navegador a rellenar los del propio usuario. Se puso `autocomplete="off"` en los 4 campos. |
      | g | El mensaje de correo duplicado revela qué correos existen | **No aplicado, a propósito** | En este CRUD el mensaje **es necesario** (sin él no se sabe por qué falla el alta) y no revela nada que el listado no muestre ya a cualquiera. Solo importaría con registro o inicio de sesión públicos: se revisará en M5 (mensajes genéricos en el login). |
      | h | Dos personas editan al mismo usuario y gana la última | **Aplicado** | Control optimista sin tocar el esquema: el formulario lleva `row_token` (sha256 del contenido de la fila) y al guardar se compara con la fila actual; si cambió, **409** sin guardar nada, conservando lo escrito. 18 pasos de prueba en `smoke.sh` (A abre, B guarda, A recibe el conflicto, recarga y guarda; si B guarda los mismos valores no hay falso conflicto; token basura o arreglo). **Límite:** queda un margen de milisegundos entre la comprobación y el `UPDATE`; cerrarlo exigiría una columna de versión. Un envío sin `row_token` (`curl`) se acepta como antes. |
- [x] Lee otra vez la tabla de seguridad (Guía §9) y señala **en tu código** dónde se aplica cada medida.
    - **Tabla de §9 aplicada a mi código** (líneas a fecha 2026-11-19; se verificó con una auditoría mecánica además de leer el código). Las seis medidas de la guía están, y mi proyecto añade algo en cada una:

      | Amenaza | Medida de la guía | Dónde está en mi código | Qué añade mi proyecto | Comprobado con |
      |---|---|---|---|---|
      | Inyección SQL | Consultas preparadas con marcadores | `create.php:27` (`INSERT`), `edit.php:48` (`SELECT`) y `:70` (`UPDATE`), `delete.php:37` (`DELETE`). `index.php:13` y `status.php:27` son sentencias fijas sin datos del usuario | Prepares **reales**: `EMULATE_PREPARES => false` en `connection.php`, así que MySQL recibe la plantilla y los valores por separado | Auditoría: **0** sentencias interpolan variables |
      | XSS | `htmlspecialchars()` en toda salida | `escape()` (`helpers.php:30`, con `ENT_QUOTES \| ENT_SUBSTITUTE` y UTF-8) en **34 de las 39** salidas `<?= ?>`; las otras 5 son cadenas constantes o un `count()`. Layout, parciales, `index.php`, `edit.php` | `ENT_SUBSTITUTE` (un UTF-8 inválido no vacía la salida); **CSP** sin scripts ni estilos en línea (el diálogo de eliminar viaja en `data-confirm`); `nosniff`. Como la defensa es escapar al mostrar, las reglas de nombres pudieron ser permisivas | Un nombre `<script>alert(1)</script>` sale como `&lt;script&gt;...`; `O'Brien "Pat" <b>Pérez</b>` no rompe el atributo; verificador de CSP sobre 7 páginas |
      | Datos inválidos | Validación en el servidor con `filter_var` y regex | `user-validation.php`: `validate_user()` con `mb_strlen` (`:143`), regex del nombre (`:162`), `filter_var(... FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE)` (`:172`) y teléfono por dígitos reales (`:184`); la usan `create.php` y `edit.php` | Longitudes iguales a las columnas; correo en minúsculas; entrada que no es texto (`name[]=x`) tratada como vacía; **422** y no 200; se valida **antes** de abrir la BD | `src/tests/unit.php` (135 casos) |
      | Borrado accidental o malicioso por URL | Eliminar solo por POST y confirmación en el navegador | `delete.php:11` responde **405** con `Allow: POST` a todo lo que no sea POST; formulario `method="post"` en `users-list.php:55`; confirmación con `data-confirm` + `js/confirm-submit.js` | **Comprobación de origen** (`reject_foreign_origin()`, 403), id validado antes de la BD (400), borrado idempotente (`msg=missing`). Se reconoce que `confirm()` es comodidad y **no** protección (un `curl` la salta) | `smoke.sh`: GET → 405, origen ajeno o ausente → 403; `confirm-submit.js` probado con `node` |
      | Identificadores manipulados | `FILTER_VALIDATE_INT` | `parse_user_id()` (`user-validation.php:47`): `ctype_digit` + `filter_var(FILTER_VALIDATE_INT)` con rango 1 a 4294967295; la usan `edit.php:27` y `delete.php:26` | Cada usuario tiene **una sola URL** (rechaza `+14`, ` 14`, `014` y `?id[]=1`); id leído solo de la fuente que corresponde al método; **404** en editar y **400** en eliminar | unitarias (20 casos de id) y 10 variantes en `smoke.sh` |
      | Duplicación al recargar | Patrón Post/Redirect/Get | `header('Location: ...'); exit;` en `create.php:34`, `edit.php:83` y `delete.php:45`, las tres operaciones de escritura | Si la BD falla **no** se redirige (503 o 500 con la misma página, no un 302 que oculte el fallo); si se reenvía el POST, el `UNIQUE` responde 409 (segunda línea de defensa) | Traza real: F5 repite el GET y no el POST |

    - **Medidas que mi proyecto tiene y la tabla de la guía no menciona:** comprobación de origen en los tres POST; cabeceras de seguridad y CSP en nginx (y sin versión visible); credenciales por variables de entorno y un usuario de BD propio (ver §9.1); errores solo al log con aviso genérico (`describe_failure`); `APP_ENV` con PHP en modo seguro fuera de `local`; `status.php` solo en `local`; `expose_php` apagado; `integrity` (SRI) en las tres CDN; control de ediciones simultáneas (`row_token`); y un límite de 5 s para conectar a la BD.
- [x] Anota las 5 limitaciones de §9.1: son las mejoras que siguen.
    - **Las 5 limitaciones de §9.1 y su estado en mi proyecto** (2026-11-19). Dos siguen igual, una está reducida, una está resuelta y una está a medias:

      | # | Limitación de la guía | Estado hoy | Mejora que la resuelve |
      |---|---|---|---|
      | 1 | **No hay autenticación**: cualquiera con la dirección ve, crea, edita y borra | **Sigue igual.** Nginx y Adminer solo escuchan en `127.0.0.1`, lo que limita *quién llega*, no *quién puede hacer qué* | **M5** inicio de sesión (sesión, `password_hash` y `password_verify`, `session_regenerate_id`, cookie `httponly` y `samesite`) |
      | 2 | **No hay protección CSRF** | **Reducida, no cerrada.** La comprobación de origen en los tres POST bloquea los formularios de otros sitios, pero falta el token por sesión, que es la defensa estándar y cubre a los clientes que no envían esas cabeceras | **M6** token CSRF (`random_bytes`, `hash_equals`, campo oculto en todos los POST). Depende de M5 |
      | 3 | **La base usa el usuario `root` sin contraseña** | **A medias.** La app **no** usa `root`: se conecta con `crud` (creado por `MYSQL_USER`), con clave y credenciales por variables de entorno, y la BD no se publica fuera de la red de Docker. Pero `SHOW GRANTS` da `GRANT ALL PRIVILEGES ON crud_usuarios.*`: ese usuario podría hacer `DROP TABLE` o `ALTER`, y la aplicación solo usa `SELECT`, `INSERT`, `UPDATE` y `DELETE` | **M7** usuario `crud_app` solo con DML y otro de migraciones. Aviso práctico: la prueba de `smoke.sh --with-db-stop` que renombra la tabla usa hoy esos privilegios de esquema; con M7 habrá que hacerla como `root` |
      | 4 | **Los errores técnicos se muestran en pantalla** | **Resuelto.** Ninguna página imprime mensajes de excepción: aviso genérico y detalle al log (`describe_failure`, `log_exception`). `display_errors` está apagado salvo con `APP_ENV=local` (`php.local.ini` por `PHP_INI_SCAN_DIR`); en producción un fallo grave responde 500 con el cuerpo vacío | Hecho antes de tiempo: era el complemento de M7 |
      | 5 | **No hay paginación**: con miles de usuarios se vuelve lento | **Sigue igual, y ahora medido** (mediana de 3 peticiones): 3 usuarios, 3 ms y 5 KB; 1.000, 16 ms y 1,0 MB; 5.000, 72 ms y 5,1 MB; 20.000, **278 ms y 20,5 MB de HTML** (20.004 filas `<tr>`) y 11,6 MB de memoria en PHP. **Matiz:** lo que se vuelve lento no es PHP ni MySQL, es enviar y pintar el HTML en el navegador | **M2** paginación (`LIMIT`, `COUNT(*)` y enlaces de página); **M1** búsqueda ayuda al mismo problema |

    - **Orden sugerido para lo que sigue:** **M7** es lo más pequeño (un `GRANT` y una prueba negativa), después **M2** (paginación, independiente de lo demás), y **M5** antes que **M6**, porque el token CSRF vive en la sesión.
- [x] Commit y etiqueta (`git tag v1-crud-base`).
    - **Hecho (2026-11-19):** commit `c808f47` («MVP 7: revision final: fechas UTC, CSP, origen en POST y pruebas», 26 archivos) y etiqueta anotada `v1-crud-base` («CRUD base: MVP 0 a 7») sobre ese mismo commit. **Aclaración sobre la etiqueta:** apunta al commit del **código**; la respuesta de la pregunta de comprobación, la tabla de §9 y las 5 limitaciones de §9.1 se escribieron después y quedan en un commit posterior que solo toca `plan.md` (se comprobó que ningún archivo de código cambió después de la etiqueta). Como la etiqueta no se ha publicado en ningún remoto, también se podría mover para incluir ese commit de documentación, pero mover una etiqueta reescribe su historia y no hace falta: el código marcado como v1 es exactamente el mismo.

### Preguntas de comprobación

Dibuja de memoria el recorrido de una petición "crear usuario" (navegador → nginx → php-fpm → MySQL → respuesta → redirección) y explica en qué punto actúa cada medida de seguridad.

**Respuesta: recorrido de una petición «crear usuario».** Comprobado paso a paso con `curl -i` el 2026-11-19 (los códigos y las cabeceras de abajo son los que salieron de verdad). Entre corchetes, dónde ocurre cada paso; en **negrita**, la medida que actúa.

*Primero, pedir el formulario (GET):*

1. **[Navegador]** El usuario pulsa «Registrar usuario» y el navegador envía `GET /create.php`. Antes de pintar nada, la **CSP** limita de dónde puede cargar scripts y estilos (solo el propio sitio y `cdn.jsdelivr.net`, y con `integrity` en las tres CDN), y `form-action 'self'` limita adónde puede enviar el formulario.
2. **[nginx]** Recibe la petición (solo en `127.0.0.1:8080`, no es accesible desde la red local). La regla `location ~ \.php$` la pasa por FastCGI a `php:9000`. Al devolver **cualquier** respuesta, también las de error, añade las **cabeceras de seguridad** (`X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: same-origin`, `Permissions-Policy` y la CSP) y no revela su versión (`Server: nginx`).
3. **[php-fpm]** `create.php` ve que no es un POST y pinta el formulario vacío con el parcial `user-form.php`. Todo lo que imprime pasa por **`escape()`**; los atributos (`maxlength`, `required`, `type="email"`, `type="tel"`) ayudan al usuario, pero **no** son seguridad. `php.ini` oculta la versión de PHP y, fuera de `APP_ENV=local`, no muestra errores.

*Después, enviarlo (POST):*

4. **[Navegador]** El usuario envía el formulario: `POST /create.php` con el cuerpo `name=...&last_name=...&email=...&phone=...`. El navegador añade solo la cabecera **`Origin`**, que un script de otra página no puede falsificar (un cliente que no sea un navegador sí, pero no trae la sesión de nadie).
5. **[nginx]** Mismo trayecto que el paso 2.
6. **[php-fpm]** `create.php` hace estas comprobaciones **en este orden**, y cada una puede cortar la petición:
   1. **Comprobación de origen:** `reject_foreign_origin()` compara `Origin` (o `Referer`) con `APP_ORIGIN`, con igualdad estricta entre orígenes normalizados. Si no coincide, falta o es `null`: **403** en texto plano y no se mira nada más (ni el cuerpo ni la BD). Si `APP_ORIGIN` está mal configurado: **500** y `[config]` en el log.
   2. **Limpieza:** `read_user_input()` toma solo las claves definidas, trata como vacío lo que no sea texto (`name[]=x`), aplica `trim` y pasa el correo a minúsculas.
   3. **Validación en el servidor:** `validate_user()` comprueba nombre y apellido (al menos una letra, sin caracteres de control, hasta 100), correo (`FILTER_VALIDATE_EMAIL`, hasta 254) y teléfono (de 7 a 15 dígitos reales, hasta 20). Si algo falla: **422**, se salta la BD y se salta al paso 12.
   4. **Conexión:** `connection.php` abre PDO con credenciales **del entorno** (un usuario con permisos solo sobre su base), `charset=utf8mb4`, excepciones activadas, consultas preparadas de verdad (`EMULATE_PREPARES` apagado), sesión de MySQL en UTC y un límite de 5 s para conectar. Si no hay conexión: `DatabaseConnectionException` → **503** con un aviso genérico, y el detalle va solo al log (`[connection]`).
   5. **INSERT preparado:** `prepare()` envía a MySQL **solo la plantilla** (`VALUES (:name, :last_name, :email, :phone)`), que fija la estructura de la consulta; `execute()` envía **después** los valores como datos, que MySQL nunca interpreta como SQL. Es lo que impide la inyección SQL. El teléfono vacío viaja como `NULL`.
7. **[MySQL / InnoDB]** Comprueba el índice **`UNIQUE KEY unique_email`** dentro del propio `INSERT`, de forma atómica y sin un `SELECT` previo (así dos peticiones simultáneas no pueden duplicar un correo). La colación `utf8mb4_unicode_ci` no distingue mayúsculas, por eso `TRAZA-1@Example.com` choca con `traza-1@example.com`. Si el correo ya existe devuelve el **error 1062**; si no, guarda la fila con `created_at` en UTC.
8. **[php-fpm]** Interpreta el resultado:
   - **1062:** se detecta con `errorInfo[1] === 1062` (no con el `23000` genérico, que agrupa cualquier violación de integridad) → **409 Conflict** y el mensaje «Ya existe un usuario registrado con ese correo.» bajo el campo del correo (no se registra como error: es un caso normal).
   - **Cualquier otro fallo de la consulta:** `describe_failure()` → **500**, aviso genérico y `[query]` en el log.
   - **Éxito:** `header('Location: index.php?msg=created')` seguido de `exit`. Es el patrón **Post/Redirect/Get (PRG)**: la respuesta al POST no es una página, es una redirección.

*Y la respuesta y la redirección:*

9. **[nginx]** Devuelve la respuesta de PHP añadiéndole otra vez las cabeceras de seguridad: `302 Found` con `Location: index.php?msg=created` (o el 403, 409, 422, 500 o 503 de los pasos anteriores).
10. **[Navegador]** Sigue la redirección con un `GET /index.php?msg=created`. Si el usuario recarga la página (F5), el navegador repite **ese GET**, no el POST: por eso no se crea un duplicado.
11. **[php-fpm]** `index.php` valida `msg` contra una **lista blanca** (`is_string` y `array_key_exists`, así que `?msg[]=x` o un valor raro no muestran nada), consulta con una sentencia fija y pinta el listado: cada dato pasa por **`escape()`**, la fecha se convierte de UTC a la zona de la aplicación y el diálogo de eliminar viaja en un `data-confirm` escapado, sin scripts ni estilos en línea (compatible con la CSP).
12. **[php-fpm]** *Si en el paso 6.3 u 8 hubo un error*, la misma respuesta (422, 409, 500 o 503) **vuelve a pintar el formulario** con lo que el usuario había escrito: `value="<?= escape(...) ?>"` hace que un `<script>alert(1)</script>` salga como `&lt;script&gt;alert(1)&lt;/script&gt;` (comprobado), y los mensajes de error también se escapan. El visitante ve un mensaje genérico; el detalle técnico nunca sale de los logs.

**Dónde actúa cada medida que pedía la pregunta:** cabeceras de nginx → pasos 2, 5 y 9 (en toda respuesta) · comprobación de origen → 6.1 · validación → 6.3 · `INSERT` preparado → 6.5 · error 1062 → 7 y 8 · PRG → 8 a 10 · `escape()` → 3, 11 y 12.

**Lo que todavía NO actúa en este recorrido:** el **token CSRF** (M6, necesita sesión), el **inicio de sesión** (M5: hoy cualquiera puede crear usuarios), la limitación de peticiones por visitante y el `confirm()` del navegador (solo existe al eliminar, y es comodidad, no protección).

---

# PARTE B. Posibles mejoras (Guía §11)

Orden recomendado (de menor a mayor dificultad). Cada mejora se hace en una rama de git (`git switch -c mejora-1-busqueda`) y se une a `main` al terminar. Metodología igual que antes: concepto → pasos pequeños → prueba → preguntas.

## M1. Búsqueda y filtros

**Objetivo:** filtrar el listado por texto.
**Concepto:** una búsqueda es una petición **GET** (se puede compartir la URL). Se usa `WHERE ... LIKE :busqueda` con consulta preparada.

- [ ] Formulario en `index.php` con `method="get"` y un campo `q`.
- [ ] Lee `$_GET['q'] ?? ''` y aplica `trim`.
- [ ] Si hay texto, usa `prepare` con `WHERE name LIKE :q1 OR last_name LIKE :q2 OR email LIKE :q3`. Los comodines `%` van **dentro del valor** que pasas (`'%' . $q . '%'`), no pegados al SQL.
- [ ] **Trampa a entender:** como PDO usa `ATTR_EMULATE_PREPARES => false`, **no puedes repetir el mismo marcador** (`:q`) tres veces. Pruébalo, lee el error (`HY093`) y resuélvelo con marcadores distintos.
- [ ] Si no hay texto, la consulta original sigue funcionando.
- [ ] El campo de búsqueda conserva el texto escrito (escapado).
- [ ] Mensaje "No se encontraron resultados para ..." (distinto del estado vacío general).
- [ ] Enlace "Limpiar búsqueda".
- [ ] Reflexión: ¿qué ocurre si el usuario busca `%` o `_`? (son comodines de LIKE). Investiga cómo escaparlos.
- [ ] Pruebas: busca por nombre, apellido, parte del correo, sin resultados, con tildes (`gomez` vs `Gómez`: ¿qué hace la collation?).

**Preguntas:** ¿por qué los `%` no se concatenan en el SQL? ¿por qué GET y no POST?

## M2. Paginación

**Objetivo:** mostrar 10 usuarios por página.
**Concepto:** `LIMIT n OFFSET m`; total de páginas = `ceil(total / porPagina)`.

- [ ] Parámetro `?pagina=2`: valida entero ≥ 1 (si no, usa 1).
- [ ] Cuenta el total con `SELECT COUNT(*)` (respetando el filtro de M1 si ya existe).
- [ ] Calcula total de páginas y corrige la página si se pasó del rango.
- [ ] Calcula `OFFSET = (pagina - 1) * porPagina`.
- [ ] **Trampa a entender:** con consultas preparadas nativas, `LIMIT :l` con un string falla. Investiga `bindValue(..., PDO::PARAM_INT)` y úsalo.
- [ ] Enlaces "Anterior / Siguiente" y números de página; la página actual resaltada; deshabilitar anterior/siguiente en los extremos.
- [ ] Los enlaces **conservan** la búsqueda (`q`). Pista: `http_build_query`.
- [ ] Para probar, inserta ~35 usuarios (investiga cómo hacerlo con un script SQL de seed adicional o un bucle PHP CLI en `src/`).
- [ ] Pruebas: página 1, última, `?pagina=0`, `?pagina=abc`, `?pagina=999`, y con búsqueda.
- [ ] Tras eliminar el último usuario de una página, ¿a dónde vuelves? Piensa en el caso límite.

**Preguntas:** ¿qué problema de rendimiento resuelve? ¿qué costo tiene `OFFSET` grande? ¿por qué hay que contar aparte?

## M3. Más campos (documento, fecha de nacimiento, rol)

**Objetivo:** ampliar la tabla y los formularios.
**Concepto:** cambiar el esquema de una BD que ya tiene datos = **migración**. El script de `docker-entrypoint-initdb.d` no sirve porque solo corre la primera vez.

- [ ] Decide los tipos: `document` (¿texto o número? ¿único? ¿nulo?), `birth_date` (`DATE`), `role` (¿`ENUM` o tabla aparte? compara ventajas).
- [ ] Crea `database/migrations/001-more-fields.sql` con `ALTER TABLE users ADD COLUMN ...`. Piensa qué valor tendrán los usuarios ya existentes (`NULL` o `DEFAULT`).
- [ ] Aplícala sin borrar datos: `docker compose exec -T db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" crud_usuarios' < database/migrations/001-more-fields.sql`
- [ ] Actualiza `01-schema.sql` para que una instalación nueva ya tenga los campos (¿por qué hay que hacer ambas cosas?).
- [ ] Actualiza `create.php`, `edit.php` e `index.php`: inputs (`type="date"`, `<select>` para rol), `$data`, validación, `INSERT`, `UPDATE`, listado.
- [ ] Validaciones nuevas: formato del documento, fecha válida (`DateTime::createFromFormat` + que no sea futura), rol dentro de una **lista blanca** (¿por qué no confiar en el valor del `<select>`?).
- [ ] Pruebas: usuarios antiguos siguen funcionando, campos opcionales vacíos se guardan como `NULL`, manipular el `<select>` con F12 a un rol inexistente da error, documento repetido (si es único).

**Preguntas:** ¿qué es una migración y por qué se versiona? ¿qué riesgo tiene `ALTER TABLE` en una tabla grande?

## M4. Borrado lógico

**Objetivo:** que "eliminar" no destruya el dato.
**Concepto:** `active TINYINT(1) NOT NULL DEFAULT 1`. Eliminar = `UPDATE ... SET active = 0`.

- [ ] Migración `002-active.sql` (y actualizar el schema).
- [ ] `delete.php`: de `DELETE` a `UPDATE`.
- [ ] `index.php` (y búsqueda/paginación/conteos): filtrar `WHERE active = 1`.
- [ ] `edit.php`: ¿debería poder abrir un usuario inactivo? Decide y justifica.
- [ ] **Problema a pensar:** el correo es `UNIQUE`. Si "eliminas" a Laura y vuelves a crear otro usuario con su correo, ¿qué ocurre? Evalúa alternativas (reactivar, índice compuesto, mensaje específico) e implementa la que elijas.
- [ ] Extra: vista "Inactivos" con botón "Restaurar" (también por POST).
- [ ] Pruebas: eliminar oculta pero la fila sigue en Adminer; restaurar la devuelve; los conteos cuadran.

**Preguntas:** ventajas y desventajas del borrado lógico (auditoría, consultas más complejas, privacidad/derecho al olvido).

## M5. Inicio de sesión

**Objetivo:** que solo usuarios autenticados usen el CRUD.
**Concepto:** el servidor guarda en una **sesión** (`$_SESSION`) quién eres; el navegador solo lleva una cookie con el id de sesión. Las contraseñas **nunca** se guardan en claro: se guarda un hash.

- [ ] Tabla nueva para los administradores del sistema (no mezcles con los usuarios del CRUD): `id`, `email` único, `password_hash` (`VARCHAR(255)`), `created_at`. Migración `003-admins.sql`.
- [ ] Investiga `password_hash($clave, PASSWORD_DEFAULT)` y `password_verify`. Pregunta: ¿por qué no basta con `md5` o `sha256`? ¿qué es un *salt*?
- [ ] Script CLI `src/scripts/create-admin.php` que reciba correo y clave por argumentos (`$argv`) y cree el admin. Ejecútalo con `docker compose exec php php scripts/create-admin.php ...`. (¿Por qué un script y no un formulario de registro público?)
- [ ] `public/login.php`: formulario (GET) y proceso (POST): busca por correo con consulta preparada, `password_verify`, mensaje **genérico** si falla ("Credenciales inválidas", sin decir cuál falló).
- [ ] Al autenticarse: `session_regenerate_id(true)` y guardar el id del admin en `$_SESSION`. Investiga qué ataque previene (*session fixation*).
- [ ] `includes/auth.php` con `session_start()` y una comprobación que redirige a `login.php` si no hay sesión. Inclúyelo (arriba, antes del HTML) en `index`, `crear`, `editar`, `eliminar`.
- [ ] `public/logout.php`: debe ser **POST**; destruye la sesión y la cookie.
- [ ] Parámetros de cookie de sesión: `httponly`, `samesite=Lax` (`session_set_cookie_params`); `secure` cuando haya HTTPS. Entiende qué hace cada uno.
- [ ] Mostrar quién está conectado en el header y botón "Cerrar sesión".
- [ ] Pruebas: entrar a `/` sin sesión redirige; credenciales malas; credenciales buenas; `delete.php` por POST sin sesión no debe borrar (¡pruébalo con `curl`!); logout invalida la sesión; botón "atrás" tras salir.
- [ ] Reflexión: ¿cómo limitarías los intentos fallidos (fuerza bruta)? (Solo diseño, no hace falta implementarlo.)

**Preguntas:** ¿dónde se guarda el estado de sesión? ¿qué guarda la cookie? ¿por qué un mensaje genérico en el login?

## M6. Tokens CSRF

**Objetivo:** impedir que otro sitio envíe formularios en nombre de un usuario autenticado.
**Concepto:** con la sesión del M5, tu navegador envía la cookie automáticamente aunque la petición se origine en *otro* sitio. Solución: un **token secreto por sesión** dentro de cada formulario que el servidor verifica.

- [ ] **Demuestra el ataque primero:** crea un HTML aparte (fuera de `src/`, o en otro puerto con `php -S`) con un formulario oculto que apunte a `http://localhost:8080/delete.php` y se auto-envíe. Estando logueado, ábrelo: borra un usuario. (Con `samesite=Lax` el navegador quizá lo bloquee; razona qué protege cada capa y por qué aun así se usa token.)
- [ ] En `includes/csrf.php`: función que crea el token (`bin2hex(random_bytes(32))`) y lo guarda en `$_SESSION` si no existe; función que lo devuelve; función que verifica con `hash_equals` (investiga por qué no `===`).
- [ ] Campo oculto `csrf_token` en **todos** los formularios POST: login, crear, editar, eliminar (en el listado), logout.
- [ ] Al inicio de cada procesamiento POST: si el token no es válido, responde `403` y detén la ejecución.
- [ ] Pruebas: el flujo normal funciona; eliminar el campo oculto con F12 → 403; token modificado → 403; tu HTML atacante ya no borra.

**Preguntas:** ¿por qué el token no puede ir solo en una cookie? ¿por qué un sitio atacante no puede leerlo?

## M7. Usuario de BD dedicado (mínimo privilegio)

**Objetivo:** que la aplicación se conecte con un usuario que solo pueda leer/escribir filas.
**Concepto:** **mínimo privilegio**. Si alguien logra una inyección, el daño se limita a lo que el usuario de la BD puede hacer.

- [ ] Investiga qué privilegios tiene hoy el usuario `crud` creado por `MYSQL_USER` (`SHOW GRANTS FOR 'crud'@'%';` en Adminer con el usuario root). ¿Puede hacer `DROP TABLE`? (Spoiler: sí tiene `ALL` sobre su base.)
- [ ] Entra a Adminer como **root** y crea un usuario nuevo (p. ej. `crud_app`) con clave y `GRANT SELECT, INSERT, UPDATE, DELETE ON crud_usuarios.* ...`. Nada más.
- [ ] Cambia `DB_USERNAME` y `DB_PASSWORD` en `.env`, recrea (`docker compose up -d`) y verifica que la app sigue funcionando.
- [ ] **Prueba negativa:** como `crud_app`, intenta `DROP TABLE users;` o `ALTER TABLE`. Debe ser rechazado.
- [ ] Reto: automatizar la creación del usuario en `database/` para instalaciones nuevas. Investiga por qué un `.sql` no puede leer variables de entorno y qué alternativa ofrece la imagen de MySQL (scripts `.sh` en `docker-entrypoint-initdb.d`).
- [ ] Separa dos usuarios: uno de **aplicación** (solo DML) y uno de **migraciones** (permisos de esquema) que solo usas manualmente. Razona por qué.
- [x] Complemento (Guía §9.1, errores técnicos en pantalla): usa `APP_ENV` para decidir si se muestran detalles de error (`local`, valor del proyecto en `.env`) o solo un mensaje genérico con registro en log (`production`). Revisa dónde tu código imprime `$e->getMessage()`.
    - **Hecho antes de tiempo (2026-11-19):** `php.ini` es seguro por defecto y `php.local.ini` solo se carga con `APP_ENV=local` mediante `PHP_INI_SCAN_DIR`; las páginas nunca imprimen mensajes de excepción (`describe_failure`). Probado en los dos entornos: en `local` el fatal se ve, en `production` responde 500 con el cuerpo vacío y el detalle queda en el log. Detalles en la tabla de menores (c) de 7.3.

**Preguntas:** ¿qué es el mínimo privilegio? ¿por qué `root` sin contraseña es aceptable en XAMPP local y nunca en producción?

## M8. Separación en capas (hacia MVC)

**Objetivo:** que cada pieza tenga una sola responsabilidad.
**Concepto:** hoy cada `.php` mezcla SQL, validación, lógica y HTML. Separamos:

- **Entidad** `User`: representa un usuario (datos).
- **Repositorio** `UserRepository`: único lugar con SQL.
- **Validación**: reglas reutilizables (adiós a la duplicación de crear/editar).
- **Vistas**: HTML con la mínima lógica.
- **Controladores/páginas**: orquestan.

Hazlo **en pasos pequeños, con el CRUD funcionando después de cada uno**:

- [ ] **8.1 Autoload con Composer.** Composer ya está en el contenedor. `docker compose exec php composer init` y define PSR-4 (`"App\\": "src/app/"`) en `composer.json`; `composer dump-autoload`. Entiende qué es un *namespace* y qué hace `require 'vendor/autoload.php'`. (Recuerda que `/vendor/` está en `.gitignore`.)
- [ ] **8.2 Clase `User`** con propiedades tipadas (`readonly` si quieres) y, opcionalmente, un método estático que cree el objeto desde una fila de la BD.
- [ ] **8.3 Clase `Conexion`** (o un factory) que construya el `PDO` desde variables de entorno,sireemplazando `connection.php`.
- [ ] **8.4 `UserRepository`** recibiendo el `PDO` en el constructor (*inyección de dependencias*; ¿por qué es mejor que `global $pdo`?). Métodos: `all()`, `search(string $q, int $page)`, `count()`, `findById(int $id)`, `create(...)`, `update(...)`, `delete(int $id)`, `emailExists(...)` si te sirve. Migra **una** operación a la vez, empezando por listar; tras cada una prueba el CRUD en el navegador.
- [ ] **8.4b** Traslada `DatabaseConnectionException` (ya sigue PSR-4), `environment()` y `log_exception()` a clases con namespace (`App\Exceptions`, `App\Config`, un logger). Aquí se justifica un manejador global de excepciones (`set_exception_handler`) que reemplace los `try/catch` repetidos en cada página.
- [ ] **8.5 Validador**: clase `UserValidator` con un método que reciba el arreglo de datos y devuelva los errores. Úsalo en create y edit; borra la duplicación.
- [ ] **8.6 Vistas**: mueve el HTML de formularios a archivos de vista compartidos (`views/users/form.php` usado por crear y editar) y define una forma de pasarles datos.
- [ ] **8.7 Páginas delgadas**: `create.php`, `edit.php`, etc. quedan en pocas líneas: leen la petición, llaman al validador/repositorio y renderizan.
- [ ] **Reto final (opcional):** un `public/index.php` *front controller* con un router sencillo (`/users`, `/users/create`, ...). Esto encaja con el `try_files ... /index.php` de Nginx. Reflexiona por qué esa configuración existe en el stack.
- [ ] Cada paso: prueba manual completa del CRUD + commit.
- [ ] Pruebas automáticas (opcional): instala PHPUnit con Composer y prueba el validador (no requiere BD) y el repositorio contra una BD de pruebas.

**Preguntas:** ¿qué ventajas tiene que solo el repositorio conozca SQL? ¿qué cambiaría si mañana cambias MySQL por PostgreSQL? ¿qué es la inyección de dependencias?

---

# Anexos

## A. Comandos Docker del día a día

| Acción | Comando |
|--------|---------|
| Levantar | `docker compose up -d` |
| Reconstruir imagen | `docker compose up -d --build` |
| Estado | `docker compose ps` |
| Logs (todos / uno) | `docker compose logs -f` / `docker compose logs -f php` |
| Shell en PHP | `docker compose exec php sh` |
| Script de consola | `docker compose exec php php scripts/db-check.php` |
| Cliente MySQL como root | `docker compose exec db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" crud_usuarios'` |
| Ejecutar un .sql | `docker compose exec -T db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" crud_usuarios' < archivo.sql` |
| Apagar (conserva datos) | `docker compose down` |
| Apagar y borrar BD | `docker compose down -v` |

## B. Método de estudio por fase

1. Lee los **conceptos** y explícalos con tus palabras (en voz alta o por escrito).
2. Haz los pasos **escribiendo** el código, sin copiar.
3. Prueba cada pequeño paso antes del siguiente. Si falla: lee el error completo → mira `docker compose logs php` → pregúntate qué esperabas y qué pasó.
4. Haz los **experimentos** (romper cosas a propósito es parte del aprendizaje).
5. Responde las **preguntas**; si alguna no sale, relee ese tema antes de seguir.
6. Commit con mensaje claro (`MVP 4: crear usuario con validación`).

## C. Cómo pedirme ayuda

- Dime en qué fase y paso estás.
- Pega tu código y el error exacto (o el comportamiento observado vs esperado).
- Cuéntame qué intentaste. Responderé con pistas y explicaciones antes que con la solución, y revisaré tu código línea a línea si lo pides.
- Al terminar cada fase, pídeme que te haga preguntas de repaso o que revise lo que escribiste.

## D. Glosario rápido

| Término | Significado |
|---------|-------------|
| Contenedor | Proceso aislado con su propio sistema de archivos (un servicio del stack) |
| Volumen | Almacenamiento que sobrevive al contenedor |
| PHP-FPM | Gestor de procesos que ejecuta PHP detrás de Nginx |
| PDO / DSN | Capa de acceso a BD / cadena de conexión |
| Consulta preparada | SQL con marcadores; los valores viajan aparte |
| XSS | Inyección de JS en la página (se evita escapando la salida) |
| CSRF | Petición no deseada hecha con tu sesión (se evita con token) |
| PRG | Post/Redirect/Get: redirigir tras un POST |
| Migración | Script versionado que cambia el esquema de la BD |
| Mínimo privilegio | Dar a cada usuario solo los permisos indispensables |
| Excepción encadenada | Excepción que guarda a la original como `previous` para no perder el detalle técnico |
| SQLSTATE | Código estándar de 5 caracteres de un error SQL (`23000`, `42S02`, `HY000`) |
| 503 / 500 | Servicio no disponible (temporal) / error interno inesperado |

## E. Estructura de carpetas

Estado actual (fin del MVP 2):

```
crud-usuarios-docker/
├── docker-compose.yml, .env(.example), docker/   # stack base
├── database/
│   ├── 01-schema.sql          # CREATE TABLE users
│   └── 02-seed.sql            # SET NAMES utf8mb4 + 3 usuarios
└── src/
    ├── public/                # SOLO lo que el navegador puede pedir
    │   ├── index.php          # listado de usuarios (MVP 3)
    │   └── status.php         # diagnóstico del stack (solo desarrollo)
    ├── includes/              # código PHP compartido (no accesible por URL)
    │   ├── connection.php
    │   ├── helpers.php        # environment(), escape(), log_exception()
    │   ├── DatabaseConnectionException.php
    │   ├── layout/            # header.php, footer.php
    │   └── partials/          # trozos de vista reutilizables (users-list.php)
    └── scripts/               # solo consola
        ├── db-check.php
        └── info.php
```

Objetivo al terminar el CRUD base (MVP 7):

```
src/
├── public/   index.php  create.php  edit.php  delete.php  status.php
├── includes/ connection.php  helpers.php  DatabaseConnectionException.php
│             layout/header.php  layout/footer.php
└── scripts/  db-check.php  info.php
```

En M8 aparece `src/app/` (namespace `App\`, autoload PSR-4 con Composer) con entidades, repositorio, validador y excepciones; `includes/` se va disolviendo.

## F. Decisiones y lecciones registradas

| Tema | Decisión / lección |
|------|--------------------|
| Idioma | Código, tablas y archivos en inglés; textos al usuario en español |
| Tabla `users` | Sin `password` (llega en M5 en otra tabla) |
| Init de MySQL | Scripts de `database/` solo corren con el volumen vacío; `SET NAMES utf8mb4;` en el seed |
| Entorno | Credenciales por variables de entorno (`getenv`), nunca en el código |
| Errores | 3 categorías (conexión 503, consulta 500, configuración 500); el visitante ve un mensaje genérico, el log lleva el detalle |
| Estilos | Bootstrap 6.0.0-alpha.1 (CSS y JS) y Bootstrap Icons 1.13.2 por CDN, los tres con `integrity` (SRI). Es una versión **alfa** y depende de internet: alojarlos en el propio servidor es la alternativa |
| Fechas y zona horaria | Se guardan en UTC (`DATETIME`, servidor con `--default-time-zone=+00:00` y `SET time_zone = '+00:00'` en cada conexión) y se muestran en `date.timezone` con `format_utc_datetime()`. `TIMESTAMP` se descartó por el límite de 2038 |
| Validación de nombres y correos | Permisiva a propósito: un nombre necesita al menos una letra y ningún carácter de control; el correo admite letras no ASCII (`FILTER_FLAG_EMAIL_UNICODE`). Una validación que rechaza datos legítimos es un fallo; el escape al mostrar es lo que protege del XSS, no esta regla |
| Cabeceras de seguridad y CSP | Nginx las pone en todas las respuestas y la CSP no permite scripts ni estilos en línea, por lo que el diálogo de eliminar usa `data-confirm` + `js/confirm-submit.js`. Si se añade otra CDN, script o `<style>` en línea hay que cambiar la política en `docker/nginx/default.conf` |
| `status.php` | Solo con `APP_ENV=local` (404 en cualquier otro entorno): enseña las versiones de PHP y MySQL |
| Tiempo de conexión | `PDO::ATTR_TIMEOUT = 5`: una BD que no responde falla en 5 s con un 503 y no tras 30 s |
| Pruebas | Versionadas: `src/tests/unit.php` (funciones puras) y `tests/smoke.sh` (punta a punta con `curl`, opcionalmente parando la BD). Sin PHPUnit hasta M8 |
| Entornos y errores | `APP_ENV=local` carga `docker/php/php.local.ini` (errores en pantalla); cualquier otro valor deja PHP en modo seguro (errores solo al log, un fallo grave responde 500 con el cuerpo vacío). El mecanismo es `PHP_INI_SCAN_DIR` en `docker-compose.yml` |
| Fallos de la capa de datos | Un solo `catch (RuntimeException)` por página y `describe_failure()` decide 503 (sin conexión), 500 (consulta) o 500 (configuración) y registra con su etiqueta. El `$pdo` global sigue hasta M8 |
| Ediciones simultáneas | Bloqueo optimista con `row_token` (sha256 del contenido de la fila): 409 si otra persona la cambió; sin columna nueva en la tabla |
| Autocomplete | `autocomplete="off"` en el formulario de usuario: son datos de otra persona, y las pistas `given-name`, `email`, `tel` harían que el navegador rellene los del propio usuario |
| Versión de PHP | `php:8.4.27-fpm`, fijada: subirla a mano a propósito (y volver a pasar las pruebas) para recibir parches de seguridad |
| Seguridad de rutas | `includes/` y `scripts/` fuera de `public/` |
| Deuda: CSRF en `create.php` y `edit.php` | **Reducida, no cerrada.** Desde 2026-11-19 los POST de `create.php`, `edit.php` y `delete.php` comprueban el origen (`Origin`/`Referer` contra `APP_ORIGIN`, 403) con `reject_foreign_origin()`, así que un formulario oculto en otro sitio ya no puede dar de alta, editar ni borrar. Falta el **token CSRF** en todos los formularios POST (login, crear, editar, eliminar, logout), verificado con `hash_equals`, que se hace en M6 y necesita la sesión de M5: la comprobación de origen no lo sustituye (no cubre a un cliente que no envía ninguna de las dos cabeceras de forma legítima, y se basa en cabeceras que solo un navegador pone de forma fiable) |
