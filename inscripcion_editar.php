<?php
session_start();
require_once 'conexion.php';

// Validar sesión activa
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: login.php");
    exit();
}

$id_inscripcion = $_GET['id'] ?? '';

if (empty($id_inscripcion)) {
    $_SESSION['mensaje_error'] = "❌ ID de inscripción no especificado.";
    header("Location: inscripciones_listado.php");
    exit();
}

// 1. PROCESAR EL FORMULARIO ANTES DE IMPRIMIR CUALQUIER HTML
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nuevo_correo = trim($_POST['correo'] ?? '');
    $nueva_seccion = trim($_POST['seccion_id'] ?? '');
    $nuevo_estado = trim($_POST['estado_cursante'] ?? '');
    $documento_privado = trim($_POST['documento_privado'] ?? '');

    if (empty($nuevo_correo) || empty($nueva_seccion) || empty($nuevo_estado)) {
        $error = "Todos los campos son obligatorios.";
    } else {
        // Actualizar el correo en la tabla privados
        $sql_up_correo = "UPDATE privados SET correo = $1 WHERE documento_identidad = $2";
        pg_query_params($conexion, $sql_up_correo, array($nuevo_correo, $documento_privado));

        // Actualizar la sección y el estado en inscripciones_educativas
        $sql_up_insc = "UPDATE inscripciones_educativas SET seccion_id = $1, estado_cursante = $2 WHERE id = $3";
        $res_up = pg_query_params($conexion, $sql_up_insc, array($nueva_seccion, $nuevo_estado, $id_inscripcion));

        if ($res_up) {
            $_SESSION['mensaje_exito'] = "✅ ¡Inscripción y datos actualizados correctamente!";
            header("Location: inscripciones_listado.php");
            exit();
        } else {
            $error = "Error al actualizar en la base de datos.";
        }
    }
}

// 2. CONSULTAR DATOS DESPUÉS DE PROCESAR
$sql = "SELECT ie.*, p.nombre, p.apellido, p.documento_identidad, p.correo, s.id as seccion_actual_id
        FROM inscripciones_educativas ie
        JOIN privados p ON ie.privado_documento = p.documento_identidad
        JOIN secciones_cursos s ON ie.seccion_id = s.id
        WHERE ie.id = $1";

$res = pg_query_params($conexion, $sql, array($id_inscripcion));

if (!$res || pg_num_rows($res) === 0) {
    $_SESSION['mensaje_error'] = "❌ No se encontró el registro de inscripción.";
    header("Location: inscripciones_listado.php");
    exit();
}

$inscripcion = pg_fetch_assoc($res);

// Obtener lista de secciones disponibles
$res_secciones = pg_query($conexion, "SELECT s.id, s.codigo_nomenclatura, c.nombre_curso, s.centro_codigo 
                                      FROM secciones_cursos s 
                                      JOIN cursos c ON s.curso_id = c.id 
                                      ORDER BY s.id DESC");
$lista_secciones = [];
if ($res_secciones) {
    while ($sec = pg_fetch_assoc($res_secciones)) {
        $lista_secciones[] = $sec;
    }
}

// 3. AHORA SÍ INCLUIMOS EL HEADER (QUE IMPRIME HTML)
require_once 'header.php';
?>

<main class="app-main" style="width: 100%; min-height: 100vh;">
    <div class="app-content-header">
        <div class="container-fluid px-4">
            <div class="row align-items-center mb-2">
                <div class="col-sm-6">
                    <h3 class="mb-0"><i class="bi bi-pencil-square"></i> Editar Inscripción Educativa</h3>
                </div>
                <div class="col-sm-6 text-end">
                    <a href="inscripciones_listado.php" class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i> Volver al Listado
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="app-content">
        <div class="container-fluid px-4">
            <div class="row justify-content-center">
                <div class="col-md-8">
                    
                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger" role="alert">
                            <?php echo $error; ?>
                        </div>
                    <?php endif; ?>

                    <div class="card card-primary card-outline shadow-sm">
                        <div class="card-body">
                            <form action="inscripcion_editar.php?id=<?php echo $id_inscripcion; ?>" method="POST">
                                
                                <input type="hidden" name="documento_privado" value="<?php echo htmlspecialchars($inscripcion['documento_identidad']); ?>">

                                <div class="mb-3">
                                    <label class="form-label fw-bold">Privado de Libertad:</label>
                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($inscripcion['apellido'] . ', ' . $inscripcion['nombre'] . ' (C.I: ' . $inscripcion['documento_identidad'] . ')'); ?>" disabled>
                                </div>

                                <div class="mb-3">
                                    <label for="correo" class="form-label fw-bold">Correo Electrónico (Editable si fue escrito mal):</label>
                                    <input type="email" class="form-control" id="correo" name="correo" value="<?php echo htmlspecialchars($inscripcion['correo'] ?? ''); ?>" required>
                                </div>

                                <div class="mb-3">
                                    <label for="seccion_id" class="form-label fw-bold">Sección / Curso:</label>
                                    <select class="form-select" id="seccion_id" name="seccion_id" required>
                                        <?php foreach ($lista_secciones as $sec): ?>
                                            <option value="<?php echo $sec['id']; ?>" <?php echo ($inscripcion['seccion_actual_id'] == $sec['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($sec['codigo_nomenclatura'] . ' - ' . $sec['nombre_curso'] . ' (' . $sec['centro_codigo'] . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label for="estado_cursante" class="form-label fw-bold">Estado Académico:</label>
                                    <select class="form-select" id="estado_cursante" name="estado_cursante" required>
                                        <option value="Cursando" <?php echo ($inscripcion['estado_cursante'] == 'Cursando') ? 'selected' : ''; ?>>Cursando</option>
                                        <option value="Aprobado" <?php echo ($inscripcion['estado_cursante'] == 'Aprobado') ? 'selected' : ''; ?>>Aprobado</option>
                                        <option value="Retirado" <?php echo ($inscripcion['estado_cursante'] == 'Retirado') ? 'selected' : ''; ?>>Retirado</option>
                                    </select>
                                </div>

                                <div class="text-end">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-save"></i> Guardar Cambios
                                    </button>
                                </div>

                            </form>
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