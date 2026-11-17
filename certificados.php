<?php
// =========================================================================
// MÓDULO DE LISTADO DE CONSULTA Y EMISIÓN DE CERTIFICADOS (SGR - IACTP)
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

// 1. Determinar el centro estricto si es coordinador
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

// Variables de filtro recibidas por GET al hacer clic en Consultar
$centro_seleccionado  = $es_coordinador ? $centro_usuario : ($_GET['centro_codigo'] ?? '');
$curso_seleccionado   = $_GET['curso_id'] ?? '';
$seccion_seleccionada = $_GET['seccion_id'] ?? null;
$busqueda_texto       = trim($_GET['busqueda_texto'] ?? '');

// 2. Obtener lista completa de centros (con nombre completo) para el Administrador
$centros_lista = [];
if ($es_administrador) {
    $res_centros = pg_query($conexion, "SELECT c.codigo, c.nombre FROM centros c ORDER BY c.nombre ASC");
    if ($res_centros) {
        while ($row_cen = pg_fetch_assoc($res_centros)) {
            $centros_lista[] = $row_cen;
        }
    }
}

// 3. Obtener lista de cursos para el filtro
$cursos_lista = [];
$res_cursos = pg_query($conexion, "SELECT id, nombre_curso FROM cursos ORDER BY nombre_curso ASC");
if ($res_cursos) {
    while ($row_cur = pg_fetch_assoc($res_cursos)) {
        $cursos_lista[] = $row_cur;
    }
}

// 4. CONSULTA DINÁMICA DE SECCIONES (Soporta 'Todos los centros' si está vacío)
$query_secciones = "
    SELECT sc.id, sc.codigo_nomenclatura, c.nombre_curso, sc.centro_codigo, ce.nombre AS nombre_centro
    FROM secciones_cursos sc
    JOIN cursos c ON sc.curso_id = c.id
    LEFT JOIN centros ce ON sc.centro_codigo = ce.codigo
    WHERE 1=1
";
$params_sec = array();
$param_idx = 1;

if ($es_coordinador) {
    $query_secciones .= " AND sc.centro_codigo = $" . $param_idx;
    $params_sec[] = $centro_usuario;
    $param_idx++;
} elseif ($es_administrador && !empty($centro_seleccionado)) {
    $query_secciones .= " AND sc.centro_codigo = $" . $param_idx;
    $params_sec[] = $centro_seleccionado;
    $param_idx++;
}

if (!empty($curso_seleccionado)) {
    $query_secciones .= " AND sc.curso_id = $" . $param_idx;
    $params_sec[] = $curso_seleccionado;
    $param_idx++;
}

$query_secciones .= " ORDER BY sc.codigo_nomenclatura ASC";
$secciones_result = !empty($params_sec) ? pg_query_params($conexion, $query_secciones, $params_sec) : pg_query($conexion, $query_secciones);

$estudiantes_aprobadas = [];
$detalles_seccion = null;

// Si se seleccionó una sección y se consultó, cargamos la vista inferior
if ($seccion_seleccionada) {
    $query_det = "
        SELECT 
            c.nombre_curso, 
            c.duracion_horas, 
            COALESCE(ee.nombre_ente, 'IACTP') AS ente_emisor, 
            sc.codigo_nomenclatura, 
            sc.centro_codigo,
            ce.nombre AS nombre_centro
        FROM secciones_cursos sc
        JOIN cursos c ON sc.curso_id = c.id
        LEFT JOIN centros ce ON sc.centro_codigo = ce.codigo
        LEFT JOIN ente_cursos ec ON c.id = ec.curso_id
        LEFT JOIN entes_educativos ee ON ec.rif_nit = ee.rif_nit
        WHERE sc.id = $1
    ";
    $res_det = pg_query_params($conexion, $query_det, array($seccion_seleccionada));
    if ($res_det) {
        $detalles_seccion = pg_fetch_assoc($res_det);
    }

    if ($es_coordinador && $detalles_seccion && $detalles_seccion['centro_codigo'] !== $centro_usuario) {
        header("Location: certificados.php");
        exit;
    }

    $query_aprobadas = "
        SELECT 
            ie.id AS inscripcion_id,
            p.documento_identidad,
            p.nombre,
            p.apellido,
            ie.estado_aprobacion,
            ie.estatus_sobresaliente
        FROM inscripciones_educativas ie
        JOIN privados p ON ie.privado_documento = p.documento_identidad
        WHERE ie.seccion_id = $1 AND ie.estado_aprobacion = 'APROBADO'
    ";
    
    $params_aprob = array($seccion_seleccionada);
    
    if (!empty($busqueda_texto)) {
        $query_aprobadas .= " AND (p.documento_identidad ILIKE $2 OR p.nombre ILIKE $2 OR p.apellido ILIKE $2)";
        $params_aprob[] = "%" . $busqueda_texto . "%";
    }

    $query_aprobadas .= " ORDER BY p.apellido ASC";
    
    $res_aprob = pg_query_params($conexion, $query_aprobadas, $params_aprob);
    if ($res_aprob) {
        while ($row = pg_fetch_assoc($res_aprob)) {
            $estudiantes_aprobadas[] = $row;
        }
    }
}
?>

<?php include('header.php'); ?>

<main class="app-main">
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row">
                <div class="col-sm-6">
                    <h3 class="mb-0"><i class="bi bi-award me-2"></i> Consulta y Emisión de Certificados</h3>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-end">
                        <li class="breadcrumb-item"><a href="index.php">Inicio</a></li>
                        <li class="breadcrumb-item active">Certificados</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <div class="app-content">
        <div class="container-fluid">
            
            <!-- TARJETA 1: FILTROS DE CONSULTA (FIJOS HASTA PRESIONAR CONSULTAR) -->
            <div class="card card-primary card-outline mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h3 class="card-title"><i class="bi bi-filter-square me-2"></i> Filtros de Búsqueda Académica</h3>
                    <span class="badge bg-secondary">
                        <?php echo $es_administrador ? 'Vista Administrador (General)' : 'Vista Coordinador (Centro: ' . $centro_usuario . ')'; ?>
                    </span>
                </div>
                <div class="card-body">
                    <form method="GET" action="certificados.php" class="row g-3">
                        
                        <?php if ($es_administrador): ?>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Centro Penitenciario:</label>
                            <select name="centro_codigo" class="form-select">
                                <option value="">-- Todos los Centros --</option>
                                <?php foreach ($centros_lista as $cen): ?>
                                    <option value="<?php echo $cen['codigo']; ?>" <?php echo ($centro_seleccionado === $cen['codigo']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cen['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>

                        <div class="col-md-<?php echo $es_administrador ? '3' : '5'; ?>">
                            <label class="form-label fw-bold">Filtrar por Curso (Opcional):</label>
                            <select name="curso_id" class="form-select">
                                <option value="">-- Todos los Cursos --</option>
                                <?php foreach ($cursos_lista as $cur): ?>
                                    <option value="<?php echo $cur['id']; ?>" <?php echo ($curso_seleccionado == $cur['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cur['nombre_curso']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-<?php echo $es_administrador ? '4' : '5'; ?>">
                            <label class="form-label fw-bold">Seleccionar Sección Activa:</label>
                            <select name="seccion_id" class="form-select" required>
                                <option value="">-- Seleccione una sección --</option>
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

                        <!-- Botón principal de consulta para fijar la vista -->
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100 shadow-sm">
                                <i class="bi bi-search me-1"></i> Consultar
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- TARJETA 2: VISTA DE CONSULTA INFERIOR (LISTADO DE APROBADAS Y ACCIONES) -->
            <?php if ($seccion_seleccionada): ?>
            <div class="card card-success card-outline mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h3 class="card-title">
                        <i class="bi bi-journal-text me-2"></i> Vista de Consulta - Cursantes Elegibles 
                        <?php if ($detalles_seccion): ?>
                            - <span class="text-dark fw-bold"><?php echo htmlspecialchars($detalles_seccion['codigo_nomenclatura'] . ' (' . $detalles_seccion['nombre_curso'] . ')'); ?></span>
                        <?php endif; ?>
                    </h3>
                    
                    <?php if (!empty($estudiantes_aprobadas)): ?>
                        <div class="d-flex gap-2">
                            <!-- Botón para Imprimir Lote Completo -->
                            <a href="certificado_imprimir.php?seccion_id=<?php echo $seccion_seleccionada; ?>" target="_blank" class="btn btn-success btn-sm">
                                <i class="bi bi-printer-fill me-1"></i> Imprimir Lote Completo
                            </a>
                            <!-- Botón para Descargar en Excel -->
                            <a href="certificados_excel.php?seccion_id=<?php echo $seccion_seleccionada; ?>" class="btn btn-dark btn-sm">
                                <i class="bi bi-file-earmark-excel-fill me-1"></i> Descargar Excel
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    
                    <!-- BARRA DE BÚSQUEDA RÁPIDA POR CÉDULA, NOMBRE O APELLIDO -->
                    <form method="GET" action="certificados.php" class="row g-3 mb-4">
                        <input type="hidden" name="seccion_id" value="<?php echo $seccion_seleccionada; ?>">
                        <?php if (!empty($centro_seleccionado)): ?>
                            <input type="hidden" name="centro_codigo" value="<?php echo $centro_seleccionado; ?>">
                        <?php endif; ?>
                        <?php if (!empty($curso_seleccionado)): ?>
                            <input type="hidden" name="curso_id" value="<?php echo $curso_seleccionado; ?>">
                        <?php endif; ?>

                        <div class="col-md-9">
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
                                <input type="text" name="busqueda_texto" value="<?php echo htmlspecialchars($busqueda_texto); ?>" class="form-control" placeholder="Buscar por número de cédula, nombre o apellido...">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-filter me-1"></i> Filtrar por Nombre / Cédula
                            </button>
                        </div>
                    </form>

                    <?php if (empty($estudiantes_aprobadas)): ?>
                        <div class="alert alert-warning text-center">
                            No se encontraron cursantes aprobadas que coincidan con los criterios de búsqueda.
                        </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped align-middle">
                            <thead class="table-dark text-center">
                                <tr>
                                    <th>Cédula</th>
                                    <th>Apellidos y Nombres</th>
                                    <th>Estatus Académico</th>
                                    <th>Acción Individual</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($estudiantes_aprobadas as $est): ?>
                                <tr>
                                    <td class="fw-bold text-center"><?php echo htmlspecialchars($est['documento_identidad']); ?></td>
                                    <td><?php echo htmlspecialchars($est['apellido'] . ', ' . $est['nombre']); ?></td>
                                    
                                    <td class="text-center">
                                        <span class="badge bg-success">APROBADO</span>
                                        <?php if ($est['estatus_sobresaliente'] == 't' || $est['estatus_sobresaliente'] == '1'): ?>
                                            <br><span class="badge bg-warning text-dark mt-1"><i class="bi bi-star-fill"></i> Sobresaliente</span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="text-center">
                                        <a href="certificado_imprimir.php?inscripcion_id=<?php echo $est['inscripcion_id']; ?>" target="_blank" class="btn btn-primary btn-sm">
                                            <i class="bi bi-printer me-1"></i> Imprimir Certificado
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>
</main>

</body>
</html>