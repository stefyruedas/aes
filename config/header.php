<?php
require_once __DIR__ . '/funciones.php';
iniciar_sesion_app();
$u = usuario_actual();
$tituloPagina = $tituloPagina ?? APP_NAME;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($tituloPagina) ?> | <?= e(APP_NAME) ?></title>

    <!-- Tipografias (se usa la local si existe; si no, se descarga de Google Fonts) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;700&family=Lato:wght@400;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="<?= e(url('assets/css/estilos.css')) ?>">
</head>
<body>

<?php if (!empty($mostrarBarra) && $u): ?>
<header class="barra-superior">
    <div class="marca">
        <!-- LOGO: cambia el src por la ruta de tu logo (ej. assets/img/logo.png) -->
        <img class="logo-aes" src="<?= e(url('assets/img/logo.png')) ?>" alt="Logo <?= e(HOSTAL_NOMBRE) ?>"
             onerror="this.style.display='none'">
        <span class="titulo"><?= e(APP_NAME) ?> &middot; <?= e(HOSTAL_NOMBRE) ?></span>
    </div>
    <div class="usuario-info">
        <span><?= e($u['nombre']) ?></span>
        <span class="pill-rol"><?= e($u['rol']) ?></span>
        <a class="btn btn-secundario" href="<?= e(url('logout.php')) ?>">Cerrar sesion</a>
    </div>
</header>
<?php endif; ?>

<?php foreach (obtener_flash() as $f): ?>
    <div class="contenedor">
        <div class="alerta alerta-<?= e($f['tipo']) ?>"><?= e($f['mensaje']) ?></div>
    </div>
<?php endforeach; ?>
