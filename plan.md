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
| 6 | Eliminar usuario (Delete) | [ ] |
| 7 | Revisión final del CRUD | [ ] |
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

### 4.4 Línea base de respuestas de `create.php` y `edit.php`

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
- [x] **Experimento guiado (en una transacción, sin perder datos):** en Adminer, `START TRANSACTION; UPDATE users SET name = 'X'; SELECT id, name FROM users; ROLLBACK;`. El `UPDATE` sin `WHERE` dejó las 9 filas con `X` y el `ROLLBACK` lo deshizo. Así se entiende por qué la cláusula es "crítica".
    - **Explica con tus palabras qué hizo `ROLLBACK` y por qué lo permiten las tablas InnoDB.** _(a) **Qué se comprobó después:** con `SELECT id, name FROM users;` tras el `ROLLBACK`, las 9 filas tenían otra vez sus nombres originales (0 con `X`); como comprobación extra, una huella MD5 de `id:nombre` de todas las filas dio el mismo valor antes del experimento y después del `ROLLBACK`, y distinta mientras la transacción seguía abierta. Dentro de la transacción el `SELECT` sí mostraba las 9 filas con `X`: cada sesión ve sus propios cambios aunque aún no estén confirmados. (b) **Sin `START TRANSACTION`:** MySQL trabaja con `autocommit = 1` (se comprueba con `SELECT @@autocommit;`), es decir, cada sentencia se confirma sola al terminar. Entonces el `UPDATE ... SET name = 'X'` habría sido definitivo en el acto y el `ROLLBACK` posterior no tendría nada que deshacer: en una tabla temporal de prueba, `UPDATE` + `ROLLBACK` sin transacción dejó `X,X`, mientras que con `START TRANSACTION` volvió a `Ana,Luis`. Importa con datos reales porque la transacción es la red de seguridad para probar sentencias peligrosas, y porque en Adminer hay que ejecutarlo **todo junto** (la transacción vive en una sola conexión; si se ejecuta por partes, el `UPDATE` puede quedar confirmado). Una regla práctica: `START TRANSACTION`, la sentencia, un `SELECT` para ver el efecto y `ROLLBACK`; solo se cambia por `COMMIT` cuando el resultado es el deseado. (c) **Por qué lo permite InnoDB:** por la **atomicidad** (la A de ACID): una transacción se aplica entera o no se aplica. InnoDB guarda en el *undo log* la versión anterior de cada fila que modifica, y `ROLLBACK` reaplica esas versiones; mientras tanto las demás sesiones siguen viendo los valores antiguos (MVCC), por eso el `UPDATE` sin `COMMIT` no es visible fuera. El motor MyISAM no tiene transacciones: en una tabla temporal MyISAM, el mismo `ROLLBACK` dejó `X,X` y no deshizo nada. (d) **Qué otras situaciones cubre, además de un error de la aplicación:** una sentencia que falla a mitad (un `UPDATE` de dos filas que choca con un `UNIQUE` en la segunda dejó **también la primera** como estaba: el resultado fue `1=10,2=20,3=30`); una caída del servidor o un corte de luz antes del `COMMIT` (al reiniciar, InnoDB recupera con su *redo log* y descarta lo no confirmado); una conexión que se corta a medias; una operación de varios pasos que debe ir junta (restar a una cuenta y sumar a otra); y un *deadlock*, en el que MySQL deshace una de las transacciones. Es el mismo mecanismo con el que se reprodujo la carrera de `edit.php`: la sesión que borraba la fila no era visible para las demás hasta su `COMMIT`. Ojo con la red de seguridad: un `UPDATE` sin `WHERE` solo se detiene por casualidad cuando choca con un `UNIQUE` (probado: no cambia nada), y cuando no choca cambia **todas** las filas (probado: `101=10,102=20,103=30`)._
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

- [ ] Crea `public/delete.php` **sin HTML ni header/footer**.
- [ ] Solo si `REQUEST_METHOD === 'POST'`: valida `$_POST['id']` con `FILTER_VALIDATE_INT` y, si es válido, `DELETE FROM users WHERE id = :id` preparado.
- [ ] Siempre (sea POST o no): `header('Location: index.php?msg=deleted'); exit;`
  - Piensa: ¿es correcto mostrar "eliminado" si no se borró nada? ¿cómo lo mejorarías con `$stmt->rowCount()`? Implementa esa mejora si quieres.
- [ ] Verifica que el formulario del listado ya tiene `onsubmit="return confirm(...)"`.

### 6.3 Pruebas

- [ ] Clic en Eliminar → aparece el cuadro de confirmación; "Cancelar" no borra.
- [ ] "Aceptar" borra y muestra el mensaje.
- [ ] Abrir `http://localhost:8080/delete.php?id=1` en la barra de direcciones **no** borra (es GET).
- [ ] Probar con un `id` inventado no produce error.
- [ ] Commit.

### Preguntas de comprobación

1. ¿Por qué un borrado con `<a href>` es una mala idea?
2. ¿La confirmación de `confirm()` protege de verdad? (No: es comodidad. ¿Qué lo impediría realmente? → M6 CSRF).
3. ¿Qué hace el usuario eliminado a nivel de datos? ¿Es recuperable? (→ M4).

---

## MVP 7. Revisión final del CRUD base

**Objetivo:** consolidar, comprobar todo y comparar con la guía.

### 7.1 Recorrido completo

- [ ] Crear → listar → editar → eliminar, sin errores PHP en pantalla.
- [ ] `docker compose logs php` no muestra errores ni warnings.
- [ ] Empezar desde cero: `docker compose down -v && docker compose up -d --build` → la BD se recrea con los 3 usuarios y la app funciona.
- [ ] Un colaborador (tú mismo en otra carpeta) puede clonar el repo, copiar `.env.example` y levantar todo siguiendo solo el README.

### 7.2 Repaso de errores comunes (Guía §10, adaptado a Docker)

Provoca y resuelve:

- [ ] Puerto 8080 ocupado (cambia `APP_PORT`).
- [ ] `Unknown database` / `Access denied` / conexión rechazada (ya los viste en el MVP 2).
- [ ] CSS que no carga (ruta equivocada).
- [ ] Tildes mal (quita `charset=utf8mb4` del DSN a ver qué pasa y devuélvelo).
- [ ] "Cannot modify header information".

### 7.3 Comparación con la solución original

- [ ] Ahora sí, descomprime `crud_usuarios.zip` en una carpeta temporal y **compara tu código con el original**: ¿qué hiciste diferente? ¿qué hace mejor cada versión?
- [ ] Pídeme una revisión de tu código (puedes pegarlo o dejarlo en el proyecto): señalamos mejoras sin reescribirlo por ti.
- [ ] Lee otra vez la tabla de seguridad (Guía §9) y señala **en tu código** dónde se aplica cada medida.
- [ ] Anota las 5 limitaciones de §9.1: son las mejoras que siguen.
- [ ] Commit y etiqueta (`git tag v1-crud-base`).

### Preguntas de comprobación

Dibuja de memoria el recorrido de una petición "crear usuario" (navegador → nginx → php-fpm → MySQL → respuesta → redirección) y explica en qué punto actúa cada medida de seguridad.

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
- [ ] Complemento (Guía §9.1, errores técnicos en pantalla): usa `APP_ENV` para decidir si se muestran detalles de error (`local`, valor del proyecto en `.env`) o solo un mensaje genérico con registro en log (`production`). Revisa dónde tu código imprime `$e->getMessage()`.

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
- [ ] **8.3 Clase `Conexion`** (o un factory) que construya el `PDO` desde variables de entorno, reemplazando `connection.php`.
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
| Estilos | Bootstrap 5 por CDN (considerar SRI) |
| Seguridad de rutas | `includes/` y `scripts/` fuera de `public/` |
