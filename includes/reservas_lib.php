<?php
/**
 * Logica compartida de habitaciones, reservas y disponibilidad.
 */
require_once __DIR__ . '/funciones.php';

/** ID del hostal con el que trabaja el sistema (mono-hostal por ahora). */
function hostal_id_actual(): int
{
    static $id = null;
    if ($id === null) {
        $id = (int) (db()->query('SELECT id FROM hostal ORDER BY id LIMIT 1')->fetchColumn() ?: 1);
    }
    return $id;
}

/** Lista todas las habitaciones del hostal. */
function listar_habitaciones(): array
{
    $stmt = db()->prepare('SELECT * FROM habitacion WHERE id_hostal = ? ORDER BY numero');
    $stmt->execute([hostal_id_actual()]);
    return $stmt->fetchAll();
}

/** Obtiene una habitacion por id. */
function obtener_habitacion(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM habitacion WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** Tipos de habitacion distintos (para selects). */
function tipos_habitacion(): array
{
    $stmt = db()->prepare('SELECT DISTINCT tipo FROM habitacion WHERE id_hostal = ? ORDER BY tipo');
    $stmt->execute([hostal_id_actual()]);
    return array_column($stmt->fetchAll(), 'tipo');
}

/**
 * Determina si una habitacion esta libre en el rango [inicio, fin).
 * Se considera cruce cuando las reservas activas/pendientes se solapan.
 * $excluirReserva permite ignorar una reserva (al editar).
 *
 * Regla de solapamiento:  inicioA < finB  AND  finA > inicioB
 * (el dia de salida libera la habitacion, por eso se usa < y >).
 */
function habitacion_disponible(int $idHabitacion, string $inicio, string $fin, ?int $excluirReserva = null): bool
{
    $sql = "SELECT COUNT(*) FROM reservas
            WHERE id_habitacion = ?
              AND estado IN ('pendiente','activa')
              AND fecha_inicio < ?
              AND fecha_fin > ?";
    $params = [$idHabitacion, $fin, $inicio];

    if ($excluirReserva !== null) {
        $sql .= ' AND id <> ?';
        $params[] = $excluirReserva;
    }

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn() === 0;
}

/**
 * Devuelve las habitaciones disponibles para un rango de fechas.
 * Util para el formulario de nueva reserva.
 */
function habitaciones_disponibles(string $inicio, string $fin, ?int $excluirReserva = null): array
{
    $disponibles = [];
    foreach (listar_habitaciones() as $hab) {
        if ($hab['estado'] === 'mantenimiento') {
            continue;
        }
        if (habitacion_disponible((int) $hab['id'], $inicio, $fin, $excluirReserva)) {
            $disponibles[] = $hab;
        }
    }
    return $disponibles;
}

/** Numero de noches entre dos fechas (minimo 1). */
function calcular_noches(string $inicio, string $fin): int
{
    $d1 = new DateTime($inicio);
    $d2 = new DateTime($fin);
    $dias = (int) $d1->diff($d2)->days;
    return max($dias, 1);
}

/** Valida que las fechas sean coherentes. Devuelve mensaje de error o ''. */
function validar_fechas(string $inicio, string $fin): string
{
    if ($inicio === '' || $fin === '') {
        return 'Debes indicar fecha de ingreso y de salida.';
    }
    $d1 = DateTime::createFromFormat('Y-m-d', $inicio);
    $d2 = DateTime::createFromFormat('Y-m-d', $fin);
    if (!$d1 || !$d2) {
        return 'Las fechas no tienen un formato valido.';
    }
    if ($d2 <= $d1) {
        return 'La fecha de salida debe ser posterior a la de ingreso.';
    }
    return '';
}

/**
 * Busca o crea un huesped por DNI dentro del hostal.
 * Actualiza sus datos si ya existe. Devuelve el id del huesped.
 */
function obtener_o_crear_huesped(array $datos): int
{
    $stmt = db()->prepare('SELECT id FROM huesped WHERE dni = ? LIMIT 1');
    $stmt->execute([$datos['dni']]);
    $id = $stmt->fetchColumn();

    if ($id) {
        $upd = db()->prepare(
            'UPDATE huesped SET nombre = ?, telefono = ?, email = ?, nacionalidad = ? WHERE id = ?'
        );
        $upd->execute([
            $datos['nombre'], $datos['telefono'], $datos['email'] ?: null,
            $datos['nacionalidad'], $id,
        ]);
        return (int) $id;
    }

    $ins = db()->prepare(
        'INSERT INTO huesped (id_hostal, nombre, dni, telefono, email, nacionalidad)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $ins->execute([
        hostal_id_actual(), $datos['nombre'], $datos['dni'],
        $datos['telefono'], $datos['email'] ?: null, $datos['nacionalidad'],
    ]);
    return (int) db()->lastInsertId();
}

/** Trae una reserva con datos del huesped y la habitacion. */
function obtener_reserva(int $id): ?array
{
    $stmt = db()->prepare(
        'SELECT r.*, h.nombre AS huesped_nombre, h.dni AS huesped_dni,
                h.telefono AS huesped_telefono, h.email AS huesped_email,
                h.nacionalidad AS huesped_nacionalidad,
                hab.numero AS habitacion_numero
         FROM reservas r
         JOIN huesped h ON h.id = r.id_huesped
         JOIN habitacion hab ON hab.id = r.id_habitacion
         WHERE r.id = ? LIMIT 1'
    );
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** Etiqueta CSS para el estado de una reserva. */
function chip_estado_reserva(string $estado): string
{
    $map = [
        'pendiente'  => 'chip-pendiente',
        'activa'     => 'chip-activo',
        'finalizada' => 'chip-inactivo',
        'cancelada'  => 'chip-cancelada',
    ];
    $clase = $map[$estado] ?? 'chip-inactivo';
    return '<span class="chip ' . $clase . '">' . e(ucfirst($estado)) . '</span>';
}
