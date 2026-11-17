<?php
session_start();
require_once 'conexion.php';

// Habilitar errores para depuración
ini_set('display_errors', 1);
error_reporting(E_ALL);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['registro_id'])) {
    header('Location: trabajo_listar.php');
    exit();
}

$registro_id = (int)$_POST['registro_id'];
$tipo_licencia = $_POST['tipo_licencia'];
$fecha_inicio = $_POST['fecha_inicio'];
$fecha_fin = $_POST['fecha_fin'];
$motivo = !empty($_POST['motivo']) ? trim($_POST['motivo']) : null;

// Validación de fechas
if ($fecha_fin < $fecha_inicio) {
    $_SESSION['error_message'] = "Error: La fecha de fin no puede ser anterior a la fecha de inicio.";
    header('Location: reposos_gestionar.php?id=' . $registro_id);
    exit();
}

// INICIO DE LA TRANSACCIÓN EN POSTGRESQL
pg_query($conexion, "BEGIN");
$exito = true;

try {
    // 1. Insertar el nuevo reposo o licencia en la base de datos
    $sql_insert_reposo = "INSERT INTO reposos_licencias (registro_laboral_id, fecha_inicio, fecha_fin, tipo, motivo) VALUES ($1, $2, $3, $4, $5)";
    $res_reposo = pg_query_params($conexion, $sql_insert_reposo, array($registro_id, $fecha_inicio, $fecha_fin, $tipo_licencia, $motivo));
    
    if (!$res_reposo) {
        throw new Exception("Error al insertar reposo/licencia: " . pg_last_error($conexion));
    }
    
    // =======================================================
    // 2. OBTENER DÍAS LABORALES
    // =======================================================
    $sql_dias = "SELECT dias_laborales FROM registros_laborales WHERE id = $1";
    $res_dias = pg_query_params($conexion, $sql_dias, array($registro_id));
    
    if (!$res_dias || pg_num_rows($res_dias) === 0) {
        throw new Exception("No se encontraron los días laborales para este registro.");
    }
    
    $dias_laborales_str = pg_fetch_assoc($res_dias)['dias_laborales'];
    pg_free_result($res_dias);
    
    $dias_laborales_array = explode(',', $dias_laborales_str); // Ej: ['L','M','X','J','V']
    $dias_semana_letras = ['Mon'=>'L', 'Tue'=>'M', 'Wed'=>'X', 'Thu'=>'J', 'Fri'=>'V', 'Sat'=>'S', 'Sun'=>'D'];

    // =======================================================
    // 3. ACTUALIZAR ASISTENCIAS (POSTGRESQL UPSERT)
    // =======================================================
    $fecha_actual = new DateTime($fecha_inicio);
    $fecha_fin_dt = new DateTime($fecha_fin);

    while ($fecha_actual <= $fecha_fin_dt) {
        $fecha_str = $fecha_actual->format('Y-m-d');
        $letra_dia_actual = $dias_semana_letras[$fecha_actual->format('D')];

        // Solo actualizamos la asistencia si ese día era un día laboral
        if (in_array($letra_dia_actual, $dias_laborales_array)) {
            
            // Usamos UPSERT nativo de PostgreSQL: ON CONFLICT (registro_laboral_id, fecha)
            $sql_upsert_asistencia = "INSERT INTO asistencias (registro_laboral_id, fecha, asistencia) 
                                      VALUES ($1, $2, $3)
                                      ON CONFLICT (registro_laboral_id, fecha) 
                                      DO UPDATE SET asistencia = EXCLUDED.asistencia";
            
            $res_asistencia = pg_query_params($conexion, $sql_upsert_asistencia, array($registro_id, $fecha_str, $tipo_licencia));
            
            if (!$res_asistencia) {
                throw new Exception("Error al actualizar la asistencia para la fecha " . $fecha_str . ": " . pg_last_error($conexion));
            }
        }

        $fecha_actual->modify('+1 day');
    }

    // Si todo salió bien, aplicamos commit
    pg_query($conexion, "COMMIT");
    $_SESSION['success_message'] = "Reposo/licencia registrado y asistencias actualizadas correctamente.";

} catch (Exception $e) {
    // Si algo falló, revertir la transacción
    pg_query($conexion, "ROLLBACK");
    $_SESSION['error_message'] = "Error al registrar: " . $e->getMessage();
}

pg_close($conexion);
header('Location: reposos_gestionar.php?id=' . urlencode($registro_id));
exit();
?>