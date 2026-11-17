<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
require_once 'header.php';
require_once 'conexion.php';

// Obtener rol y datos de sesión para validaciones
$rolUsuario = $_SESSION['cargo'] ?? $_SESSION['rol'] ?? '';
$es_coordinador = (strcasecmp($rolUsuario, 'Coordinador Regional') === 0);
$cedula_cruda = $_SESSION['nombre_usuario'] ?? $_SESSION['cedula'] ?? '';
$cedula_sesion = preg_replace('/[^0-9]/', '', $cedula_cruda); 

// Obtener y Limpiar Filtros
$busqueda = $_GET['busqueda'] ?? '';
$filtro_estado = $_GET['estado'] ?? ''; 
$filtro_anio_detencion = $_GET['anio_detencion'] ?? '';
$filtro_anio_condena = $_GET['anio_condena'] ?? '';
$filtro_anio_registro = $_GET['anio_registro'] ?? ''; 

// Consulta base uniendo con la tabla de centros si es Coordinador Regional para filtrar por su recinto
$sql = "SELECT DISTINCT p.documento_identidad, p.nombre, p.apellido, p.fecha_detencion, p.fecha_registro,
            c.nombre AS nombre_centro, 
            a.nombre_area AS nombre_area,
            cj.numero_expediente, 
            cj.fecha_condena, 
            cj.duracion_condena_dias,
            ci.nombre AS ciudad_nacimiento,
            e.nombre AS estado_nacimiento
        FROM privados p
        LEFT JOIN centros c ON p.centro_codigo = c.codigo
        LEFT JOIN areas_socioproductivas a ON p.area_codigo = a.codigo
        LEFT JOIN privados_casos pc ON p.documento_identidad = pc.privado_documento
        LEFT JOIN casos_judiciales cj ON pc.caso_expediente = cj.numero_expediente
        LEFT JOIN loc_ciudades ci ON p.ciudad_nacimiento_id = ci.id
        LEFT JOIN loc_estados e ON ci.id_estado = e.id";

$condiciones = []; 
$params = [];
$param_index = 1;

// Si es Coordinador Regional, filtramos estrictamente por los centros que tiene asignados en 'usuario_centros'
if ($es_coordinador) {
    $sql .= " JOIN usuario_centros uc ON p.centro_codigo = uc.centro_codigo";
    $condiciones[] = "uc.usuario_cedula = $" . $param_index;
    $params[] = $cedula_sesion;
    $param_index++;
}

// Aplicar Filtros dinámicos de manera segura con PostgreSQL
if (!empty($busqueda)) {
    $condiciones[] = "(p.documento_identidad ILIKE $" . $param_index . " 
               OR p.nombre ILIKE $" . $param_index . " 
               OR p.apellido ILIKE $" . $param_index . "
               OR cj.numero_expediente ILIKE $" . $param_index . "
               OR c.nombre ILIKE $" . $param_index . "
               OR a.nombre_area ILIKE $" . $param_index . "
               OR CAST(p.fecha_detencion AS TEXT) ILIKE $" . $param_index . ")";
    $params[] = "%" . $busqueda . "%";
    $param_index++;
}

if (!empty($filtro_anio_detencion)) {
    $condiciones[] = "EXTRACT(YEAR FROM p.fecha_detencion) = $" . $param_index;
    $params[] = (int)$filtro_anio_detencion;
    $param_index++;
}

if (!empty($filtro_anio_condena)) {
    $condiciones[] = "EXTRACT(YEAR FROM cj.fecha_condena) = $" . $param_index;
    $params[] = (int)$filtro_anio_condena;
    $param_index++;
}

if (!empty($filtro_anio_registro)) {
    $condiciones[] = "EXTRACT(YEAR FROM p.fecha_registro) = $" . $param_index; 
    $params[] = (int)$filtro_anio_registro;
    $param_index++;
}

if ($filtro_estado === 'candidato') {
    $condiciones[] = "(p.fecha_detencion IS NOT NULL AND cj.duracion_condena_dias > 0 AND cj.numero_expediente IS NOT NULL AND p.centro_codigo IS NOT NULL AND EXTRACT(DAY FROM (NOW() - p.fecha_detencion)) >= (cj.duracion_condena_dias / 2))";
} elseif ($filtro_estado === 'incompleto') {
    $condiciones[] = "(p.fecha_detencion IS NULL OR cj.duracion_condena_dias IS NULL OR cj.duracion_condena_dias = 0 OR cj.numero_expediente IS NULL OR p.centro_codigo IS NULL)";
} elseif ($filtro_estado === 'regular') {
    $condiciones[] = "(p.fecha_detencion IS NOT NULL AND cj.duracion_condena_dias > 0 AND cj.numero_expediente IS NOT NULL AND p.centro_codigo IS NOT NULL AND EXTRACT(DAY FROM (NOW() - p.fecha_detencion)) < (cj.duracion_condena_dias / 2))";
}

if (count($condiciones) > 0) {
    $sql .= " WHERE " . implode(' AND ', $condiciones);
}

$sql .= " ORDER BY p.apellido, p.nombre";

// Ejecución segura de la consulta en PostgreSQL
$resultado = pg_query_params($conexion, $sql, $params);

if ($resultado === false) {
    die("Error en la consulta SQL: " . pg_last_error($conexion));
}

$total_registros_filtrados = pg_num_rows($resultado); 
$query_string_pdf = http_build_query($_GET); 
?>

<style>
    /* Estilo para el scroll de la tabla */
    .scroll-container { max-height: 450px; overflow-y: auto; border: 1px solid #dee2e6; }
    .scroll-container table thead th { position: sticky; top: 0; z-index: 1; background-color: #343a40; color: white; }
    
    /* Filas Coloreadas */
    tr.fila-candidato { 
        --bs-table-bg: #d1e7dd; 
        --bs-table-striped-bg: #d1e7dd; 
        --bs-table-hover-bg: #c1e1c7; 
        color: #0f5132; 
    } 
    tr.fila-regular { 
        --bs-table-bg: #fffacd; 
        --bs-table-striped-bg: #fffacd; 
        --bs-table-hover-bg: #fff0b3; 
        color: #664d03; 
    } 
    tr.fila-incompleta { 
        --bs-table-bg: #f8d7da; 
        --bs-table-striped-bg: #f8d7da; 
        --bs-table-hover-bg: #f1aeb5; 
        color: #58151c; 
    } 
    tr.fila-incompleta:hover td, tr.fila-incompleta:hover th { 
        color: #000; 
    }
    .form-filtros { background-color: #f8f9fa; padding: 1.5rem; border-radius: 0.5rem; border: 1px solid #dee2e6; }
    .leyenda-item { padding: 0.75rem 0.5rem; border-radius: 0.25rem; margin-bottom: 0.5rem; }
</style>

<main class="app-main">
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h3 class="mb-0">Listado de Privados de Libertad</h3>
                </div>
                <div class="col-md-6 text-md-end">
                    <a href="generar_pdf_lista.php?<?php echo $query_string_pdf; ?>" target="_blank" class="btn btn-danger me-2">
                        <i class="bi bi-file-earmark-pdf"></i> Lista PDF Filtrada
                    </a>
                    <?php if (!$es_coordinador): ?>
                        <a href="privados_formulario.php" class="btn btn-primary">Registrar Nuevo</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="app-content">
        <div class="container-fluid">
            
            <div class="card mb-4">
                <div class="card-body">
                    <form method="GET" action="privados_listar.php" class="form-filtros">
                        <div class="row g-3">
                            <div class="col-md-12">
                                <label for="busqueda" class="form-label fw-bold">Búsqueda General (Documento, Nombre, Expediente...)</label>
                                <input type="text" class="form-control" name="busqueda" id="busqueda" value="<?php echo htmlspecialchars($busqueda); ?>">
                            </div>
                            
                            <div class="col-md-3">
                                <label for="anio_detencion" class="form-label fw-bold">Año Detención</label>
                                <input type="number" class="form-control" name="anio_detencion" id="anio_detencion" placeholder="Detención" value="<?php echo htmlspecialchars($filtro_anio_detencion); ?>" min="1990" max="<?php echo date('Y'); ?>">
                            </div>
                            <div class="col-md-3">
                                <label for="anio_condena" class="form-label fw-bold">Año Condena</label>
                                <input type="number" class="form-control" name="anio_condena" id="anio_condena" placeholder="Condena" value="<?php echo htmlspecialchars($filtro_anio_condena); ?>" min="1990" max="<?php echo date('Y'); ?>">
                            </div>
                            <div class="col-md-3">
                                <label for="anio_registro" class="form-label fw-bold">Año Registro</label>
                                <input type="number" class="form-control" name="anio_registro" id="anio_registro" placeholder="Registro" value="<?php echo htmlspecialchars($filtro_anio_registro); ?>" min="1990" max="<?php echo date('Y'); ?>">
                            </div>

                            <div class="col-md-3">
                                <label for="estado" class="form-label fw-bold">Filtros de Estado</label>
                                <select name="estado" id="estado" class="form-select">
                                    <option value="">-- Listado General --</option>
                                    <option value="regular" <?php echo ($filtro_estado == 'regular') ? 'selected' : ''; ?>>Falta 50% de condena</option>
                                    <option value="candidato" <?php echo ($filtro_estado == 'candidato') ? 'selected' : ''; ?>>Candidatos a Beneficio</option>
                                    <option value="incompleto" <?php echo ($filtro_estado == 'incompleto') ? 'selected' : ''; ?>>Registros Incompletos</option>
                                </select>
                            </div>

                            <div class="col-md-12 text-end">
                                <button class="btn btn-primary me-2" type="submit">Aplicar Filtros</button>
                                <a href="privados_listar.php" class="btn btn-outline-danger" title="Limpiar filtros"><i class="bi bi-x-lg"></i> Limpiar</a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-body p-3">
                    <h5 class="mb-3 text-dark">🚦 **Guía de Alerta Visual** (Significado de Colores)</h5>
                    <div class="row text-center">
                        <div class="col-md-4 mb-2 mb-md-0">
                            <div class="leyenda-item border" style="background-color: #f8d7da;"> <strong class="text-danger">Rojo</strong> <br> 
                                <small>⚠️ **Registro Incompleto/Inválido**</small>
                            </div>
                        </div>
                        <div class="col-md-4 mb-2 mb-md-0">
                            <div class="leyenda-item border" style="background-color: #d1e7dd;">
                                <strong style="color: #0f5132;">VERDE</strong> <br> 
                                <small>✅ **Candidato a Redención**</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="leyenda-item border" style="background-color: #fffacd;">
                                <strong class="text-dark">AMARILLO</strong> <br> 
                                <small>**Registro Regular** (Menos del 50% Condena)</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="card">
                <div class="card-body">
                    <div class="mb-3 text-secondary">
                        Mostrando **<?php echo $total_registros_filtrados; ?>** registros encontrados.
                    </div>
                    
                    <div class="scroll-container"> 
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped table-hover mb-0">
                                <thead class="table-dark">
                                    <tr>
                                        <th>N°</th>
                                        <th>Documento</th>
                                        <th>Nombre Completo</th>
                                        <th>Expediente</th>
                                        <th>Centro de Reclusión</th>
                                        <th>Área Socioproductiva</th>
                                        <th>Fecha Detención</th>
                                        <th>Fecha Condena</th>
                                        <th>Tiempo Condena</th>
                                        <th>Tiempo Restante</th>
                                        <th style="width: 120px;">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($total_registros_filtrados > 0): ?>
                                        <?php 
                                        $contador = 1;
                                        $fechaHoy_obj = new DateTime();
                                        
                                        while ($privado = pg_fetch_assoc($resultado)): 
                                            
                                            $clase_fila = 'fila-regular'; 
                                            $datos_completos = true;
                                            
                                            if (empty($privado['fecha_detencion']) || empty($privado['duracion_condena_dias']) || (int)$privado['duracion_condena_dias'] == 0 || empty($privado['numero_expediente']) || empty($privado['nombre_centro'])) {
                                                $datos_completos = false;
                                                $clase_fila = 'fila-incompleta';
                                            }
                                            
                                            if ($datos_completos) {
                                                try {
                                                    $totalDiasCondena = (int)$privado['duracion_condena_dias'];
                                                    $fechaDetencion = new DateTime($privado['fecha_detencion']);
                                                    $diasServidos = $fechaHoy_obj->diff($fechaDetencion)->days;
                                                    $diasMitadCondena = $totalDiasCondena / 2;
                                                    
                                                    if ($diasServidos >= $diasMitadCondena) {
                                                        $clase_fila = 'fila-candidato'; 
                                                    } else {
                                                        $clase_fila = 'fila-regular'; 
                                                    }
                                                } catch (Exception $e) { 
                                                    $clase_fila = 'fila-incompleta'; 
                                                }
                                            }
                                            
                                            $totalDiasCondena = $privado['duracion_condena_dias'] ?? 0;
                                            $aniosCondena = floor($totalDiasCondena / 365);
                                            $mesesCondena = floor(($totalDiasCondena % 365) / 30);
                                            $diasCondena = ($totalDiasCondena % 365) % 30;
                                            $tiempoCondenaStr = "{$aniosCondena}a, {$mesesCondena}m, {$diasCondena}d";
                                            $tiempoRestanteStr = 'N/A';
                                            
                                            if (!empty($privado['fecha_detencion']) && $totalDiasCondena > 0) {
                                                $fechaDetencion = new DateTime($privado['fecha_detencion']);
                                                $fechaFinCondena = (clone $fechaDetencion)->modify("+$totalDiasCondena days");
                                                
                                                if ($fechaHoy_obj >= $fechaFinCondena) {
                                                    $tiempoRestanteStr = '<span class="badge bg-success">Cumplida</span>';
                                                } else {
                                                    $intervalo = $fechaHoy_obj->diff($fechaFinCondena);
                                                    $tiempoRestanteStr = $intervalo->format('%ya, %mm, %dd');
                                                }
                                            }
                                        ?>
                                        
                                            <tr class="<?php echo $clase_fila; ?>">
                                                <td><?php echo $contador++; ?></td>
                                                <td><?php echo htmlspecialchars($privado['documento_identidad']); ?></td>
                                                <td><?php echo htmlspecialchars($privado['apellido'] . ', ' . $privado['nombre']); ?></td>
                                                <td><?php echo htmlspecialchars($privado['numero_expediente'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($privado['nombre_centro'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($privado['nombre_area'] ?? 'Sin asignar'); ?></td>
                                                <td><?php echo !empty($privado['fecha_detencion']) ? date("d/m/Y", strtotime($privado['fecha_detencion'])) : 'N/A'; ?></td>
                                                <td><?php echo !empty($privado['fecha_condena']) ? date("d/m/Y", strtotime($privado['fecha_condena'])) : 'N/A'; ?></td>
                                                <td><?php echo $tiempoCondenaStr; ?></td>
                                                <td><?php echo $tiempoRestanteStr; ?></td>
                                                <td>
                                                    <?php if (!$es_coordinador): ?>
                                                        <!-- El Administrador puede gestionar/editar -->
                                                        <a href="privados_gestionar.php?documento=<?php echo urlencode($privado['documento_identidad']); ?>" class="btn btn-sm btn-warning" title="Gestionar">
                                                            <i class="bi bi-pencil-square"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                    
                                                    <!-- Todos (incluyendo Coordinador) pueden ver la ficha en PDF -->
                                                    <a href="generar_pdf_ficha.php?documento=<?php echo urlencode($privado['documento_identidad']); ?>" target="_blank" class="btn btn-sm btn-info" title="Ficha PDF">
                                                        <i class="bi bi-file-person"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="11" class="text-center">No se encontraron registros con esos filtros.</td>
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
</main>

<?php 
if (isset($resultado)) {
    pg_free_result($resultado);
}
pg_close($conexion);
require_once 'footer.php'; 
?>