<?php
require_once 'conexion.php';

header('Content-Type: application/json');

// 1. Recoger y validar el código del centro
$centro_codigo = $_GET['centro_codigo'] ?? null;

if (empty($centro_codigo)) {
    // Devuelve un array vacío si no hay código de centro
    echo json_encode([]); 
    exit;
}

// 2. Consulta SQL adaptada a PostgreSQL con parámetro $1
$sql_areas = "SELECT 
                    a.codigo, 
                    a.nombre_area,
                    ca.capacidad_maxima,
                    (SELECT COUNT(p.documento_identidad) 
                     FROM privados p 
                     WHERE p.centro_codigo = ca.centro_codigo AND p.area_codigo = a.codigo) AS total_privados
                FROM centros_areas ca
                JOIN areas_socioproductivas a ON ca.area_codigo = a.codigo
                WHERE ca.centro_codigo = $1
                ORDER BY a.nombre_area";

// Ejecución segura de la consulta en PostgreSQL
$resultado_areas = pg_query_params($conexion, $sql_areas, array($centro_codigo));

$areas = [];

if ($resultado_areas) {
    while ($fila = pg_fetch_assoc($resultado_areas)) {
        $areas[] = [
            'codigo' => $fila['codigo'],
            'nombre_area' => $fila['nombre_area'],
            'capacidad_maxima' => (int)$fila['capacidad_maxima'],
            'ocupacion_actual' => (int)$fila['total_privados'] // El JS espera este nombre exacto
        ];
    }
    pg_free_result($resultado_areas);
}

pg_close($conexion);

// 3. DEVOLVER EL ARRAY DE DATOS COMPLETO
echo json_encode($areas);
exit;
?>