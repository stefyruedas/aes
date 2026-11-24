<?php
/**
 * Modulo de Finanzas basicas (solo administrador).
 *
 * - Ingresos: provienen de los pagos de check-out (tipo 'ingreso'), NO editables.
 * - Egresos fijos: se ingresan una vez y se mantienen (es_fijo = 1).
 * - Egresos variables: se agregan manualmente mes a mes (es_fijo = 0).
 * - Tabla por fecha con ingreso / egreso / total dia y total general.
 * - Filtro por rango de fechas.
 * - Reporte CSV (Excel) y balance general (solo visible).
 *
 * La tabla 'finanzas' guarda: fecha, tipo ('ingreso'/'egreso'), concepto,
 * monto, medio_pago, es_fijo.
 */
require_once __DIR__ . '/../includes/funciones.php';
require_once __DIR__ . '/../includes/reporte.php';
requerir_rol(['administrador']);

$errores = [];

/* ---------------- Acciones (agregar / eliminar egreso) ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verificar_csrf()) {
        $errores[] = 'Sesion expirada. Intenta nuevamente.';
    } else {
        $accion = $_POST['accion'] ?? '';

        if ($accion === 'agregar_egreso') {
            $fecha    = trim($_POST['fecha'] ?? date('Y-m-d'));
            $concepto = trim($_POST['concepto'] ?? '');
            $monto    = (float) ($_POST['monto'] ?? 0);
            $medio    = trim($_POST['medio_pago'] ?? 'efectivo');
            $esFijo   = isset($_POST['es_fijo']) ? 1 : 0;

            if ($concepto === '') $errores[] = 'El concepto es obligatorio.';
            if ($monto <= 0)      $errores[] = 'El monto debe ser mayor a cero.';
            if (!DateTime::createFromFormat('Y-m-d', $fecha)) $errores[] = 'La fecha no es valida.';

            if (!$errores) {
                db()->prepare(
                    'INSERT INTO finanzas (fecha, tipo, concepto, monto, medio_pago, es_fijo)
                     VALUES (?, "egreso", ?, ?, ?, ?)'
                )->execute([$fecha, $concepto, $monto, $medio, $esFijo]);
                flash('exito', $esFijo ? 'Egreso fijo registrado.' : 'Egreso variable registrado.');
                redirigir('/modulos/finanzas.php');
            }
        }

        if ($accion === 'eliminar_egreso') {
            $id = (int) ($_POST['id'] ?? 0);
            // Solo egresos (los ingresos no se pueden borrar desde aqui).
            db()->prepare('DELETE FROM finanzas WHERE id = ? AND tipo = "egreso"')->execute([$id]);
            flash('info', 'Egreso eliminado.');
            redirigir('/modulos/finanzas.php');
        }
    }
}

/* ---------------- Filtro de fechas ---------------- */
$desde = $_GET['desde'] ?? date('Y-m-01');          // primer dia del mes por defecto
$hasta = $_GET['hasta'] ?? date('Y-m-t');           // ultimo dia del mes por defecto
if (!DateTime::createFromFormat('Y-m-d', $desde)) $desde = date('Y-m-01');
if (!DateTime::createFromFormat('Y-m-d', $hasta)) $hasta = date('Y-m-t');

/* ---------------- Consulta de movimientos ---------------- */
$stmt = db()->prepare(
    'SELECT id, fecha, tipo, concepto, monto, medio_pago, es_fijo
     FROM finanzas
     WHERE fecha BETWEEN ? AND ?
     ORDER BY fecha ASC, id ASC'
);
$stmt->execute([$desde, $hasta]);
$movimientos = $stmt->fetchAll();

// Agrupar por fecha para la tabla (ingreso / egreso / total dia)
$porFecha = [];
$totalIngresos = 0.0;
$totalEgresos  = 0.0;
foreach ($movimientos as $m) {
    $f = $m['fecha'];
    if (!isset($porFecha[$f])) {
        $porFecha[$f] = ['ingreso' => 0.0, 'egreso' => 0.0];
    }
    if ($m['tipo'] === 'ingreso') {
        $porFecha[$f]['ingreso'] += (float) $m['monto'];
        $totalIngresos += (float) $m['monto'];
    } else {
        $porFecha[$f]['egreso'] += (float) $m['monto'];
        $totalEgresos += (float) $m['monto'];
    }
}
$totalGeneral = $totalIngresos - $totalEgresos;

/* ---------------- Reporte CSV ---------------- */
if (isset($_GET['reporte'])) {
    $enc = ['Fecha', 'Tipo', 'Concepto', 'Monto', 'Medio de pago', 'Fijo'];
    $filas = [];
    foreach ($movimientos as $m) {
        $filas[] = [
            $m['fecha'], ucfirst($m['tipo']), $m['concepto'],
            number_format((float) $m['monto'], 2, ',', '.'),
            $m['medio_pago'], $m['es_fijo'] ? 'Si' : 'No',
        ];
    }
    // Totales al final
    $filas[] = [];
    $filas[] = ['', '', 'Total ingresos', number_format($totalIngresos, 2, ',', '.'), '', ''];
    $filas[] = ['', '', 'Total egresos', number_format($totalEgresos, 2, ',', '.'), '', ''];
    $filas[] = ['', '', 'Balance general', number_format($totalGeneral, 2, ',', '.'), '', ''];
    descargar_csv('reporte_finanzas_' . $desde . '_a_' . $hasta, $enc, $filas);
}

$verBalance = isset($_GET['balance']);

$tituloPagina = 'Finanzas';
$mostrarBarra = true;
require __DIR__ . '/../includes/header.php';
?>

<div class="contenedor">
<?php foreach ($errores as $err): ?>
    <div class="alerta alerta-error"><?= e($err) ?></div>
<?php endforeach; ?>

    <div class="tarjeta">
        <h2>Finanzas basicas</h2>

        <form method="get" class="barra-filtros">
            <div class="campo">
                <label for="desde">Desde</label>
                <input type="date" id="desde" name="desde" value="<?= e($desde) ?>">
            </div>
            <div class="campo">
                <label for="hasta">Hasta</label>
                <input type="date" id="hasta" name="hasta" value="<?= e($hasta) ?>">
            </div>
            <button type="submit" class="btn btn-primario">Filtrar</button>
            <a class="btn btn-secundario" href="<?= e(url('modulos/finanzas.php?reporte=1&desde=' . $desde . '&hasta=' . $hasta)) ?>">Generar reporte (Excel/CSV)</a>
            <a class="btn btn-secundario" href="<?= e(url('modulos/finanzas.php?balance=1&desde=' . $desde . '&hasta=' . $hasta)) ?>">Balance general</a>
        </form>

        <?php if ($verBalance): ?>
            <div class="seccion-form" style="background:#eafbe4;">
                <div class="titulo-seccion">Balance general (<?= e($desde) ?> a <?= e($hasta) ?>)</div>
                <p>
                    <strong>Total ingresos:</strong> <?= money($totalIngresos) ?><br>
                    <strong>Total egresos:</strong> <?= money($totalEgresos) ?><br>
                    <strong>Balance:</strong>
                    <span style="color:<?= $totalGeneral >= 0 ? '#2a7a12' : '#c0392b' ?>;font-weight:700;">
                        <?= money($totalGeneral) ?>
                    </span>
                </p>
            </div>
        <?php endif; ?>

        <!-- Tabla por fecha: fecha / ingreso / egreso / total dia -->
        <div class="tabla-responsive" style="margin-top:1rem;">
            <table class="tabla-aes">
                <thead>
                    <tr><th>Fecha</th><th>Ingreso</th><th>Egreso</th><th>Total dia</th></tr>
                </thead>
                <tbody>
                <?php if (!$porFecha): ?>
                    <tr><td colspan="4">No hay movimientos en el rango seleccionado.</td></tr>
                <?php else: foreach ($porFecha as $f => $tot):
                    $totalDia = $tot['ingreso'] - $tot['egreso']; ?>
                    <tr>
                        <td><?= e($f) ?></td>
                        <td><?= money($tot['ingreso']) ?></td>
                        <td><?= money($tot['egreso']) ?></td>
                        <td style="font-weight:700;color:<?= $totalDia >= 0 ? '#2a7a12' : '#c0392b' ?>;"><?= money($totalDia) ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th>Total general</th>
                        <th><?= money($totalIngresos) ?></th>
                        <th><?= money($totalEgresos) ?></th>
                        <th style="color:<?= $totalGeneral >= 0 ? '#2a7a12' : '#c0392b' ?>;"><?= money($totalGeneral) ?></th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <!-- Detalle de movimientos + eliminar egresos -->
    <div class="tarjeta">
        <h2>Detalle de movimientos</h2>
        <div class="tabla-responsive">
            <table class="tabla-aes">
                <thead>
                    <tr><th>Fecha</th><th>Tipo</th><th>Concepto</th><th>Monto</th><th>Medio</th><th>Fijo</th><th></th></tr>
                </thead>
                <tbody>
                <?php if (!$movimientos): ?>
                    <tr><td colspan="7">Sin movimientos.</td></tr>
                <?php else: foreach ($movimientos as $m): ?>
                    <tr>
                        <td><?= e($m['fecha']) ?></td>
                        <td>
                            <span class="chip <?= $m['tipo'] === 'ingreso' ? 'chip-activo' : 'chip-cancelada' ?>">
                                <?= e(ucfirst($m['tipo'])) ?>
                            </span>
                        </td>
                        <td><?= e($m['concepto']) ?></td>
                        <td><?= money((float) $m['monto']) ?></td>
                        <td><?= e($m['medio_pago']) ?></td>
                        <td><?= $m['es_fijo'] ? 'Si' : 'No' ?></td>
                        <td>
                            <?php if ($m['tipo'] === 'egreso'): ?>
                                <form method="post" style="display:inline" data-confirmar="Eliminar este egreso?">
                                    <?= csrf_campo() ?>
                                    <input type="hidden" name="accion" value="eliminar_egreso">
                                    <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                                    <button type="submit" class="btn btn-peligro">Eliminar</button>
                                </form>
                            <?php else: ?>
                                <span class="texto-ayuda">No editable</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <p class="texto-ayuda">Los ingresos se generan automaticamente al pagar un check-out y no son editables.</p>
    </div>

    <!-- Agregar egreso -->
    <div class="tarjeta">
        <h2>Anadir egreso</h2>
        <form method="post" novalidate>
            <?= csrf_campo() ?>
            <input type="hidden" name="accion" value="agregar_egreso">
            <div class="barra-filtros">
                <div class="campo">
                    <label>Fecha <span class="obligatorio">*</span></label>
                    <input type="date" name="fecha" value="<?= e(date('Y-m-d')) ?>" required>
                </div>
                <div class="campo">
                    <label>Concepto <span class="obligatorio">*</span></label>
                    <input type="text" name="concepto" placeholder="Ej: Compra de insumos" required>
                </div>
                <div class="campo">
                    <label>Monto <span class="obligatorio">*</span></label>
                    <input type="number" name="monto" min="0" step="100" required>
                </div>
                <div class="campo">
                    <label>Medio de pago</label>
                    <select name="medio_pago">
                        <?php foreach (['efectivo','consignacion','tarjeta credito','tarjeta debito'] as $mp): ?>
                            <option value="<?= e($mp) ?>"><?= e(ucfirst($mp)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="campo">
                    <label>&nbsp;</label>
                    <label style="font-weight:400;"><input type="checkbox" name="es_fijo" value="1"> Egreso fijo (mensual)</label>
                </div>
                <button type="submit" class="btn btn-primario">Anadir egreso</button>
            </div>
        </form>

        <div class="barra-botones">
            <a class="btn btn-secundario" href="<?= e(url('modulos/menu.php')) ?>">Regresar al menu principal</a>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
