<?php
/**
 * Servicio web de inicio de sesion - Gestion AES.
 *
 * Recibe usuario y contrasena mediante JSON.
 * Verifica las credenciales y devuelve una respuesta JSON.
 */

require_once __DIR__ . '/../../config/db.php';

// Indica que el servicio responde en formato JSON.
header('Content-Type: application/json; charset=utf-8');

// Funcion para enviar respuestas JSON con su codigo HTTP correspondiente.
function responderJson(int $codigoHttp, array $datos): void
{
    http_response_code($codigoHttp);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

// El servicio solamente acepta solicitudes POST.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderJson(405, [
        'exito' => false,
        'mensaje' => 'Metodo no permitido. Utiliza POST.'
    ]);
}

// Recibe el contenido JSON enviado en la solicitud.
$contenido = file_get_contents('php://input');
$datos = json_decode($contenido, true);

// Comprueba que el contenido recibido sea un JSON valido.
if (!is_array($datos)) {
    responderJson(400, [
        'exito' => false,
        'mensaje' => 'El formato de los datos enviados no es valido.'
    ]);
}

// Obtiene los datos enviados en la solicitud.
$usuarioRecibido = $datos['usuario'] ?? '';
$clave = $datos['contrasena'] ?? '';

// Comprueba que ambos datos sean textos.
if (!is_string($usuarioRecibido) || !is_string($clave)) {
    responderJson(422, [
        'exito' => false,
        'mensaje' => 'Los datos enviados deben ser textos validos.'
    ]);
}

// Elimina espacios al inicio y al final del usuario.
$usuarioIngresado = trim($usuarioRecibido);

// Verifica que los campos obligatorios esten presentes.
if ($usuarioIngresado === '' || !is_string($clave) || $clave === '') {
    responderJson(422, [
        'exito' => false,
        'mensaje' => 'Debes enviar el usuario y la contrasena.'
    ]);
}

try {
    // Busca el usuario por nombre de usuario o correo electronico.
    $stmt = db()->prepare(
        'SELECT u.id, u.usuario, u.nombre, u.email, u.contrasena, u.estado,
                r.nombre_rol
         FROM usuario u
         JOIN rol r ON r.id = u.id_rol
         WHERE u.usuario = ? OR u.email = ?
         LIMIT 1'
    );

    $stmt->execute([$usuarioIngresado, $usuarioIngresado]);
    $fila = $stmt->fetch();

    // Verifica si el usuario existe y si la contrasena es correcta.
    if (!$fila || !password_verify($clave, $fila['contrasena'])) {
        responderJson(401, [
            'exito' => false,
            'mensaje' => 'Error en la autenticacion. Verifica tus credenciales.'
        ]);
    }

    // Comprueba si la cuenta ya fue validada.
    if ($fila['estado'] === 'pendiente') {
        responderJson(403, [
            'exito' => false,
            'mensaje' => 'Tu cuenta esta pendiente de validacion.'
        ]);
    }

    // Comprueba si la cuenta esta inactiva.
    if ($fila['estado'] === 'inactivo') {
        responderJson(403, [
            'exito' => false,
            'mensaje' => 'Tu cuenta esta inactiva. Contacta al administrador.'
        ]);
    }

    // Registra la fecha y hora del inicio de sesion.
    $upd = db()->prepare(
        'UPDATE usuario SET ultimo_inicio_sesion = NOW() WHERE id = ?'
    );
    $upd->execute([$fila['id']]);

    // Devuelve una respuesta satisfactoria sin exponer la contrasena.
    responderJson(200, [
        'exito' => true,
        'mensaje' => 'Autenticacion satisfactoria.',
        'usuario' => [
            'id' => (int) $fila['id'],
            'nombre' => $fila['nombre'],
            'usuario' => $fila['usuario'],
            'email' => $fila['email'],
            'rol' => $fila['nombre_rol']
        ]
    ]);

} catch (PDOException $e) {
    // Evita mostrar detalles internos de la base de datos en la respuesta.
    responderJson(500, [
        'exito' => false,
        'mensaje' => 'Ocurrio un error al procesar la autenticacion.'
    ]);
}
