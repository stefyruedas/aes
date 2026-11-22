<?php  
/** Modulo de reservas
 * Este modulo tiene tres vistas:
 * - Lista: Muestra las reservas registradas y permite filtrarlas
 * - Nueva: Presenta el formulario para registrar una reserva
 * - ver: Muestra los datos de una reserva y permite editarla o cancelarla
 */

require_once __DIR__ . '/../includes/reservas_lib.php';
requerir_rol(['administrador', 'operario']);

$vista   = $_GET['vista'] ?? 'lista';
$errores = [];
$u       = usuario_actual();

/* =================================================================
 * PROCESAMIENTO DE FORMULARIOS / ACCIONES (POST)
 * ================================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verificar_csrf()) {
        $errores[] = 'Sesion expirada. Intenta nuevamente.';
    } else {
        $accion = $_POST['accion'] ?? '';

 /* ---------- Crear o actualizar reserva ---------- */
        if ($accion === 'guardar' || $accion === 'actualizar') {
            $idReserva = $accion === 'actualizar' ? (int) ($_POST['id'] ?? 0) : null;

            // recoge los datos del huesped 
            $d = [
                'nombre'       => trim($_POST['nombre'] ?? ''),
                'dni'          => trim($_POST['dni'] ?? ''),
                'telefono'     => trim($_POST['telefono'] ?? ''),
                'email'        => trim($_POST['email'] ?? ''),
                'nacionalidad' => trim($_POST['nacionalidad'] ?? ''),
            ];

            // Obtener datos de la reserva
            $idHabitacion     = (int) ($_POST['id_habitacion'] ?? 0);
            $fechaInicio      = trim($_POST['fecha_inicio'] ?? '');
            $fechaFin         = trim($_POST['fecha_fin'] ?? '');
            $numeroHuespedes  = max(1, (int) ($_POST['numero_huespedes'] ?? 1));
            $canal            = trim($_POST['canal_reserva'] ?? 'recepcion');
            $observaciones    = trim($_POST['observaciones'] ?? '');
            $estado           = $_POST['estado'] ?? 'pendiente';

            // Validaciones basicas de los datos
            if ($d['nombre'] === '')        $errores[] = 'El nombre del huesped es obligatorio.';
            if ($d['dni'] === '')           $errores[] = 'El numero de documento es obligatorio.';
            if ($d['telefono'] === '')      $errores[] = 'El telefono es obligatorio.';
            if ($d['nacionalidad'] === '')  $errores[] = 'La nacionalidad es obligatoria.';
            if ($d['email'] !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
                $errores[] = 'El correo electronico no es valido.';
            }
            if ($idHabitacion <= 0)         $errores[] = 'Debes seleccionar una habitacion.';
            if (!in_array($estado, ['pendiente','activa','finalizada','cancelada'], true)) {
                $estado = 'pendiente';
            }

            $errFecha = validar_fechas($fechaInicio, $fechaFin);
            if ($errFecha) $errores[] = $errFecha;

            $habitacion = $idHabitacion > 0 ? obtener_habitacion($idHabitacion) : null;
            if ($idHabitacion > 0 && !$habitacion) {
                $errores[] = 'La habitacion seleccionada no existe.';
            }

            // Verificar la capacidad
            if ($habitacion && $numeroHuespedes > (int) $habitacion['capacidad']) {
                $errores[] = 'El numero de huespedes supera la capacidad de la habitacion ('
                    . (int) $habitacion['capacidad'] . ').';
            }

            // Verificar disponibilidad (evitar cruces)
            if (!$errores && !habitacion_disponible($idHabitacion, $fechaInicio, $fechaFin, $idReserva)) {
                $errores[] = 'No hay disponibilidad: la habitacion ya esta reservada en esas fechas.';
            }

            // Guardar la reserva en MYSQL
            if (!$errores) {
                $idHuesped = obtener_o_crear_huesped($d);
                $noches = calcular_noches($fechaInicio, $fechaFin);

                 // Cuando se registra una nueva reserva
                if ($accion === 'guardar') {
                    $ins = db()->prepare(
                        'INSERT INTO reservas
                         (id_huesped, id_usuario, id_habitacion, canal_reserva, tipo_habitacion,
                          fecha_inicio, fecha_fin, estado, nombre_habitacion, capacidad,
                          precio_noche, numero_huespedes, observaciones)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
                    );
                    $ins->execute([
                        $idHuesped, $u['id'], $idHabitacion, $canal, $habitacion['tipo'],
                        $fechaInicio, $fechaFin, $estado, $habitacion['nombre'], $habitacion['capacidad'],
                        $habitacion['precio_noche'], $numeroHuespedes, $observaciones ?: null,
                    ]);
                    $nuevoId = (int) db()->lastInsertId();
                    flash('exito', 'Reserva registrada correctamente (N.° ' . $nuevoId . ', ' . $noches . ' noche(s)).');
                    redirigir('/modulos/reservas.php?vista=ver&id=' . $nuevoId);

                    // Cuando se actualiza una reserva existente
                } else {
                    $upd = db()->prepare(
                        'UPDATE reservas SET
                            id_huesped = ?, id_habitacion = ?, canal_reserva = ?, tipo_habitacion = ?,
                            fecha_inicio = ?, fecha_fin = ?, estado = ?, nombre_habitacion = ?,
                            capacidad = ?, precio_noche = ?, numero_huespedes = ?, observaciones = ?
                         WHERE id = ?'
                    );
                    $upd->execute([
                        $idHuesped, $idHabitacion, $canal, $habitacion['tipo'],
                        $fechaInicio, $fechaFin, $estado, $habitacion['nombre'],
                        $habitacion['capacidad'], $habitacion['precio_noche'], $numeroHuespedes,
                        $observaciones ?: null, $idReserva,
                    ]);
                    flash('exito', 'Reserva actualizada correctamente.');
                    redirigir('/modulos/reservas.php?vista=ver&id=' . $idReserva);
                }
            }
        }

        /* ---------- Cancelar reserva ---------- */
        if ($accion === 'cancelar') {
            $idReserva = (int) ($_POST['id'] ?? 0);
            $upd = db()->prepare("UPDATE reservas SET estado = 'cancelada' WHERE id = ?");
            $upd->execute([$idReserva]);
            flash('info', 'La reserva fue cancelada.');
            redirigir('/modulos/reservas.php?vista=ver&id=' . $idReserva);
        }
    }

    // Si hubo errores, conservamos la vista del formulario correspondiente.
    if ($errores) {
        $vista = isset($_POST['id']) && $_POST['accion'] === 'actualizar' ? 'ver' : 'nueva';
    }
}

/* ---------- Diseño de la pagina ----------*/
$tituloPagina = 'Reservas';
$mostrarBarra = true;
require __DIR__ . '/../includes/header.php';
?>

<div class="contenedor">
<?php foreach ($errores as $err): ?>
    <div class="alerta alerta-error"><?= e($err) ?></div>
<?php endforeach; ?>

<!-- ----------Primera vista- lista de reservas ---------- -->

<?php if ($vista === 'lista'): ?>
    <?php
    // ----- Lista con filtro -----
    $q      = trim($_GET['q'] ?? '');
    $estadoF= $_GET['estado'] ?? '';
    $sql = "SELECT r.id, r.fecha_inicio, r.fecha_fin, r.estado, r.nombre_habitacion,
                   r.numero_huespedes, h.nombre AS huesped, h.dni
            FROM reservas r
            JOIN huesped h ON h.id = r.id_huesped
            WHERE 1=1";
    
    $params = [];
    if ($q !== '') {
        $sql .= ' AND (h.nombre LIKE ? OR h.dni LIKE ? OR r.id = ?)';
        $params[] = "%$q%"; $params[] = "%$q%"; $params[] = ctype_digit($q) ? (int) $q : 0;
    }
    if (in_array($estadoF, ['pendiente','activa','finalizada','cancelada'], true)) {
        $sql .= ' AND r.estado = ?';
        $params[] = $estadoF;
    }
    $sql .= ' ORDER BY r.fecha_inicio DESC, r.id DESC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $reservas = $stmt->fetchAll();
    ?>
    
    <div class="tarjeta">
        <h2>Gestion de reservas</h2>

        <form method="get" class="barra-filtros">
            <input type="hidden" name="vista" value="lista">
            <div class="campo">
                <label for="q">Buscar (nombre, documento o N.° reserva)</label>
                <input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="Ej: Juan, 123456, 5">
            </div>
            <div class="campo">
                <label for="estado">Estado</label>
                <select id="estado" name="estado">
                    <option value="">Todos</option>
                    <?php foreach (['pendiente','activa','finalizada','cancelada'] as $op): ?>
                        <option value="<?= $op ?>" <?= $estadoF === $op ? 'selected' : '' ?>><?= ucfirst($op) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <button type="submit" class="btn btn-primario">Consultar</button>
            <a class="btn btn-secundario" href="<?= e(url('modulos/reservas.php')) ?>">Limpiar</a>
        </form>

        <div class="barra-botones">
            <a class="btn btn-primario" href="<?= e(url('modulos/reservas.php?vista=nueva')) ?>">+ Nueva reserva</a>
        </div>

        <div class="tabla-responsive" style="margin-top:1rem;">
            <table class="tabla-aes">
                <thead>
                    <tr>
                        <th>N.°</th><th>Huesped</th><th>Documento</th><th>Habitacion</th>
                        <th>Ingreso</th><th>Salida</th><th>Huespedes</th><th>Estado</th><th></th>
                    </tr>
                </thead>
                <tbody>
                
                <?php if (!$reservas): ?>
                    <tr><td colspan="9">No se encontraron reservas.</td></tr>
                <?php else: foreach ($reservas as $r): ?>
                    <tr>
                        <td><?= (int) $r['id'] ?></td>
                        <td><?= e($r['huesped']) ?></td>
                        <td><?= e($r['dni']) ?></td>
                        <td><?= e($r['nombre_habitacion']) ?></td>
                        <td><?= e($r['fecha_inicio']) ?></td>
                        <td><?= e($r['fecha_fin']) ?></td>
                        <td><?= (int) $r['numero_huespedes'] ?></td>
                        <td><?= chip_estado_reserva($r['estado']) ?></td>
                        <td><a class="btn btn-secundario" href="<?= e(url('modulos/reservas.php?vista=ver&id=' . $r['id'])) ?>">Ver</a></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <div class="barra-botones">
            <a class="btn btn-secundario" href="<?= e(url('modulos/menu.php')) ?>">Regresar al menu principal</a>
        </div>
    </div>

<!-- ----------Segunda vista- nueva reservas ---------- -->

<?php elseif ($vista === 'nueva'): ?>
    <?php
    // Valores por defecto (o los enviados si hubo error)
    $hoy = date('Y-m-d');
    $manana = date('Y-m-d', strtotime('+1 day'));
    $habitaciones = listar_habitaciones();
    $v = fn($k, $def = '') => e($_POST[$k] ?? $def);
    ?>
    
    <div class="tarjeta">
        <h2>Nueva reserva</h2>
        <form method="post" novalidate>
            <?= csrf_campo() ?>
            <input type="hidden" name="accion" value="guardar">

            <fieldset class="seccion-form">
                <legend>Datos del huesped</legend>
                <div class="campo">
                    <label for="nombre">Nombre completo <span class="obligatorio">*</span></label>
                    <input type="text" id="nombre" name="nombre" value="<?= $v('nombre') ?>" required>
                </div>
                
                <div class="campo">
                    <label for="dni">Numero de documento <span class="obligatorio">*</span></label>
                    <input type="text" id="dni" name="dni" value="<?= $v('dni') ?>" required>
                </div>
                
                <div class="campo">
                    <label for="telefono">Telefono <span class="obligatorio">*</span></label>
                    <input type="tel" id="telefono" name="telefono" value="<?= $v('telefono') ?>" required>
                </div>
                
                <div class="campo">
                    <label for="email">Correo electronico</label>
                    <input type="email" id="email" name="email" value="<?= $v('email') ?>">
                </div>
                
                <div class="campo">
                    <label for="nacionalidad">Nacionalidad <span class="obligatorio">*</span></label>
                    <input type="text" id="nacionalidad" name="nacionalidad" value="<?= $v('nacionalidad', 'Colombiana') ?>" required>
                </div>
            </fieldset>

            <fieldset class="seccion-form">
                <legend>Datos de la estadia</legend>
                <div class="campo">
                    <label for="fecha_inicio">Fecha de ingreso <span class="obligatorio">*</span></label>
                    <input type="date" id="fecha_inicio" name="fecha_inicio" value="<?= $v('fecha_inicio', $hoy) ?>" required>
                </div>
                
                <div class="campo">
                    <label for="fecha_fin">Fecha de salida <span class="obligatorio">*</span></label>
                    <input type="date" id="fecha_fin" name="fecha_fin" value="<?= $v('fecha_fin', $manana) ?>" required>
                </div>
                
                <div class="campo">
                    <label for="id_habitacion">Habitacion / tipo <span class="obligatorio">*</span></label>
                    <select id="id_habitacion" name="id_habitacion" required>
                        <option value="">Selecciona una habitacion</option>
                        <?php foreach ($habitaciones as $hab): ?>
                            <option value="<?= (int) $hab['id'] ?>" <?= ($_POST['id_habitacion'] ?? '') == $hab['id'] ? 'selected' : '' ?>>
                                <?= e($hab['numero'] . ' - ' . $hab['nombre'] . ' (' . $hab['tipo'] . ', cap. ' . $hab['capacidad'] . ', ' . money((float) $hab['precio_noche']) . '/noche)') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="texto-ayuda">El sistema verificara que no exista cruce con otra reserva.</span>
                </div>
                
                <div class="campo">
                    <label for="numero_huespedes">Numero de huespedes <span class="obligatorio">*</span></label>
                    <input type="number" id="numero_huespedes" name="numero_huespedes" min="1" value="<?= $v('numero_huespedes', '1') ?>" required>
                </div>
                
                <div class="campo">
                    <label for="canal_reserva">Canal de reserva</label>
                    <select id="canal_reserva" name="canal_reserva">
                        <?php foreach (['recepcion'=>'Recepcion','telefono'=>'Llamada telefonica','web'=>'Pagina web','plataforma'=>'Plataforma digital'] as $vv => $txt): ?>
                            <option value="<?= $vv ?>" <?= ($_POST['canal_reserva'] ?? '') === $vv ? 'selected' : '' ?>><?= $txt ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="campo">
                    <label for="estado">Estado de la reserva</label>
                    <select id="estado" name="estado">
                        <?php foreach (['pendiente','activa'] as $op): ?>
                            <option value="<?= $op ?>" <?= ($_POST['estado'] ?? 'pendiente') === $op ? 'selected' : '' ?>><?= ucfirst($op) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="campo">
                    <label for="observaciones">Observaciones</label>
                    <textarea id="observaciones" name="observaciones" rows="2"><?= $v('observaciones') ?></textarea>
                </div>
            </fieldset>

            <div class="barra-botones">
                <button type="submit" class="btn btn-primario">Registrar reserva</button>
                <a class="btn btn-secundario" href="<?= e(url('modulos/reservas.php')) ?>">Regresar al modulo</a>
                <a class="btn btn-secundario" href="<?= e(url('modulos/menu.php')) ?>">Menu principal</a>
            </div>
        </form>
    </div>

<!-- ----------Tercera vista- consultar y editar una reserva ---------- -->

<?php elseif ($vista === 'ver'): ?>
    <?php
    $id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
    $reserva = $id ? obtener_reserva($id) : null;
    if (!$reserva) {
        echo '<div class="tarjeta"><div class="alerta alerta-error">Reserva no encontrada.</div>'
            . '<a class="btn btn-secundario" href="' . e(url('modulos/reservas.php')) . '">Volver</a></div>';
    } else {
        $habitaciones = listar_habitaciones();
        $editable = !in_array($reserva['estado'], ['cancelada','finalizada'], true);
        $noches = calcular_noches($reserva['fecha_inicio'], $reserva['fecha_fin']);
        $total = $noches * (float) $reserva['precio_noche'];
    ?>
    
    <div class="tarjeta">
        <h2>Reserva N.° <?= (int) $reserva['id'] ?> <?= chip_estado_reserva($reserva['estado']) ?></h2>
        <p class="texto-ayuda">
            <?= $noches ?> noche(s) &middot; <?= money((float) $reserva['precio_noche']) ?>/noche &middot;
            Total estimado alojamiento: <strong><?= money($total) ?></strong>
        </p>

        <form method="post" novalidate>
            <?= csrf_campo() ?>
            <input type="hidden" name="accion" value="actualizar">
            <input type="hidden" name="id" value="<?= (int) $reserva['id'] ?>">

            <fieldset class="seccion-form">
                <legend>Datos del huesped</legend>
                <div class="campo">
                    <label>Nombre completo <span class="obligatorio">*</span></label>
                    <input type="text" name="nombre" value="<?= e($_POST['nombre'] ?? $reserva['huesped_nombre']) ?>" <?= $editable ? '' : 'readonly' ?> required>
                </div>
                
                <div class="campo">
                    <label>Numero de documento <span class="obligatorio">*</span></label>
                    <input type="text" name="dni" value="<?= e($_POST['dni'] ?? $reserva['huesped_dni']) ?>" <?= $editable ? '' : 'readonly' ?> required>
                </div>
                
                <div class="campo">
                    <label>Telefono <span class="obligatorio">*</span></label>
                    <input type="tel" name="telefono" value="<?= e($_POST['telefono'] ?? $reserva['huesped_telefono']) ?>" <?= $editable ? '' : 'readonly' ?> required>
                </div>
                
                <div class="campo">
                    <label>Correo electronico</label>
                    <input type="email" name="email" value="<?= e($_POST['email'] ?? $reserva['huesped_email']) ?>" <?= $editable ? '' : 'readonly' ?>>
                </div>
                
                <div class="campo">
                    <label>Nacionalidad <span class="obligatorio">*</span></label>
                    <input type="text" name="nacionalidad" value="<?= e($_POST['nacionalidad'] ?? $reserva['huesped_nacionalidad']) ?>" <?= $editable ? '' : 'readonly' ?> required>
                </div>
            </fieldset>

            <fieldset class="seccion-form">
                <legend>Datos de la estadia</legend>
                <div class="campo">
                    <label>Fecha de ingreso <span class="obligatorio">*</span></label>
                    <input type="date" name="fecha_inicio" value="<?= e($_POST['fecha_inicio'] ?? $reserva['fecha_inicio']) ?>" <?= $editable ? '' : 'readonly' ?> required>
                </div>
                
                <div class="campo">
                    <label>Fecha de salida <span class="obligatorio">*</span></label>
                    <input type="date" name="fecha_fin" value="<?= e($_POST['fecha_fin'] ?? $reserva['fecha_fin']) ?>" <?= $editable ? '' : 'readonly' ?> required>
                </div>
                
                <div class="campo">
                    <label>Habitacion <span class="obligatorio">*</span></label>
                    <select name="id_habitacion" <?= $editable ? '' : 'disabled' ?> required>
                        <?php foreach ($habitaciones as $hab): ?>
                            <option value="<?= (int) $hab['id'] ?>" <?= ($_POST['id_habitacion'] ?? $reserva['id_habitacion']) == $hab['id'] ? 'selected' : '' ?>>
                                <?= e($hab['numero'] . ' - ' . $hab['nombre'] . ' (' . $hab['tipo'] . ', cap. ' . $hab['capacidad'] . ')') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="campo">
                    <label>Numero de huespedes <span class="obligatorio">*</span></label>
                    <input type="number" name="numero_huespedes" min="1" value="<?= e($_POST['numero_huespedes'] ?? $reserva['numero_huespedes']) ?>" <?= $editable ? '' : 'readonly' ?> required>
                </div>
                
                <div class="campo">
                    <label>Estado</label>
                    <select name="estado" <?= $editable ? '' : 'disabled' ?>>
                        <?php foreach (['pendiente','activa','finalizada','cancelada'] as $op): ?>
                            <option value="<?= $op ?>" <?= ($_POST['estado'] ?? $reserva['estado']) === $op ? 'selected' : '' ?>><?= ucfirst($op) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="campo">
                    <label>Observaciones</label>
                    <textarea name="observaciones" rows="2" <?= $editable ? '' : 'readonly' ?>><?= e($_POST['observaciones'] ?? $reserva['observaciones']) ?></textarea>
                </div>
            </fieldset>

            <?php if ($editable): ?>
            <div class="barra-botones">
                <button type="submit" class="btn btn-primario">Guardar cambios</button>
            </div>
            <?php else: ?>
                <div class="alerta alerta-info">Esta reserva esta <?= e($reserva['estado']) ?> y no puede editarse.</div>
            <?php endif; ?>
        </form>

        <?php if ($editable): ?>
        <form method="post" data-confirmar="Seguro que deseas cancelar esta reserva? Esta accion liberara la habitacion.">
            <?= csrf_campo() ?>
            <input type="hidden" name="accion" value="cancelar">
            <input type="hidden" name="id" value="<?= (int) $reserva['id'] ?>">
            <div class="barra-botones">
                <button type="submit" class="btn btn-peligro">Cancelar reserva</button>
            </div>
        </form>
        <?php endif; ?>

        <div class="barra-botones">
            <a class="btn btn-secundario" href="<?= e(url('modulos/reservas.php')) ?>">Regresar al modulo</a>
            <a class="btn btn-secundario" href="<?= e(url('modulos/menu.php')) ?>">Menu principal</a>
        </div>
    </div>
    <?php } ?>
<?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
