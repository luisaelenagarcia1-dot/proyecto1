<?php
// buscar_privado.php
require_once 'conexion.php';

header('Content-Type: application/json');

if (!isset($_GET['documento_identidad']) || empty($_GET['documento_identidad'])) {
    echo json_encode(['encontrado' => false, 'elegible' => false, 'mensaje' => 'Documento de identidad no proporcionado.']);
    exit;
}

$documento_privado = $_GET['documento_identidad'];
$fecha_corte_liquidacion = date('Y-m-d'); 
$fecha_actual = new DateTime(); 

// Inicializar variables
$dias_totales_validos = 0;
$mensaje_elegibilidad = [];
$elegible_judicialmente = true;
$elegible_laboralmente_final = false;

// =======================================================
// 1. OBTENER DATOS DEL PRIVADO Y REGISTRO LABORAL ACTIVO (PostgreSQL)
// =======================================================
$sql_privado = "SELECT p.nombre, p.apellido, 
                        rl.id AS registro_id, rl.fecha_inicio_labor, 
                        rl.horario_desde, rl.horario_hasta
                FROM privados p
                LEFT JOIN registros_laborales rl ON p.documento_identidad = rl.privado_documento AND rl.fecha_fin_labor IS NULL
                WHERE p.documento_identidad = $1";

$res_privado = pg_query_params($conexion, $sql_privado, array($documento_privado));

if (!$res_privado || pg_num_rows($res_privado) == 0) {
    if ($res_privado) pg_free_result($res_privado);
    echo json_encode(['encontrado' => false, 'elegible' => false, 'mensaje' => 'Cédula no registrada en el sistema de privados.']);
    exit;
}

$data = pg_fetch_assoc($res_privado);
pg_free_result($res_privado);

$nombre_completo = htmlspecialchars($data['nombre'] . ' ' . $data['apellido']);

if (!$data['registro_id']) {
    echo json_encode(['encontrado' => true, 'elegible' => false, 'nombre_completo' => $nombre_completo, 'dias_contados' => 0, 'mensaje' => '❌ **No es Elegible Laboralmente.** No tiene un registro de trabajo activo.']);
    exit;
}

$registro_id = $data['registro_id'];
$fecha_inicio_labor = $data['fecha_inicio_labor'];

try {
    $horario_desde = new DateTime($data['horario_desde']);
    $horario_hasta = new DateTime($data['horario_hasta']);
    $intervalo_horas = $horario_desde->diff($horario_hasta);
    $horas_por_dia = $intervalo_horas->h + ($intervalo_horas->i / 60);
} catch (Exception $e) {
    echo json_encode(['encontrado' => true, 'elegible' => false, 'nombre_completo' => $nombre_completo, 'dias_contados' => 0, 'mensaje' => '❌ Error: Horario laboral mal configurado.']);
    exit;
}


// =======================================================
// 2. VALIDACIÓN JUDICIAL (INFORMACIÓN COMPLETA Y 50% DE PENA)
// =======================================================

$sql_judicial = "SELECT 
                    p.fecha_detencion,
                    cj.fecha_condena,
                    cj.duracion_condena_dias
                FROM privados p
                LEFT JOIN privados_casos pc ON p.documento_identidad = pc.privado_documento
                LEFT JOIN casos_judiciales cj ON pc.caso_expediente = cj.numero_expediente
                WHERE p.documento_identidad = $1";

$res_judicial = pg_query_params($conexion, $sql_judicial, array($documento_privado));
$datos_judiciales = ($res_judicial && pg_num_rows($res_judicial) > 0) ? pg_fetch_assoc($res_judicial) : [];
if ($res_judicial) pg_free_result($res_judicial);

$duracion_condena_dias = (int)($datos_judiciales['duracion_condena_dias'] ?? 0);
$fecha_condena_db = $datos_judiciales['fecha_condena'] ?? '';
$fecha_detencion_db = $datos_judiciales['fecha_detencion'] ?? '';

// A. Validación de Información Completa
if (empty($fecha_condena_db) || $fecha_condena_db === '0000-00-00') {
    $elegible_judicialmente = false;
    $mensaje_elegibilidad[] = "❌ **Falta Fecha de Condena.** Información judicial incompleta.";
}

if ($duracion_condena_dias <= 0) {
    $elegible_judicialmente = false;
    $mensaje_elegibilidad[] = "❌ **Falta Duración de Condena.** (Días) debe ser mayor a cero.";
}

// B. Validación del 50% de la Pena Cumplida
if ($elegible_judicialmente) {
    $dias_requeridos_50_porciento = floor($duracion_condena_dias / 2); 
    
    try {
        $fecha_inicio_cumplimiento = new DateTime($fecha_detencion_db); 
        $dias_cumplidos = $fecha_inicio_cumplimiento->diff($fecha_actual)->days;
    } catch (Exception $e) {
        $elegible_judicialmente = false;
        $mensaje_elegibilidad[] = "❌ Error en el formato de la Fecha de Condena o Detención.";
    }

    if ($elegible_judicialmente && $dias_cumplidos < $dias_requeridos_50_porciento) {
        $elegible_judicialmente = false;
        $dias_faltantes = $dias_requeridos_50_porciento - $dias_cumplidos;
        $mensaje_elegibilidad[] = "❌ **Pena no cumplida.** Faltan **{$dias_faltantes} días** para el 50% ({$dias_requeridos_50_porciento} días requeridos). (Calculado desde Detención)";
    } elseif ($elegible_judicialmente) {
        $mensaje_elegibilidad[] = "✅ Requisito Judicial (50% de la Pena) **CUMPLIDO**. ($dias_cumplidos días cumplidos desde Detención).";
    }
}

// Bloqueo si la validación judicial falla
if (!$elegible_judicialmente) {
    echo json_encode([
        'encontrado' => true,
        'elegible' => false,
        'nombre_completo' => $nombre_completo,
        'dias_contados' => 0,
        'mensaje' => implode("<br>", $mensaje_elegibilidad)
    ]);
    if (isset($conexion)) { pg_close($conexion); }
    exit;
}


// =======================================================
// 3. OBTENER LA ÚLTIMA FECHA DE CORTE
// =======================================================
$ultima_fecha_liquidada = null;
$sql_last_cut = "SELECT fecha_fin_periodo 
                  FROM liquidacion_laboral 
                  WHERE registro_laboral_id = $1 
                   AND id_solicitud_redencion IS NOT NULL 
                  ORDER BY fecha_fin_periodo DESC 
                  LIMIT 1";

$res_last_cut = pg_query_params($conexion, $sql_last_cut, array($registro_id));
if ($res_last_cut && pg_num_rows($res_last_cut) > 0) {
    $ultima_fecha_liquidada = pg_fetch_assoc($res_last_cut)['fecha_fin_periodo'];
    pg_free_result($res_last_cut);
} elseif ($res_last_cut) {
    pg_free_result($res_last_cut);
}


// =======================================================
// 4. CALCULAR DÍAS DE ASISTENCIA NUEVOS
// =======================================================
$dias_validos_nuevos = 0;
$horas_nuevas = 0.0;

if (!empty($ultima_fecha_liquidada)) {
    $fecha_inicio_conteo = (new DateTime($ultima_fecha_liquidada))->modify('+1 day')->format('Y-m-d');
} else {
    $fecha_inicio_conteo = $fecha_inicio_labor;
}

if ($fecha_inicio_conteo <= $fecha_corte_liquidacion) {
    $sql_conteo_nuevo = "SELECT asistencia, COUNT(id) AS total_dias 
                         FROM asistencias 
                         WHERE registro_laboral_id = $1 
                         AND fecha >= $2 
                         AND fecha <= $3 
                         AND asistencia IN ('Presente', 'Reposo', 'Permiso')
                         GROUP BY asistencia";
    
    $res_conteo = pg_query_params($conexion, $sql_conteo_nuevo, array($registro_id, $fecha_inicio_conteo, $fecha_corte_liquidacion));

    $dias_presente_nuevos = 0;
    $dias_reposo_nuevos = 0;
    $dias_permiso_nuevos = 0;

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

    if ($horas_por_dia >= 6) { 
        $dias_validos_nuevos = $dias_presente_nuevos + $dias_reposo_nuevos + $dias_permiso_nuevos;
    }
}


// =======================================================
// 5. OBTENER SALDO TOTAL ACUMULADO PENDIENTE
// =======================================================
$sql_saldo_pendiente = "SELECT 
                            SUM(dias_trabajados) AS saldo_dias
                       FROM liquidacion_laboral 
                       WHERE registro_laboral_id = $1 
                       AND id_solicitud_redencion IS NULL";

$res_saldo = pg_query_params($conexion, $sql_saldo_pendiente, array($registro_id));
$saldo_acumulado = 0;
if ($res_saldo && pg_num_rows($res_saldo) > 0) {
    $row_saldo = pg_fetch_assoc($res_saldo);
    $saldo_acumulado = $row_saldo['saldo_dias'] ?? 0;
    pg_free_result($res_saldo);
} elseif ($res_saldo) {
    pg_free_result($res_saldo);
}

// Suma de Saldo Pendiente + Días Nuevos
$dias_totales_validos = (int)$saldo_acumulado + (int)$dias_validos_nuevos;
$dias_redencion_propuestos = floor($dias_totales_validos / 2); // Ratio 2:1

if ($dias_totales_validos >= 2) {
    $elegible_laboralmente_final = true;
    $mensaje_elegibilidad[] = "✅ **Elegible Laboralmente.** Total de días laborados acumulados: **{$dias_totales_validos}**.";
} elseif ($dias_totales_validos > 0) {
    $mensaje_elegibilidad[] = "⚠️ Días laborados acumulados: **{$dias_totales_validos}**. Se requieren al menos 2 días para generar una redención.";
} else {
    $mensaje_elegibilidad[] = "No tiene días válidos acumulados desde el último corte.";
}


// =======================================================
// 6. RESPUESTA JSON FINAL
// =======================================================
$elegible_final = $elegible_judicialmente && $elegible_laboralmente_final;

echo json_encode([
    'encontrado' => true,
    'elegible' => $elegible_final,
    'nombre_completo' => $nombre_completo,
    'dias_contados' => $dias_totales_validos,
    'dias_redencion_propuestos' => $dias_redencion_propuestos,
    'mensaje' => implode("<br>", $mensaje_elegibilidad) 
]);

if (isset($conexion)) {
    pg_close($conexion);
}
exit;
?>