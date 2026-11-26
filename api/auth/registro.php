<?php
/**
 * API REST: Registro de usuarios - Gestion AES / Hostal Casa Zen.
 *
 * METODO: POST
 * URL: /aes/api/usuarios/registro.php
 * FORMATO: application/json
 *
 * Este endpoint es independiente del formulario registro.php.
 * Reutiliza la conexion PDO y las funciones de verificacion existentes,
 * pero no modifica archivos ni rutas del funcionamiento actual.
 *
 * IMPORTANTE: por seguridad, los registros hechos desde esta API reciben
 * el rol de OPERARIO (id_rol = 2). El cliente NO puede enviar un rol de
 * administrador. El usuario queda en estado "pendiente", igual que en
 * el proceso de registro existente.
 *
 * Ejemplo de JSON esperado:
 * {
 *   "nombre": "Juan Perez",
 *   "tipo_identificacion": "CC",
 *   "dni": "123456789",
 *   "telefono": "3001234567",
 *   "email": "juan@correo.com",
 *   "usuario": "juanperez",
 *   "contrasena": "ClaveSegura123"
 * }
 */

// Responder siempre en formato JSON y declarar UTF-8.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/**
 * Envia una respuesta JSON y termina la ejecucion del endpoint.
 * @param int $estado Codigo HTTP de respuesta.
 * @param array $contenido Datos que recibira el consumidor de la API.
 */
function responder(int $estado, array $contenido): void
{
    http_response_code($estado);
    echo json_encode($contenido, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Este servicio solo permite peticiones POST.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    responder(405, [
        'exito' => false,
        'mensaje' => 'Metodo no permitido. Utiliza POST.'
    ]);
}

// Cargar la conexion PDO y las funciones existentes del proyecto.
require_once __DIR__ . '/../../includes/funciones.php';

// Leer el cuerpo enviado por el cliente como JSON.
$cuerpo = file_get_contents('php://input');
$entrada = json_decode($cuerpo, true);

// Verificar que el JSON sea valido y que contenga un objeto/diccionario.
if (!is_array($entrada) || json_last_error() !== JSON_ERROR_NONE) {
    responder(400, [
        'exito' => false,
        'mensaje' => 'El cuerpo de la solicitud debe contener un JSON valido.'
    ]);
}

// Recuperar y limpiar los datos recibidos.
$nombre = trim((string) ($entrada['nombre'] ?? ''));
$tipoIdentificacion = strtoupper(trim((string) ($entrada['tipo_identificacion'] ?? 'CC')));
$dni = trim((string) ($entrada['dni'] ?? ''));
$telefono = trim((string) ($entrada['telefono'] ?? ''));
$email = strtolower(trim((string) ($entrada['email'] ?? '')));
$usuario = trim((string) ($entrada['usuario'] ?? ''));
$contrasena = (string) ($entrada['contrasena'] ?? '');

// Acumular los errores para devolverlos juntos al consumidor.
$errores = [];

// Validaciones de campos obligatorios y longitudes compatibles con la BD.
if ($nombre === '' || mb_strlen($nombre) > 100) {
    $errores[] = 'El nombre es obligatorio y debe tener maximo 100 caracteres.';
}

if (!in_array($tipoIdentificacion, ['CC', 'CE', 'PA', 'TI'], true)) {
    $errores[] = 'El tipo de identificacion debe ser CC, CE, PA o TI.';
}

if ($dni === '' || mb_strlen($dni) > 20) {
    $errores[] = 'El documento es obligatorio y debe tener maximo 20 caracteres.';
}

if ($telefono === '' || mb_strlen($telefono) > 15) {
    $errores[] = 'El telefono es obligatorio y debe tener maximo 15 caracteres.';
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 100) {
    $errores[] = 'El correo no es valido o supera los 100 caracteres.';
}

if ($usuario === '' || mb_strlen($usuario) > 100) {
    $errores[] = 'El nombre de usuario es obligatorio y debe tener maximo 100 caracteres.';
}

if (strlen($contrasena) < 6) {
    $errores[] = 'La contrasena debe tener al menos 6 caracteres.';
}

// Si hay errores, no se consulta ni se modifica la base de datos.
if ($errores) {
    responder(422, [
        'exito' => false,
        'mensaje' => 'Revisa los datos enviados.',
        'errores' => $errores
    ]);
}

try {
    $conexion = db();

    // Comprobar que usuario, correo y documento no estén registrados.
    $consulta = $conexion->prepare(
        'SELECT id FROM usuario WHERE usuario = ? OR email = ? OR dni = ? LIMIT 1'
    );
    $consulta->execute([$usuario, $email, $dni]);

    if ($consulta->fetch()) {
        responder(409, [
            'exito' => false,
            'mensaje' => 'Ya existe un usuario con ese nombre de usuario, correo o documento.'
        ]);
    }

    // Generar un hash seguro; nunca guardar la contrasena en texto plano.
    $hashContrasena = password_hash($contrasena, PASSWORD_DEFAULT);

    // El rol se asigna en el servidor para impedir que alguien se registre
    // como administrador manipulando el JSON enviado desde el navegador.
    $idRolOperario = 2;

    // La transaccion permite guardar usuario y codigo de activacion juntos.
    $conexion->beginTransaction();

    // Insertar el nuevo usuario usando los mismos campos de la tabla actual.
    $insertar = $conexion->prepare(
        'INSERT INTO usuario
            (id_rol, usuario, contrasena, telefono, nombre, email,
             tipo_identificacion, dni, estado)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)' 
    );

    $insertar->execute([
        $idRolOperario,
        $usuario,
        $hashContrasena,
        $telefono,
        $nombre,
        $email,
        $tipoIdentificacion,
        $dni,
        'pendiente'
    ]);

    // Obtener el identificador generado por MySQL.
    $idUsuario = (int) $conexion->lastInsertId();

    // Crear el codigo de activacion usando el mecanismo ya existente.
    // En este proyecto el correo es simulado y queda en storage/correos.log.
    crear_y_enviar_codigo($idUsuario, $email, 'activacion');

    // Confirmar los cambios cuando el usuario y el codigo estén creados.
    $conexion->commit();

    // Respuesta exitosa: no se devuelve la contrasena ni su hash.
    responder(201, [
        'exito' => true,
        'mensaje' => 'Usuario registrado. Queda pendiente de activacion.',
        'datos' => [
            'id' => $idUsuario,
            'nombre' => $nombre,
            'usuario' => $usuario,
            'email' => $email,
            'estado' => 'pendiente'
        ]
    ]);

} catch (PDOException $e) {
    // Revertir la transaccion si ocurrió un error después de iniciarla.
    if (isset($conexion) && $conexion instanceof PDO && $conexion->inTransaction()) {
        $conexion->rollBack();
    }

    // El índice UNIQUE de MySQL también protege ante dos registros simultáneos.
    // SQLSTATE 23000 corresponde a una violación de integridad/unicidad.
    if ($e->getCode() === '23000') {
        responder(409, [
            'exito' => false,
            'mensaje' => 'El usuario, correo o documento ya se encuentra registrado.'
        ]);
    }

    // Registrar el detalle en el log del servidor, no exponerlo al consumidor.
    error_log('[API registro usuarios] ' . $e->getMessage());
    responder(500, [
        'exito' => false,
        'mensaje' => 'No fue posible completar el registro. Intenta nuevamente.'
    ]);

} catch (Throwable $e) {
    if (isset($conexion) && $conexion instanceof PDO && $conexion->inTransaction()) {
        $conexion->rollBack();
    }

    error_log('[API registro usuarios] ' . $e->getMessage());
    responder(500, [
        'exito' => false,
        'mensaje' => 'Ocurrio un error interno al procesar el registro.'
    ]);
}
