<?php

session_start();

require_once 'conexion.php';

// Habilitar errores para depuración (opcional)
ini_set('display_errors', 1);
error_reporting(E_ALL);

// =======================================================
// 1. CÓDIGO DE SEGURIDAD
// =======================================================
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: login.php?error=no_sesion');
    exit;
}

// =======================================================
// 2. VALIDAR DATOS DE ENTRADA
// =======================================================
if ($_SERVER["REQUEST_METHOD"] != "POST" || empty($_POST['privado_documento'])) {
    $_SESSION['mensaje_error'] = "No se proporcionó el documento del privado para la solicitud.";
    header('Location: redenciones.php');
    exit;
}

$documento_privado = $_POST['privado_documento'];
$usuario_solicitante = $_SESSION['nombre_usuario'] ?? 'SYSTEM'; 
$fecha_corte_liquidacion = date('Y-m-d'); // Fecha de corte

// =======================================================
// 2.5. VERIFICAR SOLICITUD PENDIENTE (PostgreSQL)
// =======================================================
$sql_check = "SELECT id_solicitud FROM solicitudes_redencion WHERE privado_documento = $1 AND estado = 'En Proceso' LIMIT 1";
$res_check = pg_query_params($conexion, $sql_check, array($documento_privado));

if ($res_check && pg_num_rows($res_check) > 0) {
    pg_free_result($res_check);
    $_SESSION['mensaje_error'] = 'Ya existe una solicitud de redención pendiente de aprobación para este privado.';
    if (isset($conexion)) { pg_close($conexion); }
    header('Location: redenciones.php');
    exit;
}
if ($res_check) { pg_free_result($res_check); }

// =======================================================
// INICIO DE TRANSACCIÓN CRÍTICA (PostgreSQL)
// =======================================================
pg_query($conexion, "BEGIN");

try {
    // =======================================================
    // 3. OBTENER EL REGISTRO DE TRABAJO ACTIVO
    // =======================================================
    $sql_reg = "SELECT id, fecha_inicio_labor, horario_desde, horario_hasta
                FROM registros_laborales
                WHERE privado_documento = $1 AND fecha_fin_labor IS NULL
                LIMIT 1";
                
    $res_reg = pg_query_params($conexion, $sql_reg, array($documento_privado));

    if (!$res_reg || pg_num_rows($res_reg) === 0) {
        if ($res_reg) pg_free_result($res_reg);
        throw new Exception("El privado no tiene un registro laboral activo.");
    }
    
    $registro = pg_fetch_assoc($res_reg);
    pg_free_result($res_reg);
    
    $registro_id = $registro['id'];

    // =======================================================
    // 4. CALCULAR Y LIQUIDAR DÍAS NUEVOS (LÓGICA ANTI-DUPLICACIÓN)
    // =======================================================

    $fecha_inicio_labor = $registro['fecha_inicio_labor'];

    // 1. Buscamos la ÚLTIMA FECHA DE FIN DE PERIODO liquidado
    $sql_last_liquidated_date = "SELECT MAX(ll.fecha_fin_periodo) AS ultima_fecha_liquidada 
                                   FROM liquidacion_laboral ll
                                   JOIN registros_laborales rl ON ll.registro_laboral_id = rl.id
                                   WHERE rl.privado_documento = $1";
                                   
    $res_last_date = pg_query_params($conexion, $sql_last_liquidated_date, array($documento_privado));
    $row_last_date = ($res_last_date && pg_num_rows($res_last_date) > 0) ? pg_fetch_assoc($res_last_date) : [];
    if ($res_last_date) pg_free_result($res_last_date);
    
    $ultima_fecha = $row_last_date['ultima_fecha_liquidada'] ?? null;
    
    // Si la última liquidación es inválida
    if (empty($fecha_inicio_labor)) {
        throw new Exception("Fecha de inicio de labor no es válida.");
    }
    
    $fecha_inicio_conteo = $fecha_inicio_labor; 

    if (!empty($ultima_fecha)) {
        try {
            $fecha_inicio_conteo = (new DateTime($ultima_fecha))->modify('+1 day')->format('Y-m-d');
        } catch (Exception $e) {
            throw new Exception("Error al calcular el día siguiente a la última liquidación: " . $e->getMessage());
        }
    }
    
    // Control para evitar liquidaciones si el inicio es igual o posterior al corte
    if ($fecha_inicio_conteo > $fecha_corte_liquidacion) {
        $dias_validos_nuevos = 0;
        $horas_nuevas = 0;
    } else {
        // --- 4.B: Contar Días Nuevos desde Asistencias ---
        $dias_presente_nuevos = 0;
        $dias_reposo_nuevos = 0;
        $dias_permiso_nuevos = 0;

        $sql_conteo_nuevo = "SELECT asistencia, COUNT(id) AS total_dias
                             FROM asistencias
                             WHERE registro_laboral_id = $1
                             AND fecha >= $2
                             AND fecha <= $3
                             AND asistencia IN ('Presente', 'Reposo', 'Permiso')
                             GROUP BY asistencia";
                             
        $res_conteo = pg_query_params($conexion, $sql_conteo_nuevo, array($registro_id, $fecha_inicio_conteo, $fecha_corte_liquidacion));

        if ($res_conteo) {
            while ($fila = pg_fetch_assoc($res_conteo)) {
                switch ($fila['asistencia']) {
                    case 'Presente': $dias_presente_nuevos = (int)$fila['total_dias']; break;
                    case 'Reposo': $dias_reposo_nuevos = (int)$fila['total_dias']; break;
                    case 'Permiso': $dias_permiso_nuevos = (int)$fila['total_dias']; break;
                }
            }
            pg_free_result($res_conteo);
        }

        // --- 4.C: Calcular Horas y Días Válidos ---
        $horario_desde = new DateTime($registro['horario_desde']);
        $horario_hasta = new DateTime($registro['horario_hasta']);
        $intervalo_horas = $horario_desde->diff($horario_hasta);
        $horas_por_dia = $intervalo_horas->h + ($intervalo_horas->i / 60);

        $dias_validos_nuevos = 0;
        if ($horas_por_dia >= 6) { 
            $dias_validos_nuevos = $dias_presente_nuevos + $dias_reposo_nuevos + $dias_permiso_nuevos;
        }
        $horas_nuevas = $dias_validos_nuevos * $horas_por_dia;
        
        // --- 4.D: INSERTAR LA NUEVA LIQUIDACIÓN ---
        if ($dias_validos_nuevos > 0) {
            $sql_insert_liq = "INSERT INTO liquidacion_laboral (registro_laboral_id, fecha_inicio_periodo, fecha_fin_periodo, dias_trabajados, horas_trabajadas, usuario_liquidador)
                               VALUES ($1, $2, $3, $4, $5, $6)"; 

            $res_insert_liq = pg_query_params($conexion, $sql_insert_liq, array(
                $registro_id,
                $fecha_inicio_conteo,
                $fecha_corte_liquidacion,
                $dias_validos_nuevos,
                $horas_nuevas,
                $usuario_solicitante
            ));
            
            if (!$res_insert_liq) {
                throw new Exception("Error al registrar la nueva liquidación: " . pg_last_error($conexion));
            }
            pg_free_result($res_insert_liq);
        }
    }


    // =======================================================
    // 5. SUMAR EL SALDO PENDIENTE TOTAL
    // =======================================================
    $sql_total_disponible = "SELECT
                                SUM(dias_trabajados) AS total_dias_validos,
                                SUM(horas_trabajadas) AS total_horas
                             FROM liquidacion_laboral
                             WHERE registro_laboral_id = $1
                             AND id_solicitud_redencion IS NULL";

    $res_total = pg_query_params($conexion, $sql_total_disponible, array($registro_id));
    $row_total = ($res_total && pg_num_rows($res_total) > 0) ? pg_fetch_assoc($res_total) : [];
    if ($res_total) pg_free_result($res_total);

    $dias_totales_validos = (int)($row_total['total_dias_validos'] ?? 0);
    $horas_totales = (float)($row_total['total_horas'] ?? 0);
    
    if ($dias_totales_validos === 0) {
        pg_query($conexion, "ROLLBACK"); 
        throw new Exception("El privado no tiene días acumulados válidos para generar una solicitud de redención.");
    }
    
    // =======================================================
    // 6. INSERTAR LA NUEVA SOLICITUD (RETURNING id_solicitud)
    // =======================================================
    $sql_insert = "INSERT INTO solicitudes_redencion
                     (privado_documento, fecha_calculo_inicio, fecha_solicitud, dias_presente_contados, horas_contadas, estado, solicitado_por_usuario)
                     VALUES ($1, $2, CURRENT_TIMESTAMP, $3, $4, $5, $6)
                     RETURNING id_solicitud";
                     
    $estado_proceso = 'En Proceso'; 
    
    $res_insert = pg_query_params($conexion, $sql_insert, array(
        $documento_privado,
        $fecha_inicio_conteo,
        $dias_totales_validos,
        $horas_totales,
        $estado_proceso,
        $usuario_solicitante
    ));

    if (!$res_insert || pg_num_rows($res_insert) === 0) {
        throw new Exception("Error al insertar la solicitud: " . pg_last_error($conexion));
    }
    
    $row_inserted = pg_fetch_assoc($res_insert);
    $id_solicitud_nueva = $row_inserted['id_solicitud'];
    pg_free_result($res_insert);
    
    // =======================================================
    // 7. VINCULAR SALDO PENDIENTE A LA NUEVA SOLICITUD
    // =======================================================
    $sql_vincular_saldo = "UPDATE liquidacion_laboral
                           SET id_solicitud_redencion = $1
                           WHERE registro_laboral_id = $2
                           AND id_solicitud_redencion IS NULL";

    $res_vincular = pg_query_params($conexion, $sql_vincular_saldo, array($id_solicitud_nueva, $registro_id));
    
    if (!$res_vincular) {
        throw new Exception("Error al vincular liquidación al id de solicitud: " . pg_last_error($conexion));
    }
    pg_free_result($res_vincular);

    // Confirmar transacción
    pg_query($conexion, "COMMIT");
    
    $_SESSION['mensaje_exito'] = "La solicitud de redención se ha generado con éxito. Días acumulados: {$dias_totales_validos}.";
    if (isset($conexion)) { pg_close($conexion); }
    header('Location: redenciones.php');
    exit;

} catch (Exception $e) {
    // Revertir transacción en caso de error
    pg_query($conexion, "ROLLBACK");
    
    error_log("Error en procesar_solicitud.php: " . $e->getMessage());
    $_SESSION['mensaje_error'] = 'Error al generar la solicitud: ' . $e->getMessage();
    if (isset($conexion)) { pg_close($conexion); }
    header('Location: redenciones.php');
    exit;
}
?>