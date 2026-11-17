<?php
// decidir_solicitud.php
session_start();
require_once 'conexion.php'; 

// Habilitar errores para depuración (opcional)
ini_set('display_errors', 1);
error_reporting(E_ALL);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: redenciones.php');
    exit;
}

$id_solicitud = $_POST['id_solicitud'] ?? null;
$decision = $_POST['decision'] ?? null;
$usuario_decision = $_SESSION['nombre_usuario'] ?? 'Usuario Desconocido'; 

if (empty($id_solicitud) || empty($decision)) {
    $_SESSION['mensaje_error'] = 'Error: Faltan datos obligatorios para procesar la decisión.';
    header('Location: redenciones.php');
    exit;
}

$fecha_decision = date('Y-m-d H:i:s');

// Iniciar transacción en PostgreSQL
pg_query($conexion, "BEGIN");

try {
    if ($decision === 'Aprobado') {
        // --- PROCESO DE APROBACIÓN ---
        
        $numero_aprobacion = $_POST['numero_aprobacion'] ?? null;
        $juez_aprobacion = $_POST['juez_aprobacion'] ?? null;
        $cedula_juez_aprobacion = $_POST['cedula_juez_aprobacion'] ?? null;

        if (empty($numero_aprobacion) || empty($juez_aprobacion) || empty($cedula_juez_aprobacion)) {
            throw new Exception('Faltan datos (Oficio, Juez o Cédula) para registrar la aprobación.');
        }

        // A. LÓGICA DE INSERCIÓN/VERIFICACIÓN DEL JUEZ (PostgreSQL)
        $sql_check = "SELECT cedula FROM personal_judicial WHERE cedula = $1";
        $res_check = pg_query_params($conexion, $sql_check, array($cedula_juez_aprobacion));

        if ($res_check && pg_num_rows($res_check) === 0) {
            pg_free_result($res_check);
            $sql_insert_juez = "INSERT INTO personal_judicial (nombre_completo, tipo, cedula) VALUES ($1, 'Juez', $2)";
            $res_insert = pg_query_params($conexion, $sql_insert_juez, array($juez_aprobacion, $cedula_juez_aprobacion));
            
            if (!$res_insert) {
                throw new Exception('Error al registrar al nuevo Juez/Funcionario: ' . pg_last_error($conexion));
            }
            pg_free_result($res_insert);
        } elseif ($res_check) {
            pg_free_result($res_check);
        }

        // B. Obtener datos clave de la solicitud ANTES de actualizarla
        $sql_sol = "SELECT privado_documento, dias_presente_contados, fecha_calculo_inicio FROM solicitudes_redencion WHERE id_solicitud = $1";
        $res_sol = pg_query_params($conexion, $sql_sol, array($id_solicitud));

        if (!$res_sol || pg_num_rows($res_sol) === 0) {
            if ($res_sol) pg_free_result($res_sol);
            throw new Exception('Solicitud no encontrada.');
        }

        $datos_solicitud = pg_fetch_assoc($res_sol);
        pg_free_result($res_sol);

        $privado_documento = $datos_solicitud['privado_documento'];
        $dias_presente_contados = (int)$datos_solicitud['dias_presente_contados'];
        $fecha_corte_liquidacion = $datos_solicitud['fecha_calculo_inicio']; 

        // C. CÁLCULO DEL BENEFICIO DE REDENCIÓN (Ratio 2:1) 
        $RATIO_REDENCION = 2; 
        $dias_redencion_otorgados = floor($dias_presente_contados / $RATIO_REDENCION); 

        // D. Actualizar la solicitud con los días otorgados y detalles de aprobación
        $sql_update_sol = "UPDATE solicitudes_redencion SET 
                               estado = $1, 
                               decidido_por_usuario = $2, 
                               fecha_decision = $3, 
                               numero_aprobacion = $4, 
                               juez_aprobacion = $5,
                               dias_redencion_otorgados = $6
                               WHERE id_solicitud = $7";
        
        $res_update_sol = pg_query_params($conexion, $sql_update_sol, array(
            $decision, 
            $usuario_decision, 
            $fecha_decision, 
            $numero_aprobacion, 
            $juez_aprobacion,
            $dias_redencion_otorgados, 
            $id_solicitud
        ));
                   
        if (!$res_update_sol) {
            throw new Exception('Error al ejecutar la actualización de la solicitud: ' . pg_last_error($conexion));
        }
        pg_free_result($res_update_sol);
        
        // E. Actualizar la tabla 'privados' (Acumular los días de redención)
        $sql_priv = "UPDATE privados SET dias_redencion_acumulados = dias_redencion_acumulados + $1 WHERE documento_identidad = $2";
        $res_priv = pg_query_params($conexion, $sql_priv, array($dias_redencion_otorgados, $privado_documento));
        
        if (!$res_priv) {
            throw new Exception('Error al actualizar el acumulado de días de redención del privado: ' . pg_last_error($conexion));
        }
        pg_free_result($res_priv);

        // F. Cierre de Liquidación
        if (!empty($datos_solicitud['fecha_calculo_inicio'])) {
             $fecha_corte_liquidacion = $datos_solicitud['fecha_calculo_inicio']; 
             $sql_reg_laboral = "UPDATE registros_laborales SET ultima_fecha_liquidada = $1 WHERE privado_documento = $2";
             $res_reg_laboral = pg_query_params($conexion, $sql_reg_laboral, array($fecha_corte_liquidacion, $privado_documento));
             
             if (!$res_reg_laboral) {
                 throw new Exception('Error al actualizar la fecha de liquidación laboral: ' . pg_last_error($conexion));
             }
             pg_free_result($res_reg_laboral);
        }

        // G. Commit y Mensaje de Éxito
        pg_query($conexion, "COMMIT");
        $_SESSION['mensaje_exito'] = '¡Solicitud APROBADA con éxito! Se otorgaron ' . $dias_redencion_otorgados . ' días de redención.';

    } elseif ($decision === 'Rechazado') {
        // --- PROCESO DE RECHAZO ---
        
        // 1. LIBERAR SALDO PENDIENTE
        $sql_liberar_dias = "UPDATE liquidacion_laboral
                             SET id_solicitud_redencion = NULL
                             WHERE id_solicitud_redencion = $1";

        $res_liberar = pg_query_params($conexion, $sql_liberar_dias, array($id_solicitud)); 

        if (!$res_liberar) {
            throw new Exception('Error al liberar los días de la liquidación tras el rechazo: ' . pg_last_error($conexion));
        }
        pg_free_result($res_liberar);

        // 2. Actualizar el estado de la Solicitud
        $sql_rechazo = "UPDATE solicitudes_redencion SET 
                           estado = $1, 
                           decidido_por_usuario = $2, 
                           fecha_decision = $3
                           WHERE id_solicitud = $4";
                           
        $res_rechazo = pg_query_params($conexion, $sql_rechazo, array($decision, $usuario_decision, $fecha_decision, $id_solicitud));
                   
        if (!$res_rechazo) {
            throw new Exception('Error al ejecutar el rechazo de la solicitud: ' . pg_last_error($conexion));
        }
        pg_free_result($res_rechazo);

        // 3. Commit y Mensaje de Éxito
        pg_query($conexion, "COMMIT");
        $_SESSION['mensaje_exito'] = 'Solicitud RECHAZADA y marcada como tal en el sistema. Los días han sido liberados para una nueva solicitud.';
    }

} catch (Exception $e) {
    pg_query($conexion, "ROLLBACK");
    error_log("Error en decidir_solicitud: " . $e->getMessage());
    $_SESSION['mensaje_error'] = 'Error de Transacción: No se pudo procesar la decisión. Detalles: ' . $e->getMessage();
}

if (isset($conexion)) {
    pg_close($conexion);
}
header('Location: redenciones.php');
exit;
?>