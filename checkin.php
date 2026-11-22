<?php
/**
 * Modulo de Check-in.
 * - Buscar por numero de reserva (arrastra datos de la reserva).
 * - Registrar check-in con reserva (confirma llegada, activa la reserva).
 * - Registrar check-in SIN reserva (crea huesped + reserva + check-in).
 * - Permite abrir la cuenta de cobro (check-out) y agregar servicios.
 */

require_once __DIR__ . '/../includes/estancia_lib.php';
requerir_rol(['administrador', 'operario']);

$u        = usuario_actual();
$errores  = [];
$vista    = $_GET['vista'] ?? 'inicio';
$reserva  = null;
$checkinExistente = null;

/* ---------- Procesamiento de formularios (POST) ---------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verificar_csrf()) {
        $errores[] = 'Sesion expirada. Intenta nuevamente.';
    } else {
        $accion = $_POST['accion'] ?? '';

        /* ---------- Registrar check-in desde una reserva ---------- */
        if ($accion === 'checkin_reserva') {
            $idReserva = (int) ($_POST['id_reserva'] ?? 0);
            $reserva   = $idReserva ? obtener_reserva($idReserva) : null;
            $numPersonas = max(1, (int) ($_POST['numero_personas'] ?? 1));
            $cantidad    = max(1, (int) ($_POST['cantidad_huesped'] ?? $numPersonas));
            $obs         = trim($_POST['observaciones'] ?? '');

            // Validar los datos de la reserva. 
            if (!$reserva) {
                $errores[] = 'No se encontro la reserva indicada.';
            } elseif (in_array($reserva['estado'], ['cancelada','finalizada'], true)) {
                $errores[] = 'La reserva esta ' . $reserva['estado'] . ' y no admite check-in.';
            } elseif (checkin_por_reserva($idReserva)) {
                $errores[] = 'Esta reserva ya tiene un check-in registrado.';
            } else {
                
                db()->beginTransaction();
                try {
                    $ins = db()->prepare(
                        'INSERT INTO check_in (id_reservas, id_huesped, cantidad_huesped, fecha_llegada, numero_personas, observaciones)
                         VALUES (?, ?, ?, NOW(), ?, ?)'
                    );
                    
                    $ins->execute([
                        $idReserva, 
                        $reserva['id_huesped'], 
                        $cantidad, $numPersonas, 
                        $obs ?: null
                    ]);

                    $idCheckin = (int) db()->lastInsertId();

                    // Activa la reserva y marca la habitacion ocupada.
                    db()->prepare("UPDATE reservas SET estado = 'activa' WHERE id = ?")->execute([$idReserva]);
                    db()->prepare("UPDATE habitacion SET estado = 'ocupada' WHERE id = ?")->execute([$reserva['id_habitacion']]);

                    db()->commit();
                    flash('exito', 'Check-in registrado. Reserva activada.');
                    redirigir('/modulos/checkin.php?vista=detalle&id=' . $idCheckin);
                } catch (Throwable $e) {
                    db()->rollBack();
                    $errores[] = 'Error al registrar el check-in: ' . $e->getMessage();
                }
            }
            $vista = 'reserva';
        }

        /* ---------- Registrar check-in sin reserva ---------- */
        if ($accion === 'checkin_sin_reserva') {
            $d = [
                'nombre'       => trim($_POST['nombre'] ?? ''),
                'dni'          => trim($_POST['dni'] ?? ''),
                'telefono'     => trim($_POST['telefono'] ?? ''),
                'email'        => trim($_POST['email'] ?? ''),
                'nacionalidad' => trim($_POST['nacionalidad'] ?? ''),
            ];

            // Obtener los datos de la estancia
            $idHabitacion  = (int) ($_POST['id_habitacion'] ?? 0);
            $fechaInicio   = trim($_POST['fecha_inicio'] ?? date('Y-m-d'));
            $fechaFin      = trim($_POST['fecha_fin'] ?? date('Y-m-d', strtotime('+1 day')));
            $numPersonas   = max(1, (int) ($_POST['numero_personas'] ?? 1));
            $obs           = trim($_POST['observaciones'] ?? '');

            // Validar los datos
            if ($d['nombre'] === '')       $errores[] = 'El nombre del huesped es obligatorio.';
            if ($d['dni'] === '')          $errores[] = 'El documento es obligatorio.';
            if ($d['telefono'] === '')     $errores[] = 'El telefono es obligatorio.';
            if ($d['nacionalidad'] === '') $errores[] = 'La nacionalidad es obligatoria.';
            if ($idHabitacion <= 0)        $errores[] = 'Debes seleccionar una habitacion.';

            // Validar las fechas
            $errFecha = validar_fechas($fechaInicio, $fechaFin);
            if ($errFecha) $errores[] = $errFecha;

            // Consultar la habitacion
            $habitacion = $idHabitacion > 0 ? obtener_habitacion($idHabitacion) : null;
            if ($idHabitacion > 0 && !$habitacion) $errores[] = 'La habitacion no existe.';

            //  Comprobar la disponibilidad de la habitacion.
            if (!$errores && !habitacion_disponible($idHabitacion, $fechaInicio, $fechaFin)) {
                $errores[] = 'La habitacion no esta disponible en esas fechas.';
            }

            if (!$errores) {
                db()->beginTransaction();
                try {
                    $idHuesped = obtener_o_crear_huesped($d);

                    // Crear una reserva activa para respaldar el check-in sin reserva previa.
                    $insR = db()->prepare(
                        'INSERT INTO reservas
                         (id_huesped, id_usuario, id_habitacion, canal_reserva, tipo_habitacion,
                          fecha_inicio, fecha_fin, estado, nombre_habitacion, capacidad,
                          precio_noche, numero_huespedes, observaciones)
                         VALUES (?,?,?,?,?,?,?,"activa",?,?,?,?,?)'
                    );
                    $insR->execute([
                        $idHuesped, $u['id'], $idHabitacion, 'recepcion', $habitacion['tipo'],
                        $fechaInicio, $fechaFin, $habitacion['nombre'], $habitacion['capacidad'],
                        $habitacion['precio_noche'], $numPersonas, $obs ?: null,
                    ]);
                    
                    $idReserva = (int) db()->lastInsertId();
                    $insC = db()->prepare(
                        'INSERT INTO check_in (id_reservas, id_huesped, cantidad_huesped, fecha_llegada, numero_personas, observaciones)
                         VALUES (?, ?, ?, NOW(), ?, ?)'
                    );
                    $insC->execute([$idReserva, $idHuesped, $numPersonas, $numPersonas, $obs ?: null]);
                    $idCheckin = (int) db()->lastInsertId();

                    db()->prepare("UPDATE habitacion SET estado = 'ocupada' WHERE id = ?")->execute([$idHabitacion]);

                    db()->commit();
                    flash('exito', 'Check-in sin reserva registrado correctamente.');
                    redirigir('/modulos/checkin.php?vista=detalle&id=' . $idCheckin);
                } catch (Throwable $e) {
                    db()->rollBack();
                    $errores[] = 'Error al registrar: ' . $e->getMessage();
                }
            }
            $vista = 'sin_reserva';
        }

        /* ---------- Abrir cuenta de cobro (crear check-out pendiente) ---------- */
        if ($accion === 'abrir_cuenta') {
            $idCheckin = (int) ($_POST['id_checkin'] ?? 0);
            $checkin = obtener_checkin($idCheckin);
            
            // Validar la existencia del check-in y de la cuenta de cobro.
            if (!$checkin) {
                $errores[] = 'Check-in no encontrado.';
            } elseif (checkout_de_checkin($idCheckin)) {
                flash('info', 'La cuenta de cobro ya estaba abierta.');
                redirigir('/modulos/checkout.php?id_checkin=' . $idCheckin);
            } else {
                $aloj = valor_alojamiento($checkin);
                $serv = total_servicios($idCheckin);
                $ins = db()->prepare(
                    'INSERT INTO check_out (id_check_in, fecha_salida, valor_alojamiento, valor_servicios, total, estado_pago)
                     VALUES (?, NOW(), ?, ?, ?, "pendiente")'
                );
                $ins->execute([$idCheckin, $aloj, $serv, $aloj + $serv]);
                flash('exito', 'Cuenta de cobro abierta.');
                redirigir('/modulos/checkout.php?id_checkin=' . $idCheckin);
            }
        }

        /* ---------- Agregar servicio adicional desde check-in ---------- */
        if ($accion === 'agregar_servicio') {
            $idCheckin = (int) ($_POST['id_checkin'] ?? 0);
            $nombre    = trim($_POST['nombre_servicio'] ?? '');
            $precio    = (float) ($_POST['precio'] ?? 0);
            $obs       = trim($_POST['observacion'] ?? '');

            if (!obtener_checkin($idCheckin)) {
                $errores[] = 'Check-in no encontrado.';
            } elseif ($nombre === '' || $precio <= 0) {
                $errores[] = 'Indica un servicio y un valor mayor a cero.';
            } else {
                $ins = db()->prepare(
                    'INSERT INTO servicios_adicionales (id_check_in, nombre_servicio, precio, observacion)
                     VALUES (?, ?, ?, ?)'
                );
                $ins->execute([$idCheckin, $nombre, $precio, $obs ?: null]);
                // Mantener sincronizada la cuenta de cobro si ya existe.
                sincronizar_checkout($idCheckin);
                flash('exito', 'Servicio agregado a la cuenta.');
            }
            redirigir('/modulos/checkin.php?vista=detalle&id=' . $idCheckin);
        }
    }
    if ($errores) {
        // volver a la vista que corresponda sin perder el flujo
        $vista = $vista === 'inicio' ? 'inicio' : $vista;
    }
}

/* =================================================================
 * Preparar datos de vista (GET)
 * ================================================================= */
if ($vista === 'buscar' || ($vista === 'reserva' && !$errores)) {
    $num = (int) ($_GET['id_reserva'] ?? $_POST['id_reserva'] ?? 0);
    if ($num) {
        $reserva = obtener_reserva($num);
        $checkinExistente = $reserva ? checkin_por_reserva($num) : null;
        $vista = 'reserva';
    }
}

// Configurar e incluir el encabezado de la pagina. 
$tituloPagina = 'Check-in';
$mostrarBarra = true;
require __DIR__ . '/../includes/header.php';
?>

<!-- Mostrar mensajes de error -->
<div class="contenedor">
<?php foreach ($errores as $err): ?>
    <div class="alerta alerta-error"><?= e($err) ?></div>
<?php endforeach; ?>

<!-- Primera vista: detalle del check-in -->
<?php if ($vista === 'detalle'):
    $idCheckin = (int) ($_GET['id'] ?? 0);
    $checkin = obtener_checkin($idCheckin);
    if (!$checkin) {
        echo '<div class="tarjeta"><div class="alerta alerta-error">Check-in no encontrado.</div>'
            . '<a class="btn btn-secundario" href="' . e(url('modulos/checkin.php')) . '">Volver</a></div>';
    } else {
        $servicios = servicios_de_checkin($idCheckin);
        $aloj = valor_alojamiento($checkin);
        $serv = total_servicios($idCheckin);
        $checkout = checkout_de_checkin($idCheckin);
?>
   
    <div class="tarjeta">
        <h2>Check-in N.° <?= (int) $checkin['id'] ?></h2>
        <div class="seccion-form">
            <div class="titulo-seccion">Informacion de la estancia</div>
            <p>
                <strong>Reserva:</strong> <?= $checkin['reserva_id'] ? ('N.° ' . (int) $checkin['reserva_id']) : 'Sin reserva previa' ?><br>
                <strong>Huesped:</strong> <?= e($checkin['huesped_nombre']) ?> (<?= e($checkin['huesped_dni']) ?>)<br>
                <strong>Habitacion:</strong> <?= e($checkin['nombre_habitacion'] ?? '-') ?> (<?= e($checkin['tipo_habitacion'] ?? '-') ?>)<br>
                <strong>Llegada:</strong> <?= e($checkin['fecha_llegada']) ?><br>
                <strong>Personas:</strong> <?= (int) $checkin['numero_personas'] ?><br>
                <strong>Observaciones:</strong> <?= e($checkin['observaciones'] ?: '-') ?>
            </p>
            
            <p>
                <strong>Alojamiento:</strong> <?= money($aloj) ?> &middot;
                <strong>Servicios:</strong> <?= money($serv) ?> &middot;
                <strong>Total:</strong> <?= money($aloj + $serv) ?>
            </p>
        </div>

        <!-- Servicios adicionales -->
        <div class="seccion-form">
            <div class="titulo-seccion">Servicios adicionales</div>
            <div class="tabla-responsive">
                <table class="tabla-aes">
                    <thead><tr><th>Servicio</th><th>Valor</th><th>Observacion</th></tr></thead>
                    <tbody>
                    <?php if (!$servicios): ?>
                        <tr><td colspan="3">Sin servicios adicionales.</td></tr>
                    <?php else: foreach ($servicios as $s): ?>
                        <tr>
                            <td><?= e($s['nombre_servicio']) ?></td>
                            <td><?= money((float) $s['precio']) ?></td>
                            <td><?= e($s['observacion'] ?: '-') ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

            <form method="post" style="margin-top:0.8rem;">
                <?= csrf_campo() ?>
                <input type="hidden" name="accion" value="agregar_servicio">
                <input type="hidden" name="id_checkin" value="<?= (int) $checkin['id'] ?>">
                <div class="barra-filtros">
                    <div class="campo">
                        <label>Servicio</label>
                        <input type="text" name="nombre_servicio" list="serv_base" placeholder="Ej: Tour al lago" required>
                        <datalist id="serv_base">
                            <?php foreach (servicios_base() as $sb): ?>
                                <option value="<?= e($sb['nombre']) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="campo">
                        <label>Valor</label>
                        <input type="number" name="precio" min="0" step="500" required>
                    </div>
                    <div class="campo">
                        <label>Observacion</label>
                        <input type="text" name="observacion">
                    </div>
                    <button type="submit" class="btn btn-primario">Anadir servicio</button>
                </div>
            </form>
        </div>

        <div class="barra-botones">
            <?php if (!$checkout): ?>
                <form method="post" style="display:inline">
                    <?= csrf_campo() ?>
                    <input type="hidden" name="accion" value="abrir_cuenta">
                    <input type="hidden" name="id_checkin" value="<?= (int) $checkin['id'] ?>">
                    <button type="submit" class="btn btn-primario">Abrir cuenta de cobro</button>
                </form>
            <?php else: ?>
                <a class="btn btn-primario" href="<?= e(url('modulos/checkout.php?id_checkin=' . $checkin['id'])) ?>">Ir al check-out</a>
            <?php endif; ?>
            <a class="btn btn-secundario" href="<?= e(url('modulos/servicios.php?id_checkin=' . $checkin['id'])) ?>">Gestionar servicios</a>
            <a class="btn btn-secundario" href="<?= e(url('modulos/checkin.php')) ?>">Nuevo check-in</a>
            <a class="btn btn-secundario" href="<?= e(url('modulos/menu.php')) ?>">Menu principal</a>
        </div>
    </div>
<?php } ?>

<?php elseif ($vista === 'reserva' && $reserva): ?>
    <div class="tarjeta">
        <h2>Check-in &mdash; Reserva N.° <?= (int) $reserva['id'] ?></h2>
        <?php if ($checkinExistente): ?>
            <div class="alerta alerta-info">Esta reserva ya tiene un check-in registrado.</div>
            <a class="btn btn-primario" href="<?= e(url('modulos/checkin.php?vista=detalle&id=' . $checkinExistente['id'])) ?>">Ver check-in</a>
        
        <?php else: ?>
            <div class="seccion-form">
                <div class="titulo-seccion">Datos arrastrados de la reserva</div>
                <p>
                    <strong>Huesped:</strong> <?= e($reserva['huesped_nombre']) ?> (<?= e($reserva['huesped_dni']) ?>)<br>
                    <strong>Habitacion:</strong> <?= e($reserva['nombre_habitacion']) ?> &mdash; N.° <?= e($reserva['habitacion_numero']) ?> (<?= e($reserva['tipo_habitacion']) ?>)<br>
                    <strong>Ingreso:</strong> <?= e($reserva['fecha_inicio']) ?> &middot; <strong>Salida:</strong> <?= e($reserva['fecha_fin']) ?><br>
                    <strong>Estado reserva:</strong> <?= chip_estado_reserva($reserva['estado']) ?>
                </p>
            </div>
            
            <form method="post" novalidate>
                <?= csrf_campo() ?>
                <input type="hidden" name="accion" value="checkin_reserva">
                <input type="hidden" name="id_reserva" value="<?= (int) $reserva['id'] ?>">
                <div class="campo">
                    <label>Numero de personas que ingresan <span class="obligatorio">*</span></label>
                    <input type="number" name="numero_personas" min="1" value="<?= (int) $reserva['numero_huespedes'] ?>" required>
                </div>
                
                <div class="campo">
                    <label>Observaciones</label>
                    <textarea name="observaciones" rows="2"><?= e($reserva['observaciones']) ?></textarea>
                </div>
                <div class="barra-botones">
                    <button type="submit" class="btn btn-primario">Registrar check-in</button>
                    <a class="btn btn-secundario" href="<?= e(url('modulos/checkin.php')) ?>">Regresar</a>
                </div>
            </form>
        <?php endif; ?>
        
        <div class="barra-botones">
            <a class="btn btn-secundario" href="<?= e(url('modulos/menu.php')) ?>">Menu principal</a>
        </div>
    </div>

<?php elseif ($vista === 'sin_reserva'): ?>
    <?php $hab = listar_habitaciones(); $v = fn($k,$d='') => e($_POST[$k] ?? $d); ?>
    <div class="tarjeta">
        <h2>Check-in sin reserva</h2>
        <form method="post" novalidate>
            <?= csrf_campo() ?>
            <input type="hidden" name="accion" value="checkin_sin_reserva">
            <fieldset class="seccion-form">
                <legend>Datos del huesped</legend>
                <div class="campo"><label>Nombre completo <span class="obligatorio">*</span></label>
                    <input type="text" name="nombre" value="<?= $v('nombre') ?>" required></div>
                <div class="campo"><label>Documento <span class="obligatorio">*</span></label>
                    <input type="text" name="dni" value="<?= $v('dni') ?>" required></div>
                <div class="campo"><label>Telefono <span class="obligatorio">*</span></label>
                    <input type="tel" name="telefono" value="<?= $v('telefono') ?>" required></div>
                <div class="campo"><label>Correo electronico</label>
                    <input type="email" name="email" value="<?= $v('email') ?>"></div>
                <div class="campo"><label>Nacionalidad <span class="obligatorio">*</span></label>
                    <input type="text" name="nacionalidad" value="<?= $v('nacionalidad','Colombiana') ?>" required></div>
            </fieldset>
            
            <fieldset class="seccion-form">
                <legend>Datos de la estancia</legend>
                <div class="campo"><label>Fecha de ingreso <span class="obligatorio">*</span></label>
                    <input type="date" name="fecha_inicio" value="<?= $v('fecha_inicio', date('Y-m-d')) ?>" required></div>
                <div class="campo"><label>Fecha de salida <span class="obligatorio">*</span></label>
                    <input type="date" name="fecha_fin" value="<?= $v('fecha_fin', date('Y-m-d', strtotime('+1 day'))) ?>" required></div>
                <div class="campo"><label>Habitacion <span class="obligatorio">*</span></label>
                    <select name="id_habitacion" required>
                        <option value="">Selecciona</option>
                        <?php foreach ($hab as $h): ?>
                            <option value="<?= (int) $h['id'] ?>" <?= ($_POST['id_habitacion'] ?? '') == $h['id'] ? 'selected' : '' ?>>
                                <?= e($h['numero'] . ' - ' . $h['nombre'] . ' (cap. ' . $h['capacidad'] . ')') ?>
                            </option>
                        <?php endforeach; ?>
                    </select></div>
                
                <div class="campo"><label>Numero de personas <span class="obligatorio">*</span></label>
                    <input type="number" name="numero_personas" min="1" value="<?= $v('numero_personas','1') ?>" required></div>
                <div class="campo"><label>Observaciones</label>
                    <textarea name="observaciones" rows="2"><?= $v('observaciones') ?></textarea></div>
            </fieldset>
            
            <div class="barra-botones">
                <button type="submit" class="btn btn-primario">Guardar check-in</button>
                <a class="btn btn-secundario" href="<?= e(url('modulos/checkin.php')) ?>">Regresar</a>
            </div>
        </form>
    </div>

<?php else: ?>
    <!-- Vista inicio: buscar reserva o check-in sin reserva -->
    <div class="tarjeta">
        <h2>Check-in</h2>
        <form method="get" class="barra-filtros">
            <input type="hidden" name="vista" value="buscar">
            <div class="campo">
                <label for="id_reserva">Numero de reserva</label>
                <input type="number" id="id_reserva" name="id_reserva" min="1" placeholder="Ej: 5" required>
            </div>
            <button type="submit" class="btn btn-primario">Buscar</button>
        </form>
        
        <div class="barra-botones">
            <a class="btn btn-secundario" href="<?= e(url('modulos/checkin.php?vista=sin_reserva')) ?>">Sin reserva</a>
            <a class="btn btn-secundario" href="<?= e(url('modulos/menu.php')) ?>">Regresar al menu principal</a>
        </div>
    </div>
<?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
