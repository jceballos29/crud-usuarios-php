// Pide confirmación antes de enviar un formulario que lleve data-confirm="mensaje".
// Va en un archivo aparte, y no en un onsubmit en línea, para poder usar una Content-Security-Policy
// que prohíba los scripts en línea (ver docker/nginx/default.conf).
document.addEventListener('submit', (event) => {
    const form = event.target;
    const message = form instanceof HTMLFormElement ? form.dataset.confirm : undefined;

    if (message && !window.confirm(message)) {
        event.preventDefault();
    }
});
