<?php
session_start();
require_once 'header.php';
require_once 'conexion.php';

// 1. Validar que se recibió un ID
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error_message'] = "No se especificó un registro de trabajo.";
    header('Location: trabajo_listar.php');
    exit();
}
$registro_id = $_GET['id'];

// 2. Consulta para obtener los datos del registro laboral (PostgreSQL)
$sql_registro = "SELECT 
                     rl.id,
                     p.documento_identidad,
                     (p.nombre || ' ' || p.apellido) AS nombre_completo,
                     rl.fecha_inicio_labor
                  FROM registros_laborales rl
                  JOIN privados p ON rl.privado_documento = p.documento_identidad
                  WHERE rl.id = $1";

$res_registro = pg_query_params($conexion, $sql_registro, array($registro_id));

if (!$res_registro || pg_num_rows($res_registro) === 0) {
    if ($res_registro) pg_free_result($res_registro);
    $_SESSION['error_message'] = "Registro de trabajo no encontrado.";
    header('Location: trabajo_listar.php');
    exit();
}
$registro = pg_fetch_assoc($res_registro);
pg_free_result($res_registro);

// 3. Consulta para obtener el historial de reposos y licencias (PostgreSQL)
$sql_reposos = "SELECT * FROM reposos_licencias WHERE registro_laboral_id = $1 ORDER BY fecha_inicio DESC";
$res_reposos = pg_query_params($conexion, $sql_reposos, array($registro_id));
$total_reposos = ($res_reposos) ? pg_num_rows($res_reposos) : 0;
?>

<main class="app-main">
    <div class="app-content-header">
        <div class="container-fluid">
            <h3 class="mb-0">Gestión de Reposos y Licencias</h3>
        </div>
    </div>
    <div class="app-content">
        <div class="container-fluid">
            <div class="row justify-content-center">
                <div class="col-lg-10">
                    <div class="card card-outline card-primary">
                        <div class="card-body">
                            <h4 class="card-title">Gestión para: <strong><?php echo htmlspecialchars($registro['nombre_completo']); ?></strong></h4>
                            <p class="card-subtitle text-muted">Registro de Trabajo #<?php echo htmlspecialchars($registro_id); ?> | Inicio: <?php echo date('d/m/Y', strtotime($registro['fecha_inicio_labor'])); ?></p>
                            <hr>

                            <?php
                            if (isset($_SESSION['success_message'])) {
                                echo '<div class="alert alert-success">' . $_SESSION['success_message'] . '</div>';
                                unset($_SESSION['success_message']); 
                            }
                            if (isset($_SESSION['error_message'])) {
                                echo '<div class="alert alert-danger">' . $_SESSION['error_message'] . '</div>';
                                unset($_SESSION['error_message']); 
                            }
                            ?>
                            <h5 class="mt-4">Registrar Nuevo Reposo o Permiso</h5>
                            <form action="procesar_reposo.php" method="POST">
                                <input type="hidden" name="registro_id" value="<?php echo htmlspecialchars($registro_id); ?>">
                                
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label for="tipo_licencia" class="form-label">Tipo <span class="text-danger">*</span></label>
                                        <select class="form-select" id="tipo_licencia" name="tipo_licencia" required>
                                            <option value="" disabled selected>Seleccione el tipo</option>
                                            <option value="Reposo">Reposo</option>
                                            <option value="Permiso">Permiso</option>
                                            <option value="Suspensión">Suspensión</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label for="fecha_inicio" class="form-label">Fecha de Inicio <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" id="fecha_inicio" name="fecha_inicio" required>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label for="fecha_fin" class="form-label">Fecha de Fin <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" id="fecha_fin" name="fecha_fin" required>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label for="motivo" class="form-label">Motivo</label>
                                    <textarea class="form-control" id="motivo" name="motivo" rows="2"></textarea>
                                </div>

                                <button type="submit" class="btn btn-primary">Registrar</button>
                                <a href="trabajo_listar.php" class="btn btn-secondary">Volver al Listado</a>
                            </form>

                            <h5 class="mt-5">Historial de Reposos y Licencias</h5>
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped mt-3">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Tipo</th>
                                            <th>Inicio</th>
                                            <th>Fin</th>
                                            <th>Motivo</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($res_reposos && $total_reposos > 0): ?>
                                            <?php while ($reposo = pg_fetch_assoc($res_reposos)): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($reposo['tipo']); ?></td>
                                                    <td><?php echo date('d/m/Y', strtotime($reposo['fecha_inicio'])); ?></td>
                                                    <td><?php echo date('d/m/Y', strtotime($reposo['fecha_fin'])); ?></td>
                                                    <td><?php echo htmlspecialchars($reposo['motivo'] ?? 'N/A'); ?></td>
                                                </tr>
                                            <?php endwhile; ?>
                                            <?php pg_free_result($res_reposos); ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="4" class="text-center">No hay reposos o licencias registrados.</td>
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
if (isset($conexion)) {
    pg_close($conexion);
}
require_once 'footer.php';
?>