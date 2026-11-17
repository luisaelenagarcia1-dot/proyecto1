<?php
// =========================================================================
// MÓDULO DE CONSULTA, REPORTES Y CERTIFICADOS (ADMIN vs COORDINADOR)
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

// 1. Determinar el centro según el rol
$centro_usuario = '';
if ($es_coordinador) {
    $query_c = "SELECT centro_codigo FROM usuario_centros WHERE usuario_cedula = $1 LIMIT 1";
    $res_c = pg_query_params($conexion, $query_c, array($usuario_cedula));
    if ($res_c && $row_c = pg_fetch_assoc($res_c)) { 
        $centro_usuario = $row_c['centro_codigo']; 
    } else {
        $centro_usuario = 'TIGRE01'; // Respaldo por defecto
    }
}

// Filtros recibidos por GET
$centro_seleccionado = $_GET['centro_codigo'] ?? ($es_coordinador ? $centro_usuario : '');
$seccion_seleccionada = $_GET['seccion_id'] ?? null;
$filtro_estatus = $_GET['filtro_estatus'] ?? 'TODOS';
$busqueda_texto = trim($_GET['busqueda_texto'] ?? ''); // Cédula o nombre

// 2. Obtener lista de centros con su NOMBRE completo para el Administrador
$centros_lista = [];
if ($es_administrador) {
    $res_centros = pg_query($conexion, "SELECT DISTINCT c.codigo, c.nombre FROM secciones_cursos sc JOIN centros c ON sc.centro_codigo = c.codigo ORDER BY c.nombre ASC");
    if ($res_centros) {
        while ($row_cen = pg_fetch_assoc($res_centros)) {
            $centros_lista[] = $row_cen;
        }
    }
}

// 3. Obtener las secciones activas según el centro seleccionado o el rol
$query_secciones = "
    SELECT sc.id, sc.codigo_nomenclatura, c.nombre_curso, sc.centro_codigo, cn.nombre AS nombre_centro
    FROM secciones_cursos sc
    JOIN cursos c ON sc.curso_id = c.id
    LEFT JOIN centros cn ON sc.centro_codigo = cn.codigo
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

$estudiantes_lista = [];

// 4. Consulta de estudiantes con filtros avanzados
if ($seccion_seleccionada || !empty($busqueda_texto) || !empty($centro_seleccionado)) {
    $query_estudiantes = "
        SELECT 
            ie.id AS inscripcion_id,
            p.documento_identidad,
            p.nombre,
            p.apellido,
            sc.codigo_nomenclatura,
            c.nombre_curso,
            sc.centro_codigo,
            cn.nombre AS nombre_centro,
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
        JOIN secciones_cursos sc ON ie.seccion_id = sc.id
        JOIN cursos c ON sc.curso_id = c.id
        LEFT JOIN centros cn ON sc.centro_codigo = cn.codigo
        WHERE 1=1
    ";

    $params_est = array();
    $param_index = 1;

    if (!empty($seccion_seleccionada)) {
        $query_estudiantes .= " AND ie.seccion_id = $" . $param_index;
        $params_est[] = $seccion_seleccionada;
        $param_index++;
    } elseif ($es_coordinador) {
        $query_estudiantes .= " AND sc.centro_codigo = $" . $param_index;
        $params_est[] = $centro_usuario;
        $param_index++;
    } elseif ($es_administrador && !empty($centro_seleccionado)) {
        $query_estudiantes .= " AND sc.centro_codigo = $" . $param_index;
        $params_est[] = $centro_seleccionado;
        $param_index++;
    }

    if (!empty($busqueda_texto)) {
        $query_estudiantes .= " AND (p.documento_identidad ILIKE $" . $param_index . " OR p.nombre ILIKE $" . $param_index . " OR p.apellido ILIKE $" . $param_index . ")";
        $params_est[] = "%" . $busqueda_texto . "%";
        $param_index++;
    }

    if ($filtro_estatus === 'APROBADO') {
        $query_estudiantes .= " AND ie.estado_aprobacion = 'APROBADO'";
    } elseif ($filtro_estatus === 'REPROBADO') {
        $query_estudiantes .= " AND (ie.estado_aprobacion = 'REPROBADO' OR ie.estado_aprobacion = 'REPROBADO_FALTAS')";
    } elseif ($filtro_estatus === 'SOBRESALIENTE') {
        $query_estudiantes .= " AND ie.estatus_sobresaliente = 't'";
    }

    $query_estudiantes .= " ORDER BY p.apellido ASC";
    
    $estudiantes_result = pg_query_params($conexion, $query_estudiantes, $params_est);
    if ($estudiantes_result) {
        while ($row = pg_fetch_assoc($estudiantes_result)) {
            $estudiantes_lista[] = $row;
        }
    }
}
?>

<?php include('header.php'); ?>

<main class="app-main">
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row align-items-center mb-2">
                <div class="col-sm-6">
                    <h3 class="mb-0"><i class="bi bi-search me-2"></i> Consulta General, Consolidado y Certificados</h3>
                </div>
                <div class="col-sm-6 text-end">
                    <!-- BOTÓN DE DESCARGAR EXCEL -->
                    <a href="evaluaciones_excel.php?centro_codigo=<?php echo urlencode($centro_seleccionado); ?>&seccion_id=<?php echo urlencode($seccion_seleccionada); ?>&filtro_estatus=<?php echo urlencode($filtro_estatus); ?>&busqueda_texto=<?php echo urlencode($busqueda_texto); ?>" class="btn btn-success btn-sm me-1">
                        <i class="bi bi-file-earmark-excel me-1"></i> Excel
                    </a>
                    
                    <!-- BOTÓN DE DESCARGAR PDF -->
                    <a href="evaluaciones_pdf.php?centro_codigo=<?php echo urlencode($centro_seleccionado); ?>&seccion_id=<?php echo urlencode($seccion_seleccionada); ?>&filtro_estatus=<?php echo urlencode($filtro_estatus); ?>&busqueda_texto=<?php echo urlencode($busqueda_texto); ?>" class="btn btn-danger btn-sm me-1">
                        <i class="bi bi-file-earmark-pdf-fill me-1"></i> PDF
                    </a>

                    <!-- BOTÓN DE CERTIFICADO GRUPAL (Se muestra si hay una sección seleccionada) -->
                    <?php if (!empty($seccion_seleccionada)): ?>
                    <a href="certificado_grupo_pdf.php?seccion_id=<?php echo $seccion_seleccionada; ?>" target="_blank" class="btn btn-warning btn-sm fw-bold">
                        <i class="bi bi-award-fill me-1"></i> Certificados Grupo (PDF)
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="app-content">
        <div class="container-fluid">
            
            <!-- TARJETA 1: FILTROS DINÁMICOS -->
            <div class="card card-primary card-outline mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h3 class="card-title"><i class="bi bi-filter-square me-2"></i> Filtros de Búsqueda Académica</h3>
                    <a href="evaluaciones_registrar.php" class="btn btn-success btn-sm">
                        <i class="bi bi-plus-circle me-1"></i> Ir al Módulo de Registro
                    </a>
                </div>
                <div class="card-body">
                    <form method="GET" action="evaluaciones_consultar.php" class="row g-3">
                        
                        <?php if ($es_administrador): ?>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Centro Penitenciario:</label>
                            <select name="centro_codigo" class="form-select" onchange="this.form.submit()">
                                <option value="">-- Todos los Centros --</option>
                                <?php foreach ($centros_lista as $cen): ?>
                                    <option value="<?php echo $cen['codigo']; ?>" <?php echo ($centro_seleccionado === $cen['codigo']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cen['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>

                        <div class="col-md-<?php echo $es_administrador ? '3' : '4'; ?>">
                            <label class="form-label fw-bold">Sección Académica:</label>
                            <select name="seccion_id" class="form-select">
                                <option value="">-- Todas las Secciones --</option>
                                <?php 
                                if ($secciones_result) {
                                    while ($sec = pg_fetch_assoc($secciones_result)) {
                                        $selected = ($seccion_seleccionada == $sec['id']) ? 'selected' : '';
                                        echo '<option value="' . $sec['id'] . '" ' . $selected . '>';
                                        echo htmlspecialchars($sec['codigo_nomenclatura'] . ' - ' . $sec['nombre_curso'] . ' (' . ($sec['nombre_centro'] ?? $sec['centro_codigo']) . ')');
                                        echo '</option>';
                                    }
                                }
                                ?>
                            </select>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label fw-bold">Cédula o Nombre:</label>
                            <input type="text" name="busqueda_texto" value="<?php echo htmlspecialchars($busqueda_texto); ?>" class="form-control" placeholder="Ej: V-12345678 o María">
                        </div>

                        <div class="col-md-2">
                            <label class="form-label fw-bold">Estatus:</label>
                            <select name="filtro_estatus" class="form-select">
                                <option value="TODOS" <?php echo ($filtro_estatus === 'TODOS') ? 'selected' : ''; ?>>Todos</option>
                                <option value="APROBADO" <?php echo ($filtro_estatus === 'APROBADO') ? 'selected' : ''; ?>>Aprobados</option>
                                <option value="REPROBADO" <?php echo ($filtro_estatus === 'REPROBADO') ? 'selected' : ''; ?>>Reprobados</option>
                                <option value="SOBRESALIENTE" <?php echo ($filtro_estatus === 'SOBRESALIENTE') ? 'selected' : ''; ?>>Sobresalientes ⭐</option>
                            </select>
                        </div>

                        <div class="col-md-1 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100" title="Buscar">
                                <i class="bi bi-search"></i>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- TARJETA 2: RESULTADOS DE LA CONSULTA -->
            <div class="card card-info card-outline mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h3 class="card-title">
                        <i class="bi bi-journal-text me-2"></i> Consolidado de Calificaciones y Rendimiento
                    </h3>
                </div>
                <div class="card-body">
                    <?php if (empty($estudiantes_lista)): ?>
                        <div class="alert alert-warning text-center">Utilice los filtros superiores para consultar los registros académicos.</div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped align-middle">
                            <thead class="table-dark text-center">
                                <tr>
                                    <th>Centro</th>
                                    <th>Sección / Curso</th>
                                    <th>Cédula</th>
                                    <th>Apellidos y Nombres</th>
                                    <th>% Asistencia</th>
                                    <th>Eval. 1</th>
                                    <th>Eval. 2</th>
                                    <th>Eval. 3</th>
                                    <th>Proyecto Final</th>
                                    <th>Nota Final</th>
                                    <th>Estatus</th>
                                    <th>Certificado Individual</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($estudiantes_lista as $est): 
                                    $asistencia = floatval($est['porcentaje_asistencia_calculado']);
                                    $n1 = $est['nota_eval_1'] ?? '0';
                                    $n2 = $est['nota_eval_2'] ?? '0';
                                    $n3 = $est['nota_eval_3'] ?? '0';
                                    $n_prac = $est['evaluacion_practica'] ?? '0';
                                    $nota_fin = $est['calificacion_final'] ?? '0';
                                    $es_aprobado = ($est['estado_aprobacion'] === 'APROBADO');
                                ?>
                                <tr>
                                    <td><small class="fw-bold text-muted"><?php echo htmlspecialchars($est['nombre_centro'] ?? $est['centro_codigo']); ?></small></td>
                                    <td><small class="fw-bold"><?php echo htmlspecialchars($est['codigo_nomenclatura'] . '<br>' . $est['nombre_curso']); ?></small></td>
                                    <td class="fw-bold text-center"><?php echo htmlspecialchars($est['documento_identidad']); ?></td>
                                    <td><?php echo htmlspecialchars($est['apellido'] . ', ' . $est['nombre']); ?></td>
                                    
                                    <td class="text-center">
                                        <?php if ($asistencia >= 75.00): ?>
                                            <span class="badge bg-success"><?php echo number_format($asistencia, 2); ?>%</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger"><?php echo number_format($asistencia, 2); ?>% (Faltas)</span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="text-center"><?php echo htmlspecialchars($n1); ?></td>
                                    <td class="text-center"><?php echo htmlspecialchars($n2); ?></td>
                                    <td class="text-center"><?php echo htmlspecialchars($n3); ?></td>
                                    <td class="text-center fw-bold text-primary"><?php echo htmlspecialchars($n_prac); ?></td>
                                    
                                    <td class="text-center fw-bold">
                                        <span class="badge bg-secondary fs-6"><?php echo number_format(floatval($nota_fin), 0); ?></span>
                                    </td>

                                    <td class="text-center">
                                        <?php if ($es_aprobado): ?>
                                            <span class="badge bg-success">APROBADO</span>
                                        <?php elseif ($est['estado_aprobacion'] === 'REPROBADO_FALTAS'): ?>
                                            <span class="badge bg-danger">REPROBADO (Inasistencia)</span>
                                        <?php elseif ($est['estado_aprobacion'] === 'REPROBADO'): ?>
                                            <span class="badge bg-danger">REPROBADO</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark">EN CURSO</span>
                                        <?php endif; ?>

                                        <?php if ($est['estatus_sobresaliente'] == 't' || $est['estatus_sobresaliente'] == '1'): ?>
                                            <br><span class="badge bg-warning text-dark mt-1"><i class="bi bi-star-fill"></i> Sobresaliente</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Columna para imprimir el certificado individual del estudiante -->
                                    <td class="text-center">
                                        <?php if ($es_aprobado): ?>
                                            <a href="certificado_individual_pdf.php?id=<?php echo $est['inscripcion_id']; ?>" target="_blank" class="btn btn-sm btn-outline-danger" title="Imprimir Certificado Individual">
                                                <i class="bi bi-file-earmark-pdf-fill me-1"></i> Certificado
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted small">No disponible</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>
</main>

<?php 
if (isset($conexion)) { pg_close($conexion); }
require_once 'footer.php'; 
?>