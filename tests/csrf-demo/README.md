# Demostración de CSRF (mejora 6)

`attack.html` es la página de un **atacante**: un formulario oculto que apunta a `delete.php` de la aplicación y
se envía solo al abrirla. Sirve para ver con tus propios ojos qué protege cada capa. **No forma parte de la
aplicación** (está fuera de `src/`) y no la sirve nginx: se abre con el servidor integrado de PHP, **en otro sitio**.

## Probarla en el navegador

1. Con la aplicación levantada, inicia sesión en `http://localhost:8080/login.php` (en el navegador).
2. Anota el `id` de un usuario de prueba (por ejemplo el de «Editar»: `edit.php?id=14`).
3. En otra terminal: `php -S 127.0.0.1:9000 -t tests/csrf-demo` (o `docker run --rm -p 9000:9000 -v "$PWD/tests/csrf-demo:/d" php:8.4-cli php -S 0.0.0.0:9000 -t /d`).
4. En **la misma ventana del navegador** abre `http://127.0.0.1:9000/attack.html?id=14`.

`127.0.0.1:9000` y `localhost:8080` son **sitios distintos** (el host cambia), así que es una petición de otro sitio.

## Qué debe pasar (y qué capa lo evita)

| Capa | Qué hace | Resultado del ataque |
|------|----------|----------------------|
| Comprobación de `Origin` (`reject_foreign_origin()`) | El navegador envía `Origin: http://127.0.0.1:9000`; no es `APP_ORIGIN` | **403**. El usuario sigue activo |
| `SameSite=Lax` en la cookie | Un POST que viene de otro sitio no lleva la cookie de sesión | Sin cookie, `delete.php` redirige a `login.php` (302); no elimina nada |
| **Token CSRF** | El formulario del atacante no lleva `csrf_token` (no puede leerlo) | **403** aunque las otras capas fallaran |

Ninguna capa sustituye a las otras. Dos casos en los que **solo** el token te protege: una página atacante
servida desde `localhost` con otro puerto (**el mismo sitio**: el puerto no cuenta para `SameSite`, así que la
cookie sí viaja) a la que algún día se le permita el origen, y un navegador o extensión que no envíe `Origin` ni `Referer`
en un POST si alguna vez se relajara la comprobación.

## Lo que se midió sin navegador

`tests/smoke.sh` simula la petición del atacante con `curl` (la cookie de la víctima, el `Origin` correcto y **sin**
el token: lo que llegaría si el atacante hubiera saltado la comprobación de origen) y comprueba el 403 en `delete.php`,
`restore.php`, `edit.php`, `create.php`, `logout.php` y `login.php` con ocho variantes de token malo. Antes de
implementar el token, esa misma petición **sí eliminaba** al usuario (con la comprobación de origen desactivada a mano).
