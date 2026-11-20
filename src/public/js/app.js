// Comportamiento mínimo de la interfaz. Va en un archivo aparte, y no en atributos en línea (onsubmit, onclick),
// para poder usar una Content-Security-Policy que prohíba los scripts en línea (docker/nginx/default.conf).
// Sin dependencias: la aplicación no carga ningún script de terceros.
//
// 1. Confirmación: un formulario con data-confirm="mensaje" pide confirmación en un <dialog> propio antes de enviarse
//    (data-confirm-note: texto de apoyo; data-confirm-action: texto del botón que confirma). Sin <dialog> usa confirm().
// 2. Avisos: un botón con data-dismiss cierra su aviso (.notice).
// 3. Campos por rol: un campo con data-gate="admin" solo se muestra si el <select name="role"> vale "admin" (la
//    contraseña de quien podrá iniciar sesión). Es comodidad: el servidor decide qué cuenta.
// 4. Envío: tras enviar un formulario se desactivan sus botones, para que un doble clic no lo envíe dos veces.
(() => {
    'use strict';

    // --- 1. Confirmación ---------------------------------------------------------------------------------------
    let dialog = null;
    let pendingForm = null;

    function buildDialog() {
        const element = document.createElement('dialog');
        element.className = 'modal';
        element.setAttribute('aria-labelledby', 'modal-title');
        element.setAttribute('aria-describedby', 'modal-note');

        const form = document.createElement('form');
        form.method = 'dialog';
        form.className = 'modal__form';

        const body = document.createElement('div');
        body.className = 'modal__body';
        const title = document.createElement('h2');
        title.id = 'modal-title';
        title.className = 'modal__title';
        const note = document.createElement('p');
        note.id = 'modal-note';
        note.className = 'modal__note';
        body.append(title, note);

        const actions = document.createElement('div');
        actions.className = 'modal__actions';
        const cancel = document.createElement('button');
        cancel.type = 'submit';
        cancel.value = 'cancel';
        cancel.className = 'btn btn--ghost';
        cancel.textContent = 'Cancelar';
        cancel.autofocus = true;
        const confirm = document.createElement('button');
        confirm.type = 'submit';
        confirm.value = 'confirm';
        confirm.className = 'btn btn--danger';
        confirm.dataset.role = 'confirm';
        actions.append(cancel, confirm);

        form.append(body, actions);
        element.append(form);

        element.addEventListener('close', () => {
            const form = pendingForm;
            pendingForm = null;

            if (form && element.returnValue === 'confirm') {
                form.submit(); // submit() no vuelve a lanzar el evento "submit": no hay bucle
            }
        });

        // Un clic fuera de la tarjeta (en el fondo oscurecido) cierra el diálogo sin confirmar.
        element.addEventListener('click', (event) => {
            if (event.target === element) {
                element.close('cancel');
            }
        });

        document.body.append(element);

        return element;
    }

    document.addEventListener('submit', (event) => {
        const form = event.target;

        if (!(form instanceof HTMLFormElement) || !form.dataset.confirm) {
            return;
        }

        event.preventDefault();

        if (typeof HTMLDialogElement !== 'function') {
            if (window.confirm(form.dataset.confirm)) {
                form.submit();
            }

            return;
        }

        dialog = dialog ?? buildDialog();
        pendingForm = form;
        dialog.querySelector('.modal__title').textContent = form.dataset.confirm;
        dialog.querySelector('.modal__note').textContent = form.dataset.confirmNote ?? '';
        dialog.querySelector('.modal__note').hidden = !form.dataset.confirmNote;
        dialog.querySelector('[data-role="confirm"]').textContent = form.dataset.confirmAction ?? 'Confirmar';
        dialog.returnValue = '';
        dialog.showModal();
    });

    // --- 2. Avisos -------------------------------------------------------------------------------------------------
    document.addEventListener('click', (event) => {
        const button = event.target instanceof Element ? event.target.closest('[data-dismiss]') : null;
        const notice = button ? button.closest('.notice') : null;

        if (notice) {
            notice.remove();
        }
    });

    // --- 3. Campos por rol ----------------------------------------------------------------------------------------
    const roleSelect = document.querySelector('select[name="role"]');

    if (roleSelect) {
        const gated = document.querySelectorAll('[data-gate]');
        const sync = () => gated.forEach((field) => { field.hidden = field.dataset.gate !== roleSelect.value; });
        roleSelect.addEventListener('change', sync);
        sync();
    }

    // --- 4. Un solo envío ------------------------------------------------------------------------------------------
    document.addEventListener('submit', (event) => {
        const form = event.target;

        // Se espera a que termine este evento: así otros manejadores (la confirmación) pueden cancelarlo antes.
        if (!(form instanceof HTMLFormElement) || form.method.toLowerCase() !== 'post') {
            return;
        }

        setTimeout(() => {
            if (event.defaultPrevented) {
                return;
            }

            form.setAttribute('aria-busy', 'true');
            form.querySelectorAll('button[type="submit"]').forEach((button) => { button.disabled = true; });
        }, 0);
    });

    // Al volver con «atrás» el navegador puede devolver la página con los botones desactivados.
    window.addEventListener('pageshow', () => {
        document.querySelectorAll('form[aria-busy="true"]').forEach((form) => {
            form.removeAttribute('aria-busy');
            form.querySelectorAll('button[type="submit"]').forEach((button) => { button.disabled = false; });
        });
    });
})();
