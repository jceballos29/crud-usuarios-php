<?php

declare(strict_types=1);

namespace App\Controller;

use App\Auth\AdminAuth;
use App\Container;
use App\Http\Failure;
use App\Http\Response;
use App\View\View;
use RuntimeException;

/** Iniciar y cerrar sesión (login.php y logout.php). */
final class AuthController
{
    private const NOTICES = [
        'logged_out' => ['success', 'Cerraste la sesión.'],
        'expired' => ['info', 'Tu sesión caducó por inactividad. Vuelve a entrar.'],
    ];

    public function __construct(private readonly Container $container)
    {
    }

    /** GET/POST login.php: el formulario de entrada y su proceso. */
    public function login(): void
    {
        header('Cache-Control: no-store');

        $submitted = $_SERVER['REQUEST_METHOD'] === 'POST';

        if ($submitted) {
            reject_foreign_origin(); // también el login: si no, otro sitio podría iniciar SU sesión en tu navegador
        }

        // Quien ya entró no necesita el formulario.
        if (current_admin() !== null) {
            Response::redirect('index.php');
        }

        $email = '';
        $errorMessage = null;

        if ($submitted && !csrf_is_valid($_POST[CSRF_FIELD] ?? null)) {
            // El token del formulario no es el de la sesión: la página se abrió hace mucho (la sesión caducó), se
            // abrió en otro navegador, o el envío viene de otro sitio. No se procesa nada. A diferencia de las
            // demás páginas (403 y texto plano), aquí se vuelve a pintar el formulario con un token nuevo: quien
            // llega con la página caducada no debería ver una pantalla en blanco.
            $submitted = false;
            http_response_code(403);
            $errorMessage = 'La página caducó o el envío no es válido. Vuelve a escribir tus datos.';
        }

        if ($submitted) {
            // Solo texto: login.php?email[]=x no debe romper nada. La contraseña NO se recorta (los espacios cuentan).
            $email = is_string($_POST['email'] ?? null) ? trim($_POST['email']) : '';
            $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';

            if ($email === '' || $password === '') {
                http_response_code(422);
                $errorMessage = 'Escribe tu correo y tu contraseña.';
            } else {
                try {
                    $admin = AdminAuth::authenticate($this->container->admins(), $email, $password);

                    if ($admin !== null) {
                        log_in_admin($admin);
                        Response::redirect('index.php');
                    }

                    // El MISMO mensaje y el mismo código para «no existe ese correo» y «contraseña incorrecta»: si
                    // no, el formulario serviría para descubrir qué correos están registrados.
                    http_response_code(401);
                    $errorMessage = AdminAuth::LOGIN_FAILED_MESSAGE;
                } catch (RuntimeException $error) {
                    [$status, $errorMessage] = Failure::describe($error, 'No se pudo iniciar sesión. Inténtalo más tarde.');
                    http_response_code($status);
                }
            }
        }

        $notice = $errorMessage === null && !$submitted && is_string($_GET['msg'] ?? null) ? (self::NOTICES[$_GET['msg']] ?? null) : null;

        // La sesión anónima que guarda el token CSRF se inicia AQUÍ, antes de imprimir nada: session_start() envía la
        // cookie en una cabecera, y las cabeceras ya no se pueden enviar cuando el HTML ha empezado a salir.
        csrf_token();

        View::page('auth/login', ['email' => $email, 'errorMessage' => $errorMessage, 'notice' => $notice], 'Iniciar sesión', null);
    }

    /** POST logout.php: cierra la sesión. */
    public function logout(): void
    {
        // Cerrar sesión CAMBIA el estado del servidor, así que es POST (como eliminar): si fuera un enlace GET, una
        // imagen <img src="logout.php"> en cualquier otro sitio cerraría la sesión de quien la visite.
        Response::requirePost();
        reject_foreign_origin();

        // Con una sesión iniciada hace falta el token (si no, una página ajena cerraría tu sesión: molesto, y un paso
        // para ataques de «login CSRF»). Sin sesión no hay nada que proteger y termina bien (ya estás fuera).
        if (current_admin() !== null) {
            require_csrf();
        }

        // Idempotente.
        log_out_admin();

        header('Cache-Control: no-store');
        Response::redirect('login.php?msg=logged_out');
    }
}
