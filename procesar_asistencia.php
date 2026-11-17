<?php
session_start();
require_once 'conexion.php';

// Habilitar errores para depuración
ini_set('display_errors', 1);
error_reporting(E_ALL);

// 1. Validar que la solicitud sea POST
if ($_SERVER["REQUEST_METHOD"] != "POST") {
    $_SESSION['error_message'] = "Método de solicitud no permitido.";
    header('Location: trabajo_listar.php');
    exit();
}

$registro_id = $_POST['registro_id'] ?? null;
$asistencias_enviadas = $_POST['asistencia'] ?? [];

if (empty($registro_id)) {
    $_SESSION['error_message'] = "No se especificó un registro laboral válido.";
    header('Location: trabajo_listar.php');
    exit();
}

// INICIO DE LA TRANSACCIÓN EN POSTGRESQL
pg_query($conexion, "BEGIN");
$exito = true;

try {
    // Recorrer cada asistencia enviada desde el formulario del mes
    foreach ($asistencias_enviadas as $fecha => $estado) {
        // Validar que el estado sea uno de los permitidos
        $estados_permitidos = ['Presente', 'Ausente', 'Reposo', 'Permiso'];
        if (!in_array($estado, $estados_permitidos)) {
            continue; // Omitir valores inválidos
        }

        // Verificar si ya existe un registro de asistencia para este registro y fecha (PostgreSQL)
        $sql_check = "SELECT id FROM asistencias WHERE registro_laboral_id = $1 AND fecha = $2";
        $res_check = pg_query_params($conexion, $sql_check, array($registro_id, $fecha));

        if ($res_check && pg_num_rows($res_check) > 0) {
            if ($res_check) pg_free_result($res_check);

            // Si existe, actualizamos
            $sql_update = "UPDATE asistencias SET asistencia = $1 WHERE registro_laboral_id = $2 AND fecha = $3";
            $res_action = pg_query_params($conexion, $sql_update, array($estado, $registro_id, $fecha));
        } else {
            if ($res_check) pg_free_result($res_check);

            // Si no existe, lo insertamos
            $sql_insert = "INSERT INTO asistencias (registro_laboral_id, fecha, asistencia) VALUES ($1, $2, $3)";
            $res_action = pg_query_params($conexion, $sql_insert, array($registro_id, $fecha, $estado));
        }

        if (!$res_action) {
            throw new Exception("Error al guardar la asistencia para la fecha " . $fecha . ": " . pg_last_error($conexion));
        }
    }

    // Si todo salió bien, aplicamos commit
    pg_query($conexion, "COMMIT");
    $_SESSION['success_message'] = "✅ Asistencias guardadas exitosamente.";

} catch (Exception $e) {
    pg_query($conexion, "ROLLBACK");
    $_SESSION['error_message'] = "Error al procesar las asistencias: " . $e->getMessage();
}

pg_close($conexion);

// Redirigir de vuelta a la gestión de asistencia del registro
header("Location: asistencia_gestionar.php?id=" . urlencode($registro_id));
exit();
?>