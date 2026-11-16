<?php
/**
 * Interfaz de inicio de sesión (index).
 */

require_once __DIR__ . '/includes/funciones.php';
iniciar_sesion_app();

//Si ya existe un usuario que inicio sesión correctamente ir al menú.
if (usuario_actual()) {
    redirigir('/modulos/menu.php');
                      }

$error = '';
$usuarioIngresado = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verificar_csrf()){
        $error = 'Sesion expirada. Intenta nuevamente.';
    } else {
        $usuarioIngresado = trim($_POST['usuario'] ?? '');
        $clave = $_POST['contrasena'] ?? '';

        if ($usuarioIngresado === '' || $clave === '') {
            $error = 'Debes ingresar usuario/correo y contrasena.';
        } else {
            //Permite iniciar sesion con nombre de usuario o correo.
            $stmt = db()->prepare(
                'SELECT u.*, r.nombre_rol
                 FROM usuario u
                 JOIN rol r ON r.id = u.id_rol
                 WHERE u.usuario = ? OR u.email = ?
                 LIMIT 1'
            );
            $stmt->execute([$usuarioIngresado, $usuarioIngresado]);
            $fila = $stmt->fetch();

            if (!$fila || !password_verify($clave, $fila['contrasena'])) {
                $error = 'Credenciales incorrectas. Verifica tu usuario y contrasena.';
            } elseif ($fila['estado'] === 'pendiente') {
                $error = 'Tu cuenta esta pendiente de validacion. Ingresa el codigo enviado a tu correo.';
            } elseif ($fila['estado'] === 'inactivo') {
                $error = 'Tu cuenta esta inactiva. Contacta al administrador.';
            } else {
                // Si el acceso es correcto registra el inicio de sesion.
                $upd = db()->prepare('UPDATE usuario SET ultimo_inicio_sesion = NOW() WHERE id = ?');
                $upd->execute([$fila['id']]);

                // Regenera el id de sesion para evitar fijacion de sesion.
                session_regenerate_id(true);
                $_SESSION['usuario'] = [
                    'id'      => (int) $fila['id'],
                    'nombre'  => $fila['nombre'],
                    'email'   => $fila['email'],
                    'usuario' => $fila['usuario'],
                    'rol'     => $fila['nombre_rol'],   
                ];
                redirigir('/modulos/menu.php');
            }
        }
    }
}

// Termina el proceso de autentificacion. Inicia la configuracion de la pagina y presentacion visual

$tituloPagina = 'Iniciar sesion';
$mostrarBarra = false;
require __DIR__ . '/includes/header.php';
?>

<div class="contenedor-estrecho">
    <div class="caja-acceso">
        <div class="encabezado-acceso">
            <!-- LOGO: cambia el src por la ruta de tu logo -->
            <img class="logo-aes" src="<?= e(url('assets/img/logo.png')) ?>" alt="Logo <?= e(HOSTAL_NOMBRE) ?>"
                 onerror="this.style.display='none'">
            <h1><?= e(APP_NAME) ?></h1>
            <p><?= e(HOSTAL_NOMBRE) ?></p>
        </div>
        
        <?php if ($error): ?>
            <div class="alerta alerta-error"><?= e($error) ?></div>
        <?php endif; ?>

        <!-- Creación del formulario -->
        <form method="post" novalidate>
            <?= csrf_campo() ?>
            <div class="campo">
                <label for="usuario">Usuario o correo electronico <span class="obligatorio">*</span></label>
                <input type="text" id="usuario" name="usuario" value="<?= e($usuarioIngresado) ?>"
                       autocomplete="username" required autofocus>
            </div>
             <div class="campo">
                <label for="contrasena">Contrasena <span class="obligatorio">*</span></label>
                <input type="password" id="contrasena" name="contrasena" autocomplete="current-password" required>
            </div>

            <div class="barra-botones">
                <button type="submit" class="btn btn-primario">Iniciar sesion</button>
                <a class="btn btn-secundario" href="<?= e(url('registro.php')) ?>">Registrarse</a>
            </div>
        </form>

        <div class="enlaces-acceso">
            <a href="<?= e(url('recuperar.php')) ?>">Recuperar contrasena</a>
        </div>

        <!-- Muestra información de prueba para facilitar el desarrollo.
             Desactivar cuando el software se entregue. -->
        
        <?php if (MODO_DESARROLLO): ?>
            <div class="aviso-dev" style="margin-top:1.2rem;">
                <strong style="letter-spacing:normal;font-size:0.9rem;">Modo desarrollo</strong><br>
                Usuario de prueba: <code>admin</code> &nbsp; Contrasena: <code>Admin123</code><br>
                Los correos simulados se guardan en <code>storage/correos.log</code>.
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>