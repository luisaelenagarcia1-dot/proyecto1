<?php
session_start();
require_once 'conexion.php';

// Validar sesión activa
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: login.php");
    exit();
}

// Obtener datos del usuario logueado y limpiar la cédula de forma flexible
$rol_usuario     = $_SESSION['cargo'] ?? $_SESSION['rol'] ?? 'Gerente';
$es_coordinador  = (strcasecmp($rol_usuario, 'Coordinador Regional') === 0);

$cedula_cruda    = $_SESSION['nombre_usuario'] ?? $_SESSION['cedula'] ?? '';
$cedula_sesion   = preg_replace('/[^0-9]/', '', $cedula_cruda);

// Obtener el centro del coordinador regional si aplica
$centro_usuario = '';
if ($es_coordinador) {
    $sql_c_coord = "SELECT centro_codigo FROM usuario_centros WHERE REPLACE(REPLACE(usuario_cedula, 'V', ''), 'v', '') = $1 LIMIT 1";
    $res_c_coord = pg_query_params($conexion, $sql_c_coord, array($cedula_sesion));
    if ($res_c_coord && pg_num_rows($res_c_coord) > 0) {
        $row_cc = pg_fetch_assoc($res_c_coord);
        $centro_usuario = trim($row_cc['centro_codigo']);
    }
} else {
    $centro_usuario = trim($_SESSION['centro_codigo'] ?? '');
}

// Filtros de búsqueda
$filtro_centro  = trim($_GET['centro_codigo'] ?? '');
$filtro_curso   = trim($_GET['curso_id'] ?? '');
$filtro_seccion = trim($_GET['seccion_id'] ?? '');
$filtro_anio    = trim($_GET['anio'] ?? '');

// Si es coordinador, forzamos su centro
if ($es_coordinador) {
    $filtro_centro = $centro_usuario;
}

// 1. Obtener listado de centros (Para el Admin)
$res_centros = pg_query($conexion, "SELECT codigo, nombre FROM centros ORDER BY nombre ASC");

// 2. Obtener listado de Cursos (Disponible para ambos roles)
$res_cursos = pg_query($conexion, "SELECT id, nombre_curso FROM cursos ORDER BY nombre_curso ASC");

// 3. Obtener Secciones (Para el Coordinador o según el centro seleccionado)
if ($es_coordinador) {
    $sql_secciones = "SELECT id, codigo_nomenclatura FROM secciones_cursos WHERE centro_codigo = $1 ORDER BY codigo_nomenclatura ASC";
    $res_secciones = pg_query_params($conexion, $sql_secciones, array($centro_usuario));
} elseif (!empty($filtro_centro)) {
    $sql_secciones = "SELECT id, codigo_nomenclatura FROM secciones_cursos WHERE centro_codigo = $1 ORDER BY codigo_nomenclatura ASC";
    $res_secciones = pg_query_params($conexion, $sql_secciones, array($filtro_centro));
} else {
    $sql_secciones = "SELECT id, codigo_nomenclatura FROM secciones_cursos ORDER BY codigo_nomenclatura ASC";
    $res_secciones = pg_query($conexion, $sql_secciones);
}

// 4. Obtener Años disponibles en los registros de asistencia para el selector
$res_anios = pg_query($conexion, "SELECT DISTINCT EXTRACT(YEAR FROM fecha) as anio FROM asistencia_educativa ORDER BY anio DESC");

// 5. Construir la consulta SQL dinámica con todos los filtros
$sql_asistencias = "SELECT ae.*, ie.privado_documento, p.nombre, p.apellido, 
                           s.codigo_nomenclatura, c.id as curso_id, c.nombre_curso, cn.nombre as nombre_centro
                    FROM asistencia_educativa ae
                    JOIN inscripciones_educativas ie ON ae.inscripcion_id = ie.id
                    JOIN privados p ON ie.privado_documento = p.documento_identidad
                    JOIN secciones_cursos s ON ie.seccion_id = s.id
                    JOIN cursos c ON s.curso_id = c.id
                    LEFT JOIN centros cn ON s.centro_codigo = cn.codigo
                    WHERE 1=1";

$params = [];
$param_idx = 1;

if ($es_coordinador) {
    $sql_asistencias .= " AND s.centro_codigo = $" . $param_idx;
    $params[] = $centro_usuario;
    $param_idx++;

    if (!empty($filtro_seccion)) {
        $sql_asistencias .= " AND s.id = $" . $param_idx;
        $params[] = $filtro_seccion;
        $param_idx++;
    }
} else {
    if (!empty($filtro_centro)) {
        $sql_asistencias .= " AND s.centro_codigo = $" . $param_idx;
        $params[] = $filtro_centro;
        $param_idx++;
    }
}

// Filtro por curso general (aplica para ambos roles)
if (!empty($filtro_curso)) {
    $sql_asistencias .= " AND c.id = $" . $param_idx;
    $params[] = $filtro_curso;
    $param_idx++;
}

// Filtro por año (aplica para ambos roles)
if (!empty($filtro_anio)) {
    $sql_asistencias .= " AND EXTRACT(YEAR FROM ae.fecha) = $" . $param_idx;
    $params[] = $filtro_anio;
    $param_idx++;
}

$sql_asistencias .= " ORDER BY ae.fecha DESC, p.apellido ASC";
$res_asistencias = pg_query_params($conexion, $sql_asistencias, $params);

require_once 'header.php';
?>

<main class="app-main" style="width: 100%; min-height: 100vh;">
    <div class="app-content-header">
        <div class="container-fluid px-4">
            <div class="row align-items-center mb-2">
                <div class="col-sm-6">
                    <h3 class="mb-0">
                        <i class="bi bi-calendar-check-fill"></i> Control de Asistencias Educativas
                        <?php if ($es_coordinador): ?>
                            <span class="badge bg-info text-dark fs-6 ms-2">Recinto Asignado</span>
                        <?php else: ?>
                            <span class="badge bg-primary fs-6 ms-2">Vista General Global</span>
                        <?php endif; ?>
                    </h3>
                </div>
                <div class="col-sm-6 text-end">
                    <a href="asistencia_registrar.php" class="btn btn-success me-2 mb-1">
                        <i class="bi bi-plus-circle"></i> Registrar Asistencia
                    </a>
                    <!-- Botón Descargar Excel -->
                    <a href="asistencias_excel.php?centro_codigo=<?php echo urlencode($filtro_centro); ?>&curso_id=<?php echo urlencode($filtro_curso); ?>&seccion_id=<?php echo urlencode($filtro_seccion); ?>&anio=<?php echo urlencode($filtro_anio); ?>" class="btn btn-success me-2 mb-1">
                        <i class="bi bi-file-earmark-excel-fill"></i> Descargar Excel
                    </a>
                    <!-- Botón Reporte PDF -->
                    <a href="asistencias_pdf.php?centro_codigo=<?php echo urlencode($filtro_centro); ?>&curso_id=<?php echo urlencode($filtro_curso); ?>&seccion_id=<?php echo urlencode($filtro_seccion); ?>&anio=<?php echo urlencode($filtro_anio); ?>" target="_blank" class="btn btn-danger mb-1">
                        <i class="bi bi-file-earmark-pdf-fill"></i> Reporte PDF
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="app-content">
        <div class="container-fluid px-4">

            <?php if (isset($_SESSION['mensaje_exito'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?php echo $_SESSION['mensaje_exito']; unset($_SESSION['mensaje_exito']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['mensaje_error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php echo $_SESSION['mensaje_error']; unset($_SESSION['mensaje_error']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Filtros de Búsqueda Dinámicos -->
            <div class="card shadow-sm mb-4">
                <div class="card-body">
                    <form method="GET" action="asistencias_listado.php" class="row g-3 align-items-end">
                        
                        <?php if (!$es_coordinador): ?>
                            <!-- Filtro por Centro (Solo Admin) -->
                            <div class="col-md-3">
                                <label class="form-label fw-bold">Centro Penitenciario:</label>
                                <select class="form-select" name="centro_codigo">
                                    <option value="">-- Todos los centros --</option>
                                    <?php while ($centro = pg_fetch_assoc($res_centros)): ?>
                                        <option value="<?php echo $centro['codigo']; ?>" <?php echo ($filtro_centro == $centro['codigo']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($centro['nombre']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                        <?php else: ?>
                            <!-- Filtro por Sección (Exclusivo para Coordinador Regional en su centro) -->
                            <div class="col-md-3">
                                <label class="form-label fw-bold">Sección de su Recinto:</label>
                                <select class="form-select" name="seccion_id">
                                    <option value="">-- Todas las secciones --</option>
                                    <?php while ($sec = pg_fetch_assoc($res_secciones)): ?>
                                        <option value="<?php echo $sec['id']; ?>" <?php echo ($filtro_seccion == $sec['id']) ? 'selected' : ''; ?>>
                                            [<?php echo htmlspecialchars($sec['codigo_nomenclatura']); ?>]
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                        <?php endif; ?>

                        <!-- Filtro por Curso (Disponible para ambos roles) -->
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Filtrar por Curso:</label>
                            <select class="form-select" name="curso_id">
                                <option value="">-- Todos los cursos --</option>
                                <?php while ($curso = pg_fetch_assoc($res_cursos)): ?>
                                    <option value="<?php echo $curso['id']; ?>" <?php echo ($filtro_curso == $curso['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($curso['nombre_curso']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <!-- Filtro por Año (Disponible para ambos roles) -->
                        <div class="col-md-2">
                            <label class="form-label fw-bold">Filtrar por Año:</label>
                            <select class="form-select" name="anio">
                                <option value="">-- Todos --</option>
                                <?php while ($a = pg_fetch_assoc($res_anios)): 
                                    $anio_val = $a['anio'];
                                    if(empty($anio_val)) continue;
                                ?>
                                    <option value="<?php echo $anio_val; ?>" <?php echo ($filtro_anio == $anio_val) ? 'selected' : ''; ?>>
                                        <?php echo $anio_val; ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="col-md-3">
                            <button type="submit" class="btn btn-primary w-100 mb-1">
                                <i class="bi bi-search"></i> Aplicar Filtro
                            </button>
                            <a href="asistencias_listado.php" class="btn btn-secondary w-100">
                                <i class="bi bi-arrow-counterclockwise"></i> Reiniciar
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Tabla de Asistencias -->
            <div class="card shadow-sm">
                <div class="card-body table-responsive">
                    <table class="table table-bordered table-striped align-middle">
                        <thead class="table-dark">
                            <tr>
                                <th>Fecha</th>
                                <th>Cédula / Documento</th>
                                <th>Apellidos y Nombres</th>
                                <th>Curso / Sección</th>
                                <th>Centro Penitenciario</th>
                                <th>Asistencia</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($res_asistencias && pg_num_rows($res_asistencias) > 0): ?>
                                <?php while ($row = pg_fetch_assoc($res_asistencias)): 
                                    $badge_color = 'bg-secondary';
                                    if (strcasecmp($row['asistencia'], 'PRESENTE') === 0) $badge_color = 'bg-success';
                                    elseif (strcasecmp($row['asistencia'], 'AUSENTE') === 0) $badge_color = 'bg-danger';
                                    elseif (strcasecmp($row['asistencia'], 'JUSTIFICADO') === 0) $badge_color = 'bg-warning text-dark';

                                    // Limpieza de cédula para evitar duplicados de V-V-
                                    $cedula_limpia = preg_replace('/^(V-|v-|V|v)/', '', trim($row['privado_documento']));
                                ?>
                                    <tr>
                                        <td><?php echo date('d/m/Y', strtotime($row['fecha'])); ?></td>
                                        <td>V-<?php echo htmlspecialchars($cedula_limpia); ?></td>
                                        <td><?php echo htmlspecialchars($row['apellido'] . ', ' . $row['nombre']); ?></td>
                                        <td>
                                            <span class="text-primary fw-bold"><?php echo htmlspecialchars($row['nombre_curso']); ?></span><br>
                                            <small class="text-muted">Sección: [<?php echo htmlspecialchars($row['codigo_nomenclatura']); ?>]</small>
                                        </td>
                                        <td><?php echo htmlspecialchars($row['nombre_centro'] ?? 'N/A'); ?></td>
                                        <td>
                                            <span class="badge <?php echo $badge_color; ?> fs-6">
                                                <?php echo htmlspecialchars($row['asistencia']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">No se encontraron registros de asistencia con los filtros seleccionados.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</main>

<?php 
if (isset($conexion)) { pg_close($conexion); }
require_once 'footer.php'; 
?>