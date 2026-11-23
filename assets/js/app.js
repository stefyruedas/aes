/* =============================================================
   Gestion AES - Scripts de interfaz
   ============================================================= */

/**
 * Modal de confirmacion reutilizable.
 * Uso en HTML:
 *   <form ... data-confirmar="Mensaje de confirmacion">
 * El envio del formulario se intercepta y pide confirmacion antes.
 */
document.addEventListener('DOMContentLoaded', function () {

    // Confirmacion en formularios con data-confirmar
    document.querySelectorAll('form[data-confirmar]').forEach(function (form) {
        form.addEventListener('submit', function (ev) {
            if (form.dataset.confirmado === 'si') return; // ya confirmado
            ev.preventDefault();
            abrirConfirmacion(form.dataset.confirmar || 'Estas seguro de realizar esta accion?', function () {
                form.dataset.confirmado = 'si';
                form.submit();
            });
        });
    });

    // Confirmacion en enlaces con data-confirmar
    document.querySelectorAll('a[data-confirmar]').forEach(function (a) {
        a.addEventListener('click', function (ev) {
            ev.preventDefault();
            abrirConfirmacion(a.dataset.confirmar || 'Estas seguro?', function () {
                window.location.href = a.href;
            });
        });
    });
});

/** Crea y muestra un modal de confirmacion (ventana emergente naranja). */
function abrirConfirmacion(mensaje, alConfirmar) {
    var fondo = document.createElement('div');
    fondo.className = 'modal-fondo abierto';
    fondo.innerHTML =
        '<div class="modal" role="alertdialog" aria-modal="true">' +
        '  <h3>Confirmacion</h3>' +
        '  <p>' + mensaje + '</p>' +
        '  <div class="barra-botones">' +
        '    <button type="button" class="btn btn-peligro" data-accion="aceptar">Si, continuar</button>' +
        '    <button type="button" class="btn btn-secundario" data-accion="cancelar">Cancelar</button>' +
        '  </div>' +
        '</div>';
    document.body.appendChild(fondo);

    function cerrar() { document.body.removeChild(fondo); }

    fondo.querySelector('[data-accion="aceptar"]').addEventListener('click', function () {
        cerrar();
        if (typeof alConfirmar === 'function') alConfirmar();
    });
    fondo.querySelector('[data-accion="cancelar"]').addEventListener('click', cerrar);
    fondo.addEventListener('click', function (ev) { if (ev.target === fondo) cerrar(); });
}
