<?php
/**
 * Modulo de disponibilidad (solo visual).
 * Se alimenta de las reservas: para cada habitacion muestra si esta
 * disponible u ocupada mostrando la fecha respectiva
 * Permite filtrar por habitacion, estado y fecha.
 *
 * Permisos: administrador y operario (solo lectura).
 */
require_once __DIR__ . '/../includes/reservas_lib.php';
requerir_rol(['administrador', 'operario']);

$fecha      = $_GET['fecha'] ?? date('Y-m-d');
$habFiltro  = (int) ($_GET['habitacion'] ?? 0);
$estadoF    = $_GET['estado'] ?? '';

if (!DateTime::createFromFormat('Y-m-d', $fecha)) {
    $fecha = date('Y-m-d');
}

$habitaciones = listar_habitaciones();

/**
 * Para una habitacion y fecha, determina estado y datos relacionados.
 */
function estado_habitacion_en_fecha(int $idHabitacion, string $fecha): array
{
    // Reserva que cubre la fecha consultada (ocupada)
    $stmt = db()->prepare(
        "SELECT r.*, h.nombre AS huesped
         FROM reservas r JOIN huesped h ON h.id = r.id_huesped
         WHERE r.id_habitacion = ?
           AND r.estado IN ('pendiente','activa')
           AND r.fecha_inicio <= ? AND r.fecha_fin > ?
         ORDER BY r.fecha_inicio LIMIT 1"
    );
    $stmt->execute([$idHabitacion, $fecha, $fecha]);
    $ocupa = $stmt->fetch();

    if ($ocupa) {
        return [
            'estado'   => 'ocupada',
            'detalle'  => 'Ocupada por ' . $ocupa['huesped'] . ' hasta ' . $ocupa['fecha_fin'],
            'huesped'  => $ocupa['huesped'],
            'desde'    => $ocupa['fecha_inicio'],
            'hasta'    => $ocupa['fecha_fin'],
        ];
    }

    // Disponible: ¿tiene una reserva futura? -> "disponible hasta tal fecha"
    $stmt = db()->prepare(
        "SELECT fecha_inicio FROM reservas
         WHERE id_habitacion = ? AND estado IN ('pendiente','activa') AND fecha_inicio > ?
         ORDER BY fecha_inicio LIMIT 1"
    );
    $stmt->execute([$idHabitacion, $fecha]);
    $prox = $stmt->fetchColumn();

    return [
        'estado'  => 'disponible',
        'detalle' => $prox ? ('Disponible hasta ' . $prox . ' (tiene reserva proxima)') : 'Disponible (sin reservas proximas)',
        'huesped' => null,
        'desde'   => null,
        'hasta'   => $prox ?: null,
    ];
}

$tituloPagina = 'Disponibilidad';
$mostrarBarra = true;
require __DIR__ . '/../includes/header.php';
?>

<div class="contenedor">
    <div class="tarjeta">
        <h2>Disponibilidad de habitaciones</h2>
        <p class="texto-ayuda">Modulo informativo. Se actualiza automaticamente con las reservas, check-in y check-out.</p>

        <form method="get" class="barra-filtros">
            <div class="campo">
                <label for="fecha">Fecha</label>
                <input type="date" id="fecha" name="fecha" value="<?= e($fecha) ?>">
            </div>
            <div class="campo">
                <label for="habitacion">Habitacion</label>
                <select id="habitacion" name="habitacion">
                    <option value="0">Todas</option>
                    <?php foreach ($habitaciones as $hab): ?>
                        <option value="<?= (int) $hab['id'] ?>" <?= $habFiltro === (int) $hab['id'] ? 'selected' : '' ?>>
                            <?= e($hab['numero'] . ' - ' . $hab['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="campo">
                <label for="estado">Estado</label>
                <select id="estado" name="estado">
                    <option value="">Todos</option>
                    <option value="disponible" <?= $estadoF === 'disponible' ? 'selected' : '' ?>>Disponible</option>
                    <option value="ocupada" <?= $estadoF === 'ocupada' ? 'selected' : '' ?>>Ocupada</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primario">Buscar</button>
            <a class="btn btn-secundario" href="<?= e(url('modulos/disponibilidad.php')) ?>">Limpiar</a>
        </form>

        <div class="tabla-responsive" style="margin-top:1rem;">
            <table class="tabla-aes">
                <thead>
                    <tr>
                        <th>N.°</th><th>Habitacion</th><th>Tipo</th><th>Capacidad</th>
                        <th>Precio/noche</th><th>Estado (<?= e($fecha) ?>)</th><th>Detalle</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $hayFilas = false;
                foreach ($habitaciones as $hab):
                    if ($habFiltro && (int) $hab['id'] !== $habFiltro) continue;

                    if ($hab['estado'] === 'mantenimiento') {
                        $info = ['estado' => 'mantenimiento', 'detalle' => 'En mantenimiento'];
                    } else {
                        $info = estado_habitacion_en_fecha((int) $hab['id'], $fecha);
                    }
                    if ($estadoF && $info['estado'] !== $estadoF) continue;
                    $hayFilas = true;

                    $chip = $info['estado'] === 'ocupada' ? 'chip-cancelada'
                          : ($info['estado'] === 'mantenimiento' ? 'chip-pendiente' : 'chip-activo');
                ?>
                    <tr>
                        <td><?= e($hab['numero']) ?></td>
                        <td><?= e($hab['nombre']) ?></td>
                        <td><?= e($hab['tipo']) ?></td>
                        <td><?= (int) $hab['capacidad'] ?></td>
                        <td><?= money((float) $hab['precio_noche']) ?></td>
                        <td><span class="chip <?= $chip ?>"><?= e(ucfirst($info['estado'])) ?></span></td>
                        <td><?= e($info['detalle']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$hayFilas): ?>
                    <tr><td colspan="7">No hay habitaciones que coincidan con el filtro.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="barra-botones">
            <?php if (es_administrador()): ?>
                <a class="btn btn-primario" href="<?= e(url('modulos/habitaciones.php')) ?>">Administrar habitaciones</a>
            <?php endif; ?>
            <a class="btn btn-secundario" href="<?= e(url('modulos/menu.php')) ?>">Regresar al menu principal</a>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
