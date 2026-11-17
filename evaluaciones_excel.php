<?php
session_start();
require_once 'conexion.php';

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: login.php');
    exit;
}

$usuario_cedula = $_SESSION['nombre_usuario'] ?? '';
$rolUsuario = $_SESSION['cargo'] ?? '';
$es_administrador = ($rolUsuario === 'Administradora' || $rolUsuario === 'Administrador');
$es_coordinador   = (strcasecmp($rolUsuario, 'Coordinador Regional') === 0);

$centro_usuario = '';
if ($es_coordinador) {
    $query_c = "SELECT centro_codigo FROM usuario_centros WHERE usuario_cedula = $1 LIMIT 1";
    $res_c = pg_query_params($conexion, $query_c, array($usuario_cedula));
    if ($res_c && $row_c = pg_fetch_assoc($res_c)) { 
        $centro_usuario = $row_c['centro_codigo']; 
    } else {
        $centro_usuario = 'TIGRE01';
    }
}

// Capturar filtros
$centro_seleccionado = $_GET['centro_codigo'] ?? ($es_coordinador ? $centro_usuario : '');
$seccion_seleccionada = $_GET['seccion_id'] ?? null;
$filtro_estatus = $_GET['filtro_estatus'] ?? 'TODOS';
$busqueda_texto = trim($_GET['busqueda_texto'] ?? '');

// Generar texto descriptivo de los filtros aplicados para el membrete
$detalles_filtros = [];
if (!empty($centro_seleccionado)) {
    $res_nc = pg_query_params($conexion, "SELECT nombre FROM centros WHERE codigo = $1", array($centro_seleccionado));
    if ($res_nc && $row_nc = pg_fetch_assoc($res_nc)) {
        $detalles_filtros[] = "Centro: " . $row_nc['nombre'];
    }
} else {
    $detalles_filtros[] = "Centro: Todos";
}

if (!empty($seccion_seleccionada)) {
    $res_ns = pg_query_params($conexion, "SELECT s.codigo_nomenclatura, c.nombre_curso FROM secciones_cursos s JOIN cursos c ON s.curso_id = c.id WHERE s.id = $1", array($seccion_seleccionada));
    if ($res_ns && $row_ns = pg_fetch_assoc($res_ns)) {
        $detalles_filtros[] = "Sección: " . $row_ns['codigo_nomenclatura'] . " (" . $row_ns['nombre_curso'] . ")";
    }
}

if ($filtro_estatus !== 'TODOS') {
    $detalles_filtros[] = "Estatus: " . $filtro_estatus;
}

if (!empty($busqueda_texto)) {
    $detalles_filtros[] = "Búsqueda: \"" . $busqueda_texto . "\"";
}

$texto_filtros_activos = "Filtros aplicados — " . implode(" | ", $detalles_filtros);

// Consulta de estudiantes con nombres completos de centros
$query_estudiantes = "
    SELECT 
        ie.id AS inscripcion_id,
        p.documento_identidad,
        p.nombre,
        p.apellido,
        sc.codigo_nomenclatura,
        c.nombre_curso,
        sc.centro_codigo,
        cn.nombre AS nombre_centro,
        ie.nota_eval_1,
        ie.nota_eval_2,
        ie.nota_eval_3,
        ie.evaluacion_practica,
        ie.calificacion_final,
        ie.estado_aprobacion,
        ie.estatus_sobresaliente,
        COALESCE(
            (
                SELECT ROUND((SUM(CASE WHEN a.asistencia = 'Presente' THEN 1 ELSE 0 END)::numeric / NULLIF(COUNT(a.id), 0)) * 100, 2)
                FROM asistencia_educativa a
                WHERE a.inscripcion_id = ie.id
            ), 0.00
        ) AS porcentaje_asistencia_calculado
    FROM inscripciones_educativas ie
    JOIN privados p ON ie.privado_documento = p.documento_identidad
    JOIN secciones_cursos sc ON ie.seccion_id = sc.id
    JOIN cursos c ON sc.curso_id = c.id
    LEFT JOIN centros cn ON sc.centro_codigo = cn.codigo
    WHERE 1=1
";

$params_est = array();
$param_index = 1;

if (!empty($seccion_seleccionada)) {
    $query_estudiantes .= " AND ie.seccion_id = $" . $param_index;
    $params_est[] = $seccion_seleccionada;
    $param_index++;
} elseif ($es_coordinador) {
    $query_estudiantes .= " AND sc.centro_codigo = $" . $param_index;
    $params_est[] = $centro_usuario;
    $param_index++;
} elseif ($es_administrador && !empty($centro_seleccionado)) {
    $query_estudiantes .= " AND sc.centro_codigo = $" . $param_index;
    $params_est[] = $centro_seleccionado;
    $param_index++;
}

if (!empty($busqueda_texto)) {
    $query_estudiantes .= " AND (p.documento_identidad ILIKE $" . $param_index . " OR p.nombre ILIKE $" . $param_index . " OR p.apellido ILIKE $" . $param_index . ")";
    $params_est[] = "%" . $busqueda_texto . "%";
    $param_index++;
}

if ($filtro_estatus === 'APROBADO') {
    $query_estudiantes .= " AND ie.estado_aprobacion = 'APROBADO'";
} elseif ($filtro_estatus === 'REPROBADO') {
    $query_estudiantes .= " AND (ie.estado_aprobacion = 'REPROBADO' OR ie.estado_aprobacion = 'REPROBADO_FALTAS')";
} elseif ($filtro_estatus === 'SOBRESALIENTE') {
    $query_estudiantes .= " AND ie.estatus_sobresaliente = 't'";
}

$query_estudiantes .= " ORDER BY p.apellido ASC";
$resultado = pg_query_params($conexion, $query_estudiantes, $params_est);

// Cabeceras HTTP para descarga de Excel (.xls)
$filename = "Consolidado_Calificaciones_" . date('Y-m-d') . ".xls";
header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

echo "\xEF\xBB\xBF"; // BOM para acentos en Excel
?>
<table border="1" style="font-family: Arial, sans-serif;">
    <tr>
        <th colspan="11" style="background-color: #f8f9fa; color: #002060; font-size: 14pt; font-weight: bold; text-align: center; height: 32px; font-family: Arial, sans-serif;">
            REPÚBLICA BOLIVARIANA DE VENEZUELA
        </th>
    </tr>
    <tr>
        <th colspan="11" style="background-color: #f8f9fa; color: #333333; font-size: 12pt; font-weight: bold; text-align: center; height: 28px; font-family: Arial, sans-serif;">
            MINISTERIO DEL PODER POPULAR PARA EL SERVICIO PENITENCIARIO
        </th>
    </tr>
    <tr>
        <th colspan="11" style="background-color: #f8f9fa; color: #333333; font-size: 12pt; font-weight: bold; text-align: center; height: 28px; font-family: Arial, sans-serif;">
            INSTITUTO AUTÓNOMO CAJA DE TRABAJO PENITENCIARIO (IACTP)
        </th>
    </tr>
    <tr>
        <td colspan="11" style="border: none; height: 12px;"></td>
    </tr>
    <tr>
        <th colspan="11" style="background-color: #002060; color: #ffffff; font-size: 14pt; font-weight: bold; text-align: center; height: 38px; font-family: Arial, sans-serif;">
            CONSOLIDADO GENERAL DE CALIFICACIONES Y RENDIMIENTO
        </th>
    </tr>
    <tr>
        <th colspan="11" style="background-color: #d9d9d9; color: #000000; font-size: 11pt; font-weight: bold; text-align: center; height: 30px; font-family: Arial, sans-serif;">
            <?php echo htmlspecialchars($texto_filtros_activos); ?>
        </th>
    </tr>
    <tr>
        <td colspan="11" style="border: none; height: 10px;"></td>
    </tr>
    <tr style="background-color: #f2f2f2; font-size: 11pt; font-weight: bold; text-align: center; height: 32px; font-family: Arial, sans-serif;">
        <th style="width: 250px; font-family: Arial, sans-serif;">Centro Penitenciario</th>
        <th style="width: 180px; font-family: Arial, sans-serif;">Sección / Curso</th>
        <th style="width: 130px; font-family: Arial, sans-serif;">Cédula</th>
        <th style="width: 220px; font-family: Arial, sans-serif;">Apellidos y Nombres</th>
        <th style="width: 110px; font-family: Arial, sans-serif;">% Asistencia</th>
        <th style="width: 80px; font-family: Arial, sans-serif;">Eval. 1</th>
        <th style="width: 80px; font-family: Arial, sans-serif;">Eval. 2</th>
        <th style="width: 80px; font-family: Arial, sans-serif;">Eval. 3</th>
        <th style="width: 110px; font-family: Arial, sans-serif;">Proyecto Final</th>
        <th style="width: 100px; font-family: Arial, sans-serif;">Nota Final</th>
        <th style="width: 150px; font-family: Arial, sans-serif;">Estatus</th>
    </tr>
    <?php if ($resultado && pg_num_rows($resultado) > 0): ?>
        <?php while ($est = pg_fetch_assoc($resultado)): 
            $asistencia = floatval($est['porcentaje_asistencia_calculado']);
            $n1 = $est['nota_eval_1'] ?? '0';
            $n2 = $est['nota_eval_2'] ?? '0';
            $n3 = $est['nota_eval_3'] ?? '0';
            $n_prac = $est['evaluacion_practica'] ?? '0';
            $nota_fin = $est['calificacion_final'] ?? '0';
        ?>
            <tr style="font-size: 11pt; height: 24px; font-family: Arial, sans-serif;">
                <td style="font-family: Arial, sans-serif;"><?php echo htmlspecialchars($est['nombre_centro'] ?? $est['centro_codigo']); ?></td>
                <td style="font-family: Arial, sans-serif;"><?php echo htmlspecialchars($est['codigo_nomenclatura'] . ' - ' . $est['nombre_curso']); ?></td>
                <td style="text-align: center; mso-number-format:'\@'; font-family: Arial, sans-serif;"><?php echo htmlspecialchars($est['documento_identidad']); ?></td>
                <td style="font-family: Arial, sans-serif;"><?php echo htmlspecialchars($est['apellido'] . ', ' . $est['nombre']); ?></td>
                <td style="text-align: center; font-family: Arial, sans-serif;"><?php echo number_format($asistencia, 2); ?>%</td>
                <td style="text-align: center; font-family: Arial, sans-serif;"><?php echo htmlspecialchars($n1); ?></td>
                <td style="text-align: center; font-family: Arial, sans-serif;"><?php echo htmlspecialchars($n2); ?></td>
                <td style="text-align: center; font-family: Arial, sans-serif;"><?php echo htmlspecialchars($n3); ?></td>
                <td style="text-align: center; font-family: Arial, sans-serif;"><?php echo htmlspecialchars($n_prac); ?></td>
                <td style="text-align: center; font-weight: bold; font-family: Arial, sans-serif;"><?php echo number_format(floatval($nota_fin), 0); ?></td>
                <td style="text-align: center; font-weight: bold; font-family: Arial, sans-serif;"><?php echo htmlspecialchars($est['estado_aprobacion']); ?></td>
            </tr>
        <?php endwhile; ?>
    <?php else: ?>
        <tr>
            <td colspan="11" style="text-align: center; color: red; font-size: 11pt; font-weight: bold; height: 35px; font-family: Arial, sans-serif;">No se encontraron registros académicos con los filtros indicados.</td>
        </tr>
    <?php endif; ?>
</table>

<?php
if (isset($conexion)) { pg_close($conexion); }
exit();
?>