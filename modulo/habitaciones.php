<?php
/**
 * Solo administrador. Alimenta a los modulos de reservas y disponibilidad.
 */
require_once __DIR__ . '/../includes/reservas_lib.php';
requerir_rol(['administrador']);

$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verificar_csrf()) {
        $errores[] = 'Sesion expirada. Intenta nuevamente.';
    } else {
        $accion = $_POST['accion'] ?? '';

        if ($accion === 'guardar' || $accion === 'actualizar') {
            $id         = $accion === 'actualizar' ? (int) ($_POST['id'] ?? 0) : null;
            $nombre     = trim($_POST['nombre'] ?? '');
            $numero     = trim($_POST['numero'] ?? '');
            $tipo       = trim($_POST['tipo'] ?? '');
            $capacidad  = max(1, (int) ($_POST['capacidad'] ?? 1));
            $precio     = (float) ($_POST['precio_noche'] ?? 0);
            $estado     = $_POST['estado'] ?? 'disponible';

            if ($nombre === '') $errores[] = 'El nombre de la habitacion es obligatorio.';
            if ($numero === '') $errores[] = 'El numero de habitacion es obligatorio.';
            if ($tipo === '')   $errores[] = 'El tipo de habitacion es obligatorio.';
            if ($precio <= 0)   $errores[] = 'El precio por noche debe ser mayor a cero.';
            if (!in_array($estado, ['disponible','ocupada','mantenimiento'], true)) {
                $estado = 'disponible';
            }

            // Numero unico dentro del hostal
            if (!$errores) {
                $sql = 'SELECT COUNT(*) FROM habitacion WHERE id_hostal = ? AND numero = ?';
                $params = [hostal_id_actual(), $numero];
                if ($id) { $sql .= ' AND id <> ?'; $params[] = $id; }
                $stmt = db()->prepare($sql);
                $stmt->execute($params);
                if ((int) $stmt->fetchColumn() > 0) {
                    $errores[] = 'Ya existe una habitacion con el numero ' . $numero . '.';
                }
            }

            if (!$errores) {
                if ($accion === 'guardar') {
                    $ins = db()->prepare(
                        'INSERT INTO habitacion (id_hostal, nombre, numero, tipo, capacidad, precio_noche, estado)
                         VALUES (?,?,?,?,?,?,?)'
                    );
                    $ins->execute([hostal_id_actual(), $nombre, $numero, $tipo, $capacidad, $precio, $estado]);
                    flash('exito', 'Habitacion creada correctamente.');
                } else {
                    $upd = db()->prepare(
                        'UPDATE habitacion SET nombre=?, numero=?, tipo=?, capacidad=?, precio_noche=?, estado=? WHERE id=?'
                    );
                    $upd->execute([$nombre, $numero, $tipo, $capacidad, $precio, $estado, $id]);
                    flash('exito', 'Habitacion actualizada correctamente.');
                }
                redirigir('/modulos/habitaciones.php');
            }
        }

        if ($accion === 'eliminar') {
            $id = (int) ($_POST['id'] ?? 0);
            // Evitar borrar si tiene reservas asociadas
            $stmt = db()->prepare('SELECT COUNT(*) FROM reservas WHERE id_habitacion = ?');
            $stmt->execute([$id]);
            if ((int) $stmt->fetchColumn() > 0) {
                flash('error', 'No se puede eliminar: la habitacion tiene reservas asociadas. Puedes ponerla en mantenimiento.');
            } else {
                $del = db()->prepare('DELETE FROM habitacion WHERE id = ?');
                $del->execute([$id]);
                flash('info', 'Habitacion eliminada.');
            }
            redirigir('/modulos/habitaciones.php');
        }
    }
}

// Habitacion en edicion (si ?editar=ID)
$editar = null;
if (isset($_GET['editar'])) {
    $editar = obtener_habitacion((int) $_GET['editar']);
}

$habitaciones = listar_habitaciones();

$tituloPagina = 'Habitaciones';
$mostrarBarra = true;
require __DIR__ . '/../includes/header.php';
?>

<div class="contenedor">
<?php foreach ($errores as $err): ?>
    <div class="alerta alerta-error"><?= e($err) ?></div>
<?php endforeach; ?>

    <div class="tarjeta">
        <h2><?= $editar ? 'Editar habitacion' : 'Nueva habitacion' ?></h2>
        <?php $val = fn($k, $d = '') => e($_POST[$k] ?? ($editar[$k] ?? $d)); ?>
        <form method="post" novalidate>
            <?= csrf_campo() ?>
            <input type="hidden" name="accion" value="<?= $editar ? 'actualizar' : 'guardar' ?>">
            <?php if ($editar): ?><input type="hidden" name="id" value="<?= (int) $editar['id'] ?>"><?php endif; ?>

            <div class="seccion-form">
                <div class="campo">
                    <label>Nombre <span class="obligatorio">*</span></label>
                    <input type="text" name="nombre" value="<?= $val('nombre') ?>" required>
                </div>
                <div class="campo">
                    <label>Numero <span class="obligatorio">*</span></label>
                    <input type="text" name="numero" value="<?= $val('numero') ?>" required>
                </div>
                <div class="campo">
                    <label>Tipo <span class="obligatorio">*</span></label>
                    <input type="text" name="tipo" value="<?= $val('tipo') ?>" list="tipos" required>
                    <datalist id="tipos">
                        <?php foreach (['Individual','Doble','Familiar','Suite'] as $t): ?>
                            <option value="<?= $t ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div class="campo">
                    <label>Capacidad <span class="obligatorio">*</span></label>
                    <input type="number" name="capacidad" min="1" value="<?= $val('capacidad', '1') ?>" required>
                </div>
                <div class="campo">
                    <label>Precio por noche <span class="obligatorio">*</span></label>
                    <input type="number" name="precio_noche" min="0" step="1000" value="<?= $val('precio_noche') ?>" required>
                </div>
                <div class="campo">
                    <label>Estado</label>
                    <select name="estado">
                        <?php foreach (['disponible','ocupada','mantenimiento'] as $op): ?>
                            <option value="<?= $op ?>" <?= ($_POST['estado'] ?? ($editar['estado'] ?? 'disponible')) === $op ? 'selected' : '' ?>><?= ucfirst($op) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="barra-botones">
                <button type="submit" class="btn btn-primario"><?= $editar ? 'Guardar cambios' : 'Agregar habitacion' ?></button>
                <?php if ($editar): ?>
                    <a class="btn btn-secundario" href="<?= e(url('modulos/habitaciones.php')) ?>">Cancelar edicion</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="tarjeta">
        <h2>Habitaciones registradas</h2>
        <div class="tabla-responsive">
            <table class="tabla-aes">
                <thead>
                    <tr><th>N.°</th><th>Nombre</th><th>Tipo</th><th>Cap.</th><th>Precio/noche</th><th>Estado</th><th>Acciones</th></tr>
                </thead>
                <tbody>
                <?php if (!$habitaciones): ?>
                    <tr><td colspan="7">No hay habitaciones registradas.</td></tr>
                <?php else: foreach ($habitaciones as $hab): ?>
                    <tr>
                        <td><?= e($hab['numero']) ?></td>
                        <td><?= e($hab['nombre']) ?></td>
                        <td><?= e($hab['tipo']) ?></td>
                        <td><?= (int) $hab['capacidad'] ?></td>
                        <td><?= money((float) $hab['precio_noche']) ?></td>
                        <td><span class="chip <?= $hab['estado'] === 'disponible' ? 'chip-activo' : ($hab['estado'] === 'mantenimiento' ? 'chip-pendiente' : 'chip-cancelada') ?>"><?= e(ucfirst($hab['estado'])) ?></span></td>
                        <td>
                            <a class="btn btn-secundario" href="<?= e(url('modulos/habitaciones.php?editar=' . $hab['id'])) ?>">Editar</a>
                            <form method="post" style="display:inline" data-confirmar="Eliminar la habitacion <?= e($hab['numero']) ?>?">
                                <?= csrf_campo() ?>
                                <input type="hidden" name="accion" value="eliminar">
                                <input type="hidden" name="id" value="<?= (int) $hab['id'] ?>">
                                <button type="submit" class="btn btn-peligro">Eliminar</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <div class="barra-botones">
            <a class="btn btn-secundario" href="<?= e(url('modulos/disponibilidad.php')) ?>">Volver a disponibilidad</a>
            <a class="btn btn-secundario" href="<?= e(url('modulos/menu.php')) ?>">Menu principal</a>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
