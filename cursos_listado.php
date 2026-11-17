<?php
session_start();
require_once 'header.php';
require_once 'conexion.php';

// --- FILTROS Y CONSULTA ---
$busqueda = trim($_GET['busqueda'] ?? '');
$nivel_filtro = trim($_GET['nivel'] ?? '');

$sql = "SELECT * FROM cursos WHERE 1=1";
$params = [];
$contador_param = 1;

// Filtro por texto (nombre o código)
if (!empty($busqueda)) {
    $sql .= " AND (nombre_curso ILIKE $" . $contador_param . " OR codigo_curso ILIKE $" . $contador_param . ")";
    $params[] = "%" . $busqueda . "%";
    $contador_param++;
}

// Filtro por Nivel (Básico, Medio, Avanzado)
if (!empty($nivel_filtro)) {
    $sql .= " AND nivel = $" . $contador_param;
    $params[] = $nivel_filtro;
    $contador_param++;
}

$sql .= " ORDER BY nombre_curso ASC";
$resultado = ($params) ? pg_query_params($conexion, $sql, $params) : pg_query($conexion, $sql);
$total_filas = ($resultado) ? pg_num_rows($resultado) : 0;
?>

<main class="app-main" style="width: 100%; min-height: 100vh;">
    <div class="app-content-header">
        <div class="container-fluid px-4">
            <div class="row align-items-center mb-2">
                <div class="col-sm-6">
                    <h3 class="mb-0"><i class="bi bi-journal-text"></i> Catálogo de Cursos</h3>
                </div>
                <div class="col-sm-6 text-end">
                    <a href="cursos_entes_gestion.php" class="btn btn-info text-white me-2">
                        <i class="bi bi-diagram-3"></i> Gestión de Cursos por Entes
                    </a>
                    <a href="cursos_pdf.php?busqueda=<?php echo urlencode($busqueda); ?>&nivel=<?php echo urlencode($nivel_filtro); ?>" target="_blank" class="btn btn-danger me-2">
                        <i class="bi bi-file-earmark-pdf"></i> Descargar PDF
                    </a>
                    <a href="cursos_form.php" class="btn btn-primary">
                        <i class="bi bi-plus-circle"></i> Registrar Curso
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

            <!-- BARRA DE BÚSQUEDA Y FILTROS -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card shadow-sm">
                        <div class="card-body">
                            <form action="cursos_listado.php" method="GET" class="row g-3">
                                <div class="col-md-5">
                                    <input type="text" class="form-control" name="busqueda" value="<?php echo htmlspecialchars($busqueda); ?>" placeholder="Filtrar por nombre del curso o código...">
                                </div>
                                <div class="col-md-3">
                                    <select name="nivel" class="form-select">
                                        <option value="">-- Todos los Niveles --</option>
                                        <option value="BASICO" <?php echo ($nivel_filtro === 'BASICO') ? 'selected' : ''; ?>>BÁSICO</option>
                                        <option value="MEDIO" <?php echo ($nivel_filtro === 'MEDIO') ? 'selected' : ''; ?>>MEDIO</option>
                                        <option value="AVANZADO" <?php echo ($nivel_filtro === 'AVANZADO') ? 'selected' : ''; ?>>AVANZADO</option>
                                    </select>
                                </div>
                                <div class="col-md-4 d-flex">
                                    <button type="submit" class="btn btn-secondary me-2"><i class="bi bi-search"></i> Filtrar</button>
                                    <a href="cursos_listado.php" class="btn btn-outline-secondary"><i class="bi bi-x-lg"></i> Todos</a>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TABLA DE CURSOS -->
            <div class="row">
                <div class="col-12">
                    <div class="card card-primary card-outline shadow-sm">
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-striped table-hover align-middle mb-0">
                                    <thead class="table-dark">
                                        <tr>
                                            <th class="text-center" style="width: 50px;">N°</th>
                                            <th>Código</th>
                                            <th>Nombre del Curso</th>
                                            <th class="text-center">Nivel</th>
                                            <th>Descripción / Contenido</th>
                                            <th>Última Modificación</th>
                                            <th class="text-center">Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($resultado && $total_filas > 0): ?>
                                            <?php 
                                            $i = 1;
                                            while ($row = pg_fetch_assoc($resultado)): 
                                                // Asignar color de etiqueta según el nivel
                                                $nivel_bd = $row['nivel'] ?? 'BASICO';
                                                $badge_color = 'bg-secondary';
                                                if ($nivel_bd === 'BASICO') $badge_color = 'bg-success';
                                                elseif ($nivel_bd === 'MEDIO') $badge_color = 'bg-primary';
                                                elseif ($nivel_bd === 'AVANZADO') $badge_color = 'bg-danger';

                                                $auditoria = !empty($row['usuario_modificacion']) 
                                                    ? "<br><small class='text-muted'>Por: <strong>{$row['usuario_modificacion']}</strong> ({$row['fecha_modificacion']})</small>" 
                                                    : "<br><small class='text-muted'>Registro inicial</small>";
                                            ?>
                                                <tr>
                                                    <td class="text-center fw-bold"><?php echo $i++; ?></td>
                                                    <td><code><?php echo htmlspecialchars($row['codigo_curso']); ?></code></td>
                                                    <td><strong><?php echo htmlspecialchars($row['nombre_curso']); ?></strong></td>
                                                    <td class="text-center">
                                                        <span class="badge <?php echo $badge_color; ?>"><?php echo htmlspecialchars($nivel_bd); ?></span>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($row['descripcion'] ?? 'Sin descripción'); ?></td>
                                                    <td><?php echo $auditoria; ?></td>
                                                    <td class="text-center">
                                                        <div class="btn-group" role="group">
                                                            <a href="cursos_form.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-warning" title="Editar Curso">
                                                                <i class="bi bi-pencil-square"></i>
                                                            </a>
                                                            <a href="cursos_entes_form.php?curso_id=<?php echo $row['id']; ?>" class="btn btn-sm btn-info text-white" title="Asignar a Ente Educativo">
                                                                <i class="bi bi-building-add"></i>
                                                            </a>
                                                            <a href="cursos_procesar.php?accion=eliminar&id=<?php echo $row['id']; ?>" class="btn btn-sm btn-danger" title="Eliminar Curso" onclick="return confirm('¿Está segura de eliminar este curso del catálogo?');">
                                                                <i class="bi bi-trash"></i>
                                                            </a>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endwhile; ?>
                                            <?php pg_free_result($resultado); ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="7" class="text-center py-4 text-muted">No se encontraron cursos registrados con los filtros seleccionados.</td>
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