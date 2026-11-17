<?php
session_start();
require_once 'header.php';
require_once 'conexion.php';

// 1. Validar ID
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error_message'] = "No se especificó un registro de trabajo.";
    header('Location: trabajo_listar.php');
    exit();
}
$registro_id = $_GET['id'];

// 2. Obtener datos del Registro Laboral (PostgreSQL)
$sql_registro = "SELECT 
                     rl.id, p.documento_identidad,
                     (p.nombre || ' ' || p.apellido) AS nombre_completo,
                     rl.fecha_inicio_labor, rl.horario_desde, rl.horario_hasta,
                     rl.dias_laborales, rl.fecha_fin_labor,
                     rl.ultima_fecha_liquidada
                  FROM registros_laborales rl
                  JOIN privados p ON rl.privado_documento = p.documento_identidad
                  WHERE rl.id = $1";

$res_registro = pg_query_params($conexion, $sql_registro, array($registro_id));

if (!$res_registro || pg_num_rows($res_registro) === 0) {
    if ($res_registro) pg_free_result($res_registro);
    $_SESSION['error_message'] = "Registro de trabajo no encontrado.";
    header('Location: trabajo_listar.php');
    exit();
}
$registro = pg_fetch_assoc($res_registro);
pg_free_result($res_registro);

$documento_privado = $registro['documento_identidad'];
$ultima_fecha_liquidada = $registro['ultima_fecha_liquidada'];

// =================================================================
// === LÓGICA DE CÁLCULO MEJORADA (LIQUIDACIÓN + NUEVO) ===
// =================================================================

// --- PARTE 1: BUSCAR EL SALDO ACUMULADO LIQUIDADO ---
$dias_liquidados_acumulados = 0;
$horas_liquidadas_acumuladas = 0.0;

$sql_liquidado = "SELECT 
                     SUM(dias_trabajados) AS total_dias, 
                     SUM(horas_trabajadas) AS total_horas
                  FROM liquidacion_laboral 
                  WHERE registro_laboral_id = $1";
$res_liq = pg_query_params($conexion, $sql_liquidado, array($registro_id));

if ($res_liq && pg_num_rows($res_liq) > 0) {
    $fila_liq = pg_fetch_assoc($res_liq);
    $dias_liquidados_acumulados = (int)($fila_liq['total_dias'] ?? 0);
    $horas_liquidadas_acumuladas = (float)($fila_liq['total_horas'] ?? 0.0);
    pg_free_result($res_liq);
}

// --- PARTE 2: CALCULAR DÍAS NUEVOS (DEL SISTEMA) ---
$dias_presente_nuevos = 0;
$dias_reposo_nuevos = 0;
$dias_permiso_nuevos = 0;
$dias_suspension_nuevos = 0;

if (!empty($ultima_fecha_liquidada)) {
    $fecha_inicio_conteo = (new DateTime($ultima_fecha_liquidada))->modify('+1 day')->format('Y-m-d');
} else {
    $fecha_inicio_conteo = $registro['fecha_inicio_labor'];
}

$sql_conteo_nuevo = "SELECT asistencia, COUNT(id) AS total_dias 
                     FROM asistencias 
                     WHERE registro_laboral_id = $1 
                     AND fecha >= $2 
                     GROUP BY asistencia";
$res_conteo = pg_query_params($conexion, $sql_conteo_nuevo, array($registro_id, $fecha_inicio_conteo));

if ($res_conteo) {
    while ($fila = pg_fetch_assoc($res_conteo)) {
        switch ($fila['asistencia']) {
            case 'Presente': $dias_presente_nuevos = (int)$fila['total_dias']; break;
            case 'Reposo': $dias_reposo_nuevos = (int)$fila['total_dias']; break;
            case 'Permiso': $dias_permiso_nuevos = (int)$fila['total_dias']; break;
            case 'Suspensión': $dias_suspension_nuevos += (int)$fila['total_dias']; break;
            case 'Ausente': $dias_suspension_nuevos += (int)$fila['total_dias']; break;
        }
    }
    pg_free_result($res_conteo);
}

// --- PARTE 3: CÁLCULOS TOTALES ---
$horario_desde = new DateTime($registro['horario_desde']);
$horario_hasta = new DateTime($registro['horario_hasta']);
$intervalo_horas = $horario_desde->diff($horario_hasta);
$horas_por_dia = $intervalo_horas->h + ($intervalo_horas->i / 60);

$dias_validos_nuevos = 0;
if ($horas_por_dia >= 6) { 
    $dias_validos_nuevos = $dias_presente_nuevos + $dias_reposo_nuevos + $dias_permiso_nuevos;
}
$horas_nuevas = $dias_validos_nuevos * $horas_por_dia;

// === ¡GRAN TOTAL! ===
$dias_totales_validos = $dias_liquidados_acumulados + $dias_validos_nuevos;
$horas_totales = $horas_liquidadas_acumuladas + $horas_nuevas;
$dias_redencion = floor($dias_totales_validos / 2);
// =================================================================
?>

<main class="app-main">
    <div class="app-content-header">
        <div class="container-fluid">
            <h3 class="mb-0">Cálculo de Redención</h3>
        </div>
    </div>
    <div class="app-content">
        <div class="container-fluid">
            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <div class="card card-outline card-primary">
                        <div class="card-body">
                            <h4 class="card-title">Resumen de Trabajo para: <strong><?php echo htmlspecialchars($registro['nombre_completo']); ?></strong></h4>
                            <p class="card-subtitle text-muted">Registro de Trabajo #<?php echo htmlspecialchars($registro_id); ?> | Inicio Laboral: <?php echo date('d/m/Y', strtotime($registro['fecha_inicio_labor'])); ?></p>
                            <hr>
                            
                            <?php if ($ultima_fecha_liquidada): ?>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle-fill"></i> El último período liquidado es hasta el <strong><?php echo date('d/m/Y', strtotime($ultima_fecha_liquidada)); ?></strong>. El cálculo de "Nuevos Días" inicia el <strong><?php echo date('d/m/Y', strtotime($fecha_inicio_conteo)); ?></strong>.
                            </div>
                            <?php endif; ?>

                            <div class="row">
                                <div class="col-md-6">
                                    <h5 class="mt-4">1. Saldo Liquidado (Historial Laboral)</h5>
                                    <ul class="list-group list-group-flush">
                                        <li class="list-group-item"><strong>Días Acumulados:</strong> <?php echo $dias_liquidados_acumulados; ?> días</li>
                                        <li class="list-group-item"><strong>Horas Acumuladas:</strong> <?php echo number_format($horas_liquidadas_acumuladas, 2); ?> horas</li>
                                    </ul>
                                </div>
                                
                                <div class="col-md-6">
                                    <h5 class="mt-4">2. Nuevos Días (Asistencia del Sistema)</h5>
                                    <ul class="list-group list-group-flush">
                                        <li class="list-group-item"><strong>Días Presente:</strong> <?php echo $dias_presente_nuevos; ?> días</li>
                                        <li class="list-group-item"><strong>Días Reposo/Permiso:</strong> <?php echo $dias_reposo_nuevos + $dias_permiso_nuevos; ?> días</li>
                                        <li class="list-group-item"><strong>Días Válidos Nuevos:</strong> <?php echo $dias_validos_nuevos; ?> días</li>
                                        <li class="list-group-item"><strong>Horas Nuevas:</strong> <?php echo number_format($horas_nuevas, 2); ?> horas</li>
                                    </ul>
                                </div>
                            </div>
                            
                            <hr class="my-4">

                            <h5 class="mt-4 text-primary">3. Resumen Total y Cálculo de Redención</h5>
                            <ul class="list-group list-group-flush">
                                <li class="list-group-item fs-5"><strong>Total Días Válidos (Liquidado + Nuevo):</strong> <strong><?php echo $dias_totales_validos; ?> días</strong></li>
                                <li class="list-group-item fs-5"><strong>Total Horas Acumuladas:</strong> <strong><?php echo number_format($horas_totales, 2); ?> horas</strong></li>
                                <li class="list-group-item fs-4 text-success">
                                    <strong>Días de Redención de Condena (<?php echo $dias_totales_validos; ?> / 2):</strong>
                                    <strong class="float-end"><?php echo $dias_redencion; ?> días</strong>
                                </li>
                            </ul>

                            <h5 class="mt-5 text-danger">Resumen de Ausencias (Sistema)</h5>
                            <ul class="list-group list-group-flush">
                                <li class="list-group-item"><strong>Ausentes Injustificados / Suspendidos:</strong> <?php echo $dias_suspension_nuevos; ?> días</li>
                            </ul>

                            <div class="mt-5 d-flex justify-content-end">
                                <a href="trabajo_listar.php" class="btn btn-secondary">Volver al Listado</a>
                            </div>
                            </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>
<?php
if (isset($conexion)) {
    pg_close($conexion);
}
require_once 'footer.php';
?>