<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';

$pageTitle = "Registrar";

// Definición de los campos: se usa tanto para validar como para dibujar el formulario.
// Los máximos coinciden con las columnas de database/01-schema.sql.
$fields = [
    'name'      => ['label' => 'Nombre',   'type' => 'text',  'required' => true,  'max' => 100],
    'last_name' => ['label' => 'Apellido', 'type' => 'text',  'required' => true,  'max' => 100],
    'email'     => ['label' => 'Email',    'type' => 'email', 'required' => true,  'max' => 254],
    'phone'     => ['label' => 'Teléfono', 'type' => 'text',  'required' => false, 'max' => 20, 'min_digits' => 7, 'max_digits' => 15],
];

$data = array_fill_keys(array_keys($fields), '');
$errors = []; // campo => mensaje (solo el primer error de cada campo)
$errorMessage = null; // error general (BD, configuración...)
$submitted = $_SERVER['REQUEST_METHOD'] === 'POST';

if ($submitted) {
    foreach (array_keys($fields) as $fieldName) {
        // Si llega un array (name[]=x) o algo raro, se trata como vacío.
        $value = $_POST[$fieldName] ?? '';
        $data[$fieldName] = is_string($value) ? trim($value) : '';
    }

    // Normalización: el email se guarda siempre en minúsculas.
    $data['email'] = mb_strtolower($data['email'], 'UTF-8');

    $length = static fn(string $value): int => mb_strlen($value, 'UTF-8');

    // Letras (con tildes), espacio literal, apóstrofe, punto y guion: O'Brien, María-José.
    $personName = '/^\p{L}[\p{L}\p{M} \'’.-]*$/u';

    // Nombre y apellido: sin mínimo de longitud (la BD solo exige NOT NULL).
    $personFields = ['name' => 'nombre', 'last_name' => 'apellido'];
    foreach ($personFields as $fieldName => $noun) {
        $max = $fields[$fieldName]['max'];

        if ($data[$fieldName] === '') {
            $errors[$fieldName] = sprintf('El %s es obligatorio.', $noun);
        } elseif ($length($data[$fieldName]) > $max) {
            $errors[$fieldName] = sprintf('El %s no debe superar los %d caracteres.', $noun, $max);
        } elseif (!preg_match($personName, $data[$fieldName])) {
            $errors[$fieldName] = sprintf('El %s solo puede contener letras, espacios, apóstrofes, puntos y guiones.', $noun);
        }
    }

    // Email
    if ($data['email'] === '') {
        $errors['email'] = 'El email es obligatorio.';
    } elseif ($length($data['email']) > $fields['email']['max']) {
        $errors['email'] = sprintf('El email no debe superar los %d caracteres.', $fields['email']['max']);
    } elseif (filter_var($data['email'], FILTER_VALIDATE_EMAIL) === false) {
        $errors['email'] = 'Ingresa un email válido.';
    }

    // Teléfono (opcional): longitud total acorde a la columna, solo caracteres de teléfono
    // y entre min_digits y max_digits dígitos reales (E.164 admite hasta 15).
    if ($data['phone'] !== '') {
        ['max' => $max, 'min_digits' => $minDigits, 'max_digits' => $maxDigits] = $fields['phone'];
        $digits = strlen(preg_replace('/\D/', '', $data['phone']));

        if ($length($data['phone']) > $max) {
            $errors['phone'] = sprintf('El teléfono no debe superar los %d caracteres.', $max);
        } elseif (!preg_match('/^\+?[0-9 ()-]+$/', $data['phone'])) {
            $errors['phone'] = 'El teléfono solo puede contener números, espacios, paréntesis y guiones (con + opcional al inicio).';
        } elseif ($digits < $minDigits || $digits > $maxDigits) {
            $errors['phone'] = sprintf('El teléfono debe tener entre %d y %d dígitos.', $minDigits, $maxDigits);
        }
    }

    if ($errors === []) {
        try {
            require_once __DIR__ . '/../includes/connection.php';
            $sql = 'INSERT INTO users (name, last_name, email, phone) VALUES (:name, :last_name, :email, :phone)';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':name' => $data['name'],
                ':last_name' => $data['last_name'],
                ':email' => $data['email'],
                ':phone' => $data['phone'] !== '' ? $data['phone'] : null,
            ]);
            header('Location: index.php?msg=created');
            exit;
        } catch (DatabaseConnectionException $error) {
            http_response_code(503);
            $errorMessage = 'No se pudo conectar a la base de datos. Inténtalo más tarde.';
            log_exception('connection', $error);
        } catch (PDOException $error) {
            // 1062 = entrada duplicada (columna email UNIQUE). 23000 es demasiado amplio.
            if (($error->errorInfo[1] ?? null) === 1062) {
                http_response_code(409);
                $errors['email'] = 'Ya existe un usuario registrado con ese correo.';
            } else {
                http_response_code(500);
                $errorMessage = 'No se pudo guardar el usuario. Inténtalo más tarde.';
                log_exception('query', $error);
            }
        } catch (RuntimeException $error) {
            http_response_code(500);
            $errorMessage = 'Ocurrió un error inesperado. Inténtalo más tarde.';
            log_exception('config', $error);
        }
    } else {
        // Regla de códigos: si el envío no se completó nunca es 200.
        // 422 = datos inválidos, 409 = choca con lo ya guardado (email duplicado), 5xx = fallo del servidor.
        http_response_code(422);
    }
}

require __DIR__ . '/../includes/layout/header.php';
?>

<h1 class="mb-4">Registrar usuario</h1>

<?php if ($errorMessage !== null): ?>
    <div class="alert theme-danger" role="alert">
        <p><?= escape($errorMessage) ?></p>
    </div>
<?php elseif ($errors !== []): ?>
    <div class="alert theme-danger" role="alert">
        <p>Revisa los campos marcados e inténtalo de nuevo.</p>
    </div>
<?php endif; ?>

<form method="post" action="create.php" class="my-4">
    <?php foreach ($fields as $fieldName => $definition): ?>
        <?php $hasError = isset($errors[$fieldName]); ?>
        <div class="form-field mb-3">
            <label for="<?= escape($fieldName) ?>" class="form-label"><?= escape($definition['label']) ?></label>
            <input
                type="<?= escape($definition['type']) ?>"
                class="form-control<?= $hasError ? ' is-invalid' : '' ?>"
                id="<?= escape($fieldName) ?>"
                name="<?= escape($fieldName) ?>"
                value="<?= escape($data[$fieldName]) ?>"
                maxlength="<?= escape($definition['max']) ?>"
                <?= $definition['required'] ? 'required' : '' ?>
                <?php if ($hasError): ?>aria-invalid="true" aria-describedby="<?= escape($fieldName) ?>-error" <?php endif; ?>>
            <?php if ($hasError): ?>
                <div class="invalid-feedback" id="<?= escape($fieldName) ?>-error"><?= escape($errors[$fieldName]) ?></div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <div class="d-grid gap-4 mt-10">
        <button type="submit" class="btn-solid theme-primary">Registrar</button>
        <a role="button" class="btn-solid" href="index.php">Cancelar</a>
    </div>
</form>

<?php
require __DIR__ . '/../includes/layout/footer.php';
