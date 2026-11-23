<?php
/**
 * Generacion de reportes en CSV (compatible con Excel).
 *
 * Se agrega BOM UTF-8 para que Excel muestre bien los acentos, y se usa
 * ';' como separador (predeterminado en Excel en configuraciones en espanol).
 */

/**
 * Envia un archivo CSV al navegador y termina la ejecucion.
 *
 * @param string $nombreArchivo  Nombre sugerido (sin extension).
 * @param array  $encabezados    Fila de titulos.
 * @param array  $filas          Array de arrays con los datos.
 */
function descargar_csv(string $nombreArchivo, array $encabezados, array $filas): void
{
    // Limpia cualquier salida previa para no corromper el archivo.
    if (ob_get_length()) {
        ob_end_clean();
    }

    $archivo = preg_replace('/[^A-Za-z0-9_\-]/', '_', $nombreArchivo) . '_' . date('Ymd_His') . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $archivo . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $salida = fopen('php://output', 'w');

    // BOM para acentos en Excel
    fwrite($salida, "\xEF\xBB\xBF");

    fputcsv($salida, $encabezados, ';');
    foreach ($filas as $fila) {
        fputcsv($salida, $fila, ';');
    }

    fclose($salida);
    exit;
}
