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
 * @var array<string, string|int> $returnState Estado del listado al que se vuelve (Pagination::listState()); [] al crear.
 * @var string $cancelUrl A dónde lleva "Cancelar" (el listado, en el mismo estado en que se salió).
 * @var string $passwordHint Texto de ayuda del campo de contraseña (solo con el rol administrador).
 */
?>
<?php if ($errorMessage !== null): ?>
    <div class="notice notice--danger mb-4" role="alert">
        <span class="notice__icon"><?= icon('alert') ?></span>
        <div class="notice__text"><p><?= escape($errorMessage) ?></p></div>
    </div>
<?php elseif ($errors !== []): ?>
    <div class="notice notice--danger mb-4" role="alert">
        <span class="notice__icon"><?= icon('alert') ?></span>
        <div class="notice__text"><p>Revisa los campos marcados e inténtalo de nuevo.</p></div>
    </div>
<?php endif; ?>

<form method="post" action="<?= escape($action) ?>" class="panel panel--form">
    <?= csrf_field() ?>
    <?php if ($userId !== null): ?>
        <input type="hidden" name="id" value="<?= escape($userId) ?>">
    <?php endif; ?>
    <?php foreach ($returnState as $stateName => $stateValue): ?>
        <input type="hidden" name="<?= escape($stateName) ?>" value="<?= escape($stateValue) ?>">
    <?php endforeach; ?>
    <?php if ($rowToken !== null): ?>
        <input type="hidden" name="row_token" value="<?= escape($rowToken) ?>">
    <?php endif; ?>

    <div class="form-grid">
    <?php foreach ($fields as $fieldName => $definition): ?>
        <?php $hasError = isset($errors[$fieldName]); ?>
        <?php /* El correo ocupa todo el ancho; el resto va de dos en dos (en pantallas estrechas, una columna). */ ?>
        <?php /* Los campos con "gate" solo se muestran con ese rol (js/app.js); sin JavaScript se ven siempre y el servidor decide. */ ?>
        <div class="field<?= $fieldName === 'email' ? ' field--wide' : '' ?>"<?= isset($definition['gate']) ? ' data-gate="' . escape($definition['gate']) . '"' : '' ?>>
            <label for="<?= escape($fieldName) ?>" class="field__label"><?= escape($definition['label']) ?></label>
            <?php if ($definition['type'] === 'select'): ?>
                <?php /* Las opciones salen de la lista blanca del servidor; lo que llegue de vuelta se vuelve a validar. */ ?>
                <div class="select">
                <select
                    class="input"
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
                <?= icon('chevron-down') ?>
                </div>
            <?php elseif ($definition['type'] === 'password'): ?>
                <?php /* Una contraseña NUNCA se vuelve a pintar (ni tras un error): sin value. new-password: que el navegador no rellene la propia. */ ?>
                <input
                    type="password"
                    class="input"
                    id="<?= escape($fieldName) ?>"
                    name="<?= escape($fieldName) ?>"
                    autocomplete="new-password"
                    <?php if ($hasError): ?>aria-invalid="true" aria-describedby="<?= escape($fieldName) ?>-error" <?php endif; ?>>
                <?php if ($fieldName === 'password' && !$hasError): ?>
                    <p class="field__hint"><?= escape($passwordHint) ?></p>
                <?php endif; ?>
            <?php else: ?>
                <input
                    type="<?= escape($definition['type']) ?>"
                    class="input"
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
                <p class="field__error" id="<?= escape($fieldName) ?>-error"><?= icon('alert') ?><span><?= escape($errors[$fieldName]) ?></span></p>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn--primary"><?= escape($submitLabel) ?></button>
        <a class="btn btn--ghost" href="<?= escape($cancelUrl) ?>">Cancelar</a>
    </div>
</form>
