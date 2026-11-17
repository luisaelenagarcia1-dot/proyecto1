<?php
session_start();
require_once 'header.php';
require_once 'conexion.php';

// Capturar posibles parámetros enviados por GET (si se viene de editar o de asignar directo)
$asignacion_id = $_GET['id'] ?? '';
$ente_id_get   = $_GET['ente_id'] ?? $_GET['rif'] ?? '';
$curso_id_get  = $_GET['curso_id'] ?? '';
$modo_edicion  = !empty($asignacion_id);

// Valores por defecto
$centro_codigo  = '';
$curso_id       = $curso_id_get;
$rif_nit        = $ente_id_get;
$horas_duracion = '';
$capacidad_max  = '20';

if ($modo_edicion) {
    $res_asig = pg_query_params($conexion, "SELECT * FROM ente_cursos WHERE id = $1", array($asignacion_id));
    if ($res_asig && pg_num_rows($res_asig) > 0) {
        $row_asig = pg_fetch_assoc($res_asig);
        $centro_codigo  = $row_asig['centro_codigo'];
        $curso_id       = $row_asig['curso_id'];
        $rif_nit        = $row_asig['rif_nit'];
        $horas_duracion = $row_asig['horas_duracion'];
        $capacidad_max  = $row_asig['capacidad_maxima'];
    }
}

// Obtener listados para los selectores usando tus tablas y columnas reales
$centros_res = pg_query($conexion, "SELECT codigo, nombre, genero_permitido FROM centros ORDER BY nombre ASC");
$entes_res   = pg_query($conexion, "SELECT rif_nit, nombre_ente FROM entes_educativos ORDER BY nombre_ente ASC");
$cursos_res  = pg_query($conexion, "SELECT id, nombre_curso, codigo_curso, sigla FROM cursos ORDER BY nombre_curso ASC");
?>

<main class="app-main" style="width: 100%; min-height: 100vh;">
    <div class="app-content-header">
        <div class="container-fluid px-4">
            <div class="row align-items-center mb-2">
                <div class="col-sm-6">
                    <h3 class="mb-0">
                        <i class="bi bi-diagram-3"></i> 
                        <?php echo $modo_edicion ? 'Modificar Asignación de Curso' : 'Asignación de Cursos por Centro y Ente'; ?>
                    </h3>
                </div>
                <div class="col-sm-6 text-end">
                    <a href="cursos_entes_gestion.php" class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i> Volver a la Gestión
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="app-content">
        <div class="container-fluid px-4">

            <?php if (isset($_SESSION['mensaje_error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php echo $_SESSION['mensaje_error']; unset($_SESSION['mensaje_error']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="row">
                <div class="col-md-8 mx-auto">
                    <div class="card card-primary card-outline shadow-sm">
                        <div class="card-header">
                            <h3 class="card-title"><?php echo $modo_edicion ? 'Actualizar Datos de la Asignación' : 'Formulario de Nueva Asignación'; ?></h3>
                        </div>
                        
                        <!-- REVISA AQUÍ EL NOMBRE DE TU ARCHIVO PROCESADOR -->
                        <form action="curso_entes_procesar.php" method="POST">
                            <input type="hidden" name="accion" value="<?php echo $modo_edicion ? 'actualizar' : 'asignar'; ?>">
                            <?php if ($modo_edicion): ?>
                                <input type="hidden" name="asignacion_id" value="<?php echo htmlspecialchars($asignacion_id); ?>">
                            <?php endif; ?>

                            <div class="card-body">
                                <div class="alert alert-info py-2 mb-3 fw-bold" role="alert">
                                    <i class="bi bi-info-circle-fill"></i> Seleccione el curso, el centro penitenciario (con su capacidad) y el ente que avala (con su duración en horas).
                                </div>

                                <!-- Selección del Curso -->
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Curso del Catálogo <span class="text-danger">*</span>:</label>
                                    <select class="form-select" name="curso_id" required>
                                        <option value="">-- Seleccione un Curso --</option>
                                        <?php while ($c = pg_fetch_assoc($cursos_res)): ?>
                                            <option value="<?php echo $c['id']; ?>" <?php echo ($curso_id == $c['id']) ? 'selected' : ''; ?>>
                                                [<?php echo htmlspecialchars($c['sigla']); ?>] - <?php echo htmlspecialchars($c['nombre_curso']); ?>
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>

                                <!-- Selección del Centro y su Capacidad -->
                                <div class="row">
                                    <div class="col-md-8 mb-3">
                                        <label class="form-label fw-bold">Centro Penitenciario / Centro <span class="text-danger">*</span>:</label>
                                        <select class="form-select" name="centro_codigo" required>
                                            <option value="">-- Seleccione un Centro --</option>
                                            <?php while ($cen = pg_fetch_assoc($centros_res)): 
                                                $genero_txt = !empty($cen['genero_permitido']) ? " (" . $cen['genero_permitido'] . ")" : "";
                                            ?>
                                                <option value="<?php echo htmlspecialchars($cen['codigo']); ?>" <?php echo ($centro_codigo == $cen['codigo']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($cen['nombre'] . $genero_txt); ?>
                                                </option>
                                            <?php endwhile; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label fw-bold">Capacidad (Cupos) <span class="text-danger">*</span>:</label>
                                        <input type="number" class="form-control" name="capacidad_maxima" min="1" required value="<?php echo htmlspecialchars($capacidad_max); ?>" placeholder="Ej: 20">
                                    </div>
                                </div>

                                <!-- Selección del Ente y la Duración en Horas -->
                                <div class="row">
                                    <div class="col-md-8 mb-3">
                                        <label class="form-label fw-bold">Ente Educativo / Ente Avala <span class="text-danger">*</span>:</label>
                                        <select class="form-select" name="rif_nit" required>
                                            <option value="">-- Seleccione un Ente --</option>
                                            <?php while ($ent = pg_fetch_assoc($entes_res)): ?>
                                                <option value="<?php echo htmlspecialchars($ent['rif_nit']); ?>" <?php echo ($rif_nit == $ent['rif_nit']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($ent['nombre_ente']); ?> (RIF: <?php echo htmlspecialchars($ent['rif_nit']); ?>)
                                                </option>
                                            <?php endwhile; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label fw-bold">Duración (Horas) <span class="text-danger">*</span>:</label>
                                        <input type="number" class="form-control" name="horas_duracion" min="1" required value="<?php echo htmlspecialchars($horas_duracion); ?>" placeholder="Ej: 40">
                                    </div>
                                </div>

                            </div>

                            <div class="card-footer text-end">
                                <a href="cursos_entes_gestion.php" class="btn btn-secondary me-2">Cancelar</a>
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-check-circle"></i> <?php echo $modo_edicion ? 'Actualizar Asignación' : 'Guardar Asignación'; ?>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

        </div>
    </div>
</main>

<?php 
if (isset($centros_res)) { pg_free_result($centros_res); }
if (isset($entes_res)) { pg_free_result($entes_res); }
if (isset($cursos_res)) { pg_free_result($cursos_res); }
if (isset($conexion)) { pg_close($conexion); }
require_once 'footer.php'; 
?>