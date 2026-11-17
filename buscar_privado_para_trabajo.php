<?php
// Este script solo devuelve datos en formato JSON
header('Content-Type: application/json');
require_once 'conexion.php';

$term = $_GET['term'] ?? '';

if (empty($term)) {
    echo json_encode([]);
    exit;
}

// Preparamos el parámetro para la búsqueda parcial
$param = "%" . $term . "%";

// Consulta adaptada a PostgreSQL con ILIKE y pg_query_params
$sql = "SELECT 
            p.documento_identidad, 
            (p.nombre || ' ' || p.apellido) AS nombre_completo
        FROM privados p
        WHERE 
            (p.documento_identidad ILIKE $1 OR p.nombre ILIKE $2 OR p.apellido ILIKE $3)
            AND p.documento_identidad NOT IN (
                SELECT rl.privado_documento FROM registros_laborales rl WHERE rl.fecha_fin_labor IS NULL
            )
        LIMIT 10"; 

$resultado = pg_query_params($conexion, $sql, array($param, $param, $param));

$privados = [];
if ($resultado) {
    while ($fila = pg_fetch_assoc($resultado)) {
        // Formateamos el texto que verá el usuario
        $fila['texto_visible'] = $fila['nombre_completo'] . ' (C.I: ' . $fila['documento_identidad'] . ')';
        $privados[] = $fila; 
    }
    pg_free_result($resultado);
}

pg_close($conexion);

echo json_encode($privados);
?>