# Plan de aprendizaje: CRUD de usuarios en PHP + MySQL con Docker

Cómo usar este documento:

- Las fases se hacen **en orden**. Cada una termina en algo que funciona y se prueba en el navegador.
- Marca `- [x]` **solo** cuando la prueba de la fase pase.
- Cada paso explica **qué** hacer y **por qué**. El **cómo** (el código) lo escribes tú.
- Si un paso no se entiende, no se sigue: se pregunta. Entender > avanzar.
- Al final de cada fase hay preguntas de comprobación. Si puedes responderlas con tus palabras, la fase está aprendida.
- Referencias `(Guía §X)` apuntan a la sección del PDF.

### Convención de nombres (decisión: código en inglés)

El plan y la guía usan español; **tu código va en inglés**. Equivalencias:

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
| 1 | Tabla `usuarios` con datos de prueba | [ ] |
| 2 | PHP habla con MySQL | [ ] |
| 3 | Listado de usuarios (Read) | [ ] |
| 4 | Crear usuario (Create) | [ ] |
| 5 | Editar usuario (Update) | [ ] |
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

- [x] Abre http://localhost:8080 → debe mostrar versión de PHP, extensiones con ✔ y "Conectado a MySQL".
- [x] Abre http://localhost:8081 (Adminer). Inicia sesión: sistema **MySQL**, servidor `db`, usuario y clave de tu `.env`, base `crud_usuarios`. Debe entrar y mostrar "No tables".
- [x] Ejecuta `docker compose exec php php db-check.php` → lista las bases de datos.
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

**Objetivo:** tabla `usuarios` con 3 usuarios de prueba, cargada automáticamente por Docker.
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

### 1.4 Probar el SQL a mano primero

- [ ] En Adminer → **SQL command**, escribe tú el `CREATE TABLE usuarios (...)` con `ENGINE=InnoDB` y charset `utf8mb4`. Ejecútalo.
- [ ] Escribe un `INSERT` con un usuario (sin `telefono`). Consulta con `SELECT * FROM usuarios;`.
- [ ] Prueba el `UNIQUE`: inserta otra vez el mismo correo y **lee el error** (código 1062 / SQLSTATE 23000). Lo volverás a ver en PHP.
- [ ] Prueba qué pasa al insertar sin `nombre` (¿error o vacío?). Relaciónalo con `NOT NULL`.
- [ ] Borra la tabla (`DROP TABLE usuarios;`): vamos a hacerlo de forma automática.

### 1.5 Dejarlo como script versionado

- [ ] Crea la carpeta `database/` en la raíz del proyecto.
- [ ] Crea `database/01-schema.sql` con tu `CREATE TABLE IF NOT EXISTS ...` (sin `CREATE DATABASE` ni `USE`).
- [ ] Crea `database/02-seed.sql` con 3 `INSERT` (uno con teléfono `NULL`, y al menos un nombre con tilde o ñ).
- [ ] Piensa por qué se numeran los archivos (pista: orden alfabético, y el seed necesita que la tabla ya exista).

### 1.6 Que Docker lo ejecute solo

La imagen de MySQL ejecuta todo `.sql` que haya en `/docker-entrypoint-initdb.d/` **únicamente la primera vez** (cuando el volumen está vacío).

- [ ] En `docker-compose.yml`, servicio `db`, añade un volumen que monte `./database` en `/docker-entrypoint-initdb.d` en modo solo lectura (`:ro`). Fíjate en cómo están escritos los otros volúmenes.
- [ ] Como el volumen `db_data` ya existe, el script **no se ejecutará** solo. Reinicia la BD desde cero: `docker compose down -v` y luego `docker compose up -d`.
- [ ] Mira `docker compose logs db`: busca las líneas donde ejecuta tus archivos.

### 1.7 Probar

- [ ] En Adminer aparece la tabla `usuarios` con 3 filas.
- [ ] Las tildes y la ñ se ven bien (si no, revisa charset).
- [ ] El tercer usuario muestra `NULL` en teléfono.
- [ ] Commit de la fase.

### Preguntas de comprobación

1. ¿Por qué el correo debe tener `UNIQUE` en la BD y no basta con validarlo en PHP?
2. ¿Por qué el script de init no se vuelve a ejecutar al hacer `docker compose up`?
3. ¿Qué diferencia hay entre `down` y `down -v`, y cuándo usarías cada uno?
4. ¿Qué ventaja tiene guardar el SQL en archivos y no solo ejecutarlo en Adminer?

---

## MVP 2. La conexión PDO

**Objetivo:** que PHP se conecte a MySQL y lea los usuarios (todavía sin diseño).
**Resultado:** una página de prueba que imprime los usuarios.
**Guía:** §6.

### 2.1 Conceptos

- **PDO**: capa estándar de PHP para hablar con bases de datos. Su gran valor: **consultas preparadas**.
- **DSN**: texto que dice a qué conectarse: `mysql:host=...;port=...;dbname=...;charset=utf8mb4`.
- **Opciones** que usaremos (explícalas con tus palabras al terminar):
  - `ATTR_ERRMODE => ERRMODE_EXCEPTION`
  - `ATTR_DEFAULT_FETCH_MODE => FETCH_ASSOC`
  - `ATTR_EMULATE_PREPARES => false`
- **`try/catch`**: captura el error en vez de dejar que PHP muestre un fallo técnico.
- **Variables de entorno**: en vez de escribir claves en el código (como en la guía), se leen con `getenv('DB_HOST')`. Mira `src/public/index.php` del stack: ya lo hace así.

### 2.2 Reorganizar carpetas

- [ ] Crea `src/includes/`. Esta carpeta está **fuera** de `src/public/` (la raíz web de Nginx). Pregunta de reflexión: ¿qué pasaría si `conexion.php` estuviera dentro de `public/` y PHP dejara de funcionar un día?

### 2.3 Escribir `src/includes/conexion.php`

Requisitos (el código lo escribes tú):

- [ ] Lee `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` con `getenv()`.
- [ ] Construye el DSN con `sprintf` o interpolación (¿qué diferencias ves con la Guía §3.2?).
- [ ] Define el arreglo de opciones PDO con las tres de arriba.
- [ ] Crea `$pdo` dentro de `try`. En el `catch (PDOException $e)`, **no muestres** el mensaje técnico completo: regístralo con `error_log()` y muestra un mensaje genérico (así no filtras detalles). Termina con `exit`/`die`.
- [ ] Sin etiqueta de cierre `?>` al final (Guía §6.4: ¿por qué?).
- [ ] Opcional pero recomendado: `declare(strict_types=1);` al inicio. Investiga qué cambia.

### 2.4 Página de prueba temporal

- [ ] Crea `src/public/prueba.php` que haga `require __DIR__ . '/../includes/conexion.php';`. Investiga: ¿qué es `__DIR__` y por qué se usa en vez de una ruta relativa simple?
- [ ] Ejecuta `SELECT id, nombre, apellido, correo, telefono, creado_en FROM usuarios ORDER BY id DESC` con `$pdo->query(...)`.
- [ ] Usa `fetchAll()` y muestra el resultado con `var_dump` dentro de `<pre>`. Lee la estructura: es un arreglo de arreglos asociativos.
- [ ] Recorre el arreglo con `foreach` e imprime solo nombre y correo.

### 2.5 Provocar errores a propósito (aprendes más rompiéndolo)

Haz cada uno, mira el mensaje y **déjalo como estaba**:

- [ ] Cambia `DB_PASSWORD` en `.env`, `docker compose up -d` y recarga: ¿qué error aparece? (Guía §10: 1045)
- [ ] Pon un nombre de base inexistente: (Guía §10: 1049)
- [ ] `docker compose stop db` y recarga: ¿qué error? (Guía §10: 2002) Luego `docker compose start db`.
- [ ] Escribe mal el nombre de la tabla en el SELECT: ¿llega al `catch` de conexión o es otro error? ¿por qué?

### 2.6 Probar

- [ ] http://localhost:8080/prueba.php imprime los 3 usuarios.
- [ ] Con la BD detenida muestra el mensaje genérico (no la clave ni el host).
- [ ] Commit.

### Preguntas de comprobación

1. ¿Qué es un DSN y qué contiene el tuyo?
2. ¿Qué haría PDO sin `ERRMODE_EXCEPTION`?
3. ¿Por qué `FETCH_ASSOC` y no el modo por defecto?
4. ¿Qué ventaja tiene leer las credenciales de variables de entorno?
5. ¿Qué diferencia hay entre `require` e `include`?

---

## MVP 3. Listar usuarios (Read)

**Objetivo:** pantalla principal con la tabla de usuarios, estilos y mensajes de confirmación.
**Resultado:** `http://localhost:8080/` muestra el listado.
**Guía:** §4.1, §7.1, §7.2, §7.7.

Se hace en sub-pasos pequeños; prueba en el navegador después de cada uno.

### 3.1 Cabecera y pie compartidos

Concepto: todas las páginas repiten el mismo inicio/fin de HTML. Se extrae a `header.php` y `footer.php` para no copiarlo en cada archivo.

- [ ] `src/includes/header.php`: `<!DOCTYPE html>`, `<html lang="es">`, `<head>` con `charset UTF-8`, `viewport`, `<title>`, enlace al CSS; abre `<body>`, un encabezado con el título del sitio y abre `<main>`.
- [ ] `src/includes/footer.php`: cierra `</main>`, `</body>`, `</html>`.
- [ ] Ruta del CSS: usa `/css/style.css` (con barra inicial) para que funcione desde cualquier página. ¿Qué pasaría con `css/style.css` si luego una página estuviera en una subcarpeta?
- [ ] En `public/index.php`: `require` de header, un texto de prueba, `require` de footer. Recarga y revisa el HTML generado con "Ver código fuente" (`Ctrl+U`): ¿ves PHP? (Guía §4.1: el navegador nunca ve el PHP).

> Regla que vas a necesitar en Crear/Editar: **la lógica va antes del `require` del header**, porque `header()` (redirecciones) no funciona si ya se imprimió HTML.

### 3.2 Tabla con datos de verdad

- [ ] En `index.php`, **arriba**: `require` de `conexion.php` y la consulta con `$pdo->query(...)->fetchAll()` a `$usuarios`. Orden: `ORDER BY id DESC` (más nuevos primero).
- [ ] Debajo del header: título "Listado de usuarios" con el conteo `count($usuarios)`.
- [ ] Tabla con columnas ID, Nombre (nombre + apellido), Correo, Teléfono, Registro, Acciones.
- [ ] Usa `foreach ($usuarios as $u): ... endforeach;` (sintaxis alternativa para mezclar con HTML) y `<?= ... ?>` para imprimir.
- [ ] Teléfono `NULL`: muestra `—` usando el operador `??`. Investiga qué hace `??`.

### 3.3 Escapar la salida (seguridad: XSS)

- [ ] Envuelve **cada** valor impreso en `htmlspecialchars()`.
- [ ] **Experimento:** primero comprueba el ataque. Inserta desde Adminer un usuario con nombre `<script>alert(1)</script>`. Con `htmlspecialchars` quitado en esa celda, recarga: se ejecuta el script. Vuelve a ponerlo: se ve como texto. Mira el código fuente para ver las entidades (`&lt;`).
- [ ] Borra ese usuario de prueba (hazlo con SQL en Adminer).
- [ ] Mejora de calidad: para no repetir `htmlspecialchars(...)` largo, investiga cómo crear una función corta (`e()`) y qué flags recomienda PHP (`ENT_QUOTES`, `UTF-8`). El `index.php` del stack tiene una de ejemplo para leer.

### 3.4 Estado vacío

- [ ] Si `$usuarios` está vacío, muestra "No hay usuarios registrados." en vez de la tabla (usa `empty()` o `count()`).
- [ ] Pruébalo vaciando la tabla en Adminer (`TRUNCATE usuarios` o DELETE) y vuelve a cargar el seed con `docker compose exec -T db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" crud_usuarios' < database/02-seed.sql`.

### 3.5 Mensajes de confirmación por URL

Concepto: las demás páginas redirigirán a `index.php?msg=creado`. El listado mostrará un aviso.

- [ ] Define un arreglo asociativo `$mensajes` con las claves permitidas (`creado`, `actualizado`, `eliminado`) y su texto.
- [ ] Lee `$_GET['msg'] ?? ''`.
- [ ] Muestra el mensaje **solo si la clave existe** en `$mensajes`. Pruébalo: `/?msg=creado` muestra el aviso; `/?msg=<b>hola</b>` no muestra nada. Pregúntate por qué esa lista blanca es más segura que imprimir `$_GET['msg']` directo.

### 3.6 Estilos (`public/css/style.css`)

El CSS no es el foco del curso. Clases que usa la guía (§7.7): `.contenedor`, `.btn`, `.btn-claro`, `.btn-sm`, `.mensaje`, `.errores`, `.formulario`, `.campo`, `.vacio`.

- [ ] Escribe un CSS mínimo propio (ancho máximo centrado, tabla con bordes, botones). Estilo de la guía: blanco y negro, Arial. Si prefieres no escribirlo, pídemelo y lo hacemos juntos.
- [ ] Verifica que Nginx lo sirve: abre http://localhost:8080/css/style.css.

### 3.7 Botones (aún sin función)

- [ ] Enlace "Nuevo usuario" → `crear.php`.
- [ ] En cada fila: enlace "Editar" → `editar.php?id=<id>`.
- [ ] En cada fila: **formulario** `method="post" action="eliminar.php"` con `<input type="hidden" name="id">` y botón "Eliminar". Añade `onsubmit="return confirm('¿Eliminar este usuario?');"`.
- [ ] Pregunta: ¿por qué Eliminar es un formulario POST y no un enlace como Editar? (se explica en MVP 6, ve pensándolo).

### 3.8 Probar

- [ ] Muestra los 3 usuarios con el más nuevo primero.
- [ ] El teléfono NULL se ve como `—`.
- [ ] `/?msg=creado` muestra el mensaje; un valor raro no muestra nada.
- [ ] `<script>` en un nombre NO se ejecuta.
- [ ] Tabla vacía muestra "No hay usuarios registrados."
- [ ] Hay estilos aplicados.
- [ ] Puedes borrar `prueba.php`.
- [ ] Commit.

### Preguntas de comprobación

1. ¿Qué es XSS y qué función lo evita? ¿Dónde se aplica: al guardar o al mostrar?
2. ¿Por qué `query()` es seguro aquí sin consulta preparada? ¿Cuándo dejaría de serlo?
3. ¿Qué hace `<?= ?>`?
4. ¿Por qué `$mensajes[$msg]` en vez de imprimir `$msg`?

---

## MVP 4. Crear usuario (Create)

**Objetivo:** formulario de alta con validación en servidor y guardado en BD.
**Resultado:** puedes crear usuarios desde el navegador y verlos en el listado.
**Guía:** §7.3, §7.4, §8.1.

Se construye en capas; no escribas todo de golpe.

### 4.1 Conceptos

- **GET** pide una página; **POST** envía datos. Un mismo archivo (`crear.php`) hace las dos cosas distinguiendo con `$_SERVER['REQUEST_METHOD']`.
- **Validación en servidor**: los atributos HTML (`required`, `type="email"`) son comodidad; cualquiera los salta (herramientas del navegador, `curl`). Solo cuenta lo que valida PHP.
- **Consulta preparada**: se envía primero la **estructura** del SQL con marcadores (`:nombre`) y luego los **valores** por separado; MySQL nunca interpreta los valores como código. Esto elimina la inyección SQL.
- **Post/Redirect/Get (PRG)**: tras guardar, se redirige con `header('Location: ...')` + `exit`, para que recargar no reenvíe el formulario.

### 4.2 Paso a paso

**4.2.1 Solo el formulario (GET)**

- [ ] Crea `public/crear.php`. Lógica arriba (aún vacía), luego `require` del header, el formulario y `require` del footer.
- [ ] Formulario `method="post" action="crear.php"` con campos `nombre`, `apellido`, `correo` (`type="email"`), `telefono`; cada uno con `<label for>` enlazado al `id` del input, `maxlength` acorde a la tabla y `required` donde corresponda.
- [ ] Botones Guardar y Cancelar (enlace a `index.php`).
- [ ] Prueba: el botón "Nuevo usuario" del listado abre el formulario.

**4.2.2 Recibir el POST y verlo**

- [ ] Arriba: `if ($_SERVER['REQUEST_METHOD'] === 'POST') { ... }`. Dentro, haz un `var_dump($_POST); exit;` temporal.
- [ ] Envía el formulario y observa `$_POST`. Fíjate en que las claves son los atributos `name` de los inputs.
- [ ] Quita el `var_dump`.

**4.2.3 Limpiar los datos**

- [ ] Prepara un arreglo `$datos` con las 4 claves vacías (también sirve para rellenar el formulario la primera vez).
- [ ] En el POST, recorre `$datos` con `foreach` y reemplaza cada valor por `trim($_POST[$campo] ?? '')`. Entiende qué hace `trim` y por qué el `?? ''` evita una advertencia.

**4.2.4 Validar**

- [ ] Crea `$errores = []` al inicio.
- [ ] Reglas (Guía §7.3): nombre y apellido no vacíos; correo con `filter_var($x, FILTER_VALIDATE_EMAIL)`; teléfono opcional pero, si viene, que cumpla la expresión regular de 7 a 20 caracteres entre dígitos, `+`, espacios y `-` (investiga `preg_match` y desglosa la regex carácter por carácter).
- [ ] Cada fallo agrega un mensaje a `$errores`.
- [ ] En el formulario, si `$errores` no está vacío, muéstralos en una lista dentro de un recuadro `.errores` (con `htmlspecialchars`).
- [ ] Cada input debe conservar lo escrito: `value="<?= ... $datos['nombre'] ... ?>"` (¿por qué escapado?).
- [ ] Prueba: manda el formulario con campos vacíos, con correo malo y con teléfono `abc`. Los valores válidos se conservan.
- [ ] **Experimento:** en el navegador (F12) quita el atributo `required` del input y envía vacío: la validación del servidor lo detiene. Ese es el motivo de validar en PHP.

**4.2.5 Insertar con consulta preparada**

- [ ] Si `$errores` está vacío: `$pdo->prepare('INSERT INTO usuarios (...) VALUES (:nombre, ...)')` y `execute([...])` con un arreglo de pares `:marcador => valor`.
- [ ] Teléfono vacío debe guardarse como `null`, no como `''`. (Pista: operador ternario.)
- [ ] Prueba: crea un usuario válido y mira en Adminer que se guardó. Crea otro sin teléfono y verifica que sea `NULL`.
- [ ] **Experimento de seguridad:** crea un usuario con nombre `'; DROP TABLE usuarios; --`. Comprueba que se guarda como texto y la tabla sigue viva. Explica por qué (los valores viajan separados de la estructura).

**4.2.6 Redirigir (PRG)**

- [ ] Tras `execute`, `header('Location: index.php?msg=creado'); exit;`
- [ ] Prueba: tras guardar se ve el listado con el mensaje verde. Recarga (F5): **no** se crea un duplicado.
- [ ] **Experimento:** quita temporalmente el `exit` y mira que el resto del script sigue ejecutándose. Entiende por qué es indispensable. Luego restáuralo.
- [ ] Provoca el error clásico: pon texto antes de `<?php` (o un `echo` antes de `header()`) y lee "headers already sent". (Guía §10.)

**4.2.7 Correo duplicado**

- [ ] Envuelve el insert en `try/catch (PDOException $e)`.
- [ ] Si `$e->getCode() === '23000'` agrega "Ya existe un usuario con ese correo."; si es otro error, mensaje genérico y `error_log($e->getMessage())`. (La guía muestra el detalle técnico; es útil en desarrollo, malo en producción: decide tú cómo manejarlo.)
- [ ] Prueba: crea dos veces el mismo correo.
- [ ] Piensa: ¿por qué es mejor capturar el 23000 que hacer primero un `SELECT` para ver si existe el correo? (pista: dos usuarios a la vez.)

### 4.3 Probar (flujo completo, Guía §8.1)

- [ ] Nuevo usuario válido → aparece primero en el listado con mensaje.
- [ ] Campos inválidos → errores y datos conservados.
- [ ] Correo repetido → mensaje claro.
- [ ] F5 después de guardar no duplica.
- [ ] Nombres con tildes/ñ se guardan y se ven bien.
- [ ] Commit.

### Preguntas de comprobación

1. ¿Qué diferencia hay entre `prepare` y `execute`? ¿Qué ve MySQL en cada paso?
2. ¿Qué pasa si falta `exit` tras `header('Location')`?
3. ¿Por qué se valida en el servidor si ya hay `required` en el HTML?
4. ¿Por qué el teléfono vacío se guarda como `NULL`?
5. ¿Por qué la lógica va antes del `require` del header?

---

## MVP 5. Editar usuario (Update)

**Objetivo:** modificar un usuario existente.
**Resultado:** botón "Editar" abre el formulario relleno y guarda cambios.
**Guía:** §7.5, §8.2.

### 5.1 Conceptos

`editar.php` es `crear.php` con **tres diferencias**: (1) identificar el registro, (2) cargarlo desde la BD, (3) usar `UPDATE ... WHERE id = :id`.

### 5.2 Paso a paso

- [ ] Duplica mentalmente el flujo de `crear.php` (no copies a ciegas: escríbelo, reutilizando lo que entiendes).
- [ ] **Identificar:** el `id` llega por la URL (GET, al abrir) o por un campo oculto (POST, al enviar). Léelo con `$_GET['id'] ?? $_POST['id'] ?? null` y valídalo con `filter_var(..., FILTER_VALIDATE_INT)`. Si no es válido: redirige al listado y `exit`.
- [ ] **Cargar:** `SELECT` preparado `WHERE id = :id` + `fetch()`. Si devuelve `false` (no existe), redirige al listado.
- [ ] **Rellenar:** la primera vez (GET), `$datos` se llena con lo cargado de la BD. Si hay POST, se llena con lo enviado.
- [ ] Formulario con `<input type="hidden" name="id" value="...">` y el mismo conjunto de campos, mismas validaciones y mensajes de error. Botón "Actualizar".
- [ ] **UPDATE:** `UPDATE usuarios SET nombre = :nombre, ... WHERE id = :id`, con `execute` (ahora son 5 parámetros).
- [ ] Correo duplicado: el mismo `catch` 23000 con mensaje "otro usuario". Piensa por qué guardar sin cambiar el correo **no** da error (el registro no choca consigo mismo).
- [ ] Redirige a `index.php?msg=actualizado` con `exit`.
- [ ] Reflexión sobre duplicación de código: `crear.php` y `editar.php` repiten validación. Anótalo; lo mejoraremos en M8.

### 5.3 Pruebas

- [ ] Editar abre el formulario con datos actuales.
- [ ] Cambiar solo el teléfono guarda bien (no da error de correo duplicado).
- [ ] Cambiar el correo a uno de otro usuario da el mensaje de duplicado.
- [ ] Vaciar el teléfono lo deja en `NULL`.
- [ ] `/editar.php` (sin id), `/editar.php?id=abc` y `/editar.php?id=9999` redirigen al listado sin error.
- [ ] **Experimento guiado:** en una copia temporal, quita el `WHERE id = :id` y edita un usuario. Observa cómo **todos** quedan iguales. Recupera datos con `docker compose down -v && docker compose up -d`. (Así se entiende por qué la cláusula es "crítica".)
- [ ] Commit.

### Preguntas de comprobación

1. ¿Cómo viaja el `id` al abrir el formulario y cómo al enviarlo?
2. ¿Por qué se valida el `id` si viene de nuestro propio enlace?
3. ¿Qué pasa sin `WHERE` en un `UPDATE`?
4. ¿Qué cambió respecto a `crear.php`?

---

## MVP 6. Eliminar usuario (Delete)

**Objetivo:** borrar un usuario de forma segura.
**Resultado:** el botón "Eliminar" pide confirmación y borra.
**Guía:** §7.6, §8.3.

### 6.1 Conceptos

- Las peticiones **GET** deben ser **solo lectura**: buscadores, extensiones y precargas de navegador las ejecutan sin permiso. Un enlace `eliminar.php?id=3` podría borrar datos sin que nadie lo decida.
- Las acciones que modifican datos usan **POST**.
- `eliminar.php` no muestra pantalla: ejecuta y redirige.

### 6.2 Paso a paso

- [ ] Crea `public/eliminar.php` **sin HTML ni header/footer**.
- [ ] Solo si `REQUEST_METHOD === 'POST'`: valida `$_POST['id']` con `FILTER_VALIDATE_INT` y, si es válido, `DELETE FROM usuarios WHERE id = :id` preparado.
- [ ] Siempre (sea POST o no): `header('Location: index.php?msg=eliminado'); exit;`
  - Piensa: ¿es correcto mostrar "eliminado" si no se borró nada? ¿cómo lo mejorarías con `$stmt->rowCount()`? Implementa esa mejora si quieres.
- [ ] Verifica que el formulario del listado ya tiene `onsubmit="return confirm(...)"`.

### 6.3 Pruebas

- [ ] Clic en Eliminar → aparece el cuadro de confirmación; "Cancelar" no borra.
- [ ] "Aceptar" borra y muestra el mensaje.
- [ ] Abrir `http://localhost:8080/eliminar.php?id=1` en la barra de direcciones **no** borra (es GET).
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
- [ ] Si hay texto, usa `prepare` con `WHERE nombre LIKE :q1 OR apellido LIKE :q2 OR correo LIKE :q3`. Los comodines `%` van **dentro del valor** que pasas (`'%' . $q . '%'`), no pegados al SQL.
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

- [ ] Decide los tipos: `documento` (¿texto o número? ¿único? ¿nulo?), `fecha_nacimiento` (`DATE`), `rol` (¿`ENUM` o tabla aparte? compara ventajas).
- [ ] Crea `database/migrations/001-mas-campos.sql` con `ALTER TABLE usuarios ADD COLUMN ...`. Piensa qué valor tendrán los usuarios ya existentes (`NULL` o `DEFAULT`).
- [ ] Aplícala sin borrar datos: `docker compose exec -T db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" crud_usuarios' < database/migrations/001-mas-campos.sql`
- [ ] Actualiza `01-schema.sql` para que una instalación nueva ya tenga los campos (¿por qué hay que hacer ambas cosas?).
- [ ] Actualiza `crear.php`, `editar.php` e `index.php`: inputs (`type="date"`, `<select>` para rol), `$datos`, validación, `INSERT`, `UPDATE`, listado.
- [ ] Validaciones nuevas: formato del documento, fecha válida (`DateTime::createFromFormat` + que no sea futura), rol dentro de una **lista blanca** (¿por qué no confiar en el valor del `<select>`?).
- [ ] Pruebas: usuarios antiguos siguen funcionando, campos opcionales vacíos se guardan como `NULL`, manipular el `<select>` con F12 a un rol inexistente da error, documento repetido (si es único).

**Preguntas:** ¿qué es una migración y por qué se versiona? ¿qué riesgo tiene `ALTER TABLE` en una tabla grande?

## M4. Borrado lógico

**Objetivo:** que "eliminar" no destruya el dato.
**Concepto:** `activo TINYINT(1) NOT NULL DEFAULT 1`. Eliminar = `UPDATE ... SET activo = 0`.

- [ ] Migración `002-activo.sql` (y actualizar el schema).
- [ ] `eliminar.php`: de `DELETE` a `UPDATE`.
- [ ] `index.php` (y búsqueda/paginación/conteos): filtrar `WHERE activo = 1`.
- [ ] `editar.php`: ¿debería poder abrir un usuario inactivo? Decide y justifica.
- [ ] **Problema a pensar:** el correo es `UNIQUE`. Si "eliminas" a Laura y vuelves a crear otro usuario con su correo, ¿qué ocurre? Evalúa alternativas (reactivar, índice compuesto, mensaje específico) e implementa la que elijas.
- [ ] Extra: vista "Inactivos" con botón "Restaurar" (también por POST).
- [ ] Pruebas: eliminar oculta pero la fila sigue en Adminer; restaurar la devuelve; los conteos cuadran.

**Preguntas:** ventajas y desventajas del borrado lógico (auditoría, consultas más complejas, privacidad/derecho al olvido).

## M5. Inicio de sesión

**Objetivo:** que solo usuarios autenticados usen el CRUD.
**Concepto:** el servidor guarda en una **sesión** (`$_SESSION`) quién eres; el navegador solo lleva una cookie con el id de sesión. Las contraseñas **nunca** se guardan en claro: se guarda un hash.

- [ ] Tabla nueva para los administradores del sistema (no mezcles con los usuarios del CRUD): `id`, `correo` único, `password_hash` (`VARCHAR(255)`), `creado_en`. Migración `003-administradores.sql`.
- [ ] Investiga `password_hash($clave, PASSWORD_DEFAULT)` y `password_verify`. Pregunta: ¿por qué no basta con `md5` o `sha256`? ¿qué es un *salt*?
- [ ] Script CLI `src/scripts/crear-admin.php` que reciba correo y clave por argumentos (`$argv`) y cree el admin. Ejecútalo con `docker compose exec php php scripts/crear-admin.php ...`. (¿Por qué un script y no un formulario de registro público?)
- [ ] `public/login.php`: formulario (GET) y proceso (POST): busca por correo con consulta preparada, `password_verify`, mensaje **genérico** si falla ("Credenciales inválidas", sin decir cuál falló).
- [ ] Al autenticarse: `session_regenerate_id(true)` y guardar el id del admin en `$_SESSION`. Investiga qué ataque previene (*session fixation*).
- [ ] `includes/auth.php` con `session_start()` y una comprobación que redirige a `login.php` si no hay sesión. Inclúyelo (arriba, antes del HTML) en `index`, `crear`, `editar`, `eliminar`.
- [ ] `public/logout.php`: debe ser **POST**; destruye la sesión y la cookie.
- [ ] Parámetros de cookie de sesión: `httponly`, `samesite=Lax` (`session_set_cookie_params`); `secure` cuando haya HTTPS. Entiende qué hace cada uno.
- [ ] Mostrar quién está conectado en el header y botón "Cerrar sesión".
- [ ] Pruebas: entrar a `/` sin sesión redirige; credenciales malas; credenciales buenas; `eliminar.php` por POST sin sesión no debe borrar (¡pruébalo con `curl`!); logout invalida la sesión; botón "atrás" tras salir.
- [ ] Reflexión: ¿cómo limitarías los intentos fallidos (fuerza bruta)? (Solo diseño, no hace falta implementarlo.)

**Preguntas:** ¿dónde se guarda el estado de sesión? ¿qué guarda la cookie? ¿por qué un mensaje genérico en el login?

## M6. Tokens CSRF

**Objetivo:** impedir que otro sitio envíe formularios en nombre de un usuario autenticado.
**Concepto:** con la sesión del M5, tu navegador envía la cookie automáticamente aunque la petición se origine en *otro* sitio. Solución: un **token secreto por sesión** dentro de cada formulario que el servidor verifica.

- [ ] **Demuestra el ataque primero:** crea un HTML aparte (fuera de `src/`, o en otro puerto con `php -S`) con un formulario oculto que apunte a `http://localhost:8080/eliminar.php` y se auto-envíe. Estando logueado, ábrelo: borra un usuario. (Con `samesite=Lax` el navegador quizá lo bloquee; razona qué protege cada capa y por qué aun así se usa token.)
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
- [ ] **Prueba negativa:** como `crud_app`, intenta `DROP TABLE usuarios;` o `ALTER TABLE`. Debe ser rechazado.
- [ ] Reto: automatizar la creación del usuario en `database/` para instalaciones nuevas. Investiga por qué un `.sql` no puede leer variables de entorno y qué alternativa ofrece la imagen de MySQL (scripts `.sh` en `docker-entrypoint-initdb.d`).
- [ ] Separa dos usuarios: uno de **aplicación** (solo DML) y uno de **migraciones** (permisos de esquema) que solo usas manualmente. Razona por qué.
- [ ] Complemento (Guía §9.1, errores técnicos en pantalla): usa `APP_ENV` para decidir si se muestran detalles de error (`local`) o solo un mensaje genérico con registro en log (`production`). Revisa dónde tu código imprime `$e->getMessage()`.

**Preguntas:** ¿qué es el mínimo privilegio? ¿por qué `root` sin contraseña es aceptable en XAMPP local y nunca en producción?

## M8. Separación en capas (hacia MVC)

**Objetivo:** que cada pieza tenga una sola responsabilidad.
**Concepto:** hoy cada `.php` mezcla SQL, validación, lógica y HTML. Separamos:

- **Entidad** `Usuario`: representa un usuario (datos).
- **Repositorio** `UsuarioRepository`: único lugar con SQL.
- **Validación**: reglas reutilizables (adiós a la duplicación de crear/editar).
- **Vistas**: HTML con la mínima lógica.
- **Controladores/páginas**: orquestan.

Hazlo **en pasos pequeños, con el CRUD funcionando después de cada uno**:

- [ ] **8.1 Autoload con Composer.** Composer ya está en el contenedor. `docker compose exec php composer init` y define PSR-4 (`"App\\": "src/app/"`) en `composer.json`; `composer dump-autoload`. Entiende qué es un *namespace* y qué hace `require 'vendor/autoload.php'`. (Recuerda que `/vendor/` está en `.gitignore`.)
- [ ] **8.2 Clase `Usuario`** con propiedades tipadas (`readonly` si quieres) y, opcionalmente, un método estático que cree el objeto desde una fila de la BD.
- [ ] **8.3 Clase `Conexion`** (o un factory) que construya el `PDO` desde variables de entorno, reemplazando `conexion.php`.
- [ ] **8.4 `UsuarioRepository`** recibiendo el `PDO` en el constructor (*inyección de dependencias*; ¿por qué es mejor que `global $pdo`?). Métodos: `todos()`, `buscar(string $q, int $pagina)`, `contar()`, `porId(int $id)`, `crear(...)`, `actualizar(...)`, `eliminar(int $id)`, `existeCorreo(...)` si te sirve. Migra **una** operación a la vez, empezando por listar; tras cada una prueba el CRUD en el navegador.
- [ ] **8.5 Validador**: clase `UsuarioValidator` con un método que reciba el arreglo de datos y devuelva los errores. Úsalo en crear y editar; borra la duplicación.
- [ ] **8.6 Vistas**: mueve el HTML de formularios a archivos de vista compartidos (`views/usuarios/formulario.php` usado por crear y editar) y define una forma de pasarles datos.
- [ ] **8.7 Páginas delgadas**: `crear.php`, `editar.php`, etc. quedan en pocas líneas: leen la petición, llaman al validador/repositorio y renderizan.
- [ ] **Reto final (opcional):** un `public/index.php` *front controller* con un router sencillo (`/usuarios`, `/usuarios/crear`, ...). Esto encaja con el `try_files ... /index.php` de Nginx. Reflexiona por qué esa configuración existe en el stack.
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
