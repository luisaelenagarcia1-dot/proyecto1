<?php
session_start();
require_once 'conexion.php';

// Habilitar errores para depuración
ini_set('display_errors', 1);
error_reporting(E_ALL);

// 1. Verificar que se recibieron datos por POST
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['asistencia'])) {

    $fecha_asistencia = $_POST['fecha_asistencia'];
    $asistencias = $_POST['asistencia'];

    // Consulta SQL con el UPSERT nativo de PostgreSQL
    $sql = "INSERT INTO asistencias (registro_laboral_id, fecha, asistencia) 
            VALUES ($1, $2, $3)
            ON CONFLICT (registro_laboral_id, fecha) 
            DO UPDATE SET asistencia = EXCLUDED.asistencia";
            
    // Iniciar transacción para procesar el lote de forma segura
    pg_query($conexion, "BEGIN");

    try {
        // 2. Recorremos cada asistencia enviada por el formulario
        foreach ($asistencias as $registro_id => $estado) {
            $res = pg_query_params($conexion, $sql, array($registro_id, $fecha_asistencia, $estado));
            if (!$res) {
                throw new Exception("Error al guardar la asistencia: " . pg_last_error($conexion));
            }
            pg_free_result($res);
        }

        // Confirmar transacción (COMMIT)
        pg_query($conexion, "COMMIT");

    } catch (Exception $e) {
        // Revertir transacción en caso de error (ROLLBACK)
        pg_query($conexion, "ROLLBACK");
        $_SESSION['error_message'] = $e->getMessage();
    }

    if (isset($conexion)) {
        pg_close($conexion);
    }

    // --- CONSTRUIR URL DE REDIRECCIÓN CON FILTROS ---
    
    // 3. Obtener los filtros que enviamos por campos ocultos
    $filtros = [
        'exito' => 1,
        'centro' => $_POST['filtro_centro'] ?? '',
        'area' => $_POST['filtro_area'] ?? '',
        'cedula' => $_POST['filtro_cedula'] ?? ''
    ];
    
    // 4. Limpiar filtros vacíos
    $filtros_activos = array_filter($filtros);
    
    // 5. Construir la cadena de consulta (query string)
    $query_string = http_build_query($filtros_activos);

    // 6. Redirigimos de vuelta con el mensaje de éxito Y los filtros
    header('Location: asistencia_diaria.php?' . $query_string);
    exit;

} else {
    // Si no hay datos POST, redirigir
    if (isset($conexion)) {
        pg_close($conexion);
    }
    header('Location: asistencia_diaria.php');
    exit;
}
?>