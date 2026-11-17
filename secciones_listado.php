<?php
session_start();
require_once 'header.php';
require_once 'conexion.php';

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: login.php");
    exit();
}

// =======================================================
// Lógica de Roles y Sesión del Coordinador Regional
// =======================================================
$rol_usuario = $_SESSION['cargo'] ?? $_SESSION['rol'] ?? '';
$es_administrador = (strcasecmp($rol_usuario, 'Administradora') === 0 || strcasecmp($rol_usuario, 'Administrador') === 0);
$es_coordinador = (strcasecmp($rol_usuario, 'Coordinador Regional') === 0);

$cedula_cruda = $_SESSION['nombre_usuario'] ?? $_SESSION['cedula'] ?? '';
$cedula_sesion = preg_replace('/[^0-9]/', '', $cedula_cruda); // Limpieza flexible de la cédula

// --- FILTROS DE BÚSQUEDA ---
$filtro_centro  = trim($_GET['centro_codigo'] ?? '');
$filtro_curso   = trim($_GET['curso_id'] ?? '');
$filtro_anio    = trim($_GET['anio'] ?? '');

// Construir consulta base con filtros opcionales dinámicos
$sql = "SELECT s.*, 
               c.nombre AS nombre_centro, c.sigla AS sigla_centro,
               cu.nombre_curso, cu.sigla AS sigla_curso,
               e.nombre_ente, e.sigla AS sigla_ente
        FROM secciones_cursos s
        LEFT JOIN centros c ON s.centro_codigo = c.codigo
        LEFT JOIN cursos cu ON s.curso_id = cu.id
        LEFT JOIN entes_educativos e ON s.rif_nit = e.rif_nit";

$params = [];
$where_conditions = [];
$param_index = 1;

// Si es Coordinador Regional, unimos obligatoriamente con 'usuario_centros' y filtramos su recinto
if ($es_coordinador) {
    $sql .= " JOIN usuario_centros uc ON s.centro_codigo = uc.centro_codigo";
    $where_conditions[] = "REPLACE(REPLACE(uc.usuario_cedula, 'V', ''), 'v', '') = $" . $param_index;
    $params[] = $cedula_sesion;
    $param_index++;
}

if (!empty($filtro_centro)) {
    $where_conditions[] = "s.centro_codigo = $" . $param_index;
    $params[] = $filtro_centro;
    $param_index++;
}

if (!empty($filtro_curso)) {
    $where_conditions[] = "s.curso_id = $" . $param_index;
    $params[] = intval($filtro_curso);
    $param_index++;
}

if (!empty($filtro_anio)) {
    $where_conditions[] = "s.anio = $" . $param_index;
    $params[] = intval($filtro_anio);
    $param_index++;
}

if (count($where_conditions) > 0) {
    $sql .= " WHERE " . implode(" AND ", $where_conditions);
}

$sql .= " ORDER BY s.anio DESC, s.centro_codigo ASC, CAST(s.numero_seccion AS INTEGER) ASC";

// Ejecutar consulta segura con parámetros
$resultado = (!empty($params)) ? pg_query_params($conexion, $sql, $params) : pg_query($conexion, $sql);

// Cargar listas para los selectores de los filtros (Si es coordinador, se limita solo a su centro)
if (!$es_coordinador) {
    $centros_filtro = pg_query($conexion, "SELECT codigo, nombre FROM centros ORDER BY nombre ASC");
} else {
    $sql_c_coord = "SELECT c.codigo, c.nombre FROM centros c JOIN usuario_centros uc ON c.codigo = uc.centro_codigo WHERE REPLACE(REPLACE(uc.usuario_cedula, 'V', ''), 'v', '') = $1 ORDER BY c.nombre ASC";
    $centros_filtro = pg_query_params($conexion, $sql_c_coord, [$cedula_sesion]);
}
$cursos_filtro  = pg_query($conexion, "SELECT id, nombre_curso FROM cursos ORDER BY nombre_curso ASC");
?>

<main class="app-main" style="width: 100%; min-height: 100vh;">
    <div class="app-content-header">
        <div class="container-fluid px-4">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h3 class="mb-0"><i class="bi bi-journal-text"></i> Listado General de Secciones Académicas</h3>
                </div>
                <div class="col-sm-6 text-end">
                    <a href="secciones_pdf.php" target="_blank" class="btn btn-danger me-2">
                        <i class="bi bi-file-earmark-pdf-fill"></i> Descargar Reporte PDF
                    </a>
                    
                    <?php if (!$es_coordinador): ?>
                        <a href="secciones_form.php" class="btn btn-primary">
                            <i class="bi bi-plus-circle"></i> Aperturar Nueva Sección
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="app-content">
        <div class="container-fluid px-4">

            <!-- Mensajes de Éxito o Error -->
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

            <!-- BARRA DE FILTROS AVANZADA -->
            <div class="card card-secondary card-outline shadow-sm mb-4">
                <div class="card-body">
                    <form method="GET" action="secciones_listado.php" class="row g-3 align-items-end">
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Centro Penitenciario:</label>
                            <select name="centro_codigo" class="form-select">
                                <?php if (!$es_coordinador): ?>
                                    <option value="">Todos los centros...</option>
                                <?php endif; ?>
                                <?php while ($cf = pg_fetch_assoc($centros_filtro)): ?>
                                    <option value="<?php echo $cf['codigo']; ?>" <?php echo ($filtro_centro == $cf['codigo']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cf['nombre']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold">Curso Asignado:</label>
                            <select name="curso_id" class="form-select">
                                <option value="">Todos los cursos...</option>
                                <?php while ($cuf = pg_fetch_assoc($cursos_filtro)): ?>
                                    <option value="<?php echo $cuf['id']; ?>" <?php echo ($filtro_curso == $cuf['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cuf['nombre_curso']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="col-md-2">
                            <label class="form-label fw-bold">Año:</label>
                            <input type="number" name="anio" class="form-control" placeholder="Ej. 2026" value="<?php echo htmlspecialchars($filtro_anio); ?>">
                        </div>

                        <div class="col-md-2 text-end">
                            <button type="submit" class="btn btn-primary w-100 mb-1">
                                <i class="bi bi-search"></i> Filtrar
                            </button>
                            <a href="secciones_listado.php" class="btn btn-outline-secondary w-100">
                                <i class="bi bi-arrow-counterclockwise"></i> Limpiar
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- TABLA DE RESULTADOS -->
            <div class="card card-primary card-outline shadow-sm">
                <div class="card-header">
                    <h3 class="card-title">Secciones Registradas en el Sistema</h3>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped align-middle">
                            <thead class="table-dark text-center">
                                <tr>
                                    <th>Nomenclatura Oficial</th>
                                    <th>Centro Penitenciario</th>
                                    <th>Curso y Ente</th>
                                    <th>Año / Sección</th>
                                    <th>Horario y Fechas</th>
                                    <th>Cupos</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($resultado && pg_num_rows($resultado) > 0): ?>
                                    <?php while ($row = pg_fetch_assoc($resultado)): ?>
                                        <tr>
                                            <td class="text-center font-monospace fw-bold text-primary">
                                                <?php echo htmlspecialchars($row['codigo_nomenclatura']); ?>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($row['nombre_centro'] ?? 'N/D'); ?></strong><br>
                                                <small class="text-muted">Sigla: <?php echo htmlspecialchars($row['sigla_centro'] ?? 'S/N'); ?></small>
                                            </td>
                                            <td>
                                                <span class="fw-bold text-dark"><?php echo htmlspecialchars($row['nombre_curso'] ?? 'N/D'); ?></span><br>
                                                <small class="text-secondary"><i class="bi bi-building"></i> <?php echo htmlspecialchars($row['nombre_ente'] ?? 'N/D'); ?></small>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge bg-secondary"><?php echo htmlspecialchars($row['anio']); ?></span><br>
                                                <span class="badge bg-info text-dark">Sección <?php echo htmlspecialchars($row['numero_seccion']); ?></span>
                                            </td>
                                            <td>
                                                <small class="fw-semibold text-dark"><i class="bi bi-calendar-week"></i> <?php echo htmlspecialchars($row['dias_semana'] ?? 'N/D'); ?></small><br>
                                                <small class="text-muted"><i class="bi bi-clock"></i> <?php echo htmlspecialchars($row['hora_clase'] ?? 'N/D'); ?></small><br>
                                                <small class="text-success"><i class="bi bi-play-circle"></i> Inicio: <?php echo htmlspecialchars($row['fecha_inicio'] ?? 'N/D'); ?></small><br>
                                                <small class="text-danger"><i class="bi bi-stop-circle"></i> Fin Aprox: <?php echo htmlspecialchars($row['fecha_fin_aprox'] ?? 'N/D'); ?></small>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge bg-success"><?php echo htmlspecialchars($row['capacidad_maxima']); ?> cupos</span>
                                            </td>
                                            <td class="text-center">
                                                <?php if (!$es_coordinador): ?>
                                                    <div class="btn-group" role="group">
                                                        <a href="secciones_form.php?id=<?php echo $row['id']; ?>" class="btn btn-warning btn-sm" title="Modificar Sección">
                                                            <i class="bi bi-pencil-square"></i>
                                                        </a>
                                                        <a href="secciones_procesar.php?accion=eliminar&id=<?php echo $row['id']; ?>" class="btn btn-danger btn-sm" title="Eliminar Sección" onclick="return confirm('¿Está segura de eliminar esta sección académica?');">
                                                            <i class="bi bi-trash"></i>
                                                        </a>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-muted small">Solo lectura</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-muted py-4">
                                            <i class="bi bi-folder2-open display-6 d-block mb-2"></i>
                                            No se encontraron secciones académicas con los filtros seleccionados para este centro.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>
</main>

<?php 
if (isset($resultado)) { pg_free_result($resultado); }
if (isset($centros_filtro)) { pg_free_result($centros_filtro); }
if (isset($cursos_filtro)) { pg_free_result($cursos_filtro); }
if (isset($conexion)) { pg_close($conexion); }
require_once 'footer.php'; 
?>