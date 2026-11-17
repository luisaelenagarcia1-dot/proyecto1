<?php
session_start();
require_once 'conexion.php';

// Validar sesión activa
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: login.php");
    exit();
}

// Obtener datos del usuario logueado y limpiar la cédula
$rol_usuario     = $_SESSION['cargo'] ?? $_SESSION['rol'] ?? 'Gerente';
$es_coordinador  = (strcasecmp($rol_usuario, 'Coordinador Regional') === 0);

$cedula_cruda    = $_SESSION['nombre_usuario'] ?? $_SESSION['cedula'] ?? '';
$cedula_sesion   = preg_replace('/[^0-9]/', '', $cedula_cruda);

// Capturar los valores de los filtros enviados por la URL
$busqueda        = trim($_GET['busqueda'] ?? '');
$seccion_filtro  = trim($_GET['seccion_id'] ?? '');
$anio_filtro     = trim($_GET['anio'] ?? '');
$centro_filtro   = trim($_GET['centro_codigo'] ?? '');

// Si es coordinador regional, forzar su centro asignado
if ($es_coordinador) {
    $sql_c_coord = "SELECT centro_codigo FROM usuario_centros WHERE REPLACE(REPLACE(usuario_cedula, 'V', ''), 'v', '') = $1 LIMIT 1";
    $res_c_coord = pg_query_params($conexion, $sql_c_coord, array($cedula_sesion));
    if ($res_c_coord && pg_num_rows($res_c_coord) > 0) {
        $row_cc = pg_fetch_assoc($res_c_coord);
        $centro_filtro = trim($row_cc['centro_codigo']);
    }
}

// -------------------------------------------------------------
// CONSTRUIR DINÁMICAMENTE LOS DETALLES DE LOS FILTROS PARA EL TÍTULO
// -------------------------------------------------------------
$detalles_filtros = [];

// 1. Filtro de Centro
if (!empty($centro_filtro)) {
    $res_nom_centro = pg_query_params($conexion, "SELECT nombre FROM centros WHERE codigo = $1", array($centro_filtro));
    if ($res_nom_centro && pg_num_rows($res_nom_centro) > 0) {
        $row_nc = pg_fetch_assoc($res_nom_centro);
        $detalles_filtros[] = "Centro: " . $row_nc['nombre'];
    }
} else {
    $detalles_filtros[] = "Centro: Todos";
}

// 2. Filtro de Sección
if (!empty($seccion_filtro)) {
    $res_nom_sec = pg_query_params($conexion, "SELECT s.codigo_nomenclatura, c.nombre_curso FROM secciones_cursos s JOIN cursos c ON s.curso_id = c.id WHERE s.id = $1", array($seccion_filtro));
    if ($res_nom_sec && pg_num_rows($res_nom_sec) > 0) {
        $row_ns = pg_fetch_assoc($res_nom_sec);
        $detalles_filtros[] = "Sección: " . $row_ns['codigo_nomenclatura'] . " (" . $row_ns['nombre_curso'] . ")";
    }
}

// 3. Filtro de Año
if (!empty($anio_filtro)) {
    $detalles_filtros[] = "Año: " . $anio_filtro;
}

// 4. Filtro de Búsqueda por texto
if (!empty($busqueda)) {
    $detalles_filtros[] = "Búsqueda: \"" . $busqueda . "\"";
}

$texto_filtros_activos = "Filtros aplicados — " . implode(" | ", $detalles_filtros);

// -------------------------------------------------------------
// CONSTRUIR LA CONSULTA SQL RESPETANDO LOS FILTROS
// -------------------------------------------------------------
$sql = "SELECT i.*, p.nombre, p.apellido, p.documento_identidad, p.correo, 
               s.codigo_nomenclatura, c.nombre_curso, cn.nombre as nombre_centro
        FROM inscripciones_educativas i
        JOIN privados p ON i.privado_documento = p.documento_identidad
        JOIN secciones_cursos s ON i.seccion_id = s.id
        JOIN cursos c ON s.curso_id = c.id
        LEFT JOIN centros cn ON s.centro_codigo = cn.codigo";

$params = [];
$where_conditions = [];
$param_index = 1;

if ($es_coordinador) {
    $sql .= " JOIN usuario_centros uc ON s.centro_codigo = uc.centro_codigo";
    $where_conditions[] = "REPLACE(REPLACE(uc.usuario_cedula, 'V', ''), 'v', '') = $" . $param_index;
    $params[] = $cedula_sesion;
    $param_index++;
} else {
    if (!empty($centro_filtro)) {
        $where_conditions[] = "s.centro_codigo = $" . $param_index;
        $params[] = $centro_filtro;
        $param_index++;
    }
}

if (!empty($busqueda)) {
    $where_conditions[] = "(p.documento_identidad ILIKE $" . $param_index . " OR p.nombre ILIKE $" . $param_index . " OR p.apellido ILIKE $" . $param_index . ")";
    $params[] = '%' . $busqueda . '%';
    $param_index++;
}

if (!empty($seccion_filtro)) {
    $where_conditions[] = "i.seccion_id = $" . $param_index;
    $params[] = $seccion_filtro;
    $param_index++;
}

if (!empty($anio_filtro)) {
    $where_conditions[] = "EXTRACT(YEAR FROM i.fecha_inscripcion) = $" . $param_index;
    $params[] = $anio_filtro;
    $param_index++;
}

if (count($where_conditions) > 0) {
    $sql .= " WHERE " . implode(" AND ", $where_conditions);
}

$sql .= " ORDER BY i.id DESC";
$resultado = ($params) ? pg_query_params($conexion, $sql, $params) : pg_query($conexion, $sql);

// Configurar cabeceras HTTP limpias para la descarga del archivo Excel
$filename = "Reporte_Inscripciones_" . date('Y-m-d') . ".xls";
header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

echo "\xEF\xBB\xBF"; // BOM para UTF-8 correcto en Excel
?>
<table border="1" style="font-family: Arial, sans-serif;">
    <!-- Membrete Institucional con Letras Grandes y Claras -->
    <tr>
        <th colspan="8" style="background-color: #f8f9fa; color: #002060; font-size: 14pt; font-weight: bold; text-align: center; height: 32px; font-family: Arial, sans-serif;">
            REPÚBLICA BOLIVARIANA DE VENEZUELA
        </th>
    </tr>
    <tr>
        <th colspan="8" style="background-color: #f8f9fa; color: #333333; font-size: 12pt; font-weight: bold; text-align: center; height: 28px; font-family: Arial, sans-serif;">
            MINISTERIO DEL PODER POPULAR PARA EL SERVICIO PENITENCIARIO
        </th>
    </tr>
    <tr>
        <th colspan="8" style="background-color: #f8f9fa; color: #333333; font-size: 12pt; font-weight: bold; text-align: center; height: 28px; font-family: Arial, sans-serif;">
            INSTITUTO AUTÓNOMO CAJA DE TRABAJO PENITENCIARIO (IACTP)
        </th>
    </tr>
    <!-- Espacio separador -->
    <tr>
        <td colspan="8" style="border: none; height: 12px;"></td>
    </tr>
    <!-- Título Principal del Reporte -->
    <tr>
        <th colspan="8" style="background-color: #002060; color: #ffffff; font-size: 14pt; font-weight: bold; text-align: center; height: 38px; font-family: Arial, sans-serif;">
            REPORTE GENERAL DE INSCRIPCIONES EDUCATIVAS
        </th>
    </tr>
    <!-- Subtítulo Dinámico con los Filtros Activos -->
    <tr>
        <th colspan="8" style="background-color: #d9d9d9; color: #000000; font-size: 11pt; font-weight: bold; text-align: center; height: 30px; font-family: Arial, sans-serif;">
            <?php echo htmlspecialchars($texto_filtros_activos); ?>
        </th>
    </tr>
    <!-- Espacio separador -->
    <tr>
        <td colspan="8" style="border: none; height: 10px;"></td>
    </tr>
    <!-- Cabecera de la Tabla de Datos con Anchos Definidos -->
    <tr style="background-color: #f2f2f2; font-size: 11pt; font-weight: bold; text-align: center; height: 32px; font-family: Arial, sans-serif;">
        <th style="width: 50px; font-family: Arial, sans-serif;">N°</th>
        <th style="width: 130px; font-family: Arial, sans-serif;">Cédula / Documento</th>
        <th style="width: 220px; font-family: Arial, sans-serif;">Apellidos y Nombres</th>
        <th style="width: 200px; font-family: Arial, sans-serif;">Correo Electrónico</th>
        <th style="width: 160px; font-family: Arial, sans-serif;">Sección / Nomenclatura</th>
        <th style="width: 180px; font-family: Arial, sans-serif;">Curso</th>
        <th style="width: 280px; font-family: Arial, sans-serif;">Centro Penitenciario</th>
        <th style="width: 110px; font-family: Arial, sans-serif;">Estado</th>
    </tr>
    <?php if ($resultado && pg_num_rows($resultado) > 0): ?>
        <?php 
        $i = 1;
        while ($row = pg_fetch_assoc($resultado)): 
            $cedula_limpia = preg_replace('/^(V-|v-|V|v)/', '', trim($row['privado_documento']));
        ?>
            <tr style="font-size: 11pt; height: 24px; font-family: Arial, sans-serif;">
                <td style="text-align: center; font-weight: bold; font-family: Arial, sans-serif;"><?php echo $i++; ?></td>
                <td style="text-align: center; mso-number-format:'\@'; font-family: Arial, sans-serif;">V-<?php echo htmlspecialchars($cedula_limpia); ?></td>
                <td style="font-family: Arial, sans-serif;"><?php echo htmlspecialchars($row['apellido'] . ', ' . $row['nombre']); ?></td>
                <td style="font-family: Arial, sans-serif;"><?php echo htmlspecialchars($row['correo'] ?? 'No registrado'); ?></td>
                <td style="text-align: center; font-family: Arial, sans-serif;"><?php echo htmlspecialchars($row['codigo_nomenclatura']); ?></td>
                <td style="font-family: Arial, sans-serif;"><?php echo htmlspecialchars($row['nombre_curso']); ?></td>
                <td style="font-family: Arial, sans-serif;"><?php echo htmlspecialchars($row['nombre_centro'] ?? 'N/A'); ?></td>
                <td style="text-align: center; font-weight: bold; font-family: Arial, sans-serif;"><?php echo htmlspecialchars($row['estado_cursante']); ?></td>
            </tr>
        <?php endwhile; ?>
    <?php else: ?>
        <tr>
            <td colspan="8" style="text-align: center; color: red; font-size: 11pt; font-weight: bold; height: 35px; font-family: Arial, sans-serif;">No se encontraron registros de inscripciones con los filtros seleccionados.</td>
        </tr>
    <?php endif; ?>
</table>

<?php
if (isset($conexion)) { pg_close($conexion); }
exit();
?>