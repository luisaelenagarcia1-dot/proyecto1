<?php
session_start();
require_once 'conexion.php';

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: login.php');
    exit;
}

$seccion_seleccionada = $_GET['seccion_id'] ?? null;

if (empty($seccion_seleccionada)) {
    die("Sección no especificada.");
}

// Obtener detalles de la sección y curso
$query_sec = "
    SELECT sc.codigo_nomenclatura, c.nombre_curso, c.duracion_horas, cn.nombre AS nombre_centro
    FROM secciones_cursos sc
    JOIN cursos c ON sc.curso_id = c.id
    LEFT JOIN centros cn ON sc.centro_codigo = cn.codigo
    WHERE sc.id = $1
";
$res_sec = pg_query_params($conexion, $query_sec, array($seccion_seleccionada));
$info_sec = ($res_sec) ? pg_fetch_assoc($res_sec) : null;

$texto_subtitulo = $info_sec ? "Centro: " . $info_sec['nombre_centro'] . " | Sección: " . $info_sec['codigo_nomenclatura'] . " - Curso: " . $info_sec['nombre_curso'] . " (" . intval($info_sec['duracion_horas'] ?? 40) . " Horas)" : "Listado de Certificados";

// Consulta de estudiantes cursantes / aprobados
$query_estudiantes = "
    SELECT 
        ie.id AS inscripcion_id,
        p.documento_identidad,
        p.nombre,
        p.apellido,
        ie.calificacion_final,
        ie.estado_aprobacion,
        ie.estatus_sobresaliente
    FROM inscripciones_educativas ie
    JOIN privados p ON ie.privado_documento = p.documento_identidad
    WHERE ie.seccion_id = $1
    ORDER BY p.apellido ASC
";
$resultado = pg_query_params($conexion, $query_estudiantes, array($seccion_seleccionada));

// Cabeceras HTTP para descarga de Excel (.xls)
$filename = "Certificados_Seccion_" . ($info_sec['codigo_nomenclatura'] ?? 'Reporte') . "_" . date('Y-m-d') . ".xls";
header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

echo "\xEF\xBB\xBF"; // BOM para acentos en Excel
?>
<table border="1" style="font-family: Arial, sans-serif;">
    <tr>
        <th colspan="6" style="background-color: #f8f9fa; color: #002060; font-size: 14pt; font-weight: bold; text-align: center; height: 32px; font-family: Arial, sans-serif;">
            REPÚBLICA BOLIVARIANA DE VENEZUELA
        </th>
    </tr>
    <tr>
        <th colspan="6" style="background-color: #f8f9fa; color: #333333; font-size: 12pt; font-weight: bold; text-align: center; height: 28px; font-family: Arial, sans-serif;">
            MINISTERIO DEL PODER POPULAR PARA EL SERVICIO PENITENCIARIO
        </th>
    </tr>
    <tr>
        <th colspan="6" style="background-color: #f8f9fa; color: #333333; font-size: 12pt; font-weight: bold; text-align: center; height: 28px; font-family: Arial, sans-serif;">
            INSTITUTO AUTÓNOMO CAJA DE TRABAJO PENITENCIARIO (IACTP)
        </th>
    </tr>
    <tr>
        <td colspan="6" style="border: none; height: 12px;"></td>
    </tr>
    <tr>
        <th colspan="6" style="background-color: #002060; color: #ffffff; font-size: 14pt; font-weight: bold; text-align: center; height: 38px; font-family: Arial, sans-serif;">
            REPORTE DE ESTADO DE CERTIFICACIÓN DE CURSANTES
        </th>
    </tr>
    <tr>
        <th colspan="6" style="background-color: #d9d9d9; color: #000000; font-size: 11pt; font-weight: bold; text-align: center; height: 30px; font-family: Arial, sans-serif;">
            <?php echo htmlspecialchars($texto_subtitulo); ?>
        </th>
    </tr>
    <tr>
        <td colspan="6" style="border: none; height: 10px;"></td>
    </tr>
    <tr style="background-color: #f2f2f2; font-size: 11pt; font-weight: bold; text-align: center; height: 32px; font-family: Arial, sans-serif;">
        <th style="width: 50px; font-family: Arial, sans-serif;">N°</th>
        <th style="width: 140px; font-family: Arial, sans-serif;">Cédula</th>
        <th style="width: 260px; font-family: Arial, sans-serif;">Apellidos y Nombres</th>
        <th style="width: 110px; font-family: Arial, sans-serif;">Nota Final</th>
        <th style="width: 160px; font-family: Arial, sans-serif;">Estado de Aprobación</th>
        <th style="width: 160px; font-family: Arial, sans-serif;">Condición Especial</th>
    </tr>
    <?php if ($resultado && pg_num_rows($resultado) > 0): ?>
        <?php 
        $i = 1;
        while ($est = pg_fetch_assoc($resultado)): 
            $cedula_limpia = preg_replace('/^(V-|v-|V|v)/', '', trim($est['documento_identidad']));
            $es_aprobado = ($est['estado_aprobacion'] === 'APROBADO');
            $es_sobresaliente = ($est['estatus_sobresaliente'] == 't' || $est['estatus_sobresaliente'] == '1');
        ?>
            <tr style="font-size: 11pt; height: 24px; font-family: Arial, sans-serif;">
                <td style="text-align: center; font-weight: bold; font-family: Arial, sans-serif;"><?php echo $i++; ?></td>
                <td style="text-align: center; mso-number-format:'\@'; font-family: Arial, sans-serif;">V-<?php echo htmlspecialchars($cedula_limpia); ?></td>
                <td style="font-family: Arial, sans-serif;"><?php echo htmlspecialchars($est['apellido'] . ', ' . $est['nombre']); ?></td>
                <td style="text-align: center; font-weight: bold; font-family: Arial, sans-serif;"><?php echo number_format(floatval($est['calificacion_final'] ?? 0), 0); ?></td>
                <td style="text-align: center; font-weight: bold; font-family: Arial, sans-serif; color: <?php echo $es_aprobado ? 'green' : 'red'; ?>;">
                    <?php echo htmlspecialchars($est['estado_aprobacion']); ?>
                </td>
                <td style="text-align: center; font-family: Arial, sans-serif;">
                    <?php echo $es_sobresaliente ? 'Sobresaliente ⭐' : 'Regular'; ?>
                </td>
            </tr>
        <?php endwhile; ?>
    <?php else: ?>
        <tr>
            <td colspan="6" style="text-align: center; color: red; font-size: 11pt; font-weight: bold; height: 35px; font-family: Arial, sans-serif;">No hay registros de cursantes en esta sección.</td>
        </tr>
    <?php endif; ?>
</table>

<?php
if (isset($conexion)) { pg_close($conexion); }
exit();
?>