<?php
// =========================================================================
// MÓDULO DE REGISTRO DE EVALUACIONES (ENTEROS, SOBRESALIENTE Y ACTAS)
// =========================================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: login.php');
    exit;
}

$usuario_cedula = $_SESSION['nombre_usuario'] ?? '';
$rolUsuario = $_SESSION['cargo'] ?? '';
$es_administrador = ($rolUsuario === 'Administradora' || $rolUsuario === 'Administrador');
$es_coordinador   = (strcasecmp($rolUsuario, 'Coordinador Regional') === 0);

require_once 'conexion.php'; 

// 1. Determinar el centro del usuario si es coordinador
$centro_usuario = '';
if ($es_coordinador) {
    $query_c = "SELECT centro_codigo FROM usuario_centros WHERE usuario_cedula = $1 LIMIT 1";
    $res_c = pg_query_params($conexion, $query_c, array($usuario_cedula));
    if ($res_c && $row_c = pg_fetch_assoc($res_c)) { 
        $centro_usuario = $row_c['centro_codigo']; 
    } else {
        $centro_usuario = 'TIGRE01'; 
    }
}

// Recoger centro seleccionado si el usuario es Administrador
$centro_seleccionado = $_GET['centro_codigo'] ?? ($es_coordinador ? $centro_usuario : '');

// Obtener lista de centros con su nombre completo para el Administrador
$centros_lista = [];
if ($es_administrador) {
    $res_centros = pg_query($conexion, "
        SELECT DISTINCT c.codigo, c.nombre 
        FROM centros c 
        JOIN secciones_cursos sc ON c.codigo = sc.centro_codigo 
        ORDER BY c.nombre ASC
    ");
    if ($res_centros) {
        while ($row_cen = pg_fetch_assoc($res_centros)) {
            $centros_lista[] = $row_cen;
        }
    }
}

// 2. Obtener las secciones activas filtradas por rol y centro
$query_secciones = "
    SELECT sc.id, sc.codigo_nomenclatura, c.nombre_curso, sc.centro_codigo, ce.nombre AS nombre_centro
    FROM secciones_cursos sc
    JOIN cursos c ON sc.curso_id = c.id
    LEFT JOIN centros ce ON sc.centro_codigo = ce.codigo
";
$params_sec = array();

if ($es_coordinador) {
    $query_secciones .= " WHERE sc.centro_codigo = $1";
    $params_sec[] = $centro_usuario;
} elseif ($es_administrador && !empty($centro_seleccionado)) {
    $query_secciones .= " WHERE sc.centro_codigo = $1";
    $params_sec[] = $centro_seleccionado;
}
$query_secciones .= " ORDER BY sc.codigo_nomenclatura ASC";

$secciones_result = !empty($params_sec) ? pg_query_params($conexion, $query_secciones, $params_sec) : pg_query($conexion, $query_secciones);

$seccion_seleccionada = $_GET['seccion_id'] ?? null;
$estudiantes_lista = [];
$config_curso = null;

if ($seccion_seleccionada) {
    $query_config = "
        SELECT c.num_evaluaciones_parciales, c.incluye_proyecto, c.nombre_curso, c.duracion_horas
        FROM secciones_cursos sc
        JOIN cursos c ON sc.curso_id = c.id
        WHERE sc.id = $1
    ";
    $res_cfg = pg_query_params($conexion, $query_config, array($seccion_seleccionada));
    if ($res_cfg) {
        $config_curso = pg_fetch_assoc($res_cfg);
    }

    $query_estudiantes = "
        SELECT 
            ie.id AS inscripcion_id,
            p.documento_identidad,
            p.nombre,
            p.apellido,
            ie.nota_eval_1,
            ie.nota_eval_2,
            ie.nota_eval_3,
            ie.evaluacion_practica,
            ie.calificacion_final,
            ie.estado_aprobacion,
            ie.estatus_sobresaliente,
            COALESCE(
                (
                    SELECT ROUND((SUM(CASE WHEN a.asistencia = 'Presente' THEN 1 ELSE 0 END)::numeric / NULLIF(COUNT(a.id), 0)) * 100, 2)
                    FROM asistencia_educativa a
                    WHERE a.inscripcion_id = ie.id
                ), 0.00
            ) AS porcentaje_asistencia_calculado
        FROM inscripciones_educativas ie
        JOIN privados p ON ie.privado_documento = p.documento_identidad
        WHERE ie.seccion_id = $1
        ORDER BY p.apellido ASC
    ";
    $estudiantes_result = pg_query_params($conexion, $query_estudiantes, array($seccion_seleccionada));
    if ($estudiantes_result) {
        while ($row = pg_fetch_assoc($estudiantes_result)) {
            $estudiantes_lista[] = $row;
        }
    }
}

// 3. Procesar formulario (Guardar notas y calcular sobresaliente >= 18)
$mensaje_alerta = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_evaluaciones'])) {
    $secc_id = $_POST['seccion_id'];
    
    if (isset($_POST['inscripcion_id']) && is_array($_POST['inscripcion_id'])) {
        foreach ($_POST['inscripcion_id'] as $index => $insc_id) {
            $n1 = intval($_POST['nota_eval_1'][$index] ?? 0);
            $n2 = intval($_POST['nota_eval_2'][$index] ?? 0);
            $n3 = intval($_POST['nota_eval_3'][$index] ?? 0);
            
            $val_raw = $_POST['evaluacion_practica'][$index] ?? 0;
            $nota_proyecto = is_numeric($val_raw) ? intval($val_raw) : 0;
            
            $asistencia_real = floatval($_POST['porcentaje_asistencia_real'][$index] ?? 0);

            $promedio_parciales = ($n1 + $n2 + $n3) / 3;
            $calificacion_final = round(($promedio_parciales * 0.60) + ($nota_proyecto * 0.40));

            $estado_aprobacion = 'EN_CURSO';
            $estado_cursante = 'Cursando';
            $es_sobresaliente = 'false';

            if ($asistencia_real >= 75.00) {
                if ($calificacion_final >= 10 && $nota_proyecto >= 10) {
                    $estado_aprobacion = 'APROBADO';
                    $estado_cursante = 'Aprobado';
                    if ($calificacion_final >= 18) {
                        $es_sobresaliente = 'true';
                    }
                } else {
                    $estado_aprobacion = 'REPROBADO';
                    $estado_cursante = 'Reprobado';
                }
            } else {
                $estado_aprobacion = 'REPROBADO_FALTAS';
                $estado_cursante = 'Reprobado';
            }

            $update_sql = "
                UPDATE inscripciones_educativas 
                SET nota_eval_1 = $1, 
                    nota_eval_2 = $2, 
                    nota_eval_3 = $3, 
                    evaluacion_practica = $4, 
                    porcentaje_asistencia = $5,
                    calificacion_final = $6,
                    estado_aprobacion = $7,
                    estado_cursante = $8,
                    estatus_sobresaliente = $9
                WHERE id = $10
            ";
            pg_query_params($conexion, $update_sql, array(
                $n1, $n2, $n3, strval($nota_proyecto), $asistencia_real, 
                $calificacion_final, $estado_aprobacion, $estado_cursante, 
                $es_sobresaliente, $insc_id
            ));
        }
        $mensaje_alerta = '<div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i> ¡Calificaciones y estatus guardados con éxito!
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>';
        
        $param_redireccion = ($es_administrador && !empty($centro_seleccionado)) ? "&centro_codigo=$centro_seleccionado" : "";
        header("Refresh: 1; URL=evaluaciones_registrar.php?seccion_id=$secc_id$param_redireccion");
    }
}
?>

<?php include('header.php'); ?>

<main class="app-main">
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row">
                <div class="col-sm-6">
                    <h3 class="mb-0"><i class="bi bi-journal-check me-2"></i> Registro de Evaluaciones y Actas</h3>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-end">
                        <li class="breadcrumb-item"><a href="index.php">Inicio</a></li>
                        <li class="breadcrumb-item active">Evaluaciones</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <div class="app-content">
        <div class="container-fluid">
            
            <?php echo $mensaje_alerta; ?>

            <!-- TARJETA 1: FILTRO DE SECCIONES -->
            <div class="card card-primary card-outline mb-4">
                <div class="card-header">
                    <h3 class="card-title"><i class="bi bi-filter-square me-2"></i> Seleccionar Sección Académica</h3>
                </div>
                <div class="card-body">
                    <form method="GET" action="evaluaciones_registrar.php" class="row g-3">
                        <?php if ($es_administrador): ?>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Centro Penitenciario:</label>
                            <select name="centro_codigo" class="form-select" onchange="this.form.submit()">
                                <option value="">-- Seleccione un Centro --</option>
                                <?php foreach ($centros_lista as $cen): ?>
                                    <option value="<?php echo $cen['codigo']; ?>" <?php echo ($centro_seleccionado === $cen['codigo']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cen['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>

                        <div class="col-md-<?php echo $es_administrador ? '8' : '12'; ?>">
                            <label class="form-label fw-bold">Secciones Activas:</label>
                            <select name="seccion_id" class="form-select" onchange="this.form.submit()" required>
                                <option value="">-- Seleccione una sección --</option>
                                <?php 
                                if ($secciones_result) {
                                    while ($sec = pg_fetch_assoc($secciones_result)) {
                                        $selected = ($seccion_seleccionada == $sec['id']) ? 'selected' : '';
                                        $nombre_centro_mostrar = $sec['nombre_centro'] ?? $sec['centro_codigo'];
                                        echo '<option value="' . $sec['id'] . '" ' . $selected . '>';
                                        echo htmlspecialchars($sec['codigo_nomenclatura'] . ' - ' . $sec['nombre_curso'] . ' (' . $nombre_centro_mostrar . ')');
                                        echo '</option>';
                                    }
                                }
                                ?>
                            </select>
                        </div>
                    </form>
                </div>
            </div>

            <!-- TARJETA 2: LISTADO DE CURSANTES Y CARGA DE NOTAS -->
            <?php if ($seccion_seleccionada): ?>
            <div class="card card-success card-outline mb-4">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <h3 class="card-title mb-0"><i class="bi bi-people-fill me-2"></i> Carga de Notas y Calificaciones</h3>
                        <?php if ($config_curso): ?>
                            <small class="text-muted d-block mt-1">
                                Curso: <strong><?php echo htmlspecialchars($config_curso['nombre_curso']); ?></strong> | 
                                Duración: <span class="badge bg-dark"><?php echo intval($config_curso['duracion_horas'] ?? 40); ?> Horas</span>
                            </small>
                        <?php endif; ?>
                    </div>
                    <!-- BOTONES GRANDES ALINEADOS A LA DERECHA -->
                    <div class="ms-auto d-flex gap-2">
                        <a href="evaluaciones_excel.php?seccion_id=<?php echo $seccion_seleccionada; ?>" class="btn btn-success btn-lg fw-bold px-4 py-2">
                            <i class="bi bi-file-earmark-excel me-2"></i> Descargar Excel
                        </a>
                        <a href="evaluaciones_pdf.php?seccion_id=<?php echo $seccion_seleccionada; ?>" class="btn btn-danger btn-lg fw-bold px-4 py-2">
                            <i class="bi bi-file-earmark-pdf-fill me-2"></i> Descargar PDF
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($estudiantes_lista)): ?>
                        <div class="alert alert-warning text-center">No hay cursantes inscritas en esta sección actualmente.</div>
                    <?php else: ?>
                    <form method="POST" action="evaluaciones_registrar.php?seccion_id=<?php echo $seccion_seleccionada; ?><?php echo !empty($centro_seleccionado) ? '&centro_codigo='.$centro_seleccionado : ''; ?>">
                        <input type="hidden" name="seccion_id" value="<?php echo $seccion_seleccionada; ?>">

                        <div class="table-responsive">
                            <table class="table table-bordered table-striped align-middle">
                                <thead class="table-dark text-center">
                                    <tr>
                                        <th>Cédula</th>
                                        <th>Apellidos y Nombres</th>
                                        <th>% Asistencia Real</th>
                                        <th>Eval. 1 (20%)</th>
                                        <th>Eval. 2 (20%)</th>
                                        <th>Eval. 3 (20%)</th>
                                        <th>Proyecto Final (40%)</th>
                                        <th>Nota Final Ponderada</th>
                                        <th>Estatus</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($estudiantes_lista as $index_est => $est): 
                                        $asist_auto = $est['porcentaje_asistencia_calculado'];
                                        $val_practica = is_numeric($est['evaluacion_practica']) ? intval($est['evaluacion_practica']) : 0;
                                        
                                        $n1 = intval($est['nota_eval_1'] ?? 0);
                                        $n2 = intval($est['nota_eval_2'] ?? 0);
                                        $n3 = intval($est['nota_eval_3'] ?? 0);
                                        $prom_parciales = ($n1 + $n2 + $n3) / 3;
                                        $nota_ponderada_inicial = round(($prom_parciales * 0.60) + ($val_practica * 0.40));
                                    ?>
                                    <tr class="fila-estudiante">
                                        <input type="hidden" name="inscripcion_id[]" value="<?php echo $est['inscripcion_id']; ?>">
                                        <input type="hidden" name="porcentaje_asistencia_real[]" value="<?php echo $asist_auto; ?>">

                                        <td class="fw-bold"><?php echo htmlspecialchars($est['documento_identidad']); ?></td>
                                        <td><?php echo htmlspecialchars($est['apellido'] . ', ' . $est['nombre']); ?></td>
                                        
                                        <td class="text-center">
                                            <?php if ($asist_auto >= 75.00): ?>
                                                <span class="badge bg-success fs-6"><?php echo number_format($asist_auto, 2); ?>%</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger fs-6"><?php echo number_format($asist_auto, 2); ?>% ⚠️</span>
                                            <?php endif; ?>
                                        </td>

                                        <td><input type="number" step="1" min="0" max="20" name="nota_eval_1[]" value="<?php echo $n1; ?>" class="form-control form-control-sm text-center nota-input n1" style="width: 75px;" required></td>
                                        <td><input type="number" step="1" min="0" max="20" name="nota_eval_2[]" value="<?php echo $n2; ?>" class="form-control form-control-sm text-center nota-input n2" style="width: 75px;" required></td>
                                        <td><input type="number" step="1" min="0" max="20" name="nota_eval_3[]" value="<?php echo $n3; ?>" class="form-control form-control-sm text-center nota-input n3" style="width: 75px;" required></td>
                                        <td>
                                            <input type="number" step="1" min="0" max="20" name="evaluacion_practica[]" value="<?php echo $val_practica; ?>" class="form-control form-control-sm text-center fw-bold text-primary nota-input proyecto" style="width: 85px;" required>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-secondary fs-6 span-ponderada"><?php echo $nota_ponderada_inicial; ?></span>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($est['estado_aprobacion'] === 'APROBADO'): ?>
                                                <span class="badge bg-success">APROBADO</span>
                                                <?php if ($est['estatus_sobresaliente'] == 't' || $est['estatus_sobresaliente'] == '1'): ?>
                                                    <br><span class="badge bg-warning text-dark mt-1"><i class="bi bi-star-fill"></i> Sobresaliente</span>
                                                <?php endif; ?>
                                            <?php elseif ($est['estado_aprobacion'] === 'REPROBADO_FALTAS'): ?>
                                                <span class="badge bg-danger">REPROBADO (Faltas)</span>
                                            <?php elseif ($est['estado_aprobacion'] === 'REPROBADO'): ?>
                                                <span class="badge bg-danger">REPROBADO</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark">EN CURSO</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-3">
                            <button type="submit" name="guardar_evaluaciones" class="btn btn-success">
                                <i class="bi bi-save me-1"></i> Guardar Calificaciones y Validar Asistencia
                            </button>
                        </div>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>
</main>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const filas = document.querySelectorAll(".fila-estudiante");
    filas.forEach(fila => {
        const inputs = fila.querySelectorAll(".nota-input");
        const spanPonderada = fila.querySelector(".span-ponderada");
        inputs.forEach(input => {
            input.addEventListener("input", function() {
                let n1 = parseFloat(fila.querySelector(".n1").value) || 0;
                let n2 = parseFloat(fila.querySelector(".n2").value) || 0;
                let n3 = parseFloat(fila.querySelector(".n3").value) || 0;
                let proyecto = parseFloat(fila.querySelector(".proyecto").value) || 0;
                let promedioParciales = (n1 + n2 + n3) / 3;
                let notaFinal = Math.round((promedioParciales * 0.60) + (proyecto * 0.40));
                spanPonderada.textContent = notaFinal;
                spanPonderada.className = "badge bg-info text-dark fs-6 span-ponderada";
            });
        });
    });
});
</script>

<?php require_once 'footer.php'; ?>