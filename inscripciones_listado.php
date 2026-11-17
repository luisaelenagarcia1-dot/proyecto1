<?php
session_start();
require_once 'header.php';
require_once 'conexion.php';

// Validar sesión activa
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: login.php");
    exit();
}

// Obtener datos del usuario logueado desde la sesión y limpiar la cédula de forma flexible
$rol_usuario     = $_SESSION['cargo'] ?? $_SESSION['rol'] ?? 'Gerente'; 
$es_coordinador  = (strcasecmp($rol_usuario, 'Coordinador Regional') === 0);

$cedula_cruda    = $_SESSION['nombre_usuario'] ?? $_SESSION['cedula'] ?? '';
$cedula_sesion   = preg_replace('/[^0-9]/', '', $cedula_cruda); // Extrae solo números

// Capturar los valores de los filtros de la interfaz
$busqueda        = trim($_GET['busqueda'] ?? '');
$seccion_filtro  = trim($_GET['seccion_id'] ?? '');
$anio_filtro     = trim($_GET['anio'] ?? '');
$centro_filtro   = trim($_GET['centro_codigo'] ?? '');

// 1. Obtener lista de Centros (Solo visible para Gerente o Analista en el filtro)
$lista_centros = [];
if (!$es_coordinador) {
    $res_centros = pg_query($conexion, "SELECT codigo, nombre FROM centros ORDER BY nombre ASC");
    if ($res_centros) {
        while ($cen = pg_fetch_assoc($res_centros)) {
            $lista_centros[] = $cen;
        }
    }
}

// 2. Obtener la lista de secciones (Si es coordinador, limitadas estrictamente a su centro mediante usuario_centros)
$lista_secciones = [];
if ($es_coordinador) {
    $sql_sec_list = "SELECT s.id, s.codigo_nomenclatura, c.nombre_curso 
                     FROM secciones_cursos s 
                     JOIN cursos c ON s.curso_id = c.id 
                     JOIN usuario_centros uc ON s.centro_codigo = uc.centro_codigo
                     WHERE REPLACE(REPLACE(uc.usuario_cedula, 'V', ''), 'v', '') = $1 
                     ORDER BY s.id DESC";
    $res_secciones = pg_query_params($conexion, $sql_sec_list, array($cedula_sesion));
} else {
    $sql_sec_list = "SELECT s.id, s.codigo_nomenclatura, c.nombre_curso, s.centro_codigo 
                     FROM secciones_cursos s 
                     JOIN cursos c ON s.curso_id = c.id";
    $params_sec = [];
    if (!empty($centro_filtro)) {
        $sql_sec_list .= " WHERE s.centro_codigo = $1";
        $params_sec[] = $centro_filtro;
    }
    $sql_sec_list .= " ORDER BY s.id DESC";
    $res_secciones = ($params_sec) ? pg_query_params($conexion, $sql_sec_list, $params_sec) : pg_query($conexion, $sql_sec_list);
}

if ($res_secciones) {
    while ($sec = pg_fetch_assoc($res_secciones)) {
        $lista_secciones[] = $sec;
    }
}

// 3. Consulta base de inscritos uniendo con las tablas correspondientes
$sql = "SELECT i.*, p.nombre, p.apellido, p.documento_identidad, p.correo, s.codigo_nomenclatura, c.nombre_curso, s.centro_codigo
        FROM inscripciones_educativas i
        JOIN privados p ON i.privado_documento = p.documento_identidad
        JOIN secciones_cursos s ON i.seccion_id = s.id
        JOIN cursos c ON s.curso_id = c.id";

$params = [];
$where_conditions = [];
$param_index = 1;

// REGLA DE SEGURIDAD ESTRICTA: Coordinador Regional añade su JOIN al inicio de las uniones
if ($es_coordinador) {
    $sql .= " JOIN usuario_centros uc ON s.centro_codigo = uc.centro_codigo";
    $where_conditions[] = "REPLACE(REPLACE(uc.usuario_cedula, 'V', ''), 'v', '') = $" . $param_index;
    $params[] = $cedula_sesion;
    $param_index++;
} else {
    // Si es Gerente o Analista, puede usar el filtro opcional de centro a nivel global
    if (!empty($centro_filtro)) {
        $where_conditions[] = "s.centro_codigo = $" . $param_index;
        $params[] = $centro_filtro;
        $param_index++;
    }
}

// Aplicar filtro de búsqueda por texto libre (cédula, nombre o apellido)
if (!empty($busqueda)) {
    $where_conditions[] = "(p.documento_identidad ILIKE $" . $param_index . " OR p.nombre ILIKE $" . $param_index . " OR p.apellido ILIKE $" . $param_index . ")";
    $params[] = '%' . $busqueda . '%';
    $param_index++;
}

// Aplicar filtro por Sección específica
if (!empty($seccion_filtro)) {
    $where_conditions[] = "i.seccion_id = $" . $param_index;
    $params[] = $seccion_filtro;
    $param_index++;
}

// Aplicar filtro por Año (basado en la fecha de inscripción)
if (!empty($anio_filtro)) {
    $where_conditions[] = "EXTRACT(YEAR FROM i.fecha_inscripcion) = $" . $param_index;
    $params[] = $anio_filtro;
    $param_index++;
}

// Unir todas las condiciones WHERE de forma limpia
if (count($where_conditions) > 0) {
    $sql .= " WHERE " . implode(" AND ", $where_conditions);
}

$sql .= " ORDER BY i.id DESC";
$resultado = ($params) ? pg_query_params($conexion, $sql, $params) : pg_query($conexion, $sql);
$total_filas = ($resultado) ? pg_num_rows($resultado) : 0;
?>

<main class="app-main" style="width: 100%; min-height: 100vh;">
    <div class="app-content-header">
        <div class="container-fluid px-4">
            <div class="row align-items-center mb-2">
                <div class="col-sm-6">
                    <h3 class="mb-0">
                        <i class="bi bi-mortarboard"></i> Inscripciones Educativas 
                        <?php if ($es_coordinador): ?>
                            <span class="badge bg-info text-dark fs-6 ms-2">Recinto Asignado</span>
                        <?php endif; ?>
                    </h3>
                </div>
                <div class="col-sm-6 text-end">
                    <!-- BOTÓN DE DESCARGAR EXCEL (Mantiene los mismos filtros de la URL) -->
                    <a href="inscripciones_excel.php?busqueda=<?php echo urlencode($busqueda); ?>&seccion_id=<?php echo urlencode($seccion_filtro); ?>&anio=<?php echo urlencode($anio_filtro); ?>&centro_codigo=<?php echo urlencode($centro_filtro); ?>" class="btn btn-success me-2">
                        <i class="bi bi-file-earmark-excel"></i> Descargar Excel
                    </a>

                    <!-- Botón de Imprimir PDF -->
                    <button onclick="window.print()" class="btn btn-danger me-2">
                        <i class="bi bi-printer"></i> Imprimir PDF
                    </button>
                    
                    <!-- Botón directo para redirigir al formulario de inscripción -->
                    <a href="inscripcion_form.php" class="btn btn-primary">
                        <i class="bi bi-person-plus"></i> Inscribir Privado
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

            <!-- Panel de Filtros Dinámicos -->
            <div class="row mb-4 no-print">
                <div class="col-12">
                    <div class="card shadow-sm">
                        <div class="card-body">
                            <form action="inscripciones_listado.php" method="GET" class="row g-3">
                                
                                <div class="col-md-<?php echo (!$es_coordinador) ? '3' : '4'; ?>">
                                    <label class="form-label fw-bold small">Cédula o Nombre:</label>
                                    <input type="text" class="form-control" name="busqueda" value="<?php echo htmlspecialchars($busqueda); ?>" placeholder="Ej: V26530793...">
                                </div>

                                <?php if (!$es_coordinador): ?>
                                    <div class="col-md-3">
                                        <label class="form-label fw-bold small">Filtrar por Centro:</label>
                                        <select class="form-select" name="centro_codigo" onchange="this.form.submit()">
                                            <option value="">-- Todos los centros --</option>
                                            <?php foreach ($lista_centros as $cen): ?>
                                                <option value="<?php echo $cen['codigo']; ?>" <?php echo ($centro_filtro == $cen['codigo']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($cen['codigo'] . ' - ' . $cen['nombre']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                <?php endif; ?>

                                <div class="col-md-<?php echo (!$es_coordinador) ? '3' : '4'; ?>">
                                    <label class="form-label fw-bold small">Filtrar por Sección:</label>
                                    <select class="form-select" name="seccion_id">
                                        <option value="">-- Todas las secciones --</option>
                                        <?php foreach ($lista_secciones as $sec): ?>
                                            <option value="<?php echo $sec['id']; ?>" <?php echo ($seccion_filtro == $sec['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($sec['codigo_nomenclatura'] . ' - ' . $sec['nombre_curso']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-2">
                                    <label class="form-label fw-bold small">Filtrar por Año:</label>
                                    <select class="form-select" name="anio">
                                        <option value="">-- Todos --</option>
                                        <?php 
                                        $anio_actual = date('Y');
                                        for ($y = $anio_actual; $y >= 2024; $y--): 
                                        ?>
                                            <option value="<?php echo $y; ?>" <?php echo ($anio_filtro == $y) ? 'selected' : ''; ?>><?php echo $y; ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>

                                <div class="col-md-2 d-flex align-items-end">
                                    <button type="submit" class="btn btn-secondary w-100 me-1" title="Aplicar filtros"><i class="bi bi-filter"></i></button>
                                    <a href="inscripciones_listado.php" class="btn btn-outline-secondary" title="Limpiar filtros"><i class="bi bi-x-lg"></i></a>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tabla de Inscritos -->
            <div class="row">
                <div class="col-12">
                    <div class="card card-primary card-outline shadow-sm">
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-striped table-hover align-middle mb-0">
                                    <thead class="table-dark">
                                        <tr>
                                            <th class="text-center" style="width: 50px;">N°</th>
                                            <th>Cédula / Documento</th>
                                            <th>Apellidos y Nombres</th>
                                            <th>Correo Electrónico</th>
                                            <th>Sección / Nomenclatura</th>
                                            <th>Curso</th>
                                            <th class="text-center">Centro</th>
                                            <th class="text-center">Estado</th>
                                            <th class="text-center no-print">Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($resultado && $total_filas > 0): ?>
                                            <?php 
                                            $i = 1;
                                            while ($row = pg_fetch_assoc($resultado)): 
                                            ?>
                                                <tr>
                                                    <td class="text-center fw-bold"><?php echo $i++; ?></td>
                                                    <td><strong><?php echo htmlspecialchars($row['privado_documento']); ?></strong></td>
                                                    <td><?php echo htmlspecialchars($row['apellido'] . ', ' . $row['nombre']); ?></td>
                                                    <td><?php echo htmlspecialchars($row['correo'] ?? 'No registrado'); ?></td>
                                                    <td><code><?php echo htmlspecialchars($row['codigo_nomenclatura']); ?></code></td>
                                                    <td><?php echo htmlspecialchars($row['nombre_curso']); ?></td>
                                                    <td class="text-center"><?php echo htmlspecialchars($row['centro_codigo']); ?></td>
                                                    <td class="text-center">
                                                        <span class="badge bg-<?php echo ($row['estado_cursante'] == 'Cursando') ? 'primary' : (($row['estado_cursante'] == 'Aprobado') ? 'success' : 'secondary'); ?>">
                                                            <?php echo $row['estado_cursante']; ?>
                                                        </span>
                                                    </td>
                                                    <td class="text-center no-print">
                                                        <div class="btn-group" role="group">
                                                            <a href="inscripcion_editar.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-warning" title="Editar Estado o Sección">
                                                                <i class="bi bi-pencil-square"></i>
                                                            </a>
                                                            <a href="constancia_pdf.php?id=<?php echo $row['id']; ?>" target="_blank" class="btn btn-sm btn-outline-danger" title="Ver Constancia PDF">
                                                                <i class="bi bi-file-earmark-pdf-fill"></i>
                                                            </a>
                                                            <a href="constancia_enviar_correo.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-primary" title="Reenviar Constancia por Correo">
                                                                <i class="bi bi-envelope-fill"></i>
                                                            </a>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endwhile; ?>
                                            <?php pg_free_result($resultado); ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="9" class="text-center py-4 text-muted">No se encontraron registros bajo los filtros seleccionados para este centro.</td>
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

<style>
@media print {
    .no-print, .app-header, .app-sidebar, .app-footer {
        display: none !important;
    }
    main.app-main {
        margin: 0 !important;
        padding: 0 !important;
    }
}
</style>

<?php 
if (isset($conexion)) { pg_close($conexion); }
require_once 'footer.php'; 
?>