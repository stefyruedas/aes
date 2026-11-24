<?php
/**
 * Modulo de Servicios adicionales.
 * - Gestiona los servicios desde el modulo check-in (agregar, editar, eliminar).
 * - En el modulo se puede buscar la reserva a la cual se le quiere agregar un servicio.
 *
 * Permisos: administrador y operario.
 */
require_once __DIR__ . '/../includes/estancia_lib.php';
requerir_rol(['administrador', 'operario']);

$errores   = [];
$idCheckin = (int) ($_GET['id_checkin'] ?? $_POST['id_checkin'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verificar_csrf()) {
        $errores[] = 'Sesion expirada. Intenta nuevamente.';
    } else {
        $accion = $_POST['accion'] ?? '';

        if ($accion === 'agregar') {
            $nombre = trim($_POST['nombre_servicio'] ?? '');
            $precio = (float) ($_POST['precio'] ?? 0);
            $obs    = trim($_POST['observacion'] ?? '');
            if (!obtener_checkin($idCheckin)) {
                $errores[] = 'Selecciona un check-in valido.';
            } elseif ($nombre === '' || $precio <= 0) {
                $errores[] = 'Indica un servicio y un valor mayor a cero.';
            } else {
                db()->prepare(
                    'INSERT INTO servicios_adicionales (id_check_in, nombre_servicio, precio, observacion) VALUES (?,?,?,?)'
                )->execute([$idCheckin, $nombre, $precio, $obs ?: null]);
                sincronizar_checkout($idCheckin);
                flash('exito', 'Servicio agregado.');
                redirigir('/modulos/servicios.php?id_checkin=' . $idCheckin);
            }
        }

        if ($accion === 'editar') {
            $idServ = (int) ($_POST['id_servicio'] ?? 0);
            $nombre = trim($_POST['nombre_servicio'] ?? '');
            $precio = (float) ($_POST['precio'] ?? 0);
            $obs    = trim($_POST['observacion'] ?? '');
            if ($nombre === '' || $precio <= 0) {
                $errores[] = 'Indica un servicio y un valor mayor a cero.';
            } else {
                db()->prepare(
                    'UPDATE servicios_adicionales SET nombre_servicio=?, precio=?, observacion=? WHERE id=? AND id_check_in=?'
                )->execute([$nombre, $precio, $obs ?: null, $idServ, $idCheckin]);
                sincronizar_checkout($idCheckin);
                flash('exito', 'Servicio actualizado.');
                redirigir('/modulos/servicios.php?id_checkin=' . $idCheckin);
            }
        }

        if ($accion === 'eliminar') {
            $idServ = (int) ($_POST['id_servicio'] ?? 0);
            db()->prepare('DELETE FROM servicios_adicionales WHERE id=? AND id_check_in=?')
                ->execute([$idServ, $idCheckin]);
            sincronizar_checkout($idCheckin);
            flash('info', 'Servicio eliminado.');
            redirigir('/modulos/servicios.php?id_checkin=' . $idCheckin);
        }
    }
}

$checkin = $idCheckin ? obtener_checkin($idCheckin) : null;

$tituloPagina = 'Servicios adicionales';
$mostrarBarra = true;
require __DIR__ . '/../includes/header.php';
?>

<div class="contenedor">
<?php foreach ($errores as $err): ?>
    <div class="alerta alerta-error"><?= e($err) ?></div>
<?php endforeach; ?>

<?php if (!$checkin): ?>
    <div class="tarjeta">
        <h2>Servicios adicionales</h2>
        <p class="texto-ayuda">Busca el check-in del huesped por numero de reserva para gestionar sus servicios.</p>
        <form method="get" class="barra-filtros">
            <div class="campo">
                <label for="id_reserva">Numero de reserva</label>
                <input type="number" id="id_reserva" name="id_reserva" min="1" required>
            </div>
            <button type="submit" class="btn btn-primario">Buscar</button>
        </form>
        <?php
        // Si buscaron por reserva, resolver el check-in
        if (isset($_GET['id_reserva'])) {
            $ci = checkin_por_reserva((int) $_GET['id_reserva']);
            if ($ci) {
                echo '<script>window.location.href="' . e(url('modulos/servicios.php?id_checkin=' . $ci['id'])) . '";</script>';
            } else {
                echo '<div class="alerta alerta-error">No hay check-in para esa reserva. Primero registra el check-in.</div>';
            }
        }
        ?>
        <div class="barra-botones">
            <a class="btn btn-secundario" href="<?= e(url('modulos/menu.php')) ?>">Regresar al menu principal</a>
        </div>
    </div>
<?php else:
    $servicios = servicios_de_checkin($idCheckin);
    $total = total_servicios($idCheckin);
    $editarServ = null;
    if (isset($_GET['editar'])) {
        foreach ($servicios as $s) {
            if ((int) $s['id'] === (int) $_GET['editar']) { $editarServ = $s; break; }
        }
    }
?>
    <div class="tarjeta">
        <h2>Servicios &mdash; <?= e($checkin['huesped_nombre']) ?> (Check-in N.° <?= (int) $checkin['id'] ?>)</h2>

        <div class="tabla-responsive">
            <table class="tabla-aes">
                <thead><tr><th>Servicio</th><th>Valor</th><th>Observacion</th><th>Acciones</th></tr></thead>
                <tbody>
                <?php if (!$servicios): ?>
                    <tr><td colspan="4">Sin servicios registrados.</td></tr>
                <?php else: foreach ($servicios as $s): ?>
                    <tr>
                        <td><?= e($s['nombre_servicio']) ?></td>
                        <td><?= money((float) $s['precio']) ?></td>
                        <td><?= e($s['observacion'] ?: '-') ?></td>
                        <td>
                            <a class="btn btn-secundario" href="<?= e(url('modulos/servicios.php?id_checkin=' . $idCheckin . '&editar=' . $s['id'])) ?>">Editar</a>
                            <form method="post" style="display:inline" data-confirmar="Eliminar este servicio?">
                                <?= csrf_campo() ?>
                                <input type="hidden" name="accion" value="eliminar">
                                <input type="hidden" name="id_checkin" value="<?= (int) $idCheckin ?>">
                                <input type="hidden" name="id_servicio" value="<?= (int) $s['id'] ?>">
                                <button type="submit" class="btn btn-peligro">Eliminar</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
                <tfoot>
                    <tr><th>Total servicios</th><th colspan="3"><?= money($total) ?></th></tr>
                </tfoot>
            </table>
        </div>
    </div>

    <div class="tarjeta">
        <h2><?= $editarServ ? 'Editar servicio' : 'Agregar servicio' ?></h2>
        <form method="post" novalidate>
            <?= csrf_campo() ?>
            <input type="hidden" name="accion" value="<?= $editarServ ? 'editar' : 'agregar' ?>">
            <input type="hidden" name="id_checkin" value="<?= (int) $idCheckin ?>">
            <?php if ($editarServ): ?><input type="hidden" name="id_servicio" value="<?= (int) $editarServ['id'] ?>"><?php endif; ?>
            <div class="barra-filtros">
                <div class="campo">
                    <label>Servicio</label>
                    <input type="text" name="nombre_servicio" list="serv_base"
                           value="<?= e($editarServ['nombre_servicio'] ?? '') ?>" required>
                    <datalist id="serv_base">
                        <?php foreach (servicios_base() as $sb): ?>
                            <option value="<?= e($sb['nombre']) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div class="campo">
                    <label>Valor</label>
                    <input type="number" name="precio" min="0" step="500" value="<?= e($editarServ['precio'] ?? '') ?>" required>
                </div>
                <div class="campo">
                    <label>Observacion</label>
                    <input type="text" name="observacion" value="<?= e($editarServ['observacion'] ?? '') ?>">
                </div>
                <button type="submit" class="btn btn-primario"><?= $editarServ ? 'Guardar' : 'Anadir servicio' ?></button>
                <?php if ($editarServ): ?>
                    <a class="btn btn-secundario" href="<?= e(url('modulos/servicios.php?id_checkin=' . $idCheckin)) ?>">Cancelar</a>
                <?php endif; ?>
            </div>
        </form>

        <div class="barra-botones">
            <a class="btn btn-primario" href="<?= e(url('modulos/checkout.php?id_checkin=' . $idCheckin)) ?>">Ir al check-out</a>
            <a class="btn btn-secundario" href="<?= e(url('modulos/checkin.php?vista=detalle&id=' . $idCheckin)) ?>">Volver al check-in</a>
            <a class="btn btn-secundario" href="<?= e(url('modulos/menu.php')) ?>">Menu principal</a>
        </div>
    </div>
<?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
