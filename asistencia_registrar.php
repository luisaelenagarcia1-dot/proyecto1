<?php
session_start();
require_once 'conexion.php';

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: login.php");
    exit();
}

$rol_usuario     = $_SESSION['cargo'] ?? $_SESSION['rol'] ?? 'Gerente';
$es_coordinador  = (strcasecmp($rol_usuario, 'Coordinador Regional') === 0);
$cedula_cruda    = $_SESSION['nombre_usuario'] ?? $_SESSION['cedula'] ?? '';
$cedula_sesion   = preg_replace('/[^0-9]/', '', $cedula_cruda);

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

$seccion_seleccionada = trim($_GET['seccion_id'] ?? '');
$fecha_asistencia     = trim($_GET['fecha'] ?? date('Y-m-d'));

// Procesar el guardado masivo de asistencias por POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $seccion_id = trim($_POST['seccion_id'] ?? '');
    $fecha_reg  = trim($_POST['fecha'] ?? date('Y-m-d'));
    $asistencias_enviadas = $_POST['asistencia'] ?? array();

    if (empty($seccion_id) || empty($fecha_reg)) {
        $_SESSION['mensaje_error'] = "❌ Debe seleccionar una sección y una fecha válida.";
        header("Location: asistencia_registrar.php");
        exit();
    }

    // Iterar sobre cada alumno inscrito en la sección y guardar/actualizar su estado
    foreach ($asistencias_enviadas as $inscripcion_id => $estado_asistencia) {
        $inscripcion_id = intval($inscripcion_id);
        $estado_asistencia = strtoupper(trim($estado_asistencia));

        // Verificar si ya existe un registro para esta inscripción en esta fecha exacta
        $sql_verif = "SELECT id FROM asistencia_educativa WHERE inscripcion_id = $1 AND fecha = $2";
        $res_verif = pg_query_params($conexion, $sql_verif, array($inscripcion_id, $fecha_reg));

        if ($res_verif && pg_num_rows($res_verif) > 0) {
            // Actualizar asistencia existente
            $sql_upd = "UPDATE asistencia_educativa SET asistencia = $1 WHERE inscripcion_id = $2 AND fecha = $3";
            pg_query_params($conexion, $sql_upd, array($estado_asistencia, $inscripcion_id, $fecha_reg));
        } else {
            // Insertar nuevo registro de asistencia
            $sql_ins = "INSERT INTO asistencia_educativa (inscripcion_id, fecha, asistencia, created_at) VALUES ($1, $2, $3, NOW())";
            pg_query_params($conexion, $sql_ins, array($inscripcion_id, $fecha_reg, $estado_asistencia));
        }
    }

    $_SESSION['mensaje_exito'] = "✅ ¡Asistencia registrada correctamente para la fecha " . date('d/m/Y', strtotime($fecha_reg)) . "!";
    header("Location: asistencias_listado.php?seccion_id=" . $seccion_id);
    exit();
}

// Cargar secciones permitidas según el rol
if ($es_coordinador) {
    $sql_sec = "SELECT s.id, s.codigo_nomenclatura, c.nombre_curso FROM secciones_cursos s JOIN cursos c ON s.curso_id = c.id WHERE s.centro_codigo = $1 ORDER BY s.codigo_nomenclatura ASC";
    $res_sec = pg_query_params($conexion, $sql_sec, array($centro_usuario));
} else {
    $sql_sec = "SELECT s.id, s.codigo_nomenclatura, c.nombre_curso FROM secciones_cursos s JOIN cursos c ON s.curso_id = c.id ORDER BY s.codigo_nomenclatura ASC";
    $res_sec = pg_query($conexion, $sql_sec);
}

// Cargar alumnos inscritos en la sección seleccionada
$alumnos_inscritos = null;
if (!empty($seccion_seleccionada)) {
    $sql_alumnos = "SELECT ie.id as inscripcion_id, ie.privado_documento, p.nombre, p.apellido,
                           (SELECT a.asistencia FROM asistencia_educativa a WHERE a.inscripcion_id = ie.id AND a.fecha = $2) as asistencia_actual
                    FROM inscripciones_educativas ie
                    JOIN privados p ON ie.privado_documento = p.documento_identidad
                    WHERE ie.seccion_id = $1 AND ie.estado_cursante ILIKE 'Cursando'
                    ORDER BY p.apellido ASC";
    $res_alumnos = pg_query_params($conexion, $sql_alumnos, array($seccion_seleccionada, $fecha_asistencia));
    if ($res_alumnos) {
        $alumnos_inscritos = $res_alumnos;
    }
}

require_once 'header.php';
?>

<main class="app-main" style="width: 100%; min-height: 100vh;">
    <div class="app-content-header">
        <div class="container-fluid px-4">
            <div class="row align-items-center mb-2">
                <div class="col-sm-6">
                    <h3 class="mb-0">
                        <i class="bi bi-check2-square"></i> Registro Diario de Asistencia
                        <?php if ($es_coordinador): ?>
                            <span class="badge bg-info text-dark fs-6 ms-2">Recinto Asignado</span>
                        <?php endif; ?>
                    </h3>
                </div>
                <div class="col-sm-6 text-end">
                    <a href="asistencias_listado.php" class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i> Volver al Listado
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

            <!-- Selector de Sección y Fecha para cargar lista -->
            <div class="card shadow-sm mb-4">
                <div class="card-body">
                    <form method="GET" action="asistencia_registrar.php" class="row g-3 align-items-end">
                        <div class="col-md-7">
                            <label class="form-label fw-bold">Seleccionar Sección:</label>
                            <select class="form-select" name="seccion_id" required onchange="this.form.submit()">
                                <option value="">-- Seleccione una sección activa --</option>
                                <?php while ($s = pg_fetch_assoc($res_sec)): ?>
                                    <option value="<?php echo $s['id']; ?>" <?php echo ($seccion_seleccionada == $s['id']) ? 'selected' : ''; ?>>
                                        [<?php echo htmlspecialchars($s['codigo_nomenclatura']); ?>] - <?php echo htmlspecialchars($s['nombre_curso']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Fecha de Asistencia:</label>
                            <input type="date" class="form-control" name="fecha" value="<?php echo htmlspecialchars($fecha_asistencia); ?>" required onchange="this.form.submit()">
                        </div>
                        <div class="col-md-2">
                            <noscript><button type="submit" class="btn btn-primary w-100">Cargar</button></noscript>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Formulario masivo para guardar asistencias -->
            <?php if (!empty($seccion_seleccionada)): ?>
                <div class="card shadow-sm">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Lista de Cursantes Activos para Pasar Lista</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="asistencia_registrar.php">
                            <input type="hidden" name="seccion_id" value="<?php echo htmlspecialchars($seccion_seleccionada); ?>">
                            <input type="hidden" name="fecha" value="<?php echo htmlspecialchars($fecha_asistencia); ?>">

                            <div class="table-responsive">
                                <table class="table table-bordered table-striped align-middle">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Cédula / Documento</th>
                                            <th>Apellidos y Nombres</th>
                                            <th class="text-center" style="width: 40%;">Estado de Asistencia</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($alumnos_inscritos && pg_num_rows($alumnos_inscritos) > 0): ?>
                                            <?php while ($alumno = pg_fetch_assoc($alumnos_inscritos)): 
                                                $estado_actual = strtoupper(trim($alumno['asistencia_actual'] ?? 'PRESENTE'));
                                            ?>
                                                <tr>
                                                    <td>V-<?php echo htmlspecialchars($alumno['privado_documento']); ?></td>
                                                    <td><?php echo htmlspecialchars($alumno['apellido'] . ', ' . $alumno['nombre']); ?></td>
                                                    <td class="text-center">
                                                        <div class="btn-group" role="group">
                                                            <input type="radio" class="btn-check" name="asistencia[<?php echo $alumno['inscripcion_id']; ?>]" id="pres_<?php echo $alumno['inscripcion_id']; ?>" value="PRESENTE" <?php echo ($estado_actual === 'PRESENTE') ? 'checked' : ''; ?>>
                                                            <label class="btn btn-outline-success" for="pres_<?php echo $alumno['inscripcion_id']; ?>">Presente</label>

                                                            <input type="radio" class="btn-check" name="asistencia[<?php echo $alumno['inscripcion_id']; ?>]" id="aus_<?php echo $alumno['inscripcion_id']; ?>" value="AUSENTE" <?php echo ($estado_actual === 'AUSENTE') ? 'checked' : ''; ?>>
                                                            <label class="btn btn-outline-danger" for="aus_<?php echo $alumno['inscripcion_id']; ?>">Ausente</label>

                                                            <input type="radio" class="btn-check" name="asistencia[<?php echo $alumno['inscripcion_id']; ?>]" id="just_<?php echo $alumno['inscripcion_id']; ?>" value="JUSTIFICADO" <?php echo ($estado_actual === 'JUSTIFICADO') ? 'checked' : ''; ?>>
                                                            <label class="btn btn-outline-warning text-dark" for="just_<?php echo $alumno['inscripcion_id']; ?>">Justificado</label>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endwhile; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="3" class="text-center text-muted py-4">No hay privados con inscripción activa ('Cursando') en esta sección.</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <?php if ($alumnos_inscritos && pg_num_rows($alumnos_inscritos) > 0): ?>
                                <div class="text-end mt-3">
                                    <button type="submit" class="btn btn-success btn-lg">
                                        <i class="bi bi-save"></i> Guardar Asistencia Masiva
                                    </button>
                                </div>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

        </div>
    </div>
</main>

<?php 
if (isset($conexion)) { pg_close($conexion); }
require_once 'footer.php'; 
?>