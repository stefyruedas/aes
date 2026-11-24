<?php
/**
 * Interfaz de registro de nuevos usuarios.
 * El registro queda en estado 'pendiente' hasta validar el codigo enviado
 * al correo (simulado). Tras guardar, redirige a la validacion de perfil.
 */
require_once __DIR__ . '/includes/funciones.php';
iniciar_sesion_app();

$errores = [];
$datos = [
    'nombre' => '', 'tipo_identificacion' => 'CC', 'dni' => '',
    'telefono' => '', 'email' => '', 'usuario' => '', 'id_rol' => '2',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verificar_csrf()) {
        $errores[] = 'Sesion expirada. Intenta nuevamente.';
    } else {
        foreach ($datos as $k => $_) {
            $datos[$k] = trim($_POST[$k] ?? '');
        }
        $clave  = $_POST['contrasena'] ?? '';
        $clave2 = $_POST['contrasena2'] ?? '';

        if ($datos['nombre'] === '')  $errores[] = 'El nombre completo es obligatorio.';
        if ($datos['dni'] === '')     $errores[] = 'El numero de identificacion es obligatorio.';
        if ($datos['telefono'] === '') $errores[] = 'El telefono es obligatorio.';
        if (!filter_var($datos['email'], FILTER_VALIDATE_EMAIL)) $errores[] = 'El correo electronico no es valido.';
        if ($datos['usuario'] === '') $errores[] = 'El nombre de usuario es obligatorio.';
        if (!in_array($datos['id_rol'], ['1', '2'], true)) $errores[] = 'Selecciona un rol valido.';
        if (strlen($clave) < 6)       $errores[] = 'La contrasena debe tener al menos 6 caracteres.';
        if ($clave !== $clave2)       $errores[] = 'Las contrasenas no coinciden.';

        // Unicidad
        if (!$errores) {
            $stmt = db()->prepare('SELECT COUNT(*) FROM usuario WHERE usuario = ? OR email = ? OR dni = ?');
            $stmt->execute([$datos['usuario'], $datos['email'], $datos['dni']]);
            if ((int) $stmt->fetchColumn() > 0) {
                $errores[] = 'Ya existe un usuario con ese nombre de usuario, correo o identificacion.';
            }
        }

        if (!$errores) {
            $hash = password_hash($clave, PASSWORD_DEFAULT);
            $ins = db()->prepare(
                'INSERT INTO usuario (id_rol, usuario, contrasena, telefono, nombre, email, tipo_identificacion, dni, estado)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, "pendiente")'
            );
            $ins->execute([
                (int) $datos['id_rol'], $datos['usuario'], $hash, $datos['telefono'],
                $datos['nombre'], $datos['email'], $datos['tipo_identificacion'], $datos['dni'],
            ]);
            $idUsuario = (int) db()->lastInsertId();

            // Genera y "envia" el codigo de activacion.
            $codigo = crear_y_enviar_codigo($idUsuario, $datos['email'], 'activacion');

            // En desarrollo mostramos el codigo en la pantalla de validacion.
            $_SESSION['validacion'] = [
                'id_usuario' => $idUsuario,
                'email'      => $datos['email'],
                'codigo_dev' => MODO_DESARROLLO ? $codigo : null,
            ];

            flash('exito', 'Registro creado. Ingresa el codigo de activacion enviado a tu correo.');
            redirigir('/validar_perfil.php');
        }
    }
}

$tituloPagina = 'Registro de usuario';
$mostrarBarra = false;
require __DIR__ . '/includes/header.php';
?>

<div class="contenedor-estrecho">
    <div class="caja-acceso">
        <div class="encabezado-acceso">
            <h1>Crear cuenta</h1>
            <p><?= e(APP_NAME) ?></p>
        </div>

        <?php foreach ($errores as $err): ?>
            <div class="alerta alerta-error"><?= e($err) ?></div>
        <?php endforeach; ?>

        <form method="post" novalidate>
            <?= csrf_campo() ?>

            <fieldset class="seccion-form">
                <legend>Datos personales</legend>
                <div class="campo">
                    <label for="nombre">Nombre completo <span class="obligatorio">*</span></label>
                    <input type="text" id="nombre" name="nombre" value="<?= e($datos['nombre']) ?>" required>
                </div>
                <div class="campo">
                    <label for="tipo_identificacion">Tipo de identificacion <span class="obligatorio">*</span></label>
                    <select id="tipo_identificacion" name="tipo_identificacion">
                        <?php foreach (['CC' => 'Cedula de ciudadania', 'CE' => 'Cedula de extranjeria', 'PA' => 'Pasaporte', 'TI' => 'Tarjeta de identidad'] as $v => $txt): ?>
                            <option value="<?= e($v) ?>" <?= $datos['tipo_identificacion'] === $v ? 'selected' : '' ?>><?= e($txt) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="campo">
                    <label for="dni">Numero de identificacion <span class="obligatorio">*</span></label>
                    <input type="text" id="dni" name="dni" value="<?= e($datos['dni']) ?>" required>
                </div>
                <div class="campo">
                    <label for="telefono">Telefono <span class="obligatorio">*</span></label>
                    <input type="tel" id="telefono" name="telefono" value="<?= e($datos['telefono']) ?>" required>
                </div>
                <div class="campo">
                    <label for="email">Correo electronico <span class="obligatorio">*</span></label>
                    <input type="email" id="email" name="email" value="<?= e($datos['email']) ?>" required>
                </div>
            </fieldset>

            <fieldset class="seccion-form">
                <legend>Credenciales de acceso</legend>
                <div class="campo">
                    <label for="usuario">Nombre de usuario <span class="obligatorio">*</span></label>
                    <input type="text" id="usuario" name="usuario" value="<?= e($datos['usuario']) ?>" required>
                </div>
                <div class="campo">
                    <label for="id_rol">Rol <span class="obligatorio">*</span></label>
                    <select id="id_rol" name="id_rol">
                        <option value="1" <?= $datos['id_rol'] === '1' ? 'selected' : '' ?>>Administrativo</option>
                        <option value="2" <?= $datos['id_rol'] === '2' ? 'selected' : '' ?>>Operario</option>
                    </select>
                </div>
                <div class="campo">
                    <label for="contrasena">Contrasena <span class="obligatorio">*</span></label>
                    <input type="password" id="contrasena" name="contrasena" required>
                    <span class="texto-ayuda">Minimo 6 caracteres.</span>
                </div>
                <div class="campo">
                    <label for="contrasena2">Confirmar contrasena <span class="obligatorio">*</span></label>
                    <input type="password" id="contrasena2" name="contrasena2" required>
                </div>
            </fieldset>

            <div class="barra-botones">
                <button type="submit" class="btn btn-primario">Enviar informacion</button>
                <a class="btn btn-secundario" href="<?= e(url('index.php')) ?>">Volver al inicio</a>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
