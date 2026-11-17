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

// Consulta de estudiantes
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

// Cabecera HTTP para descarga de PDF
$filename = "Certificados_Seccion_" . ($info_sec['codigo_nomenclatura'] ?? 'Reporte') . "_" . date('Y-m-d') . ".pdf";
header("Content-Type: application/pdf; charset=utf-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte de Certificación</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 10pt; color: #333; margin: 0; padding: 15px; }
        .text-center { text-align: center; }
        .fw-bold { font-weight: bold; }
        .header-title { font-size: 13pt; font-weight: bold; color: #002060; margin-bottom: 2px; }
        .header-sub { font-size: 10pt; color: #555; margin-bottom: 2px; }
        .report-title { font-size: 12pt; font-weight: bold; background-color: #002060; color: #fff; padding: 8px; text-align: center; margin-top: 15px; }
        .report-filters { font-size: 10pt; font-weight: bold; background-color: #d9d9d9; color: #000; padding: 6px; text-align: center; margin-bottom: 15px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #777; padding: 6px 8px; font-size: 9.5pt; }
        th { background-color: #f2f2f2; text-align: center; }
    </style>
</head>
<body>

    <!-- Membrete Institucional -->
    <div class="text-center">
        <div class="header-title">REPÚBLICA BOLIVARIANA DE VENEZUELA</div>
        <div class="header-sub">MINISTERIO DEL PODER POPULAR PARA EL SERVICIO PENITENCIARIO</div>
        <div class="header-sub fw-bold">INSTITUTO AUTÓNOMO CAJA DE TRABAJO PENITENCIARIO (IACTP)</div>
    </div>

    <div class="report-title">REPORTE DE ESTADO DE CERTIFICACIÓN DE CURSANTES</div>
    <div class="report-filters"><?php echo htmlspecialchars($texto_subtitulo); ?></div>

    <!-- Tabla de Datos -->
    <table>
        <thead>
            <tr>
                <th style="width: 45px;">N°</th>
                <th style="width: 130px;">Cédula</th>
                <th>Apellidos y Nombres</th>
                <th style="width: 90px;">Nota Final</th>
                <th style="width: 130px;">Estado</th>
                <th style="width: 130px;">Condición</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($resultado && pg_num_rows($resultado) > 0): ?>
                <?php 
                $i = 1;
                while ($est = pg_fetch_assoc($resultado)): 
                    $cedula_limpia = preg_replace('/^(V-|v-|V|v)/', '', trim($est['documento_identidad']));
                    $es_aprobado = ($est['estado_aprobacion'] === 'APROBADO');
                    $es_sobresaliente = ($est['estatus_sobresaliente'] == 't' || $est['estatus_sobresaliente'] == '1');
                ?>
                    <tr>
                        <td class="text-center fw-bold"><?php echo $i++; ?></td>
                        <td class="text-center">V-<?php echo htmlspecialchars($cedula_limpia); ?></td>
                        <td><?php echo htmlspecialchars($est['apellido'] . ', ' . $est['nombre']); ?></td>
                        <td class="text-center fw-bold"><?php echo number_format(floatval($est['calificacion_final'] ?? 0), 0); ?></td>
                        <td class="text-center fw-bold" style="color: <?php echo $es_aprobado ? 'green' : 'red'; ?>;">
                            <?php echo htmlspecialchars($est['estado_aprobacion']); ?>
                        </td>
                        <td class="text-center">
                            <?php echo $es_sobresaliente ? 'Sobresaliente ⭐' : 'Regular'; ?>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr>
                    <td colspan="6" class="text-center fw-bold" style="color: red; padding: 15px;">No hay registros de cursantes en esta sección.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

</body>
</html>
<?php
if (isset($conexion)) { pg_close($conexion); }
exit();
?>