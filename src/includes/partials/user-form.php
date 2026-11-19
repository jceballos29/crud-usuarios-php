<?php

declare(strict_types=1);

/**
 * Formulario de usuario compartido (create.php y edit.php), con los avisos de error.
 * Variables esperadas (las define la página antes del require):
 *
 * @var array<string, array{label: string, type: string, required: bool, max: int}> $fields Definición de campos (user_fields()).
 * @var array<string, string> $data Valores a pintar en cada campo.
 * @var array<string, string> $errors Errores por campo (campo => mensaje).
 * @var string|null $errorMessage Error general (BD, configuración...), o null.
 * @var string $action URL de destino del formulario (create.php, edit.php).
 * @var string $submitLabel Texto del botón de envío (Registrar, Actualizar).
 * @var int|null $userId Id del usuario que se edita; null al crear (no se pinta el campo oculto).
 * @var string|null $rowToken Huella de la fila con la que se abrió el formulario (user_row_token()); null al crear.
 */
?>
<?php if ($errorMessage !== null): ?>
    <div class="alert theme-danger" role="alert">
        <p><?= escape($errorMessage) ?></p>
    </div>
<?php elseif ($errors !== []): ?>
    <div class="alert theme-danger" role="alert">
        <p>Revisa los campos marcados e inténtalo de nuevo.</p>
    </div>
<?php endif; ?>

<form method="post" action="<?= escape($action) ?>" class="my-4">
    <?php if ($userId !== null): ?>
        <input type="hidden" name="id" value="<?= escape($userId) ?>">
    <?php endif; ?>
    <?php if ($rowToken !== null): ?>
        <input type="hidden" name="row_token" value="<?= escape($rowToken) ?>">
    <?php endif; ?>

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
                <?php /* Son datos de OTRA persona: autocomplete="off" evita que el navegador rellene los del propio usuario. */ ?>
                autocomplete="off"
                <?= $definition['required'] ? 'required' : '' ?>
                <?php if ($hasError): ?>aria-invalid="true" aria-describedby="<?= escape($fieldName) ?>-error" <?php endif; ?>>
            <?php if ($hasError): ?>
                <div class="invalid-feedback" id="<?= escape($fieldName) ?>-error"><?= escape($errors[$fieldName]) ?></div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <div class="d-grid gap-4 mt-10">
        <button type="submit" class="btn-solid theme-primary"><?= escape($submitLabel) ?></button>
        <a role="button" class="btn-solid" href="index.php">Cancelar</a>
    </div>
</form>
