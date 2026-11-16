<?php  
/** Interfaz de recuperación de contrasena.
 * Flujo:
 *   1. El usuario ingresa su correo y solicita un código.
 *   2. Ingresa el código (4 dígitos, vigencia 10 min) + nueva contraseña.
 *   3. Se valida el código y se guarda la nueva contraseña.
 */

require_once __DIR__ . '/includes/funciones.php';
iniciar_sesion_app();

$error = '';
$exito = '';
$paso  = $_SESSION['recup']['paso'] ?? 1;
$email = $_SESSION['recup']['email'] ?? '';
$codigoDev = null;

// Detectar si el usuario envio el formulario - Metodo post
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verificar_csrf()) {
        $error = 'Sesion expirada. Intenta nuevamente.';
    } else {
        $accion = $_POST['accion'] ?? '';

        if ($accion === 'enviar_codigo') {
            $email = trim($_POST['email'] ?? '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Ingresa un correo valido.';
            } else {
                $stmt = db()->prepare('SELECT id, estado FROM usuario WHERE email = ? LIMIT 1');
                $stmt->execute([$email]);
                $fila = $stmt->fetch();

                // Por seguridad no revelamos si el correo existe; el mensaje es neutro.
                if ($fila && $fila['estado'] !== 'inactivo') {
                    $codigo = crear_y_enviar_codigo((int) $fila['id'], $email, 'recuperacion');
                    $_SESSION['recup'] = [
                        'paso'       => 2,
                        'email'      => $email,
                        'id_usuario' => (int) $fila['id'],
                        'codigo_dev' => MODO_DESARROLLO ? $codigo : null,
                    ];
                }
                $_SESSION['recup']['paso']  = 2;
                $_SESSION['recup']['email'] = $email;
                flash('info', 'Si el correo esta registrado, recibiras un codigo de recuperacion.');
                redirigir('/recuperar.php');
            }
        } elseif ($accion === 'cambiar') {
            $idUsuario = $_SESSION['recup']['id_usuario'] ?? null;
            $email     = $_SESSION['recup']['email'] ?? '';
            $codigo    = trim($_POST['codigo'] ?? '');
            $clave     = $_POST['contrasena'] ?? '';
            $clave2    = $_POST['contrasena2'] ?? '';

            if (!$idUsuario) {
                $error = 'No hay una solicitud de recuperacion activa.';
            } elseif (!preg_match('/^\d{4}$/', $codigo)) {
                $error = 'El codigo debe tener 4 digitos.';
            } elseif (strlen($clave) < 6) {
                $error = 'La nueva contrasena debe tener al menos 6 caracteres.';
            } elseif ($clave !== $clave2) {
                $error = 'Las contrasenas no coinciden.';
            } elseif (!verificar_codigo((int) $idUsuario, $codigo, 'recuperacion')) {
                $error = 'Codigo incorrecto o vencido (vigencia ' . CODIGO_VIGENCIA_MIN . ' min).';
            } else {
                $hash = password_hash($clave, PASSWORD_DEFAULT);
                $upd = db()->prepare('UPDATE usuario SET contrasena = ? WHERE id = ?');
                $upd->execute([$hash, (int) $idUsuario]);
                unset($_SESSION['recup']);
                flash('exito', 'Contrasena actualizada. Ya puedes iniciar sesion.');
                redirigir('/index.php');
            }
        }
    }
}

// Actualizar variables tras posibles cambios de sesion.
$paso = $_SESSION['recup']['paso'] ?? 1;
$email = $_SESSION['recup']['email'] ?? $email;
$codigoDev = $_SESSION['recup']['codigo_dev'] ?? null;

// Encabezado de la pagina
$tituloPagina = 'Recuperar contrasena';
$mostrarBarra = false;
require __DIR__ . '/includes/header.php';
?>

<div class="contenedor-estrecho">
    <div class="caja-acceso">
        <div class="encabezado-acceso">
            <h1>Recuperar contrasena</h1>
        </div>

        <?php if ($error): ?>
            <div class="alerta alerta-error"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if (MODO_DESARROLLO && $codigoDev): ?>
            <div class="aviso-dev">
                Modo desarrollo &mdash; codigo de recuperacion:
                <strong><?= e($codigoDev) ?></strong>
            </div>
        <?php endif; ?>

        <?php if ($paso === 1): ?>
            <form method="post" novalidate>
                <?= csrf_campo() ?>
                <input type="hidden" name="accion" value="enviar_codigo">
                <div class="campo">
                    <label for="email">Correo electronico registrado <span class="obligatorio">*</span></label>
                    <input type="email" id="email" name="email" value="<?= e($email) ?>" required autofocus>
                </div>
                <div class="barra-botones">
                    <button type="submit" class="btn btn-primario">Enviar codigo de recuperacion</button>
                    <a class="btn btn-secundario" href="<?= e(url('index.php')) ?>">Volver al inicio de sesion</a>
                </div>
            </form>
        
        <?php else: ?>
            <p class="texto-ayuda">Se envio un codigo a <strong><?= e($email) ?></strong>.</p>
            <form method="post" novalidate>
                <?= csrf_campo() ?>
                <input type="hidden" name="accion" value="cambiar">
                <div class="campo">
                    <label for="codigo">Codigo recibido (4 digitos) <span class="obligatorio">*</span></label>
                    <input type="text" id="codigo" name="codigo" maxlength="4" inputmode="numeric" pattern="\d{4}" required autofocus>
                </div>
                <div class="campo">
                    <label for="contrasena">Nueva contrasena <span class="obligatorio">*</span></label>
                    <input type="password" id="contrasena" name="contrasena" required>
                    <span class="texto-ayuda">Minimo 6 caracteres.</span>
                </div>
                <div class="campo">
                    <label for="contrasena2">Confirmar nueva contrasena <span class="obligatorio">*</span></label>
                    <input type="password" id="contrasena2" name="contrasena2" required>
                </div>
                <div class="barra-botones">
                    <button type="submit" class="btn btn-primario">Guardar nueva contrasena</button>
                    <a class="btn btn-secundario" href="<?= e(url('recuperar.php?reiniciar=1')) ?>">Usar otro correo</a>
                </div>
            </form>
       
        <?php endif; ?>

        <div class="enlaces-acceso">
            <a href="<?= e(url('index.php')) ?>">Volver al inicio de sesion</a>
        </div>
    </div>
</div>

<?php

// Permite reiniciar el proceso de recuperación.
if (isset($_GET['reiniciar'])) {
    unset($_SESSION['recup']);
    echo '<script>window.location.href="' . e(url('recuperar.php')) . '";</script>';
}
require __DIR__ . '/includes/footer.php';

?>