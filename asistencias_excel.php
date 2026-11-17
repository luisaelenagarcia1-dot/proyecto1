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

// Obtener el centro del coordinador regional si aplica
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

// Recoger filtros de URL
$filtro_centro  = trim($_GET['centro_codigo'] ?? '');
$filtro_curso   = trim($_GET['curso_id'] ?? '');
$filtro_seccion = trim($_GET['seccion_id'] ?? '');
$filtro_anio    = trim($_GET['anio'] ?? '');

if ($es_coordinador) {
    $filtro_centro = $centro_usuario;
}

// Obtener subtítulo descriptivo si hay sección seleccionada
$subtitulo_texto = "Todas las Secciones";
if (!empty($filtro_seccion)) {
    $res_s = pg_query_params($conexion, "SELECT s.codigo_nomenclatura, c.nombre_curso FROM secciones_cursos s JOIN cursos c ON s.curso_id = c.id WHERE s.id = $1", array($filtro_seccion));
    if ($res_s && pg_num_rows($res_s) > 0) {
        $datos_sec = pg_fetch_assoc($res_s);
        $subtitulo_texto = "Sección: " . $datos_sec['codigo_nomenclatura'] . " - Curso: " . $datos_sec['nombre_curso'];
        pg_free_result($res_s);
    }
}

// Construir consulta SQL con los mismos filtros
$sql_asistencias = "SELECT ae.fecha, ie.privado_documento, p.nombre, p.apellido, 
                           s.codigo_nomenclatura, c.nombre_curso, cn.nombre as nombre_centro, ae.asistencia
                    FROM asistencia_educativa ae
                    JOIN inscripciones_educativas ie ON ae.inscripcion_id = ie.id
                    JOIN privados p ON ie.privado_documento = p.documento_identidad
                    JOIN secciones_cursos s ON ie.seccion_id = s.id
                    JOIN cursos c ON s.curso_id = c.id
                    LEFT JOIN centros cn ON s.centro_codigo = cn.codigo
                    WHERE 1=1";

$params = [];
$param_idx = 1;

if ($es_coordinador) {
    $sql_asistencias .= " AND s.centro_codigo = $" . $param_idx;
    $params[] = $centro_usuario;
    $param_idx++;

    if (!empty($filtro_seccion)) {
        $sql_asistencias .= " AND s.id = $" . $param_idx;
        $params[] = $filtro_seccion;
        $param_idx++;
    }
} else {
    if (!empty($filtro_centro)) {
        $sql_asistencias .= " AND s.centro_codigo = $" . $param_idx;
        $params[] = $filtro_centro;
        $param_idx++;
    }
}

if (!empty($filtro_curso)) {
    $sql_asistencias .= " AND c.id = $" . $param_idx;
    $params[] = $filtro_curso;
    $param_idx++;
}

if (!empty($filtro_anio)) {
    $sql_asistencias .= " AND EXTRACT(YEAR FROM ae.fecha) = $" . $param_idx;
    $params[] = $filtro_anio;
    $param_idx++;
}

$sql_asistencias .= " ORDER BY ae.fecha DESC, p.apellido ASC";
$res_asistencias = pg_query_params($conexion, $sql_asistencias, $params);

// Configurar cabeceras HTTP limpias para Excel estándar sin errores de apertura
$filename = "Reporte_Asistencias_" . date('Y-m-d') . ".xls";
header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

echo "\xEF\xBB\xBF"; // BOM para UTF-8
?>
<table border="1">
    <!-- Membrete Institucional Oficial con Letras Grandes y Claras -->
    <tr>
        <th colspan="7" style="background-color: #f8f9fa; color: #002060; font-size: 14pt; font-weight: bold; text-align: center; height: 32px;">
            REPÚBLICA BOLIVARIANA DE VENEZUELA
        </th>
    </tr>
    <tr>
        <th colspan="7" style="background-color: #f8f9fa; color: #333333; font-size: 12pt; font-weight: bold; text-align: center; height: 28px;">
            MINISTERIO DEL PODER POPULAR PARA EL SERVICIO PENITENCIARIO
        </th>
    </tr>
    <tr>
        <th colspan="7" style="background-color: #f8f9fa; color: #333333; font-size: 12pt; font-weight: bold; text-align: center; height: 28px;">
            INSTITUTO AUTÓNOMO CAJA DE TRABAJO PENITENCIARIO (IACTP)
        </th>
    </tr>
    <!-- Espacio separador -->
    <tr>
        <td colspan="7" style="border: none; height: 12px;"></td>
    </tr>
    <!-- Títulos del Reporte -->
    <tr>
        <th colspan="7" style="background-color: #002060; color: #ffffff; font-size: 14pt; font-weight: bold; text-align: center; height: 38px;">
            REPORTE GENERAL DE ASISTENCIAS EDUCATIVAS
        </th>
    </tr>
    <tr>
        <th colspan="7" style="background-color: #d9d9d9; color: #000000; font-size: 12pt; font-weight: bold; text-align: center; height: 30px;">
            <?php echo htmlspecialchars($subtitulo_texto); ?>
        </th>
    </tr>
    <!-- Espacio separador -->
    <tr>
        <td colspan="7" style="border: none; height: 10px;"></td>
    </tr>
    <!-- Cabecera de la Tabla de Datos -->
    <tr style="background-color: #f2f2f2; font-size: 11pt; font-weight: bold; text-align: center; height: 32px;">
        <th>Fecha</th>
        <th>Cédula / Documento</th>
        <th>Apellidos y Nombres</th>
        <th>Curso</th>
        <th>Sección</th>
        <th>Centro Penitenciario</th>
        <th>Estado Asistencia</th>
    </tr>
    <?php if ($res_asistencias && pg_num_rows($res_asistencias) > 0): ?>
        <?php while ($row = pg_fetch_assoc($res_asistencias)): 
            $cedula_limpia = preg_replace('/^(V-|v-|V|v)/', '', trim($row['privado_documento']));
        ?>
            <tr style="font-size: 11pt; height: 24px;">
                <td style="text-align: center;"><?php echo date('d/m/Y', strtotime($row['fecha'])); ?></td>
                <td style="text-align: center; mso-number-format:'\@';">V-<?php echo htmlspecialchars($cedula_limpia); ?></td>
                <td><?php echo htmlspecialchars($row['apellido'] . ', ' . $row['nombre']); ?></td>
                <td><?php echo htmlspecialchars($row['nombre_curso']); ?></td>
                <td style="text-align: center;"><?php echo htmlspecialchars($row['codigo_nomenclatura']); ?></td>
                <td><?php echo htmlspecialchars($row['nombre_centro'] ?? 'N/A'); ?></td>
                <td style="text-align: center; font-weight: bold;"><?php echo htmlspecialchars($row['asistencia']); ?></td>
            </tr>
        <?php endwhile; ?>
    <?php else: ?>
        <tr>
            <td colspan="7" style="text-align: center; color: red; font-size: 11pt; font-weight: bold; height: 35px;">No se encontraron registros de asistencia con los filtros seleccionados.</td>
        </tr>
    <?php endif; ?>
</table>

<?php
if (isset($conexion)) { pg_close($conexion); }
exit();
?>