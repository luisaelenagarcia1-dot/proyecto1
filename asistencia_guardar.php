<?php
// Suponiendo que recibes $seccion_id y $fecha_asistencia del formulario
$seccion_id = $_POST['seccion_id'] ?? null;
$fecha_asistencia = $_POST['fecha'] ?? date('Y-m-d'); // Fecha en que se intenta registrar la asistencia

// 1. Consultar la fecha de inicio de la sección o curso
$query_curso = "
    SELECT sc.fecha_inicio, c.nombre_curso 
    FROM secciones_cursos sc
    JOIN cursos c ON sc.curso_id = c.id
    WHERE sc.id = $1
";
$res_curso = pg_query_params($conexion, $query_curso, array($seccion_id));

if ($res_curso && $row_curso = pg_fetch_assoc($res_curso)) {
    $fecha_inicio_curso = $row_curso['fecha_inicio'];

    // 2. Validar si la fecha de asistencia es anterior a la fecha de inicio
    if (!empty($fecha_inicio_curso) && $fecha_asistencia < $fecha_inicio_curso) {
        $_SESSION['mensaje_error'] = "Error: No se puede registrar asistencia. El curso '" . $row_curso['nombre_curso'] . "' inicia el " . date('d/m/Y', strtotime($fecha_inicio_curso)) . ".";
        header("Location: asistencia_registrar.php");
        exit();
    }
}
?>