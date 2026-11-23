<?php
/**
 * Logica compartida de check-in, servicios adicionales y check-out.
 */
require_once __DIR__ . '/reservas_lib.php';

/** Lista los servicios base configurables (catalogo editable). */
function servicios_base(): array
{
    // Catalogo por defecto. Se puede ampliar en la tabla de servicios por check-in.
    return [
        ['nombre' => 'Bolsa de agua', 'precio' => 3000],
        ['nombre' => 'Cerveza',       'precio' => 6000],
        ['nombre' => 'Tour',          'precio' => 0], // precio manual
    ];
}

/** Obtiene un check-in con datos del huesped y (si tiene) la reserva. */
function obtener_checkin(int $id): ?array
{
    $stmt = db()->prepare(
        'SELECT c.*, h.nombre AS huesped_nombre, h.dni AS huesped_dni,
                h.telefono AS huesped_telefono, h.email AS huesped_email,
                r.id AS reserva_id, r.fecha_inicio, r.fecha_fin, r.estado AS reserva_estado,
                r.nombre_habitacion, r.tipo_habitacion, r.precio_noche, r.id_habitacion
         FROM check_in c
         JOIN huesped h ON h.id = c.id_huesped
         LEFT JOIN reservas r ON r.id = c.id_reservas
         WHERE c.id = ? LIMIT 1'
    );
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** Busca el check-in asociado a un numero de reserva. */
function checkin_por_reserva(int $idReserva): ?array
{
    $stmt = db()->prepare('SELECT id FROM check_in WHERE id_reservas = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$idReserva]);
    $id = $stmt->fetchColumn();
    return $id ? obtener_checkin((int) $id) : null;
}

/** Lista los servicios adicionales de un check-in. */
function servicios_de_checkin(int $idCheckin): array
{
    $stmt = db()->prepare('SELECT * FROM servicios_adicionales WHERE id_check_in = ? ORDER BY id');
    $stmt->execute([$idCheckin]);
    return $stmt->fetchAll();
}

/** Suma el total de servicios adicionales de un check-in. */
function total_servicios(int $idCheckin): float
{
    $stmt = db()->prepare('SELECT COALESCE(SUM(precio),0) FROM servicios_adicionales WHERE id_check_in = ?');
    $stmt->execute([$idCheckin]);
    return (float) $stmt->fetchColumn();
}

/**
 * Calcula el valor del alojamiento para un check-in.
 * Si tiene reserva, usa noches * precio_noche; si no, 0 (se puede ajustar).
 */
function valor_alojamiento(array $checkin): float
{
    if (!empty($checkin['fecha_inicio']) && !empty($checkin['fecha_fin'])) {
        $noches = calcular_noches($checkin['fecha_inicio'], $checkin['fecha_fin']);
        return $noches * (float) $checkin['precio_noche'];
    }
    return 0.0;
}

/** Devuelve el check-out asociado a un check-in (o null). */
function checkout_de_checkin(int $idCheckin): ?array
{
    $stmt = db()->prepare('SELECT * FROM check_out WHERE id_check_in = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$idCheckin]);
    return $stmt->fetch() ?: null;
}

/**
 * Recalcula totales del check-out asociado (si existe y sigue pendiente).
 */
function sincronizar_checkout(int $idCheckin): void
{
    $co = checkout_de_checkin($idCheckin);
    if ($co && $co['estado_pago'] === 'pendiente') {
        $checkin = obtener_checkin($idCheckin);
        $aloj = valor_alojamiento($checkin);
        $serv = total_servicios($idCheckin);
        $upd = db()->prepare('UPDATE check_out SET valor_alojamiento=?, valor_servicios=?, total=? WHERE id=?');
        $upd->execute([$aloj, $serv, $aloj + $serv, $co['id']]);
    }
}

/**
 * Registra el pago de una cuenta en finanzas (ingreso) y marca el
 * check-out como pagado. Devuelve true si se aplico.
 */
function registrar_pago_checkout(int $idCheckout, string $medioPago): bool
{
    $co = db()->prepare('SELECT * FROM check_out WHERE id = ? LIMIT 1');
    $co->execute([$idCheckout]);
    $checkout = $co->fetch();
    if (!$checkout || $checkout['estado_pago'] === 'pagado') {
        return false;
    }

    db()->beginTransaction();
    try {
        $upd = db()->prepare("UPDATE check_out SET estado_pago = 'pagado' WHERE id = ?");
        $upd->execute([$idCheckout]);

        $ins = db()->prepare(
            'INSERT INTO finanzas (id_check_out, fecha, tipo, concepto, monto, medio_pago, es_fijo)
             VALUES (?, CURRENT_DATE, "ingreso", ?, ?, ?, 0)'
        );
        $ins->execute([
            $idCheckout,
            'Pago de estancia (check-out N.° ' . $idCheckout . ')',
            (float) $checkout['total'],
            $medioPago,
        ]);
        db()->commit();
        return true;
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
}
