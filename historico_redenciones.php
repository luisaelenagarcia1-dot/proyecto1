<?php
session_start();
require_once 'header.php';
require_once 'conexion.php';

// 2. Obtención de Filtros
$filtro_anio = $_GET['anio'] ?? '';
$filtro_estado = $_GET['estado'] ?? '';
$filtro_centro = $_GET['centro'] ?? '';

$sql_historico = "SELECT s.*, p.nombre, p.apellido 
                  FROM solicitudes_redencion s
                  JOIN privados p ON s.privado_documento = p.documento_identidad
                  WHERE s.estado IN ('Aprobado', 'Rechazado')";

$condiciones = [];
$param_values = [];
$param_index = 1;

if (!empty($filtro_anio)) {
    $condiciones[] = "EXTRACT(YEAR FROM s.fecha_decision) = $" . $param_index;
    $param_values[] = $filtro_anio;
    $param_index++;
}

if (!empty($filtro_estado) && ($filtro_estado == 'Aprobado' || $filtro_estado == 'Rechazado')) {
    $condiciones[] = "s.estado = $" . $param_index;
    $param_values[] = $filtro_estado;
    $param_index++;
}

if (!empty($filtro_centro)) {
    $condiciones[] = "p.centro_codigo = $" . $param_index;
    $param_values[] = $filtro_centro;
    $param_index++;
}

if (count($condiciones) > 0) {
    $sql_historico .= " AND " . implode(' AND ', $condiciones);
}

$sql_historico .= " ORDER BY s.fecha_decision DESC"; 

// Ejecutar consulta principal con PostgreSQL
$resultado = pg_query_params($conexion, $sql_historico, $param_values);
$total_filas = ($resultado) ? pg_num_rows($resultado) : 0;

$query_string_pdf = http_build_query($_GET);

// Obtener años para el selector (PostgreSQL EXTRACT)
$sql_anios = "SELECT DISTINCT EXTRACT(YEAR FROM fecha_decision) AS anio FROM solicitudes_redencion WHERE fecha_decision IS NOT NULL ORDER BY anio DESC";
$res_anios = pg_query($conexion, $sql_anios);
$anios_lista = [];
if ($res_anios) {
    while ($a = pg_fetch_assoc($res_anios)) { $anios_lista[] = $a['anio']; }
    pg_free_result($res_anios);
}

// Obtener centros para el selector
$sql_centros = "SELECT codigo, nombre FROM centros ORDER BY nombre";
$res_centros = pg_query($conexion, $sql_centros);
$centros_lista = [];
if ($res_centros) {
    while ($c = pg_fetch_assoc($res_centros)) { $centros_lista[] = $c; }
    pg_free_result($res_centros);
}
?>

<style>
    .estado-aprobado { color: #198754; font-weight: bold; }
    .estado-rechazado { color: #dc3545; font-weight: bold; }
    .text-muted { color: #888; }
</style>

<main class="app-main">
    <div class="app-content-header">
        <div class="container-fluid">
            <h1>Histórico de Redenciones</h1>
        </div>
    </div>

    <div class="app-content">
        <div class="container-fluid">
            
            <div class="card mb-4">
                <div class="card-body">
                    <form action="historico_redenciones.php" method="GET" class="row g-3 align-items-end">
                        
                        <div class="col-md-3">
                            <label for="anio_filtro" class="form-label">Año Decisión:</label>
                            <select class="form-select" id="anio_filtro" name="anio">
                                <option value="">Todos los Años</option>
                                <?php foreach($anios_lista as $anio_val): ?>
                                    <option value="<?php echo $anio_val; ?>" <?php echo $filtro_anio == $anio_val ? 'selected' : ''; ?>>
                                        <?php echo $anio_val; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-3">
                            <label for="estado_filtro" class="form-label">Estado:</label>
                            <select class="form-select" id="estado_filtro" name="estado">
                                <option value="">Todos los Estados</option>
                                <option value="Aprobado" <?php echo $filtro_estado == 'Aprobado' ? 'selected' : ''; ?>>Aprobadas</option>
                                <option value="Rechazado" <?php echo $filtro_estado == 'Rechazado' ? 'selected' : ''; ?>>Rechazadas</option>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <label for="centro_filtro" class="form-label">Centro:</label>
                            <select class="form-select" id="centro_filtro" name="centro">
                                <option value="">Todos los Centros</option>
                                <?php foreach($centros_lista as $c): ?>
                                    <option value="<?php echo $c['codigo']; ?>" <?php echo $filtro_centro == $c['codigo'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($c['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-3 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary me-2">Aplicar Filtros</button>
                            <a href="historico_redenciones.php" class="btn btn-outline-secondary" title="Limpiar Filtros"><i class="bi bi-x-lg"></i></a>
                        </div>
                    </form>
                </div>
            </div>

            <div class="text-end mb-3">
                <a href="generar_pdf_historico.php?<?php echo $query_string_pdf; ?>" target="_blank" class="btn btn-danger">
                    <i class="bi bi-file-earmark-pdf"></i> Imprimir Reporte Filtrado
                </a>
            </div>

            <div class="card">
                <div class="card-body p-0">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Privado</th>
                                <th>Documento</th>
                                <th>Fecha Solicitud</th>
                                <th>Días Contados</th>
                                <th>Estado</th>
                                <th>Fecha Decisión</th>
                                <th>Decidido Por</th>
                                <th>Nro. Aprobación</th>
                                <th>Juez</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($resultado && $total_filas > 0): ?>
                                <?php while($fila = pg_fetch_assoc($resultado)): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($fila['nombre'] . ' ' . $fila['apellido']); ?></td>
                                        <td><?php echo htmlspecialchars($fila['privado_documento']); ?></td>
                                        <td><?php echo date('d/m/Y', strtotime($fila['fecha_solicitud'])); ?></td>
                                        <td><?php echo htmlspecialchars($fila['dias_presente_contados']); ?></td>
                                        <td>
                                            <?php 
                                            $estado = htmlspecialchars($fila['estado']);
                                            $clase_estado = ($estado == 'Aprobado') ? 'estado-aprobado' : 'estado-rechazado';
                                            echo '<span class="' . $clase_estado . '">' . $estado . '</span>';
                                            ?>
                                        </td>
                                        <td><?php echo date('d/m/Y', strtotime($fila['fecha_decision'])); ?></td>
                                        <td><?php echo htmlspecialchars($fila['decidido_por_usuario']); ?></td>
                                        <td>
                                            <?php echo $fila['numero_aprobacion'] ? htmlspecialchars($fila['numero_aprobacion']) : '<span class="text-muted">N/A</span>'; ?>
                                        </td>
                                        <td>
                                            <?php echo $fila['juez_aprobacion'] ? htmlspecialchars($fila['juez_aprobacion']) : '<span class="text-muted">N/A</span>'; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                                <?php pg_free_result($resultado); ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="text-center">No hay solicitudes que coincidan con el filtro.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</main>

<?php 
if (isset($conexion)) { pg_close($conexion); } 
require_once 'footer.php'; 
?>