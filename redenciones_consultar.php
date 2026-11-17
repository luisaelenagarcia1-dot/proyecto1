<?php
session_start();
require_once 'header.php';
require_once 'conexion.php';

// 1. Validar ID
if (!isset($_GET['documento']) || empty($_GET['documento'])) {
    $_SESSION['error_message'] = "No se especificó un documento de identidad para la consulta.";
    header('Location: redenciones.php');
    exit();
}
$documento_privado = $_GET['documento'];

// 2. Obtener datos del Privado y su Registro Laboral ACTIVO
$sql_registro = "SELECT 
                    rl.id, 
                    CONCAT(p.nombre, ' ', p.apellido) AS nombre_completo,
                    rl.fecha_inicio_labor, rl.horario_desde, rl.horario_hasta
                FROM registros_laborales rl
                JOIN privados p ON rl.privado_documento = p.documento_identidad
                WHERE rl.privado_documento = ? AND rl.fecha_fin_labor IS NULL
                LIMIT 1"; // Solo consideramos el trabajo ACTIVO

$stmt_registro = $conexion->prepare($sql_registro);
$stmt_registro->bind_param("s", $documento_privado);
$stmt_registro->execute();
$resultado_registro = $stmt_registro->get_result();

if ($resultado_registro->num_rows === 0) {
    // Si no hay registro laboral activo, buscamos solo el nombre del privado
    $sql_privado = "SELECT CONCAT(nombre, ' ', apellido) AS nombre_completo FROM privados WHERE documento_identidad = ?";
    $stmt_privado = $conexion->prepare($sql_privado);
    $stmt_privado->bind_param("s", $documento_privado);
    $stmt_privado->execute();
    $resultado_privado = $stmt_privado->get_result();
    $nombre_completo = ($resultado_privado->num_rows > 0) ? $resultado_privado->fetch_assoc()['nombre_completo'] : 'Privado No Encontrado';
    $stmt_privado->close();

    $registro = null; // No hay trabajo activo

} else {
    $registro = $resultado_registro->fetch_assoc();
    $registro_id = $registro['id'];
    $nombre_completo = $registro['nombre_completo'];
}
$stmt_registro->close();


// Inicialización de variables para la presentación
$dias_totales_validos_redencion = 0;
$horas_totales_redencion = 0;
$dias_redencion = 0;
$fecha_corte_historica = null;
$dias_presente_nuevos = 0;
$dias_reposo_nuevos = 0;
$dias_permiso_nuevos = 0;
$dias_suspension_nuevos = 0;
$horas_por_dia = 0;
$dias_validos_nuevos = 0;


if ($registro) {
    // =================================================================
    // === LÓGICA DE CÁLCULO DE REDENCIÓN (DEPENDE DE CARGA HISTÓRICA) ===
    // =================================================================

    // --- PARTE 1: BUSCAR EL SALDO HISTÓRICO (ÚLTIMO RESET) ---
    $dias_historicos_validos = 0;
    $horas_historicas = 0;

    // Buscamos el último punto de corte (la Carga Histórica con 0 días/horas)
    $sql_historico = "SELECT dias_presente_contados, horas_contadas, fecha_solicitud 
                      FROM solicitudes_redencion 
                      WHERE privado_documento = ? 
                      AND juez_aprobacion = 'Carga Histórica de Sistema'
                      LIMIT 1";
    $stmt_hist = $conexion->prepare($sql_historico);
    $stmt_hist->bind_param("s", $documento_privado);
    $stmt_hist->execute();
    $res_hist = $stmt_hist->get_result();

    if ($res_hist->num_rows > 0) {
        $fila_hist = $res_hist->fetch_assoc();
        // Nota: Estos deben ser 0 si la redención fue aprobada recientemente
        $dias_historicos_validos = (int)$fila_hist['dias_presente_contados']; 
        $horas_historicas = (float)$fila_hist['horas_contadas'];
        $fecha_corte_historica = $fila_hist['fecha_solicitud']; 
    }
    $stmt_hist->close();


    // --- PARTE 2: CALCULAR DÍAS NUEVOS (Desde el último corte hasta Hoy) ---
    
    // Si hay corte, empezamos a contar desde el día siguiente. Si no, desde el inicio del trabajo.
    if ($fecha_corte_historica) {
        $fecha_inicio_conteo = (new DateTime($fecha_corte_historica))->modify('+1 day')->format('Y-m-d');
    } else {
        $fecha_inicio_conteo = $registro['fecha_inicio_labor'];
    }

    $sql_conteo_nuevo = "SELECT asistencia, COUNT(id) AS total_dias 
                         FROM asistencias 
                         WHERE registro_laboral_id = ? 
                         AND fecha >= ? 
                         AND fecha <= CURDATE() 
                         GROUP BY asistencia";
    $stmt_conteo = $conexion->prepare($sql_conteo_nuevo);
    $stmt_conteo->bind_param("is", $registro_id, $fecha_inicio_conteo);
    $stmt_conteo->execute();
    $resultado_conteo = $stmt_conteo->get_result();

    while ($fila = $resultado_conteo->fetch_assoc()) {
        switch ($fila['asistencia']) {
            case 'Presente': $dias_presente_nuevos = $fila['total_dias']; break;
            case 'Reposo': $dias_reposo_nuevos = $fila['total_dias']; break;
            case 'Permiso': $dias_permiso_nuevos = $fila['total_dias']; break;
            case 'Suspensión': 
            case 'Ausente': $dias_suspension_nuevos += $fila['total_dias']; break;
        }
    }
    $stmt_conteo->close();

    // --- PARTE 3: CÁLCULOS FINALES ---

    $horario_desde = new DateTime($registro['horario_desde']);
    $horario_hasta = new DateTime($registro['horario_hasta']);
    $intervalo_horas = $horario_desde->diff($horario_hasta);
    $horas_por_dia = $intervalo_horas->h + ($intervalo_horas->i / 60);

    // Días válidos son Presente, Reposo y Permiso (si cumple el mínimo de 6 horas)
    if ($horas_por_dia >= 6) { 
        $dias_validos_nuevos = $dias_presente_nuevos + $dias_reposo_nuevos + $dias_permiso_nuevos;
    }
    $horas_nuevas = $dias_validos_nuevos * $horas_por_dia;

    // === TOTALES DE REDENCIÓN ===
    $dias_totales_validos_redencion = $dias_historicos_validos + $dias_validos_nuevos;
    $horas_totales_redencion = $horas_historicas + $horas_nuevas;
    $dias_redencion = floor($dias_totales_validos_redencion / 2);
    // =================================================================
}
?>

<main class="app-main">
    <div class="app-content-header">
        <div class="container-fluid">
            <h3 class="mb-0">Consulta de Acumulación para Redención</h3>
        </div>
    </div>
    <div class="app-content">
        <div class="container-fluid">
            <div class="row justify-content-center">
                <div class="col-lg-10">
                    <div class="card card-outline card-primary">
                        <div class="card-header">
                            <h5 class="card-title">Documento: <strong><?php echo htmlspecialchars($documento_privado); ?></strong> | Privado: <strong><?php echo htmlspecialchars($nombre_completo); ?></strong></h5>
                        </div>
                        <div class="card-body">
                            
                            <?php if (!$registro): ?>
                                <div class="alert alert-warning text-center">
                                    <i class="bi bi-exclamation-triangle-fill"></i> El privado **<?php echo htmlspecialchars($nombre_completo); ?>** no tiene un **Registro Laboral ACTIVO**. No se puede calcular la acumulación de redención.
                                    <br> <small>Revise la sección de Gestión Laboral para iniciar su registro.</small>
                                </div>
                                <div class="mt-4 d-flex justify-content-end">
                                    <a href="redenciones.php" class="btn btn-secondary">Volver a Redenciones</a>
                                </div>

                            <?php else: 
                                // Mostrar Resumen de Trabajo Activo
                                $fecha_inicio_formato = date('d/m/Y', strtotime($registro['fecha_inicio_labor']));
                                $horario_formato = date('h:i A', strtotime($registro['horario_desde'])) . ' - ' . date('h:i A', strtotime($registro['horario_hasta']));
                                ?>
                                
                                <h6 class="text-muted">Detalles del Registro Laboral Activo (#<?php echo htmlspecialchars($registro_id); ?>)</h6>
                                <p><strong>Inicio:</strong> <?php echo $fecha_inicio_formato; ?> | <strong>Horario:</strong> <?php echo $horario_formato; ?> (<?php echo number_format($horas_por_dia, 2); ?> horas/día)</p>
                                <hr>

                                <!-- ALERTA DE CORTE -->
                                <?php if ($fecha_corte_historica): ?>
                                <div class="alert alert-info py-2">
                                    <i class="bi bi-info-circle-fill"></i> **Corte para Redención:** El conteo del sistema se reinició el **<?php echo date('d/m/Y', strtotime($fecha_corte_historica)); ?>** (Fecha de Solicitud Aprobada).
                                </div>
                                <?php endif; ?>

                                <div class="row">
                                    <!-- RESUMEN HISTÓRICO -->
                                    <div class="col-md-6">
                                        <div class="card card-outline card-secondary h-100">
                                            <div class="card-header">
                                                <h5 class="card-title">1. Acumulado Anterior (Carga Histórica)</h5>
                                            </div>
                                            <div class="card-body">
                                                <ul class="list-group list-group-flush">
                                                    <li class="list-group-item">
                                                        <strong>Días Contables (Históricos):</strong> 
                                                        <span class="float-end"><?php echo $dias_historicos_validos; ?> días</span>
                                                    </li>
                                                    <li class="list-group-item">
                                                        <strong>Horas Contables (Históricas):</strong> 
                                                        <span class="float-end"><?php echo number_format($horas_historicas, 2); ?> horas</span>
                                                    </li>
                                                </ul>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- RESUMEN NUEVO -->
                                    <div class="col-md-6">
                                        <div class="card card-outline card-success h-100">
                                            <div class="card-header">
                                                <h5 class="card-title">2. Acumulado Nuevo (Desde el corte hasta hoy)</h5>
                                            </div>
                                            <div class="card-body">
                                                <ul class="list-group list-group-flush">
                                                    <li class="list-group-item">
                                                        <strong>Días Presente/Permiso/Reposo:</strong> 
                                                        <span class="float-end"><?php echo $dias_validos_nuevos; ?> días</span>
                                                    </li>
                                                    <li class="list-group-item">
                                                        <strong>Horas Contables Nuevas:</strong> 
                                                        <span class="float-end"><?php echo number_format($horas_nuevas, 2); ?> horas</span>
                                                    </li>
                                                    <li class="list-group-item text-danger">
                                                        <strong>Días de Ausencia / Suspensión:</strong> 
                                                        <span class="float-end"><?php echo $dias_suspension_nuevos; ?> días</span>
                                                    </li>
                                                </ul>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <hr class="my-5">
                                
                                <!-- RESULTADO FINAL -->
                                <div class="card bg-gradient-primary text-white shadow-lg">
                                    <div class="card-body text-center">
                                        <h3 class="mb-3">TOTAL ACUMULADO PARA REDENCIÓN</h3>
                                        <h1 class="display-4">
                                            <?php echo $dias_totales_validos_redencion; ?> DÍAS VÁLIDOS
                                        </h1>
                                        <h4 class="mb-4">
                                            (<?php echo number_format($horas_totales_redencion, 2); ?> Horas Acumuladas)
                                        </h4>
                                        
                                        <h3 class="mb-0 mt-4">DÍAS DE REDENCIÓN DISPONIBLES:</h3>
                                        <h1 class="display-3 font-weight-bold"><?php echo $dias_redencion; ?> DÍAS</h1>
                                    </div>
                                </div>

                                <div class="mt-4 d-flex justify-content-end">
                                    <a href="redenciones.php" class="btn btn-secondary">Volver a Redenciones</a>
                                </div>

                            <?php endif; ?>

                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>
<?php
$conexion->close();
require_once 'footer.php';
?>