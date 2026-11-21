<?php
/**
 * Modulo de Inventario.
 * - Tabla de productos con busqueda por nombre.
 * - Agregar, editar y eliminar productos.
 * - Registrar entradas/salidas de stock.
 * - Alerta cuando el stock llega al minimo.
 * - Reporte CSV (Excel).
 */

require_once __DIR__ . '/../includes/funciones.php';
require_once __DIR__ . '/../includes/reporte.php';
requerir_rol(['administrador', 'operario']);

$errores = [];

/* Metodo post */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verificar_csrf()) {
        $errores[] = 'Sesion expirada. Intenta nuevamente.';
    } else {
        $accion = $_POST['accion'] ?? '';

/* Agreagr y actualizar productos */

        if ($accion === 'guardar' || $accion === 'actualizar') {
            $id           = $accion === 'actualizar' ? (int) ($_POST['id'] ?? 0) : null;
            $tipoProducto = trim($_POST['tipo_producto'] ?? '');
            $nombre       = trim($_POST['nombre'] ?? '');
            $stock        = max(0, (int) ($_POST['stock'] ?? 0));
            $descripcion  = trim($_POST['descripcion'] ?? '');
            $stockMin     = max(0, (int) ($_POST['stock_minimo'] ?? 1));

            // Validar los campos obligatorios
            if ($nombre === '')       $errores[] = 'El nombre del producto es obligatorio.';
            if ($tipoProducto === '') $errores[] = 'El tipo de producto es obligatorio.';
            if ($descripcion === '')  $errores[] = 'La descripcion es obligatoria.';

            // Guardar un producto nuevo y actualizar un producto existente
            if (!$errores) {
                if ($accion === 'guardar') {
                    db()->prepare(
                        'INSERT INTO inventario (tipo_producto, nombre, stock, descripcion, stock_minimo)
                         VALUES (?,?,?,?,?)'
                    )->execute([$tipoProducto, $nombre, $stock, $descripcion, $stockMin]);
                    flash('exito', 'Producto agregado al inventario.');
                } else {
                    db()->prepare(
                        'UPDATE inventario SET tipo_producto=?, nombre=?, stock=?, descripcion=?, stock_minimo=? WHERE id=?'
                    )->execute([$tipoProducto, $nombre, $stock, $descripcion, $stockMin, $id]);
                    flash('exito', 'Producto actualizado.');
                }
                redirigir('/modulos/inventario.php');
            }
        }

        // Eliminar un producto
        if ($accion === 'eliminar') {
            $id = (int) ($_POST['id'] ?? 0);
            db()->prepare('DELETE FROM inventario WHERE id = ?')->execute([$id]);
            flash('info', 'Producto eliminado del inventario.');
            redirigir('/modulos/inventario.php');
        }

        // Registrar entradas y salidas de stock
        if ($accion === 'movimiento') {
            $id   = (int) ($_POST['id'] ?? 0);
            $tipo = $_POST['movimiento'] ?? 'entrada';
            $cant = max(1, (int) ($_POST['cantidad'] ?? 1));

            // Consultar stock actual
            $stmt = db()->prepare('SELECT stock FROM inventario WHERE id = ?');
            $stmt->execute([$id]);
            $actual = $stmt->fetchColumn();

            if ($actual === false) {
                $errores[] = 'Producto no encontrado.';
            } else {
                $nuevo = $tipo === 'salida' ? ((int) $actual - $cant) : ((int) $actual + $cant);
                
                // Evitar que el stokc sea negativo
                if ($nuevo < 0) {
                    flash('error', 'No hay suficiente stock para esa salida.');
                } else {
                    db()->prepare('UPDATE inventario SET stock = ? WHERE id = ?')->execute([$nuevo, $id]);
                    flash('exito', 'Stock actualizado (' . $tipo . ' de ' . $cant . ').');
                }
                redirigir('/modulos/inventario.php');
            }
        }
    }
}

/* ---------------- Busqueda de productos ---------------- */
$q = trim($_GET['q'] ?? '');
$sql = 'SELECT * FROM inventario';
$params = [];
if ($q !== '') {
    $sql .= ' WHERE nombre LIKE ? OR tipo_producto LIKE ?';
    $params[] = "%$q%"; $params[] = "%$q%";
}

/* Preparar y ejecutar la consulta de productos */
$sql .= ' ORDER BY nombre';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$productos = $stmt->fetchAll();

/* ---------------- Generar reporte CSV para excel---------------- */
if (isset($_GET['reporte'])){
    $enc = ['Codigo', 'Nombre', 'Tipo', 'Descripcion', 'Stock', 'Stock minimo', 'Estado'];
    $filas = [];
    foreach ($productos as $p) {
        $filas[] = [
            $p['id'], $p['nombre'], $p['tipo_producto'], $p['descripcion'],
            $p['stock'], $p['stock_minimo'],
            ((int) $p['stock'] <= (int) $p['stock_minimo']) ? 'REPONER' : 'OK',
        ];
    }
    descargar_csv('reporte_inventario', $enc, $filas);
}

// Producto en edicion
$editar = null;
if (isset($_GET['editar'])) {
    $stmt = db()->prepare('SELECT * FROM inventario WHERE id = ?');
    $stmt->execute([(int) $_GET['editar']]);
    $editar = $stmt->fetch() ?: null;
}

// Productos en nivel minimo (para la alerta)
$bajos = array_filter($productos, fn($p) => (int) $p['stock'] <= (int) $p['stock_minimo']);

$tituloPagina = 'Inventario';
$mostrarBarra = true;
require __DIR__ . '/../includes/header.php';
?>

<!-- Estructura principal de la pagina -->

<div class="contenedor">
<?php foreach ($errores as $err): ?>
    <div class="alerta alerta-error"><?= e($err) ?></div>
<?php endforeach; ?>

<!-- Mostrar alertas de stock -->
<?php if ($bajos): ?>
    <div class="alerta alerta-error">
        <strong>Alerta de stock:</strong>
        <?= count($bajos) ?> producto(s) llegaron al stock minimo y requieren reposicion:
        <?= e(implode(', ', array_map(fn($p) => $p['nombre'] . ' (' . $p['stock'] . ')', $bajos))) ?>.
    </div>
<?php endif; ?>

   <!-- Construccion de la tabla de inventario -->
    <div class="tarjeta">
        <h2>Inventario de productos</h2>

        <!-- Formulario de busqueda - metodo GET --> 
        <form method="get" class="barra-filtros">
            <div class="campo">
                <label for="q">Buscar producto</label>
                <input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="Nombre o tipo">
            </div>

            <button type="submit" class="btn btn-primario">Consultar</button>
            <a class="btn btn-secundario" href="<?= e(url('modulos/inventario.php')) ?>">Limpiar</a>
            <a class="btn btn-secundario" href="<?= e(url('modulos/inventario.php?reporte=1' . ($q !== '' ? '&q=' . urlencode($q) : ''))) ?>">Generar informe (Excel/CSV)</a>
        </form>

<!-- Estructura de la tabla de productos -->
        <div class="tabla-responsive" style="margin-top:1rem;">
            <table class="tabla-aes">
                <thead>
                    <tr><th>Codigo</th><th>Nombre</th><th>Tipo</th><th>Descripcion</th><th>Stock</th><th>Minimo</th><th>Estado</th><th>Acciones</th></tr>
                </thead>
                <tbody>
                
                <?php if (!$productos): ?>
                    <tr><td colspan="8">No se encontraron productos.</td></tr>
                
                <?php else: foreach ($productos as $p):
                    $bajo = (int) $p['stock'] <= (int) $p['stock_minimo']; ?>
                    <tr>
                        <td><?= (int) $p['id'] ?></td>
                        <td><?= e($p['nombre']) ?></td>
                        <td><?= e($p['tipo_producto']) ?></td>
                        <td><?= e($p['descripcion']) ?></td>
                        <td style="<?= $bajo ? 'color:#c0392b;font-weight:700;' : '' ?>"><?= (int) $p['stock'] ?></td>
                        <td><?= (int) $p['stock_minimo'] ?></td>
                        
                        <td>
                            <span class="chip <?= $bajo ? 'chip-cancelada' : 'chip-activo' ?>"><?= $bajo ? 'Reponer' : 'OK' ?></span>
                        </td>
                        <td>

                            <!-- Movimiento rapido - metodo post-->
                            <form method="post" style="display:inline-flex;gap:4px;align-items:center;">
                                <?= csrf_campo() ?>
                                <input type="hidden" name="accion" value="movimiento">
                                <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                <input type="number" name="cantidad" min="1" value="1" style="width:60px;padding:3px;">
                                
                                <select name="movimiento" style="width:auto;padding:3px;">
                                    <option value="entrada">+ Entrada</option>
                                    <option value="salida">- Salida</option>
                                </select>
                                
                                <button type="submit" class="btn btn-secundario">Aplicar</button>
                            </form>

                            <!-- Editar-->
                            <a class="btn btn-secundario" href="<?= e(url('modulos/inventario.php?editar=' . $p['id'])) ?>">Editar</a>

                            <!-- Eliminar - metodo post-->
                            <form method="post" style="display:inline" data-confirmar="Eliminar el producto <?= e($p['nombre']) ?>?">
                                <?= csrf_campo() ?>
                                <input type="hidden" name="accion" value="eliminar">
                                <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                <button type="submit" class="btn btn-peligro">Eliminar</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

// Formulario para agregar o editar productos
    <div class="tarjeta">
        <h2><?= $editar ? 'Editar producto' : 'Agregar producto' ?></h2>
        <?php $val = fn($k, $d = '') => e($_POST[$k] ?? ($editar[$k] ?? $d)); ?>
        
        <form method="post" novalidate>
            <?= csrf_campo() ?>
            <input type="hidden" name="accion" value="<?= $editar ? 'actualizar' : 'guardar' ?>">
            <?php if ($editar): ?><input type="hidden" name="id" value="<?= (int) $editar['id'] ?>"><?php endif; ?>
            
            <!-- Campos del formulario de inventario -->
            <div class="seccion-form">
                <div class="campo">
                    <label>Nombre del producto <span class="obligatorio">*</span></label>
                    <input type="text" name="nombre" value="<?= $val('nombre') ?>" required>
                </div>
                
                <div class="campo">
                    <label>Tipo de producto <span class="obligatorio">*</span></label>
                    <input type="text" name="tipo_producto" value="<?= $val('tipo_producto') ?>" list="tipos" required>
                    <datalist id="tipos">
                        <?php foreach (['Aseo','Lenceria','Bebida','Alimento','Otro'] as $t): ?>
                            <option value="<?= $t ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>
                
                <div class="campo">
                    <label>Descripcion <span class="obligatorio">*</span></label>
                    <input type="text" name="descripcion" value="<?= $val('descripcion') ?>" required>
                </div>
                
                <div class="campo">
                    <label>Cantidad disponible (stock) <span class="obligatorio">*</span></label>
                    <input type="number" name="stock" min="0" value="<?= $val('stock', '0') ?>" required>
                </div>
                
                <div class="campo">
                    <label>Stock minimo <span class="obligatorio">*</span></label>
                    <input type="number" name="stock_minimo" min="0" value="<?= $val('stock_minimo', '1') ?>" required>
                </div>
            </div>
            
            <div class="barra-botones">
                <button type="submit" class="btn btn-primario"><?= $editar ? 'Guardar cambios' : 'Agregar producto' ?></button>
                <?php if ($editar): ?>
                    <a class="btn btn-secundario" href="<?= e(url('modulos/inventario.php')) ?>">Cancelar edicion</a>
                <?php endif; ?>
                
                <a class="btn btn-secundario" href="<?= e(url('modulos/menu.php')) ?>">Regresar al menu principal</a>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
