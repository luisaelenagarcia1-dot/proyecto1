<?php
session_start();
require_once 'header.php';
require_once 'conexion.php';

// 1. Validar que se haya pasado el ID del curso
$curso_id = $_GET['id'] ?? '';
if (empty($curso_id)) {
    $_SESSION['mensaje_error'] = "❌ Error: Curso no especificado.";
    header("Location: cursos_listado.php");
    exit();
}

// 2. Obtener la información general del curso
$res_curso = pg_query_params($conexion, "SELECT * FROM cursos WHERE id = $1", array($curso_id));
if (!$res_curso || pg_num_rows($res_curso) === 0) {
    $_SESSION['mensaje_error'] = "❌ El curso seleccionado no existe.";
    header("Location: cursos_listado.php");
    exit();
}
$curso = pg_fetch_assoc($res_curso);

// 3. Obtener la lista de todos los entes educativos disponibles para el select
$res_entes = pg_query($conexion, "SELECT rif_nit, nombre_ente FROM entes_educativos ORDER BY nombre_ente ASC");

// 4. Obtener los entes que ya tienen asignado este curso con sus respectivas horas
$sql_asignados = "SELECT ec.id as asignacion_id, ee.rif_nit, ee.nombre_ente, ec.horas_duracion, ec.usuario_modificacion, ec.fecha_modificacion 
                  FROM ente_cursos ec 
                  JOIN entes_educativos ee ON ec.rif_nit = ee.rif_nit 
                  WHERE ec.curso_id = $1 
                  ORDER BY ee.nombre_ente ASC";
$res_asignados = pg_query_params($conexion, $sql_asignados, array($curso_id));
?>

<main class="app-main" style="width: 100%; min-height: 100vh;">
    <div class="app-content-header">
        <div class="container-fluid px-4">
            <div class="row align-items-center mb-2">
                <div class="col-sm-8">
                    <h3 class="mb-0">
                        <i class="bi bi-building-add"></i> Asignación de Entes a Curso
                    </h3>
                    <p class="text-muted mt-1 mb-0">
                        Curso: <strong><?php echo htmlspecialchars($curso['nombre_curso']); ?></strong> 
                        <code>(<?php echo htmlspecialchars($curso['codigo_curso']); ?>)</code>
                    </p>
                </div>
                <div class="col-sm-4 text-end">
                    <a href="cursos_listado.php" class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i> Volver al Catálogo
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

            <div class="row">
                <!-- FORMULARIO PARA ASIGNAR NUEVO ENTE -->
                <div class="col-lg-4 mb-4">
                    <div class="card card-primary card-outline shadow-sm h-100">
                        <div class="card-header">
                            <h3 class="card-title">Asignar Ente Educativo</h3>
                        </div>
                        <form action="curso_entes_procesar.php" method="POST">
                            <input type="hidden" name="accion" value="asignar">
                            <input type="hidden" name="curso_id" value="<?php echo htmlspecialchars($curso_id); ?>">

                            <div class="card-body">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Seleccionar Ente <span class="text-danger">*</span>:</label>
                                    <select name="rif_nit" class="form-select" required>
                                        <option value="">-- Seleccione un Ente --</option>
                                        <?php while ($ente = pg_fetch_assoc($res_entes)): ?>
                                            <option value="<?php echo htmlspecialchars($ente['rif_nit']); ?>">
                                                <?php echo htmlspecialchars($ente['nombre_ente'] . " (" . $ente['rif_nit'] . ")"); ?>
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-bold">Duración Específica (Horas) <span class="text-danger">*</span>:</label>
                                    <input type="number" class="form-control" name="horas_duracion" required min="1" max="1000" placeholder="Ej: 80">
                                    <small class="text-muted">Horas académicas que este ente dedicará al curso.</small>
                                </div>
                            </div>

                            <div class="card-footer text-end">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-plus-circle"></i> Asignar Curso a Ente
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- TABLA DE ENTES QUE YA IMPARTEN EL CURSO -->
                <div class="col-lg-8 mb-4">
                    <div class="card card-secondary card-outline shadow-sm h-100">
                        <div class="card-header">
                            <h3 class="card-title">Entes Autorizados para Impartir este Curso</h3>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-striped table-hover align-middle mb-0">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>RIF / NIT</th>
                                            <th>Nombre del Ente Educativo</th>
                                            <th class="text-center">Duración</th>
                                            <th>Modificación</th>
                                            <th class="text-center">Acción</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($res_asignados && pg_num_rows($res_asignados) > 0): ?>
                                            <?php while ($asig = pg_fetch_assoc($res_asignados)): ?>
                                                <tr>
                                                    <td><code><?php echo htmlspecialchars($asig['rif_nit']); ?></code></td>
                                                    <td><strong><?php echo htmlspecialchars($asig['nombre_ente']); ?></strong></td>
                                                    <td class="text-center"><span class="badge bg-success fs-6"><?php echo htmlspecialchars($asig['horas_duracion']); ?> hrs</span></td>
                                                    <td>
                                                        <small class='text-muted'>
                                                            Por: <strong><?php echo htmlspecialchars($asig['usuario_modificacion'] ?? 'Sistema'); ?></strong><br>
                                                            <?php echo htmlspecialchars($asig['fecha_modificacion']); ?>
                                                        </small>
                                                    </td>
                                                    <td class="text-center">
                                                        <a href="curso_entes_procesar.php?accion=eliminar&id=<?php echo $asig['asignacion_id']; ?>&curso_id=<?php echo $curso_id; ?>" 
                                                           class="btn btn-sm btn-danger" 
                                                           title="Remover Asignación"
                                                           onclick="return confirm('¿Está seguro de remover este ente del curso?');">
                                                            <i class="bi bi-trash"></i>
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endwhile; ?>
                                            <?php pg_free_result($res_asignados); ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="5" class="text-center py-4 text-muted">Este curso aún no ha sido asignado a ningún ente educativo.</td>
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