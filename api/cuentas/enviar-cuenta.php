<?php
/**
 * Servicio web para el envio de cuentas de cobro.
 * Proyecto: Gestion AES - Hostal Casa Zen.
 *
 * Recibe los datos de la cuenta mediante JSON
 * y registra el correo en el archivo de correos simulados.
 */

// Carga la configuracion general del proyecto.
require_once __DIR__ . '/../../config/config.php';

// Indica que el servicio responde en formato JSON.
header('Content-Type: application/json; charset=utf-8');

/**
 * Envia una respuesta JSON con su codigo HTTP.
 */
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

// Convierte el contenido JSON en un arreglo de PHP.
$datos = json_decode($contenido, true);

// Comprueba que el JSON sea valido.
if (!is_array($datos)) {
    responderJson(400, [
        'exito' => false,
        'mensaje' => 'El formato de los datos enviados no es valido.'
    ]);
}

// Obtiene los datos de la cuenta de cobro.
$correo = $datos['correo'] ?? '';
$nombre = $datos['nombre'] ?? '';
$reserva = $datos['numero_reserva'] ?? '';
$total = $datos['total'] ?? null;

// Comprueba que los datos sean textos validos.
if (
    !is_string($correo) ||
    !is_string($nombre) ||
    !is_string($reserva)
) {
    responderJson(422, [
        'exito' => false,
        'mensaje' => 'Los datos enviados tienen un formato incorrecto.'
    ]);
}

// Elimina espacios innecesarios.
$correo = trim($correo);
$nombre = trim($nombre);
$reserva = trim($reserva);

// Comprueba que no falten datos obligatorios.
if (
    $correo === '' ||
    $nombre === '' ||
    $reserva === '' ||
    $total === null ||
    !is_numeric($total) ||
    !is_finite((float) $total) ||
    (float) $total < 0
) {
    responderJson(422, [
        'exito' => false,
        'mensaje' => 'Debes enviar todos los datos de la cuenta de cobro correctamente.'
    ]);
}

// Valida el formato del correo electronico.
if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    responderJson(422, [
        'exito' => false,
        'mensaje' => 'La direccion de correo electronico no es valida.'
    ]);
}

// Prepara el asunto del correo.
$asunto = 'Cuenta de cobro - Hostal Casa Zen';

// Prepara el contenido del correo.
$cuerpo = "Hola, $nombre.\n\n";
$cuerpo .= "Te compartimos la informacion de tu cuenta de cobro.\n\n";
$cuerpo .= "Numero de reserva: $reserva\n";
$cuerpo .= "Total a pagar: $" . number_format((float) $total, 0, ',', '.') . "\n\n";
$cuerpo .= "Gracias por hospedarte en Hostal Casa Zen.\n";
$cuerpo .= "Gestion AES.";

// Construye el contenido que se guardara en el archivo de correos.
$linea = sprintf(
    "[%s] Para: %s | Asunto: %s\n%s\n%s\n",
    date('Y-m-d H:i:s'),
    $correo,
    $asunto,
    $cuerpo,
    str_repeat('-', 50)
);

try {
    // Obtiene la carpeta donde se almacenan los correos simulados.
    $directorio = dirname(CORREO_LOG);

    // Crea la carpeta si todavia no existe.
    if (!is_dir($directorio)) {
        if (!mkdir($directorio, 0777, true) && !is_dir($directorio)) {
            responderJson(500, [
                'exito' => false,
                'mensaje' => 'No fue posible crear el directorio de correos.'
            ]);
        }
    }

    // Guarda el correo en el archivo de registro.
    $resultado = file_put_contents(
        CORREO_LOG,
        $linea,
        FILE_APPEND | LOCK_EX
    );

    // Comprueba si el registro se realizo correctamente.
    if ($resultado === false) {
        responderJson(500, [
            'exito' => false,
            'mensaje' => 'Error al registrar el correo de la cuenta de cobro.'
        ]);
    }

    // Devuelve una respuesta satisfactoria.
    responderJson(200, [
        'exito' => true,
        'mensaje' => 'Correo registrado correctamente. Envio simulado.',
        'destinatario' => $correo
    ]);

} catch (Throwable $e) {

    // Devuelve un mensaje general sin revelar detalles internos.
    responderJson(500, [
        'exito' => false,
        'mensaje' => 'Ocurrio un error al procesar el envio del correo.'
    ]);
}
