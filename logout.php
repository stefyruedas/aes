<?php
/**
 * Cierra la sesion del usuario y vuelve al inicio de sesion.
 */

require_once __DIR__ . '/includes/funciones.php';
iniciar_sesion_app();

$u = usuario_actual();
if ($u) {
    $upd = db()->prepare('UPDATE usuario SET ultimo_cierre_sesion = NOW() WHERE id = ?');
    $upd->execute([$u['id']]);
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();

header('Location: ' . url('/index.php'));
exit;
