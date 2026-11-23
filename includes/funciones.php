<?php
/**
 * Funciones auxiliares de Gestion AES.
 */
require_once __DIR__ . '/../config/db.php';

/* ------------------------------------------------------------------
 * Sesion
 * ------------------------------------------------------------------ */
function iniciar_sesion_app(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

/** Devuelve el usuario autenticado (array) o null. */
function usuario_actual(): ?array
{
    iniciar_sesion_app();
    return $_SESSION['usuario'] ?? null;
}

/** Exige que haya un usuario autenticado; si no, redirige al login. */
function requerir_login(): void
{
    if (!usuario_actual()) {
        redirigir('/index.php');
    }
}

/** Nombre del rol del usuario actual (administrador | operario | null). */
function rol_actual(): ?string
{
    $u = usuario_actual();
    return $u['rol'] ?? null;
}

function es_administrador(): bool
{
    return rol_actual() === 'administrador';
}

/**
 * Exige que el usuario tenga uno de los roles indicados.
 * Si no cumple, muestra un mensaje y detiene la ejecucion.
 */
function requerir_rol(array $roles_permitidos): void
{
    requerir_login();
    if (!in_array(rol_actual(), $roles_permitidos, true)) {
        http_response_code(403);
        die('<h2>Acceso denegado</h2><p>Tu rol no tiene permisos para acceder a este modulo.</p>'
            . '<p><a href="' . e(url('/modulos/menu.php')) . '">Volver al menu principal</a></p>');
    }
}

/* ------------------------------------------------------------------
 * Utilidades de salida / navegacion
 * ------------------------------------------------------------------ */

/** Escapa texto para imprimir en HTML de forma segura. */
function e(?string $texto): string
{
    return htmlspecialchars($texto ?? '', ENT_QUOTES, 'UTF-8');
}

/** Construye una URL absoluta a partir de BASE_URL. */
function url(string $ruta = ''): string
{
    return rtrim(BASE_URL, '/') . '/' . ltrim($ruta, '/');
}

/** Redirige a una ruta interna y termina. */
function redirigir(string $ruta): void
{
    header('Location: ' . url($ruta));
    exit;
}

/* ------------------------------------------------------------------
 * Mensajes flash (se muestran una sola vez tras una redireccion)
 * ------------------------------------------------------------------ */
function flash(string $tipo, string $mensaje): void
{
    iniciar_sesion_app();
    $_SESSION['flash'][] = ['tipo' => $tipo, 'mensaje' => $mensaje];
}

function obtener_flash(): array
{
    iniciar_sesion_app();
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ------------------------------------------------------------------
 * Seguridad: token CSRF
 * ------------------------------------------------------------------ */
function csrf_token(): string
{
    iniciar_sesion_app();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_campo(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function verificar_csrf(): bool
{
    iniciar_sesion_app();
    $enviado = $_POST['csrf'] ?? '';
    return is_string($enviado) && hash_equals($_SESSION['csrf'] ?? '', $enviado);
}

/* ------------------------------------------------------------------
 * Correo simulado: guarda el "correo" en un log y, en desarrollo,
 * permite mostrar el codigo en pantalla.
 * ------------------------------------------------------------------ */
function enviar_correo_simulado(string $destinatario, string $asunto, string $cuerpo): void
{
    $linea = sprintf(
        "[%s] Para: %s | Asunto: %s\n%s\n%s\n",
        date('Y-m-d H:i:s'),
        $destinatario,
        $asunto,
        $cuerpo,
        str_repeat('-', 50)
    );

    $dir = dirname(CORREO_LOG);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    @file_put_contents(CORREO_LOG, $linea, FILE_APPEND);
}

/* ------------------------------------------------------------------
 * Codigos de verificacion (4 digitos)
 * ------------------------------------------------------------------ */
function generar_codigo_4(): string
{
    return str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
}

/**
 * Crea un codigo de verificacion para un usuario, lo guarda y lo "envia".
 * Devuelve el codigo generado (util para mostrarlo en pantalla en desarrollo).
 */
function crear_y_enviar_codigo(int $idUsuario, string $email, string $tipo): string
{
    $codigo = generar_codigo_4();
    $expira = (new DateTime())->modify('+' . CODIGO_VIGENCIA_MIN . ' minutes')->format('Y-m-d H:i:s');

    // Invalida codigos previos del mismo tipo para este usuario.
    $stmt = db()->prepare('UPDATE codigo_verificacion SET usado = 1 WHERE id_usuario = ? AND tipo = ? AND usado = 0');
    $stmt->execute([$idUsuario, $tipo]);

    $stmt = db()->prepare(
        'INSERT INTO codigo_verificacion (id_usuario, codigo, tipo, fecha_expira) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$idUsuario, $codigo, $tipo, $expira]);

    $asunto = $tipo === 'activacion'
        ? 'Codigo de activacion - Gestion AES'
        : 'Codigo de recuperacion de contrasena - Gestion AES';
    $cuerpo = "Tu codigo de verificacion es: $codigo\n"
        . "Vigencia: " . CODIGO_VIGENCIA_MIN . " minutos.";

    enviar_correo_simulado($email, $asunto, $cuerpo);

    return $codigo;
}

/**
 * Verifica un codigo para un usuario. Devuelve true si es valido y no vencido.
 * Si es valido, lo marca como usado.
 */
function verificar_codigo(int $idUsuario, string $codigo, string $tipo): bool
{
    $stmt = db()->prepare(
        'SELECT id, fecha_expira FROM codigo_verificacion
         WHERE id_usuario = ? AND codigo = ? AND tipo = ? AND usado = 0
         ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$idUsuario, $codigo, $tipo]);
    $fila = $stmt->fetch();

    if (!$fila) {
        return false;
    }
    if (strtotime($fila['fecha_expira']) < time()) {
        return false; // vencido
    }

    $upd = db()->prepare('UPDATE codigo_verificacion SET usado = 1 WHERE id = ?');
    $upd->execute([$fila['id']]);
    return true;
}

/* ------------------------------------------------------------------
 * Helpers de formato
 * ------------------------------------------------------------------ */
function money(float $valor): string
{
    return '$' . number_format($valor, 0, ',', '.');
}
