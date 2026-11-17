<?php
// Este script solo devuelve datos en formato JSON
header('Content-Type: application/json');
require_once 'conexion.php';

// Habilitar errores para depuración (opcional)
ini_set('display_errors', 0);
error_reporting(0);

$term = $_GET['term'] ?? '';

if (empty($term)) {
    echo json_encode([]);
    exit;
}

// Preparamos el parámetro para la búsqueda parcial
$param = "%" . $term . "%";

// Consulta adaptada a PostgreSQL con ILIKE y pg_query_params
$sql_privados = "SELECT 
                    p.documento_identidad, 
                    (p.nombre || ' ' || p.apellido) AS nombre_completo,
                    rl.id AS registro_id,
                    rl.fecha_inicio_labor,
                    rl.dias_laborales
                 FROM privados p
                 JOIN registros_laborales rl ON p.documento_identidad = rl.privado_documento
                 WHERE 
                    rl.fecha_fin_labor IS NULL
                    AND (p.documento_identidad ILIKE $1 OR p.nombre ILIKE $2 OR p.apellido ILIKE $3)
                 LIMIT 10";

$resultado = pg_query_params($conexion, $sql_privados, array($param, $param, $param));

$privados = [];
if ($resultado) {
    while ($fila = pg_fetch_assoc($resultado)) {
        $privados[] = $fila; // Añadimos la fila al array
    }
    pg_free_result($resultado);
}

if (isset($conexion)) {
    pg_close($conexion);
}

// Devolvemos el array como JSON
echo json_encode($privados);
?>