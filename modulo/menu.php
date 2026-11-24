<?php
/**
 * Menu principal.
 * Muestra los botones de los modulos en dos columnas, segun el rol.
 */
require_once __DIR__ . '/../includes/funciones.php';
requerir_login();

$esAdmin = es_administrador();

/*
 * Cada modulo: etiqueta, archivo, icono y si requiere ser administrador.
 * El orden se conserva igual para ambos roles (los no permitidos se ocultan).
 */
$modulos = [
    ['Reservas',             'reservas.php',      '🗓️', false],
    ['Check-in',             'checkin.php',       '🔑', false],
    ['Check-out',            'checkout.php',      '🧾', false],
    ['Inventario',           'inventario.php',    '📦', false],
    ['Finanzas',             'finanzas.php',      '💰', true],
    ['Servicios adicionales','servicios.php',     '🛎️', false],
    ['Disponibilidad',       'disponibilidad.php','🏨', false],
    ['Gestion de usuarios',  'usuarios.php',      '👥', true],
];

$tituloPagina = 'Menu principal';
$mostrarBarra = true;
require __DIR__ . '/../includes/header.php';
?>

<div class="contenedor">
    <div class="tarjeta">
        <h2>Menu principal</h2>
        <p>Bienvenido, <strong><?= e($u['nombre']) ?></strong>. Selecciona un modulo para comenzar.</p>

        <div class="menu-grid">
            <?php foreach ($modulos as [$etiqueta, $archivo, $icono, $soloAdmin]): ?>
                <?php if ($soloAdmin && !$esAdmin) continue; ?>
                <a class="menu-boton" href="<?= e(url('modulos/' . $archivo)) ?>">
                    <span class="icono" aria-hidden="true"><?= $icono ?></span>
                    <span><?= e($etiqueta) ?></span>
                </a>
            <?php endforeach; ?>

            <a class="menu-boton cerrar" href="<?= e(url('logout.php')) ?>">
                <span class="icono" aria-hidden="true">🚪</span>
                <span>Cerrar sesion</span>
            </a>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
