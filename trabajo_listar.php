<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
require_once 'header.php';
require_once 'conexion.php';

// Obtener datos de sesión para control de roles y limpiar la cédula (flexible con la "V")
$rolUsuario = $_SESSION['cargo'] ?? $_SESSION['rol'] ?? '';
$es_coordinador = (strcasecmp($rolUsuario, 'Coordinador Regional') === 0);
$cedula_cruda = $_SESSION['nombre_usuario'] ?? $_SESSION['cedula'] ?? '';
$cedula_sesion = preg_replace('/[^0-9]/', '', $cedula_cruda); // Extrae solo los números para garantizar el cruce exacto

// --- LÓGICA DE FILTROS ---
$filtro_centro = $_GET['centro'] ?? '';
$filtro_area = $_GET['area'] ?? '';
$filtro_general = $_GET['general'] ?? '';
$filtro_estado = $_GET['estado'] ?? ''; 
$filtro_anio = $_GET['anio'] ?? ''; 

// Carga de catálogos para los selectores (Solo necesario si es admin, pero se mantiene para la estructura)
$centros_lista = [];
if (!$es_coordinador) {
    $sql_centros = "SELECT codigo, nombre FROM centros ORDER BY nombre";
    $res_centros = pg_query($conexion, $sql_centros);
    if ($res_centros) {
        while ($f = pg_fetch_assoc($res_centros)) { $centros_lista[] = $f; }
        pg_free_result($res_centros);
    }
}

$sql_areas = "SELECT codigo, nombre_area FROM areas_socioproductivas ORDER BY nombre_area";
$res_areas = pg_query($conexion, $sql_areas);
$areas_lista = [];
if ($res_areas) {
    while ($f = pg_fetch_assoc($res_areas)) { $areas_lista[] = $f; }
    pg_free_result($res_areas);
}

$sql_anios = "SELECT DISTINCT EXTRACT(YEAR FROM fecha_inicio_labor) AS anio FROM registros_laborales WHERE fecha_inicio_labor IS NOT NULL ORDER BY anio DESC";
$res_anios = pg_query($conexion, $sql_anios);
$anios_lista = [];
if ($res_anios) {
    while ($f = pg_fetch_assoc($res_anios)) { $anios_lista[] = (int)$f['anio']; }
    pg_free_result($res_anios);
}
// --- FIN LÓGICA DE FILTROS ---

// --- CONSULTA SQL DINÁMICA CON POSTGRESQL ---
$sql_base = "SELECT DISTINCT
            rl.id, p.documento_identidad, p.nombre, p.apellido, (p.nombre || ' ' || p.apellido) AS nombre_completo,
            rl.fecha_inicio_labor, rl.horario_desde, rl.horario_hasta, rl.dias_laborales,
            rl.fecha_fin_labor, a.nombre_area, c.nombre, c.nombre AS nombre_centro
        FROM registros_laborales rl
        JOIN privados p ON rl.privado_documento = p.documento_identidad
        LEFT JOIN areas_socioproductivas a ON p.area_codigo = a.codigo
        LEFT JOIN centros c ON p.centro_codigo = c.codigo";

$where_conditions = [];
$param_values = [];
$param_index = 1;

// Si es Coordinador Regional, unimos obligatoriamente con 'usuario_centros' y filtramos por su recinto
if ($es_coordinador) {
    $sql_base .= " JOIN usuario_centros uc ON p.centro_codigo = uc.centro_codigo";
    // Usamos REPLACE para comparar ignorando si en la BD está con V o sin V
    $where_conditions[] = "REPLACE(REPLACE(uc.usuario_cedula, 'V', ''), 'v', '') = $" . $param_index;
    $param_values[] = $cedula_sesion;
    $param_index++;
}

if (!empty($filtro_general)) {
    $where_conditions[] = "(p.documento_identidad ILIKE $" . $param_index . " OR p.nombre ILIKE $" . ($param_index + 1) . " OR p.apellido ILIKE $" . ($param_index + 2) . ")";
    $param_like = "%" . $filtro_general . "%";
    $param_values[] = $param_like;
    $param_values[] = $param_like;
    $param_values[] = $param_like;
    $param_index += 3;
}

// El filtro por centro manual solo aplica si no es coordinador
if (!empty($filtro_centro) && !$es_coordinador) {
    $where_conditions[] = "p.centro_codigo = $" . $param_index;
    $param_values[] = $filtro_centro;
    $param_index++;
}

if (!empty($filtro_area)) {
    $where_conditions[] = "p.area_codigo = $" . $param_index;
    $param_values[] = $filtro_area;
    $param_index++;
}

if (!empty($filtro_anio)) {
    $where_conditions[] = "EXTRACT(YEAR FROM rl.fecha_inicio_labor) = $" . $param_index;
    $param_values[] = (int)$filtro_anio;
    $param_index++;
}

if ($filtro_estado === 'activo') {
    $where_conditions[] = "rl.fecha_fin_labor IS NULL";
} elseif ($filtro_estado === 'finalizado') {
    $where_conditions[] = "rl.fecha_fin_labor IS NOT NULL";
}

$sql_trabajadores = $sql_base;
if (count($where_conditions) > 0) {
    $sql_trabajadores .= " WHERE " . implode(" AND ", $where_conditions);
}
$sql_trabajadores .= " ORDER BY c.nombre, a.nombre_area, p.nombre, p.apellido";

$resultado = pg_query_params($conexion, $sql_trabajadores, $param_values);
$total_registros = ($resultado) ? pg_num_rows($resultado) : 0;
// --- FIN CONSULTA DINÁMICA ---

$query_string_pdf = http_build_query($_GET);
?>

<main class="app-main">
    <div class="app-content-header">
        <div class="container-fluid">
             <div class="row align-items-center">
                <div class="col-sm-6">
                    <h3 class="mb-0">Registros Laborales</h3>
                </div>
                <div class="col-sm-6 text-sm-end">
                    <?php if (!$es_coordinador): ?>
                        <a href="trabajo_registro.php" class="btn btn-primary">
                            <i class="bi bi-plus-circle me-1"></i> Asignar actividad laboral
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <div class="app-content">
        <div class="container-fluid">

            <?php
            if (isset($_SESSION['success_message'])) {
                echo '<div class="alert alert-success alert-dismissible fade show" role="alert">' . $_SESSION['success_message'] . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
                unset($_SESSION['success_message']);
            }
            if (isset($_SESSION['error_message'])) {
                echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">' . $_SESSION['error_message'] . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
                unset($_SESSION['error_message']);
            }
            ?>

            <div class="card card-outline card-secondary mb-4">
                <div class="card-header">
                    <h3 class="card-title"><i class="bi bi-filter"></i> Filtros de Búsqueda</h3>
                </div>
                <div class="card-body">
                    <form action="trabajo_listar.php" method="GET">
                        <div class="row">
                            <div class="col-md-3">
                                <label for="general" class="form-label">Búsqueda General</label>
                                <input type="text" class="form-control" name="general" id="general" value="<?php echo htmlspecialchars($filtro_general); ?>" placeholder="Nombre, Apellido o Cédula...">
                            </div>

                            <!-- Selector de Centro oculto para el Coordinador Regional -->
                            <?php if (!$es_coordinador): ?>
                            <div class="col-md-3">
                                <label for="centro" class="form-label">Centro</label>
                                <select class="form-select" name="centro" id="centro">
                                    <option value="">-- Todos --</option>
                                    <?php foreach ($centros_lista as $fila_centro): ?>
                                        <option value="<?php echo $fila_centro['codigo']; ?>" <?php echo ($filtro_centro == $fila_centro['codigo']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($fila_centro['nombre']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>

                            <div class="col-md-3">
                                <label for="area" class="form-label">Área</label>
                                <select class="form-select" name="area" id="area">
                                    <option value="">-- Todas --</option>
                                    <?php foreach ($areas_lista as $fila_area): ?>
                                        <option value="<?php echo $fila_area['codigo']; ?>" <?php echo ($filtro_area == $fila_area['codigo']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($fila_area['nombre_area']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="anio" class="form-label">Año Inicio</label>
                                <select class="form-select" name="anio" id="anio">
                                    <option value="">-- Todos --</option>
                                    <?php foreach ($anios_lista as $anio): ?>
                                        <option value="<?php echo $anio; ?>" <?php echo ($filtro_anio == $anio) ? 'selected' : ''; ?>>
                                            <?php echo $anio; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="row mt-3">
                            <div class="col-md-3">
                                <label for="estado" class="form-label">Estado</label>
                                <select class="form-select" name="estado" id="estado">
                                    <option value="">-- Todos --</option>
                                    <option value="activo" <?php echo ($filtro_estado == 'activo') ? 'selected' : ''; ?>>Activos</option>
                                    <option value="finalizado" <?php echo ($filtro_estado == 'finalizado') ? 'selected' : ''; ?>>Finalizados (Histórico)</option>
                                </select>
                            </div>
                            <div class="col-md-9 d-flex justify-content-end align-items-end">
                                <a href="trabajo_listar.php" class="btn btn-secondary me-2">Limpiar Filtros</a>
                                <button type="submit" class="btn btn-primary me-2">Aplicar Filtros</button>
                                <a href="generar_pdf_laboral.php?<?php echo $query_string_pdf; ?>" class="btn btn-danger" target="_blank">
                                    <i class="bi bi-file-earmark-pdf"></i> Imprimir PDF
                                </a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card card-outline card-primary">
                <div class="card-body">
                    <div class="mb-3">
                        <input type="text" id="searchInput" class="form-control" placeholder="Escriba un nombre, documento o área para filtrar la tabla...">
                    </div>
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped table-hover">
                            <thead class="table-dark">
                                <tr>
                                    <th>Privado de Libertad</th>
                                    <th>Centro / Área</th>
                                    <th>Inicio Laboral</th>
                                    <th>Días / Horario</th>
                                    <th>Estado</th>
                                    <th style="width: 170px;">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($resultado && $total_registros > 0): ?>
                                    <?php while ($fila = pg_fetch_assoc($resultado)): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($fila['nombre_completo']); ?></strong><br>
                                            <small class="text-muted"><?php echo htmlspecialchars($fila['documento_identidad']); ?></small>
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($fila['nombre_centro'] ?? 'N/A'); ?></strong><br>
                                            <small class="text-muted"><?php echo htmlspecialchars($fila['nombre_area'] ?? 'N/A'); ?></small>
                                        </td>
                                        <td><?php echo date('d/m/Y', strtotime($fila['fecha_inicio_labor'])); ?></td>
                                        <td>
                                            <?php
                                            $dias_amigables = str_replace(['L','M','X','J','V','S','D'], ['Lun','Mar','Mié','Jue','Vie','Sáb','Dom'], $fila['dias_laborales']);
                                            echo htmlspecialchars($dias_amigables);
                                            ?>
                                            <br>
                                            <small class="text-muted">
                                                <?php echo date('h:i A', strtotime($fila['horario_desde'])) . ' - ' . date('h:i A', strtotime($fila['horario_hasta'])); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <?php 
                                            if (!empty($fila['fecha_fin_labor'])) {
                                                echo '<span class="badge text-bg-secondary">Finalizado: ' . date('d/m/Y', strtotime($fila['fecha_fin_labor'])) . '</span>';
                                            } else {
                                                echo '<span class="badge text-bg-success">Activo</span>';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <div class="btn-group" role="group">
                                                <!-- Botones permitidos para el Coordinador y Administrador -->
                                                <a href="asistencia_gestionar.php?id=<?php echo $fila['id']; ?>" class="btn btn-sm btn-info" title="Asistencia"><i class="bi bi-calendar-check"></i></a>
                                                <a href="reposos_gestionar.php?id=<?php echo $fila['id']; ?>" class="btn btn-sm btn-warning" title="Reposos/Licencias"><i class="bi bi-bandaid"></i></a>
                                                
                                                <!-- Botones restringidos (Exclusivos para Administradores) -->
                                                <?php if (!$es_coordinador): ?>
                                                    <a href="trabajo_calcular.php?id=<?php echo $fila['id']; ?>" class="btn btn-sm btn-success" title="Calcular Redención"><i class="bi bi-calculator"></i></a>
                                                    
                                                    <?php if (empty($fila['fecha_fin_labor'])): ?>
                                                        <button type="button" class="btn btn-sm btn-danger btn-finalizar" 
                                                                data-bs-toggle="modal" 
                                                                data-bs-target="#confirmarFinalizarModal" 
                                                                data-id="<?php echo $fila['id']; ?>" 
                                                                data-nombre="<?php echo htmlspecialchars($fila['nombre_completo']); ?>"
                                                                title="Finalizar Actividad">
                                                            <i class="bi bi-x-circle"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                    <?php if ($resultado) pg_free_result($resultado); ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="text-center">No se encontraron registros de trabajo con esos filtros.</td>
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

<?php if (!$es_coordinador): ?>
<div class="modal fade" id="confirmarFinalizarModal" tabindex="-1" aria-labelledby="modalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form action="finalizar_registro.php" method="POST">
        <div class="modal-header">
            <h5 class="modal-title" id="modalLabel">Confirmar Finalización</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
            <p>¿Está seguro de que desea finalizar esta actividad laboral?</p>
            <p><strong>Privado:</strong> <span id="modalNombrePrivado"></span></p>
            
            <div class="mb-3">
                <label for="motivo_fin" class="form-label">Motivo de Finalización <span class="text-danger">*</span></label>
                <select class="form-select" id="motivo_fin" name="motivo_fin" required>
                    <option value="" disabled selected>-- Seleccione un motivo --</option>
                    <option value="Libertad">Libertad</option>
                    <option value="Fin de Condena">Fin de Condena</option>
                    <option value="Sanción">Sanción</option>
                    <option value="Traslado (Cese de Actividad)">Traslado (Cese de Actividad)</option>
                    <option value="Otro">Otro</option>
                </select>
            </div>
            
            <p class="text-danger fw-bold mt-3">
                <i class="bi bi-exclamation-triangle-fill"></i> Para confirmar esta acción, ingrese su contraseña.
            </p>
            <div class="mb-3">
                <label for="contrasena_confirmacion" class="form-label">Su Contraseña:</label>
                <input type="password" class="form-control" id="contrasena_confirmacion" name="contrasena_confirmacion" required>
            </div>
            
            <input type="hidden" id="modalRegistroId" name="registro_id" value="">
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" class="btn btn-danger">Finalizar Actividad</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        const tableBody = document.querySelector('.table-hover tbody');
        const rows = tableBody.querySelectorAll('tr');

        searchInput.addEventListener('keyup', function() {
            const searchTerm = this.value.toLowerCase();
            rows.forEach(row => {
                if (row.querySelector('td[colspan="6"]')) return;
                const text = row.textContent.toLowerCase();
                if (text.includes(searchTerm)) row.style.display = '';
                else row.style.display = 'none';
            });
        });
    }

    const finalizarModal = document.getElementById('confirmarFinalizarModal');
    if (finalizarModal) {
        finalizarModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const id = button.getAttribute('data-id');
            const nombre = button.getAttribute('data-nombre');

            const modalNombre = finalizarModal.querySelector('#modalNombrePrivado');
            const modalRegistroIdInput = finalizarModal.querySelector('#modalRegistroId');
            const modalPasswordInput = finalizarModal.querySelector('#contrasena_confirmacion');
            const modalMotivoSelect = finalizarModal.querySelector('#motivo_fin'); 

            modalNombre.textContent = nombre;
            modalRegistroIdInput.value = id;
            modalPasswordInput.value = '';
            modalMotivoSelect.selectedIndex = 0;
        });
    }
});
</script>

<?php
if (isset($conexion)) {
    pg_close($conexion);
}
require_once 'footer.php';
?>