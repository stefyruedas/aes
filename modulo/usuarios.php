<?php
/**
 * Modulo de gestión de usuarios - rol administrador
 * Este modulo tiene 5 funciones principales:
 * - Consultar los usuarios registrados
 * - Buscar y filtrar por nombre, correo, rol o estado
 * - Editar los datos de usuario
 * - Activar o desactivar cuentas
 * - Validar solicitudes de registro pendiente
 */

require_once __DIR__ . '/../includes/funciones.php';
requerir_rol(['administrador']);

$u       = usuario_actual();
$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verificar_csrf()) {
        $errores[] = 'Sesion expirada. Intenta nuevamente.';
    } else {
        $accion = $_POST['accion'] ?? '';
        $id     = (int) ($_POST['id'] ?? 0);

        // Evitar que el admin se bloquee a si mismo.
        $esMismo = ($id === (int) $u['id']);

        if ($accion === 'actualizar') {
            $nombre   = trim($_POST['nombre'] ?? '');
            $email    = trim($_POST['email'] ?? '');
            $telefono = trim($_POST['telefono'] ?? '');
            $idRol    = (int) ($_POST['id_rol'] ?? 0);
            $estado   = $_POST['estado'] ?? 'activo';

            if ($nombre === '') $errores[] = 'El nombre es obligatorio.';
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errores[] = 'El correo no es valido.';
            if (!in_array($idRol, [1, 2], true)) $errores[] = 'Rol invalido.';
            if (!in_array($estado, ['pendiente','activo','inactivo'], true)) $estado = 'activo';
            if ($esMismo && $estado !== 'activo') $errores[] = 'No puedes desactivar tu propia cuenta.';

            // Unicidad de correo (excluyendo al propio usuario)
            if (!$errores) {
                $stmt = db()->prepare('SELECT COUNT(*) FROM usuario WHERE email = ? AND id <> ?');
                $stmt->execute([$email, $id]);
                if ((int) $stmt->fetchColumn() > 0) $errores[] = 'Ya existe otro usuario con ese correo.';
            }

            if (!$errores) {
                db()->prepare(
                    'UPDATE usuario SET nombre=?, email=?, telefono=?, id_rol=?, estado=? WHERE id=?'
                )->execute([$nombre, $email, $telefono, $idRol, $estado, $id]);
                flash('exito', 'Usuario actualizado correctamente.');
                redirigir('/modulos/usuarios.php');
            }
        }

        if ($accion === 'cambiar_estado') {
            $nuevo = $_POST['estado'] ?? 'activo';
            if (!in_array($nuevo, ['activo','inactivo'], true)) $nuevo = 'activo';
            if ($esMismo) {
                flash('error', 'No puedes cambiar el estado de tu propia cuenta.');
            } else {
                db()->prepare('UPDATE usuario SET estado=? WHERE id=?')->execute([$nuevo, $id]);
                flash('exito', 'Estado actualizado a ' . $nuevo . '.');
            }
            redirigir('/modulos/usuarios.php');
        }

        if ($accion === 'aprobar') {
            db()->prepare("UPDATE usuario SET estado='activo' WHERE id=?")->execute([$id]);
            flash('exito', 'Perfil validado y activado.');
            redirigir('/modulos/usuarios.php');
        }

        if ($accion === 'rechazar') {
            if ($esMismo) {
                flash('error', 'No puedes rechazar tu propia cuenta.');
            } else {
                // Rechazar = eliminar la solicitud pendiente.
                db()->prepare("DELETE FROM usuario WHERE id=? AND estado='pendiente'")->execute([$id]);
                flash('info', 'Solicitud de registro rechazada.');
            }
            redirigir('/modulos/usuarios.php');
        }
    }
}

/* ---------------- Filtros ---------------- */
$q       = trim($_GET['q'] ?? '');
$rolF    = $_GET['rol'] ?? '';
$estadoF = $_GET['estado'] ?? '';

$sql = 'SELECT u.*, r.nombre_rol
        FROM usuario u JOIN rol r ON r.id = u.id_rol
        WHERE 1=1';
$params = [];
if ($q !== '') {
    $sql .= ' AND (u.nombre LIKE ? OR u.email LIKE ? OR u.usuario LIKE ? OR u.dni LIKE ?)';
    array_push($params, "%$q%", "%$q%", "%$q%", "%$q%");
}
if (in_array($rolF, ['administrador','operario'], true)) {
    $sql .= ' AND r.nombre_rol = ?';
    $params[] = $rolF;
}
if (in_array($estadoF, ['pendiente','activo','inactivo'], true)) {
    $sql .= ' AND u.estado = ?';
    $params[] = $estadoF;
}
$sql .= ' ORDER BY (u.estado = "pendiente") DESC, u.nombre';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$usuarios = $stmt->fetchAll();

// Usuario en edicion
$editar = null;
if (isset($_GET['editar'])) {
    $stmt = db()->prepare('SELECT * FROM usuario WHERE id = ?');
    $stmt->execute([(int) $_GET['editar']]);
    $editar = $stmt->fetch() ?: null;
}

// Conteo de pendientes (para el aviso)
$pendientes = 0;
foreach ($usuarios as $usr) { if ($usr['estado'] === 'pendiente') $pendientes++; }

function chip_estado_usuario(string $estado): string
{
    $map = ['activo' => 'chip-activo', 'pendiente' => 'chip-pendiente', 'inactivo' => 'chip-inactivo'];
    return '<span class="chip ' . ($map[$estado] ?? 'chip-inactivo') . '">' . e(ucfirst($estado)) . '</span>';
}

$tituloPagina = 'Gestion de usuarios';
$mostrarBarra = true;
require __DIR__ . '/../includes/header.php';
?>

<div class="contenedor">
<?php foreach ($errores as $err): ?>
    <div class="alerta alerta-error"><?= e($err) ?></div>
<?php endforeach; ?>

<?php if ($pendientes > 0): ?>
    <div class="alerta alerta-info">
        Hay <strong><?= $pendientes ?></strong> solicitud(es) de registro pendiente(s) de validacion.
    </div>
<?php endif; ?>

    <div class="tarjeta">
        <h2>Gestion de usuarios</h2>

        <form method="get" class="barra-filtros">
            <div class="campo">
                <label for="q">Buscar (nombre, correo, usuario o documento)</label>
                <input type="search" id="q" name="q" value="<?= e($q) ?>">
            </div>
            
            <div class="campo">
                <label for="rol">Rol</label>
                <select id="rol" name="rol">
                    <option value="">Todos</option>
                    <option value="administrador" <?= $rolF === 'administrador' ? 'selected' : '' ?>>Administrador</option>
                    <option value="operario" <?= $rolF === 'operario' ? 'selected' : '' ?>>Operario</option>
                </select>
            </div>
            
            <div class="campo">
                <label for="estado">Estado</label>
                <select id="estado" name="estado">
                    <option value="">Todos</option>
                    <?php foreach (['pendiente','activo','inactivo'] as $op): ?>
                        <option value="<?= $op ?>" <?= $estadoF === $op ? 'selected' : '' ?>><?= ucfirst($op) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <button type="submit" class="btn btn-primario">Buscar / Filtrar</button>
            <a class="btn btn-secundario" href="<?= e(url('modulos/usuarios.php')) ?>">Limpiar</a>
        </form>

        <div class="tabla-responsive" style="margin-top:1rem;">
            <table class="tabla-aes">
                <thead>
                    <tr>
                        <th>Nombre completo</th><th>Correo</th><th>Rol</th><th>Estado</th>
                        <th>Ultimo inicio</th><th>Ultimo cierre</th><th>Acciones</th>
                    </tr>
                </thead>
                
                <tbody>
                <?php if (!$usuarios): ?>
                    <tr><td colspan="7">No se encontraron usuarios.</td></tr>
                
                <?php else: foreach ($usuarios as $usr):
                    $esMismo = ((int) $usr['id'] === (int) $u['id']); ?>
                    <tr>
                        <td><?= e($usr['nombre']) ?><?= $esMismo ? ' <span class="texto-ayuda">(tu)</span>' : '' ?></td>
                        <td><?= e($usr['email']) ?></td>
                        <td><?= e(ucfirst($usr['nombre_rol'])) ?></td>
                        <td><?= chip_estado_usuario($usr['estado']) ?></td>
                        <td><?= e($usr['ultimo_inicio_sesion'] ?: '-') ?></td>
                        <td><?= e($usr['ultimo_cierre_sesion'] ?: '-') ?></td>
                        <td>
                            
                            <?php if ($usr['estado'] === 'pendiente'): ?>
                                <form method="post" style="display:inline" data-confirmar="Aprobar y activar a <?= e($usr['nombre']) ?>?">
                                    <?= csrf_campo() ?>
                                    <input type="hidden" name="accion" value="aprobar">
                                    <input type="hidden" name="id" value="<?= (int) $usr['id'] ?>">
                                    <button type="submit" class="btn btn-primario">Validar</button>
                                </form>
                                
                                <form method="post" style="display:inline" data-confirmar="Rechazar la solicitud de <?= e($usr['nombre']) ?>? Se eliminara.">
                                    <?= csrf_campo() ?>
                                    <input type="hidden" name="accion" value="rechazar">
                                    <input type="hidden" name="id" value="<?= (int) $usr['id'] ?>">
                                    <button type="submit" class="btn btn-peligro">Rechazar</button>
                                </form>
                            
                            <?php else: ?>
                                <a class="btn btn-secundario" href="<?= e(url('modulos/usuarios.php?editar=' . $usr['id'])) ?>">Editar</a>
                                
                                <?php if (!$esMismo): ?>
                                    <?php $siguiente = $usr['estado'] === 'activo' ? 'inactivo' : 'activo'; ?>
                                    <form method="post" style="display:inline" data-confirmar="Cambiar estado de <?= e($usr['nombre']) ?> a <?= $siguiente ?>?">
                                        <?= csrf_campo() ?>
                                        <input type="hidden" name="accion" value="cambiar_estado">
                                        <input type="hidden" name="id" value="<?= (int) $usr['id'] ?>">
                                        <input type="hidden" name="estado" value="<?= $siguiente ?>">
                                        <button type="submit" class="btn <?= $usr['estado'] === 'activo' ? 'btn-peligro' : 'btn-primario' ?>">
                                            <?= $usr['estado'] === 'activo' ? 'Desactivar' : 'Activar' ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <div class="barra-botones">
            <a class="btn btn-secundario" href="<?= e(url('modulos/menu.php')) ?>">Regresar al menu principal</a>
        </div>
    </div>

    <?php if ($editar): ?>
    <div class="tarjeta">
        <h2>Editar usuario</h2>
        <?php $val = fn($k, $d = '') => e($_POST[$k] ?? ($editar[$k] ?? $d)); ?>
        <form method="post" data-confirmar="Confirmas guardar los cambios de este usuario?" novalidate>
            <?= csrf_campo() ?>
            <input type="hidden" name="accion" value="actualizar">
            <input type="hidden" name="id" value="<?= (int) $editar['id'] ?>">
            
            <div class="seccion-form">
                <div class="campo">
                    <label>Nombre completo <span class="obligatorio">*</span></label>
                    <input type="text" name="nombre" value="<?= $val('nombre') ?>" required>
                </div>
                
                <div class="campo">
                    <label>Correo electronico <span class="obligatorio">*</span></label>
                    <input type="email" name="email" value="<?= $val('email') ?>" required>
                </div>
                
                <div class="campo">
                    <label>Telefono</label>
                    <input type="tel" name="telefono" value="<?= $val('telefono') ?>">
                </div>
                
                <div class="campo">
                    <label>Rol <span class="obligatorio">*</span></label>
                    <select name="id_rol">
                        <option value="1" <?= (int) ($editar['id_rol']) === 1 ? 'selected' : '' ?>>Administrador</option>
                        <option value="2" <?= (int) ($editar['id_rol']) === 2 ? 'selected' : '' ?>>Operario</option>
                    </select>
                </div>
                
                <div class="campo">
                    <label>Estado</label>
                    <select name="estado">
                        <?php foreach (['activo','inactivo','pendiente'] as $op): ?>
                            <option value="<?= $op ?>" <?= $editar['estado'] === $op ? 'selected' : '' ?>><?= ucfirst($op) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="barra-botones">
                <button type="submit" class="btn btn-primario">Guardar cambios</button>
                <a class="btn btn-secundario" href="<?= e(url('modulos/usuarios.php')) ?>">Cancelar</a>
            </div>
        </form>
    </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
