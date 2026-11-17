<?php
// 1. Incluye tu archivo de conexión a la base de datos (PostgreSQL)
include 'conexion.php'; 

// 2. Prepara la respuesta que enviaremos (en formato JSON)
header('Content-Type: application/json');
$respuesta = ['existe' => false];

// Verifica que la variable de conexión exista
if (!isset($conexion)) {
    $respuesta['error'] = "Error de conexión a la base de datos.";
    echo json_encode($respuesta);
    exit;
}

// 3. Verifica si se recibió una cédula por POST
if (isset($_POST['cedula'])) {
    $cedula = trim($_POST['cedula']);

    try {
        // --- CÓDIGO CORREGIDO PARA POSTGRESQL ---
        $sql = "SELECT cedula FROM usuarios WHERE cedula = $1 LIMIT 1";
        
        // Usamos pg_query_params para evitar inyecciones SQL de forma segura en Postgres
        $resultado = pg_query_params($conexion, $sql, array($cedula));
        
        if ($resultado) {
            // 4. Comprueba si se encontró un resultado
            if (pg_num_rows($resultado) > 0) {
                $respuesta['existe'] = true;
            }
        } else {
            $respuesta['error'] = "Error en la consulta: " . pg_last_error($conexion);
        }

    } catch (Exception $e) {
        // Captura cualquier error de ejecución
        $respuesta['error'] = "Error en la ejecución: " . $e->getMessage();
    }
}

// 5. Cierra la conexión y devuelve la respuesta en formato JSON
if (isset($conexion)) {
    pg_close($conexion);
}
echo json_encode($respuesta);
?>