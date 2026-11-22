<?php
/**
 * Modulo de Check-out.
 * - Busca por numero de reserva (o recibe id_checkin directo).
 * - Muestra el resumen: alojamiento, servicios, total, estado de pago.
 * - Pagar cuenta -> envia el ingreso a finanzas y marca pagado.
 * - Registrar check-out -> libera la habitacion (si no hay otra reserva vigente).
 * - Enviar cuenta de cobro al correo (simulado).
 */

require_once __DIR__ . '/../includes/estancia_lib.php';
requerir_rol(['administrador', 'operario']);

$errores   = [];
$idCheckin = (int) ($_GET['id_checkin'] ?? $_POST['id_checkin'] ?? 0);

/* Busca el check-in asociado al número de reserva recibido. */
if (!$idCheckin && isset($_GET['id_reserva'])) {
    $ci = checkin_por_reserva((int) $_GET['id_reserva']);
    if ($ci) {
        redirigir('/modulos/checkout.php?id_checkin=' . $ci['id']);
    } else {
        $errores[] = 'No existe un check-in para esa reserva. Primero registra el check-in.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verificar_csrf()) {
        $errores[] = 'Sesion expirada. Intenta nuevamente.';
    } else {
        $accion  = $_POST['accion'] ?? '';
        $checkin = obtener_checkin($idCheckin);

        if (!$checkin) {
            $errores[] = 'Check-in no encontrado.';
        } else {
            
            // Crear la cuenta de cobro si todavía no existe.
            $checkout = checkout_de_checkin($idCheckin);
            if (!$checkout && in_array($accion, ['pagar','finalizar','enviar_cuenta'], true)) {
                $aloj = valor_alojamiento($checkin);
                $serv = total_servicios($idCheckin);
                db()->prepare(
                    'INSERT INTO check_out (id_check_in, fecha_salida, valor_alojamiento, valor_servicios, total, estado_pago)
                     VALUES (?, NOW(), ?, ?, ?, "pendiente")'
                )->execute([$idCheckin, $aloj, $serv, $aloj + $serv]);
                $checkout = checkout_de_checkin($idCheckin);
            }

            if ($accion === 'pagar') {
                // Recalcula los valores por si se agregaron o modificaron servicios.
                sincronizar_checkout($idCheckin);
                $checkout = checkout_de_checkin($idCheckin);
                $medio = $_POST['medio_pago'] ?? 'efectivo';
                try {
                    if (registrar_pago_checkout((int) $checkout['id'], $medio)) {
                        flash('exito', 'Pago registrado y enviado a finanzas.');
                    } else {
                        flash('info', 'La cuenta ya estaba pagada.');
                    }
                } catch (Throwable $e) {
                    $errores[] = 'Error al registrar el pago: ' . $e->getMessage();
                }
                if (!$errores) redirigir('/modulos/checkout.php?id_checkin=' . $idCheckin);
            }

            if ($accion === 'finalizar') {
                if ($checkout['estado_pago'] !== 'pagado') {
                    $errores[] = 'No se puede finalizar: la cuenta debe estar a paz y salvo (pagada).';
                } else {
                    db()->beginTransaction();
                    
                    try {
                        // Marca la reserva como finalizada.
                        $idReserva = (int) $checkin['reserva_id'];
                        if ($idReserva) {
                            db()->prepare("UPDATE reservas SET estado='finalizada' WHERE id=?")->execute([$idReserva]);
                        }
                        
                        // Libera la habitación si no existen otras reservas vigentes.
                        $idHab = (int) $checkin['id_habitacion'];
                        if ($idHab) {
                            $stmt = db()->prepare(
                                "SELECT COUNT(*) FROM reservas
                                 WHERE id_habitacion=? AND estado IN ('pendiente','activa')
                                   AND fecha_fin > CURRENT_DATE"
                            );
                            $stmt->execute([$idHab]);
                            $nuevoEstado = ((int) $stmt->fetchColumn() > 0) ? 'ocupada' : 'disponible';
                            db()->prepare('UPDATE habitacion SET estado=? WHERE id=?')->execute([$nuevoEstado, $idHab]);
                        }

                        db()->commit();
                        flash('exito', 'Check-out registrado. Estancia finalizada y habitacion actualizada.');
                        redirigir('/modulos/checkout.php?id_checkin=' . $idCheckin);
                    } catch (Throwable $e) {
                        db()->rollBack();
                        $errores[] = 'Error al finalizar: ' . $e->getMessage();
                    }
                }
            }

            if ($accion === 'enviar_cuenta') {
                $email = $checkin['huesped_email'];
                if (!$email) {
                    $errores[] = 'El huesped no tiene correo registrado.';
                } else {
                    $aloj = (float) $checkout['valor_alojamiento'];
                    $serv = (float) $checkout['valor_servicios'];
                    
                    // Construye el contenido de la cuenta de cobro para enviarlo al huésped.
                    $cuerpo = "Cuenta de cobro - " . HOSTAL_NOMBRE . "\n"
                        . "Huesped: " . $checkin['huesped_nombre'] . "\n"
                        . "Habitacion: " . ($checkin['nombre_habitacion'] ?? '-') . "\n"
                        . "Alojamiento: " . money($aloj) . "\n"
                        . "Servicios: " . money($serv) . "\n"
                        . "TOTAL: " . money((float) $checkout['total']) . "\n"
                        . "Estado: " . $checkout['estado_pago'];
                    enviar_correo_simulado($email, 'Cuenta de cobro - ' . HOSTAL_NOMBRE, $cuerpo);
                    flash('exito', 'Cuenta de cobro enviada al correo ' . $email . ' (simulado: ver storage/correos.log).');
                    redirigir('/modulos/checkout.php?id_checkin=' . $idCheckin);
                }
            }
        }
    }
}

/* ==========================================================
   VISUALIZACIÓN DEL MÓDULO (HTML)
   ========================================================== */

$checkin = $idCheckin ? obtener_checkin($idCheckin) : null;

$tituloPagina = 'Check-out';
$mostrarBarra = true;
require __DIR__ . '/../includes/header.php';
?>

<div class="contenedor">
<!-- Muestra los mensajes de error generados durante el proceso. -->
<?php foreach ($errores as $err): ?>
    <div class="alerta alerta-error"><?= e($err) ?></div>
<?php endforeach; ?>

<?php if (!$checkin): ?>
    <div class="tarjeta">
        <h2>Check-out</h2>
        <p class="texto-ayuda">Busca la estancia por numero de reserva.</p>
        <form method="get" class="barra-filtros">
            <div class="campo">
                <label for="id_reserva">Numero de reserva</label>
                <input type="number" id="id_reserva" name="id_reserva" min="1" required>
            </div>
            <button type="submit" class="btn btn-primario">Buscar</button>
        </form>
        <div class="barra-botones">
            <a class="btn btn-secundario" href="<?= e(url('modulos/menu.php')) ?>">Regresar al menu principal</a>
        </div>
    </div>

<?php else:
    // Consulta la cuenta de cobro y actualiza los totales.
    $checkout = checkout_de_checkin($idCheckin);
    if ($checkout) { sincronizar_checkout($idCheckin); $checkout = checkout_de_checkin($idCheckin); }
    $aloj = $checkout ? (float) $checkout['valor_alojamiento'] : valor_alojamiento($checkin);
    $serv = $checkout ? (float) $checkout['valor_servicios'] : total_servicios($idCheckin);
    $total = $aloj + $serv;
    $pagado = $checkout && $checkout['estado_pago'] === 'pagado';
    $finalizada = ($checkin['reserva_estado'] ?? '') === 'finalizada';
?>
    
    <div class="tarjeta">
        <h2>Check-out &mdash; <?= e($checkin['huesped_nombre']) ?></h2>
        <div class="seccion-form">
            <div class="titulo-seccion">Resumen de la estancia</div>
            <p>
                <strong>N.° de reserva:</strong> <?= $checkin['reserva_id'] ? (int) $checkin['reserva_id'] : '-' ?><br>
                <strong>Huesped:</strong> <?= e($checkin['huesped_nombre']) ?> (<?= e($checkin['huesped_dni']) ?>)<br>
                <strong>Habitacion:</strong> <?= e($checkin['nombre_habitacion'] ?? '-') ?><br>
                <strong>Fecha/hora de salida:</strong> <?= e($checkout['fecha_salida'] ?? date('Y-m-d H:i')) ?>
            </p>
            
            <div class="tabla-responsive">
                <table class="tabla-aes">
                    <tbody>
                        <tr><td>Valor del alojamiento</td><td><?= money($aloj) ?></td></tr>
                        <tr><td>Valor de servicios adicionales</td><td><?= money($serv) ?></td></tr>
                        <tr><th>Total de la cuenta</th><th><?= money($total) ?></th></tr>
                        <tr><td>Estado del pago</td>
                            <td><span class="chip <?= $pagado ? 'chip-activo' : 'chip-pendiente' ?>"><?= $pagado ? 'Pagado (paz y salvo)' : 'Pendiente' ?></span></td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($finalizada): ?>
            <div class="alerta alerta-exito">Esta estancia ya fue finalizada (check-out completado).</div>
        
        <?php else: ?>
            <?php if (!$pagado): ?>
                <!-- Formulario para registrar el pago de la cuenta -->
                <form method="post" class="seccion-form">
                    <?= csrf_campo() ?>
                    <input type="hidden" name="accion" value="pagar">
                    <input type="hidden" name="id_checkin" value="<?= (int) $idCheckin ?>">
                    <div class="titulo-seccion">Pagar cuenta</div>
                    <div class="barra-filtros">
                        <div class="campo">
                            <label>Medio de pago</label>
                            <select name="medio_pago">
                                <?php foreach (['efectivo','consignacion','tarjeta credito','tarjeta debito'] as $mp): ?>
                                    <option value="<?= e($mp) ?>"><?= e(ucfirst($mp)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primario">Pagar cuenta (<?= money($total) ?>)</button>
                    </div>
                </form>
            <?php endif; ?>

            <div class="barra-botones">
                <?php if ($pagado): ?>
                    <form method="post" style="display:inline" data-confirmar="Finalizar la estancia y liberar la habitacion?">
                        <?= csrf_campo() ?>
                        <input type="hidden" name="accion" value="finalizar">
                        <input type="hidden" name="id_checkin" value="<?= (int) $idCheckin ?>">
                        <button type="submit" class="btn btn-primario">Registrar check-out (finalizar)</button>
                    </form>
                <?php endif; ?>

                <form method="post" style="display:inline">
                    <?= csrf_campo() ?>
                    <input type="hidden" name="accion" value="enviar_cuenta">
                    <input type="hidden" name="id_checkin" value="<?= (int) $idCheckin ?>">
                    <button type="submit" class="btn btn-secundario">Enviar cuenta de cobro al correo</button>
                </form>
            </div>
        <?php endif; ?>

        <div class="barra-botones">
            <a class="btn btn-secundario" href="<?= e(url('modulos/servicios.php?id_checkin=' . $idCheckin)) ?>">Gestionar servicios</a>
            <a class="btn btn-secundario" href="<?= e(url('modulos/menu.php')) ?>">Regresar al menu principal</a>
        </div>
    </div>
<?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
