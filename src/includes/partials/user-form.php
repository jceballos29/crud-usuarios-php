<?php

declare(strict_types=1);

/**
 * Formulario de usuario compartido (create.php y edit.php), con los avisos de error.
 * Variables esperadas (las define la página antes del require):
 *
 * @var array<string, array{label: string, type: string, required: bool, max: int, options?: array<string, string>}> $fields Definición de campos (user_fields()).
 * @var array<string, string> $data Valores a pintar en cada campo.
 * @var array<string, string> $errors Errores por campo (campo => mensaje).
 * @var string|null $errorMessage Error general (BD, configuración...), o null.
 * @var string $action URL de destino del formulario (create.php, edit.php).
 * @var string $submitLabel Texto del botón de envío (Registrar, Actualizar).
 * @var int|null $userId Id del usuario que se edita; null al crear (no se pinta el campo oculto).
 * @var string|null $rowToken Huella de la fila con la que se abrió el formulario (user_row_token()); null al crear.
 * @var array<string, string|int> $returnState Estado del listado al que se vuelve (list_state()); [] al crear.
 * @var string $cancelUrl A dónde lleva "Cancelar" (el listado, en el mismo estado en que se salió).
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
    <?php foreach ($returnState as $stateName => $stateValue): ?>
        <input type="hidden" name="<?= escape($stateName) ?>" value="<?= escape($stateValue) ?>">
    <?php endforeach; ?>
    <?php if ($rowToken !== null): ?>
        <input type="hidden" name="row_token" value="<?= escape($rowToken) ?>">
    <?php endif; ?>

    <?php foreach ($fields as $fieldName => $definition): ?>
        <?php $hasError = isset($errors[$fieldName]); ?>
        <div class="form-field mb-3">
            <label for="<?= escape($fieldName) ?>" class="form-label"><?= escape($definition['label']) ?></label>
            <?php if ($definition['type'] === 'select'): ?>
                <?php /* Las opciones salen de la lista blanca del servidor; lo que llegue de vuelta se vuelve a validar. */ ?>
                <select
                    class="form-select<?= $hasError ? ' is-invalid' : '' ?>"
                    id="<?= escape($fieldName) ?>"
                    name="<?= escape($fieldName) ?>"
                    autocomplete="off"
                    <?= $definition['required'] ? 'required' : '' ?>
                    <?php if ($hasError): ?>aria-invalid="true" aria-describedby="<?= escape($fieldName) ?>-error" <?php endif; ?>>
                    <?php /* Un valor que no está en la lista (envío manipulado) no se pinta: queda sin seleccionar. */ ?>
                    <?php if (!array_key_exists($data[$fieldName], $definition['options'])): ?>
                        <option value="" selected disabled>Selecciona…</option>
                    <?php endif; ?>
                    <?php foreach ($definition['options'] as $optionValue => $optionLabel): ?>
                        <option value="<?= escape($optionValue) ?>"<?= (string) $optionValue === $data[$fieldName] ? ' selected' : '' ?>><?= escape($optionLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php else: ?>
                <input
                    type="<?= escape($definition['type']) ?>"
                    class="form-control<?= $hasError ? ' is-invalid' : '' ?>"
                    id="<?= escape($fieldName) ?>"
                    name="<?= escape($fieldName) ?>"
                    value="<?= escape($data[$fieldName]) ?>"
                    <?php if ($definition['type'] !== 'date'): ?>maxlength="<?= escape($definition['max']) ?>"<?php endif; ?>
                    <?php /* Son datos de OTRA persona: autocomplete="off" evita que el navegador rellene los del propio usuario. */ ?>
                    autocomplete="off"
                    <?= $definition['required'] ? 'required' : '' ?>
                    <?php if ($hasError): ?>aria-invalid="true" aria-describedby="<?= escape($fieldName) ?>-error" <?php endif; ?>>
            <?php endif; ?>
            <?php if ($hasError): ?>
                <div class="invalid-feedback" id="<?= escape($fieldName) ?>-error"><?= escape($errors[$fieldName]) ?></div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <div class="d-grid gap-4 mt-10">
        <button type="submit" class="btn-solid theme-primary"><?= escape($submitLabel) ?></button>
        <a role="button" class="btn-solid" href="<?= escape($cancelUrl) ?>">Cancelar</a>
    </div>
</form>
