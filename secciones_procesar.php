<?php
session_start();
require_once 'conexion.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && (!isset($_GET['accion']) || $_GET['accion'] !== 'eliminar')) {
    header("Location: secciones_listado.php");
    exit();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: login.php");
    exit();
}

$usuario_activo = $_SESSION['nombre_usuario'] ?? $_SESSION['usuario_activo'] ?? 'Administrador';

$accion           = trim($_POST['accion'] ?? $_GET['accion'] ?? '');
$id               = trim($_POST['id'] ?? $_GET['id'] ?? '');

$centro_codigo    = trim($_POST['centro_codigo'] ?? '');
$curso_id         = intval($_POST['curso_id'] ?? 0);
$rif_nit          = trim($_POST['rif_nit'] ?? '');
$numero_seccion   = strtoupper(trim($_POST['numero_seccion'] ?? '01'));
$anio             = intval($_POST['anio'] ?? date('Y'));
$capacidad_maxima = intval($_POST['capacidad_maxima'] ?? 30);
$observaciones    = trim($_POST['observaciones'] ?? '');

$dias_array       = $_POST['dias'] ?? [];
$dias_semana      = !empty($dias_array) ? implode(', ', $dias_array) : '';

$hora_desde       = trim($_POST['hora_desde'] ?? '');
$hora_hasta       = trim($_POST['hora_hasta'] ?? '');
$hora_clase       = "{$hora_desde} - {$hora_hasta}";
$fecha_inicio     = trim($_POST['fecha_inicio'] ?? date('Y-m-d'));

$horario_completo = (!empty($dias_semana) ? $dias_semana . ' | ' : '') . $hora_clase;
$anio_actual      = intval(date('Y'));

if ($accion === 'registrar' || $accion === 'actualizar') {
    if ($anio < $anio_actual) {
        $_SESSION['mensaje_error'] = "❌ No se pueden aperturar secciones para años anteriores al año en curso ({$anio_actual}).";
        header("Location: secciones_form.php" . (!empty($id) ? "?id=$id" : ""));
        exit();
    }

    if (empty($centro_codigo) || empty($curso_id) || empty($rif_nit) || empty($numero_seccion) || empty($dias_semana) || empty($hora_desde) || empty($hora_hasta) || empty($fecha_inicio)) {
        $_SESSION['mensaje_error'] = "❌ Todos los campos obligatorios de días, horario y fecha de inicio son requeridos.";
        header("Location: secciones_form.php" . (!empty($id) ? "?id=$id" : ""));
        exit();
    }

    // Cálculo automático de la fecha fin aproximada en el backend
    $res_dur = pg_query_params($conexion, "SELECT duracion_horas FROM cursos WHERE id = $1", array($curso_id));
    $row_dur = pg_fetch_assoc($res_dur);
    $duracion_horas = intval($row_dur['duracion_horas'] ?? 40);

    $dias_por_semana = count($dias_array);
    if ($dias_por_semana > 0 && $duracion_horas > 0) {
        $horas_por_sesion = 4; // Estimado estándar de horas por sesión
        $sesiones_totales = ceil($duracion_horas / $horas_por_sesion);
        $semanas_necesarias = ceil($sesiones_totales / $dias_por_semana);
        $dias_calendario = $semanas_necesarias * 7;
        
        $timestamp_inicio = strtotime($fecha_inicio);
        $timestamp_fin = strtotime("+{$dias_calendario} days", $timestamp_inicio);
        $fecha_fin_aprox = date('Y-m-d', $timestamp_fin);
    } else {
        $fecha_fin_aprox = date('Y-m-d', strtotime("+30 days", strtotime($fecha_inicio)));
    }

    // Obtener la sigla oficial del centro
    $res_c = pg_query_params($conexion, "SELECT sigla, codigo FROM centros WHERE codigo = $1", array($centro_codigo));
    $row_c = pg_fetch_assoc($res_c);
    $sigla_centro = !empty($row_c['sigla']) ? $row_c['sigla'] : $row_c['codigo'];

    // Obtener la sigla oficial del curso
    $res_cu = pg_query_params($conexion, "SELECT sigla FROM cursos WHERE id = $1", array($curso_id));
    $row_cu = pg_fetch_assoc($res_cu);
    $sigla_curso = !empty($row_cu['sigla']) ? $row_cu['sigla'] : 'CURSO';

    // Obtener la sigla oficial del ente educativo
    $res_e = pg_query_params($conexion, "SELECT sigla FROM entes_educativos WHERE rif_nit = $1", array($rif_nit));
    $row_e = pg_fetch_assoc($res_e);
    $sigla_ente = !empty($row_e['sigla']) ? $row_e['sigla'] : 'ENTE';

    if (is_numeric($numero_seccion) && strlen($numero_seccion) === 1) {
        $numero_seccion = '0' . $numero_seccion;
    }

    // Nomenclatura oficial completa: [CENTRO]-[CURSO]-[AÑO]-[SECCIÓN]-[ENTE]
    $codigo_nomenclatura = strtoupper("{$sigla_centro}-{$sigla_curso}-{$anio}-{$numero_seccion}-{$sigla_ente}");

    if ($accion === 'registrar') {
        $check = pg_query_params($conexion, "SELECT id FROM secciones_cursos WHERE codigo_nomenclatura = $1", array($codigo_nomenclatura));
        if ($check && pg_num_rows($check) > 0) {
            $_SESSION['mensaje_error'] = "❌ Ya existe una sección con esta misma nomenclatura ({$codigo_nomenclatura}).";
            header("Location: secciones_form.php");
            exit();
        }

        $sql = "INSERT INTO secciones_cursos (codigo_nomenclatura, centro_codigo, curso_id, rif_nit, numero_seccion, horario, dias_semana, hora_clase, fecha_inicio, fecha_fin_aprox, anio, capacidad_maxima, observaciones, usuario_modificacion, fecha_modificacion) 
                VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13, $14, NOW())";
        $res = pg_query_params($conexion, $sql, array($codigo_nomenclatura, $centro_codigo, $curso_id, $rif_nit, $numero_seccion, $horario_completo, $dias_semana, $hora_clase, $fecha_inicio, $fecha_fin_aprox, $anio, $capacidad_maxima, $observaciones, $usuario_activo));

        if ($res) {
            $_SESSION['mensaje_exito'] = "✅ Sección aperturada exitosamente bajo la nomenclatura oficial: <strong>{$codigo_nomenclatura}</strong>";
            header("Location: secciones_listado.php");
            exit();
        } else {
            $_SESSION['mensaje_error'] = "❌ Error al aperturar la sección: " . pg_last_error($conexion);
            header("Location: secciones_form.php");
            exit();
        }

    } elseif ($accion === 'actualizar') {
        $check = pg_query_params($conexion, "SELECT id FROM secciones_cursos WHERE codigo_nomenclatura = $1 AND id != $2", array($codigo_nomenclatura, $id));
        if ($check && pg_num_rows($check) > 0) {
            $_SESSION['mensaje_error'] = "❌ La nomenclatura resultante ({$codigo_nomenclatura}) ya pertenece a otra sección registrada.";
            header("Location: secciones_form.php?id=$id");
            exit();
        }

        $sql = "UPDATE secciones_cursos 
                SET codigo_nomenclatura = $1, centro_codigo = $2, curso_id = $3, rif_nit = $4, numero_seccion = $5, horario = $6, dias_semana = $7, hora_clase = $8, fecha_inicio = $9, fecha_fin_aprox = $10, anio = $11, capacidad_maxima = $12, observaciones = $13, usuario_modificacion = $14, fecha_modificacion = NOW() 
                WHERE id = $15";
        $res = pg_query_params($conexion, $sql, array($codigo_nomenclatura, $centro_codigo, $curso_id, $rif_nit, $numero_seccion, $horario_completo, $dias_semana, $hora_clase, $fecha_inicio, $fecha_fin_aprox, $anio, $capacidad_maxima, $observaciones, $usuario_activo, $id));

        if ($res) {
            $_SESSION['mensaje_exito'] = "✅ Sección actualizada correctamente ({$codigo_nomenclatura}).";
            header("Location: secciones_listado.php");
            exit();
        } else {
            $_SESSION['mensaje_error'] = "❌ Error al actualizar: " . pg_last_error($conexion);
            header("Location: secciones_form.php?id=$id");
            exit();
        }
    }

} elseif ($accion === 'eliminar') {
    $res = pg_query_params($conexion, "DELETE FROM secciones_cursos WHERE id = $1", array($id));
    if ($res) {
        $_SESSION['mensaje_exito'] = "✅ Sección eliminada correctamente.";
    } else {
        $_SESSION['mensaje_error'] = "❌ No se pudo eliminar la sección.";
    }
    header("Location: secciones_listado.php");
    exit();
}

if (isset($conexion)) { pg_close($conexion); }
?>