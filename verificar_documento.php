<?php
require_once 'conexion.php'; // Reutiliza tu conexión

// Preparamos la respuesta que será JSON
header('Content-Type: application/json');
$respuesta = ['existe' => false];

// 1. Validar que los datos lleguen
if (isset($_GET['tipo']) && isset($_GET['numero'])) {
    
    $tipoDocumento = $_GET['tipo'];
    $numeroDocumento = $_GET['numero'];

    // 2. Consulta adaptada a PostgreSQL con marcadores $1 y $2
    $sql = "SELECT COUNT(*) as total FROM privados WHERE prefijo_documento = $1 AND numero_documento = $2";
    
    // Ejecutamos con pg_query_params para total seguridad contra inyección SQL
    $resultado = pg_query_params($conexion, $sql, array($tipoDocumento, $numeroDocumento));
    
    if ($resultado) {
        $fila = pg_fetch_assoc($resultado);
        
        if ($fila && $fila['total'] > 0) {
            $respuesta['existe'] = true;
        }
        
        pg_free_result($resultado);
    } else {
        // En caso de error en la consulta
        $respuesta['error'] = "Error al consultar la base de datos: " . pg_last_error($conexion);
    }
    
    pg_close($conexion);

} else {
    // Si no se envían los datos esperados
    $respuesta['error'] = "Datos incompletos.";
}

// 6. Devolver la respuesta en formato JSON
echo json_encode($respuesta);
?>