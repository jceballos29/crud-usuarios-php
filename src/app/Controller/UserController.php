<?php

declare(strict_types=1);

namespace App\Controller;

use App\Container;
use App\Domain\User;
use App\Exceptions\DuplicateUserException;
use App\Http\Failure;
use App\Http\Pagination;
use App\Http\Response;
use App\Search\UserSearch;
use App\View\View;
use RuntimeException;

/**
 * Las páginas de usuarios (listar, crear, editar, eliminar, restaurar). Cada acción hace siempre lo mismo: comprobar
 * quién pide, leer la petición, llamar al validador y al repositorio, y devolver una vista o una redirección.
 * No contiene SQL (es del repositorio) ni HTML (es de las vistas).
 *
 * Orden de las comprobaciones en los POST: método (405) -> origen (403) -> sesión (302 a login.php) -> token CSRF
 * (403) -> lo que envía (id 400, datos 422, usuario 404) -> BD. Primero «¿quién pide?», después «¿qué pide?».
 */
final class UserController
{
    private const MESSAGES = [
        'created' => ['El usuario ha sido registrado exitosamente.', 'success'],
        'updated' => ['El usuario ha sido actualizado exitosamente.', 'success'],
        'deleted' => ['El usuario ha sido eliminado exitosamente. Puedes restaurarlo desde «Eliminados».', 'success'],
        'missing' => ['El usuario ya no existía, no se eliminó nada.', 'info'], // el estado deseado ya se cumplía
        'restored' => ['El usuario ha sido restaurado exitosamente.', 'success'],
        'not_restored' => ['El usuario no estaba eliminado, no se restauró nada.', 'info'],
        'self' => ['No puedes eliminar tu propia cuenta: perderías el acceso. Pídele a otro administrador que lo haga.', 'info'],
    ];

    private const PASSWORD_HINT = 'Mínimo 10 caracteres. Con ella esta persona podrá iniciar sesión en la aplicación.';

    public function __construct(private readonly Container $container)
    {
    }

    /** GET index.php: el listado, con búsqueda (?q=), página (?page=) y vista (?view=inactive). */
    public function index(): void
    {
        $admin = require_login(); // sin sesión: 302 a login.php, antes de cualquier consulta o salida

        $search = UserSearch::parseTerm($_GET['q'] ?? null);
        $requestedPage = Pagination::parsePage($_GET['page'] ?? null);
        $view = Pagination::parseView($_GET['view'] ?? null);
        $showActive = $view === Pagination::VIEW_ACTIVE;
        $pagination = Pagination::paginate(0, $requestedPage);
        $users = [];
        $errorMessage = null;

        try {
            // Primero se cuenta (con el mismo filtro) para saber cuántas páginas hay; después se pide UNA página.
            // paginate() corrige la página si se pasó del rango: ?page=999 muestra la última, no una vacía.
            $repository = $this->container->users();
            $pagination = Pagination::paginate($repository->count($search, $showActive), $requestedPage);
            $users = $repository->page($search, $showActive, $pagination['perPage'], $pagination['offset']);
        } catch (RuntimeException $error) {
            [$status, $errorMessage] = Failure::describe($error, 'No se pudieron obtener los usuarios. Inténtalo más tarde.');
            http_response_code($status);
        }

        // Si hubo un error, no se muestra ningún mensaje de éxito.
        $key = $errorMessage === null && is_string($_GET['msg'] ?? null) ? $_GET['msg'] : null;
        [$message, $messageType] = $key !== null && isset(self::MESSAGES[$key]) ? self::MESSAGES[$key] : [null, 'success'];

        View::page('users/index', [
            'message' => $message,
            'messageType' => $messageType,
            'errorMessage' => $errorMessage,
            'users' => $users,
            'search' => $search,
            'view' => $view,
            'pagination' => $pagination,
        ], 'Inicio', $admin);
    }

    /** GET/POST create.php: el formulario de alta y su proceso. */
    public function create(): void
    {
        $submitted = $_SERVER['REQUEST_METHOD'] === 'POST';

        if ($submitted) {
            reject_foreign_origin();
        }

        $admin = require_login();

        if ($submitted) {
            require_csrf();
        }

        $validator = $this->container->validator();
        $data = $validator->emptyData();
        $errors = []; // campo => mensaje (solo el primer error de cada campo)
        $errorMessage = null; // error general (BD, configuración...)

        if ($submitted) {
            $data = $validator->read($_POST);
            $errors = $validator->validate($data);

            if ($errors === []) {
                try {
                    $this->container->users()->create($data);
                    Response::redirect('index.php?msg=created');
                } catch (DuplicateUserException $duplicate) {
                    http_response_code(409);
                    $errors[$duplicate->field] = $duplicate->userMessage(false);
                } catch (RuntimeException $error) {
                    [$status, $errorMessage] = Failure::describe($error, 'No se pudo guardar el usuario. Inténtalo más tarde.');
                    http_response_code($status);
                }
            } else {
                // Regla de códigos: si el envío no se completó nunca es 200.
                // 422 = datos inválidos, 409 = choca con lo ya guardado (correo o documento duplicado), 5xx = fallo del servidor.
                http_response_code(422);
            }
        }

        View::page('users/create', ['form' => [
            'fields' => $validator->fields(),
            'data' => $data,
            'errors' => $errors,
            'errorMessage' => $errorMessage,
            'action' => 'create.php',
            'submitLabel' => 'Registrar',
            'userId' => null,
            'rowToken' => null,
            'returnState' => [], // un alta no viene de ninguna página: el usuario nuevo sale el primero
            'cancelUrl' => 'index.php',
            'passwordHint' => self::PASSWORD_HINT,
        ]], 'Registrar', $admin);
    }

    /** GET/POST edit.php?id=N: el formulario de edición y su proceso. */
    public function edit(): void
    {
        $submitted = $_SERVER['REQUEST_METHOD'] === 'POST';

        if ($submitted) {
            reject_foreign_origin(); // 403 si el POST no viene del propio sitio, antes de mirar el id o lo que envía
        }

        $admin = require_login(); // después del origen: sin sesión, 302 a login.php antes de tocar la BD

        if ($submitted) {
            require_csrf(); // después de la sesión y antes de mirar el id o lo que envía
        }

        $validator = $this->container->validator();
        $errors = [];
        $errorMessage = null;

        // Estado del listado al que se vuelve tras guardar o cancelar (búsqueda y página). Llega en la URL al
        // abrir el formulario y en campos ocultos al enviarlo, y se valida igual que en el listado. Son solo dos
        // valores (un texto limpio y un entero): nunca una URL, así que no hay redirección a sitios externos.
        $stateSource = $submitted ? $_POST : $_GET;
        $returnSearch = UserSearch::parseTerm($stateSource['q'] ?? null);
        $returnPage = Pagination::parsePage($stateSource['page'] ?? null);

        // El id se lee una sola vez y solo de la fuente que corresponde al método: GET al abrir el formulario,
        // POST al enviarlo. Nunca se mezclan. parseId() da el id como int, o null si no es válido.
        $userId = User::parseId($submitted ? ($_POST['id'] ?? null) : ($_GET['id'] ?? null));

        // Huella de la fila con la que se abrió el formulario (la trae el POST). Si la BD falla, el formulario que
        // se vuelve a pintar la conserva; si no llega, simplemente no se comprueba (curl, scripts).
        $submittedToken = $submitted && is_string($_POST['row_token'] ?? null) && $_POST['row_token'] !== '' ? $_POST['row_token'] : null;
        $rowToken = $submittedToken;

        $notFound = $userId === null; // id inválido: el inexistente se detecta más abajo al buscarlo
        $loaded = false;               // true cuando el usuario se cargó de la BD
        $user = null;

        // Lo enviado se lee antes de tocar la BD: si la conexión falla, el formulario conserva lo escrito.
        $data = $submitted ? $validator->read($_POST) : $validator->emptyData();

        if (!$notFound) {
            try {
                // Cargar (también al enviar el POST: un usuario borrado entre tanto debe dar 404).
                $user = $this->container->users()->findActive($userId);

                if ($user === null) {
                    $notFound = true;
                } else {
                    $loaded = true;
                    $currentToken = $user->rowToken();
                    $rowToken = $submittedToken ?? $currentToken;

                    if ($submitted && !hash_equals($currentToken, $rowToken)) {
                        // Edición simultánea: la fila ya no es la que se mostró al abrir el formulario. No se
                        // guarda nada. Se conserva lo escrito y la huella VIEJA: hay que recargar para ver los
                        // datos actuales y volver a aplicar los cambios a propósito.
                        http_response_code(409);
                        $errorMessage = 'Otra persona modificó a este usuario mientras lo editabas. Recarga la página para ver los datos actuales: tus cambios no se han guardado.';
                    } elseif ($submitted) {
                        // Al ascender a alguien a administrador la contraseña es obligatoria; a quien ya lo es, vacía = conservar.
                        $errors = $validator->validate($data, $user->role !== 'admin');

                        // Nadie se quita a sí mismo el rol de administrador: perdería el acceso (y podría dejar la
                        // aplicación sin nadie que entre). Se lo quita otro administrador.
                        if ($userId === $admin['id'] && $data['role'] !== 'admin' && !isset($errors['role'])) {
                            $errors['role'] = 'No puedes quitarte el rol de administrador a ti mismo: perderías el acceso.';
                        }

                        if ($errors === []) {
                            if ($this->container->users()->update($userId, $data)) {
                                if ($userId === $admin['id']) {
                                    refresh_admin_session_email($data['email']); // el encabezado enseña el correo nuevo
                                }

                                Response::redirect(Pagination::listUrl($returnSearch, $returnPage, ['msg' => 'updated']));
                            }

                            // update() devolvió false: el usuario ya no existe (alguien lo borró entre la carga y el guardado).
                            $notFound = true;
                        } else {
                            // Regla de códigos: si el envío no se completó nunca es 200.
                            http_response_code(422);
                        }
                    } else {
                        $data = $user->formData(); // rellenar con lo guardado
                    }
                }
            } catch (DuplicateUserException $duplicate) {
                // Aquí significa que lo usa OTRO usuario: guardar sin cambiarlo no choca, el registro no
                // entra en conflicto consigo mismo.
                http_response_code(409);
                $errors[$duplicate->field] = $duplicate->userMessage(true);
            } catch (RuntimeException $error) {
                [$status, $errorMessage] = Failure::describe($error, 'No se pudo completar la operación. Inténtalo más tarde.');
                http_response_code($status);
            }
        }

        // Id inválido o inexistente: 404 con una página que lo explica.
        if ($notFound) {
            http_response_code(404);
            View::page('users/not-found', [], 'Usuario no encontrado', $admin);

            return;
        }

        View::page('users/edit', [
            'showForm' => $loaded || $submitted,
            'errorMessage' => $errorMessage,
            'user' => $user,
            'form' => [
                'fields' => $validator->fields(),
                'data' => $data,
                'errors' => $errors,
                'errorMessage' => $errorMessage,
                'action' => 'edit.php',
                'submitLabel' => 'Actualizar',
                'userId' => $userId,
                'rowToken' => $rowToken,
                'returnState' => Pagination::listState($returnSearch, $returnPage),
                'cancelUrl' => Pagination::listUrl($returnSearch, $returnPage),
                'passwordHint' => $user !== null && $user->hasAccess ? 'Déjala vacía para conservar la actual. Si escribes una, reemplaza a la anterior.' : self::PASSWORD_HINT,
            ],
        ], 'Editar usuario', $admin);
    }

    /** POST delete.php: elimina (lógicamente) y redirige. Casi nunca muestra pantalla. */
    public function delete(): void
    {
        $this->changeState(
            fn(int $id): bool => $this->container->users()->deactivate($id),
            ['deleted', 'missing'],
            Pagination::VIEW_ACTIVE,
            'No se pudo eliminar el usuario',
            'No se pudo eliminar',
            static fn(int $id, array $admin): ?string => $id === $admin['id'] ? 'self' : null // nadie se elimina a sí mismo
        );
    }

    /** POST restore.php: restaura a un eliminado y redirige. */
    public function restore(): void
    {
        $this->changeState(
            fn(int $id): bool => $this->container->users()->restore($id),
            ['restored', 'not_restored'],
            Pagination::VIEW_INACTIVE,
            'No se pudo restaurar el usuario',
            'No se pudo restaurar'
        );
    }

    /**
     * Lo común de eliminar y restaurar: solo POST, con las comprobaciones de siempre, un id válido, y una
     * operación idempotente del repositorio cuyo resultado decide el aviso. Si la BD falla no se redirige
     * (sería un 302 y luego un 200): se responde con el código honesto y una página con el aviso.
     *
     * @param callable(int): bool $operation Devuelve true si cambió algo.
     * @param array{0: string, 1: string} $messages [aviso si cambió algo, aviso si no].
     * @param string $returnView Vista a la que se vuelve.
     * @param (callable(int, array{id: int, email: string}): ?string)|null $refuse Si devuelve una clave de aviso, la operación
     *                                                                         NO se hace y se vuelve con ese aviso.
     */
    private function changeState(callable $operation, array $messages, string $returnView, string $errorTitle, string $pageTitle, ?callable $refuse = null): void
    {
        Response::requirePost();           // 405
        reject_foreign_origin();           // 403
        $admin = require_login();          // 302: sin sesión no se toca nada
        require_csrf();                    // 403: ni sin el token de esa sesión

        try {
            // El id se valida ANTES de abrir la BD. Un id mal formado no identifica a ningún usuario: es una
            // petición incorrecta (400), no un usuario «ya borrado».
            $userId = User::parseId($_POST['id'] ?? null);

            if ($userId === null) {
                Response::plain(400, "Petición incorrecta: el id no es válido.\n");
            }

            // Estado del listado desde el que se pulsó el botón (búsqueda y página), para volver a él. Si era el
            // último de la última página, el listado corrige la página y se vuelve a la nueva última.
            $returnSearch = UserSearch::parseTerm($_POST['q'] ?? null);
            $returnPage = Pagination::parsePage($_POST['page'] ?? null);

            $refusal = $refuse !== null ? $refuse($userId, $admin) : null;

            if ($refusal !== null) {
                Response::redirect(Pagination::listUrl($returnSearch, $returnPage, ['msg' => $refusal], $returnView));
            }

            // Idempotente: si ya estaba así, el estado deseado ya se cumple y no es un error. Pero no se dice
            // «hecho» si esta petición no cambió nada.
            $result = $operation($userId) ? $messages[0] : $messages[1];

            Response::redirect(Pagination::listUrl($returnSearch, $returnPage, ['msg' => $result], $returnView));
        } catch (RuntimeException $error) {
            [$status, $errorMessage] = Failure::describe($error, $errorTitle . '. Inténtalo más tarde.');
            http_response_code($status);
        }

        View::page('partials/action-error', ['errorTitle' => $errorTitle, 'errorMessage' => $errorMessage], $pageTitle, $admin);
    }
}
