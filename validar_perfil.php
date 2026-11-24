<?php
/**
 * Interfaz de validacion de perfil.
 * Se ingresan los codigos numericos de 4 digitos enviados al correo.
 * Al confirmar, la cuenta pasa a 'activo' y vuelve al inicio de sesion.
 * Tambien permite reenviar el codigo.
 */
require_once __DIR__ . '/includes/funciones.php';
iniciar_sesion_app();

$validacion = $_SESSION['validacion'] ?? null;

// Si no hay un registro en curso, permite ingresar el correo para reanudar.
$error = '';
$codigoDev = $validacion['codigo_dev'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verificar_csrf()) {
        $error = 'Sesion expirada. Intenta nuevamente.';
    } else {
        $accion = $_POST['accion'] ?? 'confirmar';

        // Resolver el usuario objetivo (desde sesion o por correo).
        $idUsuario = $validacion['id_usuario'] ?? null;
        $email     = $validacion['email'] ?? null;

        if (!$idUsuario) {
            $email = trim($_POST['email'] ?? '');
            if ($email !== '') {
                $stmt = db()->prepare('SELECT id FROM usuario WHERE email = ? AND estado = "pendiente" LIMIT 1');
                $stmt->execute([$email]);
                $idUsuario = $stmt->fetchColumn() ?: null;
            }
        }

        if (!$idUsuario) {
            $error = 'No encontramos un registro pendiente con ese correo.';
        } elseif ($accion === 'reenviar') {
            $codigo = crear_y_enviar_codigo((int) $idUsuario, $email, 'activacion');
            $_SESSION['validacion'] = [
                'id_usuario' => (int) $idUsuario,
                'email'      => $email,
                'codigo_dev' => MODO_DESARROLLO ? $codigo : null,
            ];
            $codigoDev = $_SESSION['validacion']['codigo_dev'];
            flash('info', 'Se reenvio un nuevo codigo a tu correo.');
            redirigir('/validar_perfil.php');
        } else {
            $codigo = trim($_POST['codigo'] ?? '');
            if (!preg_match('/^\d{4}$/', $codigo)) {
                $error = 'El codigo debe tener 4 digitos.';
            } elseif (!verificar_codigo((int) $idUsuario, $codigo, 'activacion')) {
                $error = 'Codigo incorrecto o vencido. Puedes reenviar el codigo.';
            } else {
                $upd = db()->prepare('UPDATE usuario SET estado = "activo" WHERE id = ?');
                $upd->execute([(int) $idUsuario]);
                unset($_SESSION['validacion']);
                flash('exito', 'Cuenta activada correctamente. Ya puedes iniciar sesion.');
                redirigir('/index.php');
            }
        }
    }
}

$tituloPagina = 'Validacion de perfil';
$mostrarBarra = false;
require __DIR__ . '/includes/header.php';
?>

<div class="contenedor-estrecho">
    <div class="caja-acceso">
        <div class="encabezado-acceso">
            <h1>Validacion de perfil</h1>
            <p>Ingresa el codigo de 4 digitos enviado a tu correo</p>
        </div>

        <?php if ($error): ?>
            <div class="alerta alerta-error"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if (MODO_DESARROLLO && $codigoDev): ?>
            <div class="aviso-dev">
                Modo desarrollo &mdash; tu codigo de activacion es:
                <strong><?= e($codigoDev) ?></strong>
                <br>(en produccion llegaria por correo)
            </div>
        <?php endif; ?>

        <form method="post" novalidate>
            <?= csrf_campo() ?>
            <input type="hidden" name="accion" value="confirmar">

            <?php if (!$validacion): ?>
                <div class="campo">
                    <label for="email">Correo registrado <span class="obligatorio">*</span></label>
                    <input type="email" id="email" name="email" required>
                </div>
            <?php endif; ?>

            <div class="campo">
                <label for="codigo">Codigo de activacion (4 digitos) <span class="obligatorio">*</span></label>
                <input type="text" id="codigo" name="codigo" maxlength="4" inputmode="numeric"
                       pattern="\d{4}" required autofocus>
            </div>

            <div class="barra-botones">
                <button type="submit" class="btn btn-primario">Confirmar</button>
            </div>
        </form>

        <form method="post" style="margin-top:0.6rem;">
            <?= csrf_campo() ?>
            <input type="hidden" name="accion" value="reenviar">
            <?php if (!$validacion): ?>
                <div class="campo">
                    <label for="email2">Correo registrado</label>
                    <input type="email" id="email2" name="email">
                </div>
            <?php endif; ?>
            <div class="barra-botones">
                <button type="submit" class="btn btn-secundario">Reenviar codigo</button>
                <a class="btn btn-secundario" href="<?= e(url('index.php')) ?>">Volver al inicio de sesion</a>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
