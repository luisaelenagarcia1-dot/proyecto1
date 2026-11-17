<?php
session_start();
require_once 'header.php';
require_once 'conexion.php';

// Obtener datos de sesión para control de roles
$rolUsuario = $_SESSION['cargo'] ?? $_SESSION['rol'] ?? '';
$es_coordinador = (strcasecmp($rolUsuario, 'Coordinador Regional') === 0);
$cedula_sesion = $_SESSION['nombre_usuario'] ?? $_SESSION['cedula'] ?? '';

// --- LÓGICA DE FILTROS ---
$filtro_centro = $_GET['centro'] ?? '';
$filtro_area = $_GET['area'] ?? '';
$filtro_cedula = $_GET['cedula'] ?? '';

// Cargar centros para el selector (Solo si no es coordinador)
$centros_lista = [];
if (!$es_coordinador) {
    $sql_centros = "SELECT codigo, nombre FROM centros ORDER BY nombre";
    $res_centros = pg_query($conexion, $sql_centros);
    if ($res_centros) {
        while ($f = pg_fetch_assoc($res_centros)) { $centros_lista[] = $f; }
        pg_free_result($res_centros);
    }
}

// Cargar áreas para el selector
$sql_areas = "SELECT codigo, nombre_area FROM areas_socioproductivas ORDER BY nombre_area";
$res_areas = pg_query($conexion, $sql_areas);
$areas_lista = [];
if ($res_areas) {
    while ($f = pg_fetch_assoc($res_areas)) { $areas_lista[] = $f; }
    pg_free_result($res_areas);
}

// --- LÓGICA DE LA PÁGINA (CONSULTA DINÁMICA CON POSTGRESQL) ---
$fecha_hoy = date('Y-m-d');
$dias_semana_letras = ['Mon'=>'L', 'Tue'=>'M', 'Wed'=>'X', 'Thu'=>'J', 'Fri'=>'V', 'Sat'=>'S', 'Sun'=>'D'];
$letra_dia_hoy = $dias_semana_letras[date('D')];

// Se añaden p.apellido y p.nombre al SELECT DISTINCT para cumplir con las reglas del ORDER BY en PostgreSQL
$sql_base = "SELECT DISTINCT
                rl.id,
                p.documento_identidad,
                p.nombre,
                p.apellido,
                (p.nombre || ' ' || p.apellido) AS nombre_completo,
                a.nombre_area
             FROM registros_laborales rl
             JOIN privados p ON rl.privado_documento = p.documento_identidad
             LEFT JOIN areas_socioproductivas a ON p.area_codigo = a.codigo";

$where_conditions = [];
$param_values = [];
$param_index = 1;

// Si es Coordinador Regional, unimos con 'usuario_centros' para forzar su recinto
if ($es_coordinador) {
    $sql_base .= " JOIN usuario_centros uc ON p.centro_codigo = uc.centro_codigo";
    $where_conditions[] = "uc.usuario_cedula = $" . $param_index;
    $param_values[] = $cedula_sesion;
    $param_index++;
}

// Condiciones fijas de día y estado activo
$where_conditions[] = "rl.fecha_fin_labor IS NULL";
$where_conditions[] = "rl.fecha_inicio_labor <= CURRENT_DATE";
$where_conditions[] = "rl.dias_laborales ILIKE $" . $param_index;
$param_values[] = "%" . $letra_dia_hoy . "%";
$param_index++;

// Filtro manual de centro (Solo si es Administrador)
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

if (!empty($filtro_cedula)) {
    $where_conditions[] = "p.documento_identidad ILIKE $" . $param_index;
    $param_values[] = "%" . $filtro_cedula . "%";
    $param_index++;
}

$sql_trabajadores = $sql_base;
if (count($where_conditions) > 0) {
    $sql_trabajadores .= " WHERE " . implode(" AND ", $where_conditions);
}
$sql_trabajadores .= " ORDER BY p.apellido, p.nombre";

$resultado_trabajadores = pg_query_params($conexion, $sql_trabajadores, $param_values);
$total_trabajadores = ($resultado_trabajadores) ? pg_num_rows($resultado_trabajadores) : 0;

// Obtener asistencias ya registradas para hoy
$sql_asistencias = "SELECT registro_laboral_id, asistencia FROM asistencias WHERE fecha = $1";
$resultado_asistencias = pg_query_params($conexion, $sql_asistencias, array($fecha_hoy));

$asistencias_hoy = [];
if ($resultado_asistencias) {
    while ($asistencia = pg_fetch_assoc($resultado_asistencias)) {
        $asistencias_hoy[$asistencia['registro_laboral_id']] = $asistencia['asistencia'];
    }
    pg_free_result($resultado_asistencias);
}

$select_color_map = [
    'Presente' => 'text-bg-success',
    'Ausente'  => 'text-bg-danger',
    'Reposo'   => 'text-bg-warning',
    'Permiso'  => 'text-bg-info'
];
?>

<main class="app-main">
    <div class="app-content-header">
        <div class="container-fluid">
            <h3 class="mb-0">Hoja de Asistencia Diaria</h3>
        </div>
    </div>
    <div class="app-content">
        <div class="container-fluid">

            <div class="card card-outline card-secondary mb-4">
                <div class="card-header">
                    <h3 class="card-title"><i class="bi bi-filter"></i> Filtros de Búsqueda</h3>
                </div>
                <div class="card-body">
                    <form action="asistencia_diaria.php" method="GET">
                        <div class="row">
                            <!-- Selector de Centro oculto para el Coordinador Regional -->
                            <?php if (!$es_coordinador): ?>
                            <div class="col-md-4">
                                <label for="centro" class="form-label">Centro de Reclusión</label>
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

                            <div class="col-md-<?php echo $es_coordinador ? '6' : '4'; ?>">
                                <label for="area" class="form-label">Área Socioproductiva</label>
                                <select class="form-select" name="area" id="area">
                                    <option value="">-- Todas --</option>
                                    <?php foreach ($areas_lista as $fila_area): ?>
                                        <option value="<?php echo $fila_area['codigo']; ?>" <?php echo ($filtro_area == $fila_area['codigo']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($fila_area['nombre_area']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-<?php echo $es_coordinador ? '6' : '4'; ?>">
                                <label for="cedula" class="form-label">Cédula del Privado</label>
                                <input type="text" class="form-control" name="cedula" id="cedula" value="<?php echo htmlspecialchars($filtro_cedula); ?>" placeholder="Ej: V12345678">
                            </div>
                        </div>
                        <div class="row mt-3">
                            <div class="col-12 text-end">
                                <a href="asistencia_diaria.php" class="btn btn-secondary">Limpiar Filtros</a>
                                <button type="submit" class="btn btn-primary">Aplicar Filtros</button>
                                <a href="#" id="btnImprimirPDF" class="btn btn-info" target="_blank">
                                    <i class="bi bi-printer-fill"></i> Imprimir PDF
                                </a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card card-outline card-primary">
                <div class="card-header">
                    <h3 class="card-title">Asistencia para el <?php echo date('d/m/Y'); ?> (Día: <?php echo str_replace(['L','M','X','J','V','S','D'], ['Lunes','Martes','Miércoles','Jueves','Viernes','Sábado','Domingo'], $letra_dia_hoy); ?>)</h3>
                </div>
                <div class="card-body">

                    <?php if (isset($_GET['exito'])): ?>
                        <div class="alert alert-success">¡Asistencia guardada correctamente!</div>
                    <?php endif; ?>

                    <form action="procesar_asistencia_diaria.php" method="POST">
                        <input type="hidden" name="fecha_asistencia" value="<?php echo $fecha_hoy; ?>">
                        <input type="hidden" name="filtro_centro" value="<?php echo htmlspecialchars($filtro_centro); ?>">
                        <input type="hidden" name="filtro_area" value="<?php echo htmlspecialchars($filtro_area); ?>">
                        <input type="hidden" name="filtro_cedula" value="<?php echo htmlspecialchars($filtro_cedula); ?>">

                        <div class="table-responsive">
                            <table class="table table-bordered table-striped">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Privado de Libertad</th>
                                        <th>Documento</th>
                                        <th>Área de Trabajo</th>
                                        <th style="width: 200px;">Estado de Asistencia</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($total_trabajadores > 0): ?>
                                        <?php while ($fila = pg_fetch_assoc($resultado_trabajadores)): ?>
                                            <?php
                                            $estado_actual = $asistencias_hoy[$fila['id']] ?? 'Ausente';
                                            $color_clase = $select_color_map[$estado_actual] ?? 'text-bg-danger';
                                            ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($fila['nombre_completo']); ?></td>
                                                <td><?php echo htmlspecialchars($fila['documento_identidad']); ?></td>
                                                <td><?php echo htmlspecialchars($fila['nombre_area'] ?? 'N/A'); ?></td>
                                                <td>
                                                    <select class="form-select <?php echo $color_clase; ?>" name="asistencia[<?php echo $fila['id']; ?>]">
                                                        <option value="Presente" <?php echo $estado_actual === 'Presente' ? 'selected' : ''; ?>>Presente</option>
                                                        <option value="Ausente"  <?php echo $estado_actual === 'Ausente' ? 'selected' : ''; ?>>Ausente</option>
                                                        <option value="Reposo"   <?php echo $estado_actual === 'Reposo' ? 'selected' : ''; ?>>Reposo</option>
                                                        <option value="Permiso"  <?php echo $estado_actual === 'Permiso' ? 'selected' : ''; ?>>Permiso</option>
                                                    </select>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                        <?php pg_free_result($resultado_trabajadores); ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4" class="text-center">
                                                <strong>No se encontraron trabajadores para hoy con los filtros aplicados.</strong>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="text-end mt-3">
                            <button type="submit" class="btn btn-lg btn-success" <?php echo ($total_trabajadores == 0) ? 'disabled' : ''; ?>>
                                <i class="bi bi-check-all me-1"></i> Guardar Asistencia del Día
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', function() {
    
    // --- Lógica para el color del <select> ---
    const colorClasses = {'Presente':'text-bg-success', 'Ausente':'text-bg-danger', 'Reposo':'text-bg-warning', 'Permiso':'text-bg-info'};
    function updateSelectColor(select) {
        Object.values(colorClasses).forEach(cls => select.classList.remove(cls));
        const newClass = colorClasses[select.value];
        if (newClass) select.classList.add(newClass);
    }
    const selects = document.querySelectorAll('select[name^="asistencia["]');
    selects.forEach(select => {
        select.addEventListener('change', function() { updateSelectColor(this); });
    });
    
    // --- Lógica para el botón de Imprimir PDF ---
    const btnPDF = document.getElementById('btnImprimirPDF');
    if (btnPDF) {
        function actualizarEnlacePDF() {
            const centroEl = document.getElementById('centro');
            const centro = centroEl ? centroEl.value : '';
            const area = document.getElementById('area').value;
            const cedula = document.getElementById('cedula').value;
            
            const url = `generar_pdf_asistencia.php?centro=${encodeURIComponent(centro)}&area=${encodeURIComponent(area)}&cedula=${encodeURIComponent(cedula)}`;
            btnPDF.href = url;
        }
        
        actualizarEnlacePDF();
        
        const centroEl = document.getElementById('centro');
        if (centroEl) centroEl.addEventListener('change', actualizarEnlacePDF);
        document.getElementById('area').addEventListener('change', actualizarEnlacePDF);
        document.getElementById('cedula').addEventListener('keyup', actualizarEnlacePDF);
    }
});
</script>

<?php
if (isset($conexion)) {
    pg_close($conexion);
}
require_once 'footer.php';
?>