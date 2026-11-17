<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
require_once 'header.php';
require_once 'conexion.php';

// =======================================================
// 1. Lógica de Restricción de Acceso y Roles
// =======================================================
$rol_usuario = $_SESSION['cargo'] ?? $_SESSION['rol'] ?? 'Invitado';
$es_administrador = (strcasecmp($rol_usuario, 'Administradora') === 0 || strcasecmp($rol_usuario, 'Administrador') === 0);
$es_coordinador = (strcasecmp($rol_usuario, 'Coordinador Regional') === 0);

$cedula_cruda = $_SESSION['nombre_usuario'] ?? $_SESSION['cedula'] ?? '';
$cedula_sesion = preg_replace('/[^0-9]/', '', $cedula_cruda); // Limpieza flexible de la cédula

// =======================================================
// 2. Lógica de Filtros
// =======================================================
$filtro_centro = isset($_GET['centro']) ? $_GET['centro'] : null;
$filtro_area = isset($_GET['area']) ? $_GET['area'] : null;

// Consulta 1: Obtener la lista de centros para el filtro (Si es coordinador, se restringe a su centro)
$centros_lista = [];
if (!$es_coordinador) {
    $sql_centros = "SELECT codigo, nombre FROM centros ORDER BY nombre";
    $res_centros = pg_query($conexion, $sql_centros);
    if ($res_centros) {
        while ($f = pg_fetch_assoc($res_centros)) { $centros_lista[] = $f; }
        pg_free_result($res_centros);
    }
} else {
    // Si es coordinador, cargamos solo su centro asignado
    $sql_centros = "SELECT c.codigo, c.nombre 
                    FROM centros c 
                    JOIN usuario_centros uc ON c.codigo = uc.centro_codigo 
                    WHERE REPLACE(REPLACE(uc.usuario_cedula, 'V', ''), 'v', '') = $1";
    $res_centros = pg_query_params($conexion, $sql_centros, [$cedula_sesion]);
    if ($res_centros) {
        while ($f = pg_fetch_assoc($res_centros)) { $centros_lista[] = $f; }
        pg_free_result($res_centros);
    }
}

// Consulta 2: Obtener la lista de áreas para rellenar el filtro
$sql_areas_filtro = "SELECT codigo, nombre_area FROM areas_socioproductivas ORDER BY nombre_area";
$res_areas_filtro = pg_query($conexion, $sql_areas_filtro);
$areas_lista = [];
if ($res_areas_filtro) {
    while ($f = pg_fetch_assoc($res_areas_filtro)) { $areas_lista[] = $f; }
    pg_free_result($res_areas_filtro);
}

// Consulta 3: Principal, para el conteo de privados por área y centro (Dinámica con parámetros)
$sql_listado = "SELECT 
                    c.nombre AS nombre_centro, 
                    a.nombre_area, 
                    ca.capacidad_maxima,
                    COUNT(p.documento_identidad) AS total_privados,
                    c.codigo AS centro_codigo,
                    a.codigo AS area_codigo
                FROM centros_areas ca
                LEFT JOIN privados p ON ca.centro_codigo = p.centro_codigo AND ca.area_codigo = p.area_codigo
                LEFT JOIN centros c ON ca.centro_codigo = c.codigo
                LEFT JOIN areas_socioproductivas a ON ca.area_codigo = a.codigo";

$where_conditions = [];
$param_values = [];
$param_index = 1;

// Si es Coordinador Regional, unimos obligatoriamente con 'usuario_centros' para filtrar su recinto
if ($es_coordinador) {
    $sql_listado .= " JOIN usuario_centros uc ON ca.centro_codigo = uc.centro_codigo";
    $where_conditions[] = "REPLACE(REPLACE(uc.usuario_cedula, 'V', ''), 'v', '') = $" . $param_index;
    $param_values[] = $cedula_sesion;
    $param_index++;
}

$sql_listado .= " WHERE 1=1";

if (!empty($filtro_centro)) {
    $where_conditions[] = "c.codigo = $" . $param_index;
    $param_values[] = $filtro_centro;
    $param_index++;
}
if (!empty($filtro_area)) {
    $where_conditions[] = "a.codigo = $" . $param_index;
    $param_values[] = $filtro_area;
    $param_index++;
}

if (count($where_conditions) > 0) {
    $sql_listado .= " AND " . implode(" AND ", $where_conditions);
}

$sql_listado .= " GROUP BY c.nombre, a.nombre_area, ca.capacidad_maxima, c.codigo, a.codigo ORDER BY c.nombre, a.nombre_area";

// Ejecutar consulta principal de forma segura
$resultado = pg_query_params($conexion, $sql_listado, $param_values);
$total_filas = ($resultado) ? pg_num_rows($resultado) : 0;

// Crear la URL dinámica que contiene todos los filtros GET
$query_string_pdf = http_build_query($_GET);
?>

<style>
    /* Estilos para colorear la fila completa según la capacidad */
    tr.fila-peligro {
        --bs-table-bg: #f8d7da !important; /* ROJO */
        --bs-table-hover-bg: #f5c6cb !important; 
        font-weight: bold;
    }
    tr.fila-alerta {
        --bs-table-bg: #fff3cd !important; /* AMARILLO */
        --bs-table-hover-bg: #ffe69c !important; 
    }
    tr.fila-normal {
        --bs-table-bg: #d1e7dd !important; /* VERDE */
        --bs-table-hover-bg: #c1e1c7 !important;
    }

    .table-container-fixed {
        max-height: 70vh; 
        overflow-y: auto; 
    }
</style>

<main class="app-main">
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h3 class="mb-0">Control de Áreas Socioproductivas</h3>
                </div>
                <div class="col-md-4 text-md-end">
                    <?php if ($es_administrador): ?>
                    <a href="registro_area_base.php" class="btn btn-success me-2"> <i class="bi bi-plus-circle"></i> Registrar Nueva Área
                    </a>
                    <a href="areas_formulario.php" class="btn btn-warning text-dark"> <i class="bi bi-clipboard-plus"></i> Asignar Área a Centro
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <div class="app-content">
        <div class="container-fluid">
            
            <div class="card mb-4">
                <div class="card-body">
                    <form action="area_listar.php" method="GET" class="mb-4">
                        <div class="row g-3 align-items-end">
                            <!-- Si es coordinador, se muestra el centro fijo o bloqueado en su vista -->
                            <div class="col-md-5">
                                <label for="centro_filtro" class="form-label">Filtrar por Centro:</label>
                                <select class="form-select" id="centro_filtro" name="centro" <?php echo $es_coordinador ? '' : ''; ?>>
                                    <option value="">Todos los centros</option>
                                    <?php foreach ($centros_lista as $centro): ?>
                                        <option value="<?php echo htmlspecialchars($centro['codigo']); ?>" <?php echo $filtro_centro == $centro['codigo'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($centro['nombre']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-5">
                                <label for="area_filtro" class="form-label">Filtrar por Área:</label>
                                <select class="form-select" id="area_filtro" name="area">
                                    <option value="">Todas las áreas</option>
                                    <?php foreach ($areas_lista as $area): ?>
                                        <option value="<?php echo htmlspecialchars($area['codigo']); ?>" <?php echo $filtro_area == $area['codigo'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($area['nombre_area']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2 d-flex">
                                <button type="submit" class="btn btn-primary me-2">Aplicar Filtro</button>
                                <a href="area_listar.php" class="btn btn-outline-secondary" title="Limpiar Filtros"><i class="bi bi-x-lg"></i></a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="text-end mb-3">
                <a href="generar_pdf_capacidad.php?<?php echo $query_string_pdf; ?>" target="_blank" class="btn btn-danger">
                    <i class="bi bi-file-earmark-pdf"></i> Generar Listado PDF (Filtrado)
                </a>
            </div>

            <div class="card mb-4">
                <div class="card-body p-3">
                    <h5 class="mb-3 text-dark">🔴 <strong>Guía de Capacidad de Áreas</strong></h5>
                    <div class="row text-center">
                        <div class="col-md-4 mb-2 mb-md-0">
                            <div class="p-2 border rounded" style="background-color: #f8d7da;">
                                <strong class="text-danger">ROJO (PELIGRO)</strong> <br> 
                                <small><strong>OCUPACIÓN MÁXIMA</strong> (Capacidad 0 o sobrepasada).</small>
                            </div>
                        </div>
                        <div class="col-md-4 mb-2 mb-md-0">
                            <div class="p-2 border rounded" style="background-color: #fff3cd;">
                                <strong class="text-warning">AMARILLO (ALERTA)</strong> <br> 
                                <small><strong>CAPACIDAD BAJA</strong> (5 o menos cupos disponibles).</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="p-2 border rounded" style="background-color: #d1e7dd;"> <strong style="color: #0f5132;">VERDE (NORMAL)</strong> <br> 
                                <small><strong>ESPACIO SUFICIENTE</strong> (Más de 5 cupos disponibles).</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card">
                <div class="card-body">
                    <div class="table-container-fixed">
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped table-hover">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Centro de Reclusión</th>
                                        <th>Área Socioproductiva</th>
                                        <th>Capacidad Máxima</th>
                                        <th>Total de Privados</th>
                                        <th>Espacio Disponible</th>
                                        <th style="width: 150px;">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($resultado && $total_filas > 0): ?>
                                        <?php while ($fila = pg_fetch_assoc($resultado)): 
                                            $capacidad = isset($fila['capacidad_maxima']) ? (int)$fila['capacidad_maxima'] : 0;
                                            $privados = (int)$fila['total_privados'];
                                            // Cálculo correcto del espacio disponible (Capacidad menos privados actuales)
                                            $disponible = ($capacidad > 0) ? ($capacidad - $privados) : 'N/A';
                                            
                                            $clase_fila = 'fila-normal'; 
                                            $badge_class = 'bg-success'; 
                                            
                                            if ($disponible !== 'N/A' && $disponible <= 0) {
                                                $clase_fila = 'fila-peligro'; 
                                                $badge_class = 'bg-danger';
                                            } else if ($disponible !== 'N/A' && $disponible <= 5) {
                                                $clase_fila = 'fila-alerta'; 
                                                $badge_class = 'bg-warning text-dark';
                                            }
                                        ?>
                                            <tr class="<?php echo $clase_fila; ?>">
                                                <td><?php echo htmlspecialchars($fila['nombre_centro'] ?? 'Sin centro'); ?></td>
                                                <td><?php echo htmlspecialchars($fila['nombre_area'] ?? 'Sin área'); ?></td>
                                                <td><?php echo htmlspecialchars($capacidad); ?></td>
                                                <td><?php echo htmlspecialchars($privados); ?></td>
                                                <td><span class="badge <?php echo $badge_class; ?>"><?php echo htmlspecialchars($disponible); ?></span></td>
                                                
                                                <td>
                                                    <?php if ($es_administrador): ?>
                                                         <a href="areas_formulario.php?centro=<?php echo urlencode($fila['centro_codigo']); ?>&area=<?php echo urlencode($fila['area_codigo']); ?>" class="btn btn-sm btn-success me-1" title="Gestionar Capacidad">
                                                            <i class="bi bi-pencil-square"></i>
                                                         </a>
                                                    <?php endif; ?>
                                                    
                                                     <a href="generar_pdf_areas.php?centro=<?php echo urlencode($fila['centro_codigo']); ?>&area=<?php echo urlencode($fila['area_codigo']); ?>" target="_blank" class="btn btn-sm btn-danger" title="Generar PDF de Privados">
                                                        <i class="bi bi-file-earmark-pdf"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                        <?php pg_free_result($resultado); ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="6" class="text-center">No se encontraron registros que coincidan con los filtros.</td>
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
if (isset($conexion)) {
    pg_close($conexion);
}
require_once 'footer.php'; 
?>