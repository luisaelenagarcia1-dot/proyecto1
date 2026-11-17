<?php
session_start();
require_once 'header.php';
require_once 'conexion.php';

// =======================================================
// 1. Lógica de Restricción de Roles y Sesión
// =======================================================
$rol_usuario = $_SESSION['cargo'] ?? $_SESSION['rol'] ?? '';
$es_administrador = (strcasecmp($rol_usuario, 'Administradora') === 0 || strcasecmp($rol_usuario, 'Administrador') === 0);
$es_coordinador = (strcasecmp($rol_usuario, 'Coordinador Regional') === 0);

$cedula_cruda = $_SESSION['nombre_usuario'] ?? $_SESSION['cedula'] ?? '';
$cedula_sesion = preg_replace('/[^0-9]/', '', $cedula_cruda); // Limpieza flexible de la cédula

// --- FILTROS DE BÚSQUEDA ---
$busqueda = trim($_GET['busqueda'] ?? '');

// Consulta uniendo centros, entes_educativos, cursos y la tabla intermedia ente_cursos
$sql = "SELECT ec.id as asignacion_id, 
               c.id as curso_id, 
               c.codigo_curso, 
               c.nombre_curso, 
               ee.rif_nit, 
               ee.nombre_ente, 
               cen.codigo as centro_codigo,
               cen.nombre as nombre_centro,
               ec.horas_duracion, 
               ec.capacidad_maxima,
               ec.usuario_modificacion, 
               ec.fecha_modificacion 
        FROM ente_cursos ec 
        JOIN cursos c ON ec.curso_id = c.id 
        JOIN entes_educativos ee ON ec.rif_nit = ee.rif_nit 
        JOIN centros cen ON ec.centro_codigo = cen.codigo";

$params = [];
$where_conditions = [];
$param_index = 1;

// Si es Coordinador Regional, unimos con 'usuario_centros' y filtramos estrictamente por su recinto
if ($es_coordinador) {
    $sql .= " JOIN usuario_centros uc ON ec.centro_codigo = uc.centro_codigo";
    $where_conditions[] = "REPLACE(REPLACE(uc.usuario_cedula, 'V', ''), 'v', '') = $" . $param_index;
    $params[] = $cedula_sesion;
    $param_index++;
}

if (!empty($busqueda)) {
    $where_conditions[] = "(c.nombre_curso ILIKE $" . $param_index . " OR c.codigo_curso ILIKE $" . $param_index . " OR ee.nombre_ente ILIKE $" . $param_index . " OR cen.nombre ILIKE $" . $param_index . ")";
    $params[] = "%" . $busqueda . "%";
    $param_index++;
}

if (count($where_conditions) > 0) {
    $sql .= " WHERE " . implode(" AND ", $where_conditions);
}

$sql .= " ORDER BY cen.nombre ASC, c.nombre_curso ASC";

// Ejecución segura de la consulta en PostgreSQL
$resultado = ($params) ? pg_query_params($conexion, $sql, $params) : pg_query($conexion, $sql);
$total_filas = ($resultado) ? pg_num_rows($resultado) : 0;
?>

<main class="app-main" style="width: 100%; min-height: 100vh;">
    <div class="app-content-header">
        <div class="container-fluid px-4">
            <div class="row align-items-center mb-2">
                <div class="col-sm-6">
                    <h3 class="mb-0">
                        <i class="bi bi-diagram-3"></i> Gestión de Cursos por Centro y Ente
                    </h3>
                </div>
                <div class="col-sm-6 text-end">
                    <!-- Botón para Descargar PDF (disponible para todos) -->
                    <a href="cursos_entes_pdf.php?busqueda=<?php echo urlencode($busqueda); ?>" target="_blank" class="btn btn-danger me-2">
                        <i class="bi bi-file-earmark-pdf"></i> Descargar PDF
                    </a>
                    
                    <!-- Exclusivo para Administradores -->
                    <?php if (!$es_coordinador): ?>
                        <a href="cursos_entes_form.php" class="btn btn-primary">
                            <i class="bi bi-plus-circle"></i> Nueva Asignación
                        </a>
                    <?php endif; ?>
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

            <!-- BARRA DE BÚSQUEDA -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card shadow-sm">
                        <div class="card-body">
                            <form action="cursos_entes_gestion.php" method="GET" class="row g-3">
                                <div class="col-md-8">
                                    <input type="text" class="form-control" name="busqueda" value="<?php echo htmlspecialchars($busqueda); ?>" placeholder="Filtrar por centro, curso, código o ente educativo...">
                                </div>
                                <div class="col-md-4 d-flex">
                                    <button type="submit" class="btn btn-secondary me-2"><i class="bi bi-search"></i> Buscar</button>
                                    <a href="cursos_entes_gestion.php" class="btn btn-outline-secondary"><i class="bi bi-x-lg"></i> Todos</a>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TABLA GENERAL DE ASIGNACIONES -->
            <div class="row">
                <div class="col-12">
                    <div class="card card-primary card-outline shadow-sm">
                        <div class="card-header">
                            <h3 class="card-title">Listado de Cursos Asignados por Recinto Penitenciario</h3>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-striped table-hover align-middle mb-0">
                                    <thead class="table-dark">
                                        <tr>
                                            <th class="text-center" style="width: 50px;">N°</th>
                                            <th>Centro Penitenciario</th>
                                            <th>Curso / Ente Avala</th>
                                            <th class="text-center">Duración</th>
                                            <th class="text-center">Capacidad (Cupos)</th>
                                            <th>Modificación</th>
                                            <th class="text-center">Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($resultado && $total_filas > 0): ?>
                                            <?php 
                                            $i = 1;
                                            while ($row = pg_fetch_assoc($resultado)): 
                                                $modificacion = !empty($row['usuario_modificacion']) 
                                                    ? "Por: <strong>{$row['usuario_modificacion']}</strong><br><small class='text-muted'>{$row['fecha_modificacion']}</small>" 
                                                    : "<span class='text-muted'>Registro inicial</span>";
                                            ?>
                                                <tr>
                                                    <td class="text-center fw-bold"><?php echo $i++; ?></td>
                                                    <td><strong><?php echo htmlspecialchars($row['nombre_centro']); ?></strong></td>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($row['nombre_curso']); ?></strong> <code>(<?php echo htmlspecialchars($row['codigo_curso']); ?>)</code><br>
                                                        <small class="text-muted">Ente: <strong><?php echo htmlspecialchars($row['nombre_ente']); ?></strong></small>
                                                    </td>
                                                    <td class="text-center">
                                                        <span class="badge bg-success fs-6"><?php echo htmlspecialchars($row['horas_duracion']); ?> hrs</span>
                                                    </td>
                                                    <td class="text-center">
                                                        <span class="badge bg-info text-dark fs-6"><?php echo htmlspecialchars($row['capacidad_maxima']); ?> cupos</span>
                                                    </td>
                                                    <td><?php echo $modificacion; ?></td>
                                                    <td class="text-center">
                                                        <?php if (!$es_coordinador): ?>
                                                            <!-- Opciones restringidas exclusivamente para Administradores -->
                                                            <a href="cursos_entes_form.php?id=<?php echo $row['asignacion_id']; ?>" class="btn btn-sm btn-warning" title="Editar Asignación">
                                                                <i class="bi bi-pencil-square"></i>
                                                            </a>
                                                            <a href="curso_entes_procesar.php?accion=eliminar&id=<?php echo $row['asignacion_id']; ?>" 
                                                               class="btn btn-sm btn-danger" 
                                                               title="Eliminar Asignación"
                                                               onclick="return confirm('¿Está seguro de remover esta asignación del sistema?');">
                                                                <i class="bi bi-trash"></i>
                                                            </a>
                                                        <?php else: ?>
                                                            <span class="text-muted small">Solo lectura</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endwhile; ?>
                                            <?php pg_free_result($resultado); ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="7" class="text-center py-4 text-muted">No se encontraron asignaciones registradas en el sistema para este centro.</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</main>

<?php 
if (isset($conexion)) { pg_close($conexion); }
require_once 'footer.php'; 
?>