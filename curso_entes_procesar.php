<?php
session_start();
require_once 'conexion.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && (!isset($_GET['accion']) || $_GET['accion'] !== 'eliminar')) {
    header("Location: cursos_entes_gestion.php");
    exit();
}

// Validar sesión activa
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    $_SESSION['mensaje_error'] = "❌ Acceso denegado: Debe iniciar sesión.";
    header("Location: login.php");
    exit();
}

$usuario_activo = $_SESSION['nombre_usuario'] ?? $_SESSION['usuario_activo'] ?? 'Sistema';

$accion         = trim($_POST['accion'] ?? $_GET['accion'] ?? '');
$asignacion_id  = trim($_POST['asignacion_id'] ?? $_GET['id'] ?? '');
$centro_codigo  = trim($_POST['centro_codigo'] ?? '');
$curso_id       = trim($_POST['curso_id'] ?? '');
$rif_nit        = trim($_POST['rif_nit'] ?? '');
$horas_duracion = trim($_POST['horas_duracion'] ?? '');
$capacidad_max  = trim($_POST['capacidad_maxima'] ?? '20');

// ==========================================
// 1. ASIGNAR NUEVO CURSO A CENTRO Y ENTE
// ==========================================
if ($accion === 'asignar') {
    if (empty($centro_codigo) || empty($curso_id) || empty($rif_nit) || empty($horas_duracion)) {
        $_SESSION['mensaje_error'] = "❌ Error: Todos los campos obligatorios deben estar llenos.";
        header("Location: cursos_entes_form.php");
        exit();
    }

    // Validar si ya existe esta combinación (Centro + Curso + Ente)
    $check_dup = pg_query_params(
        $conexion, 
        "SELECT id FROM ente_cursos WHERE centro_codigo = $1 AND curso_id = $2 AND rif_nit = $3", 
        array($centro_codigo, $curso_id, $rif_nit)
    );
    
    if ($check_dup && pg_num_rows($check_dup) > 0) {
        $_SESSION['mensaje_error'] = "❌ Este curso ya se encuentra asignado a este ente educativo en este centro penitenciario.";
        header("Location: cursos_entes_form.php");
        exit();
    }

    $sql_insert = "INSERT INTO ente_cursos (centro_codigo, curso_id, rif_nit, horas_duracion, capacidad_maxima, usuario_modificacion, fecha_modificacion) VALUES ($1, $2, $3, $4, $5, $6, NOW()) RETURNING id";
    $res_insert = pg_query_params($conexion, $sql_insert, array($centro_codigo, $curso_id, $rif_nit, $horas_duracion, $capacidad_max, $usuario_activo));

    if ($res_insert) {
        // Obtener nombres para la auditoría
        $cen_info = pg_fetch_assoc(pg_query_params($conexion, "SELECT nombre FROM centros WHERE codigo = $1", array($centro_codigo)));
        $c_info   = pg_fetch_assoc(pg_query_params($conexion, "SELECT nombre_curso FROM cursos WHERE id = $1", array($curso_id)));
        $e_info   = pg_fetch_assoc(pg_query_params($conexion, "SELECT nombre_ente FROM entes_educativos WHERE rif_nit = $1", array($rif_nit)));

        // Registrar en Auditoría General
        $sql_audit = "INSERT INTO auditoria_general (modulo, accion, registro_id, detalles, usuario) VALUES ($1, $2, $3, $4, $5)";
        pg_query_params($conexion, $sql_audit, array(
            'CURSOS_ENTES',
            'ASIGNACIÓN',
            "Centro: {$centro_codigo} - Curso ID: {$curso_id}",
            "Asignado curso '{$c_info['nombre_curso']}' dictado por '{$e_info['nombre_ente']}' en el centro '{$cen_info['nombre']}' ({$horas_duracion} hrs, {$capacidad_max} cupos).",
            $usuario_activo
        ));

        $_SESSION['mensaje_exito'] = "✅ Curso asignado al centro y ente educativo correctamente.";
        header("Location: cursos_entes_gestion.php");
        exit();
    } else {
        $_SESSION['mensaje_error'] = "❌ Error en Base de Datos: " . htmlspecialchars(pg_last_error($conexion));
        header("Location: cursos_entes_form.php");
        exit();
    }

// ==========================================
// 2. ACTUALIZAR ASIGNACIÓN EXISTENTE
// ==========================================
} elseif ($accion === 'actualizar') {
    if (empty($asignacion_id) || empty($centro_codigo) || empty($curso_id) || empty($rif_nit) || empty($horas_duracion)) {
        $_SESSION['mensaje_error'] = "❌ Faltan datos obligatorios para actualizar la asignación.";
        header("Location: cursos_entes_form.php?id=$asignacion_id");
        exit();
    }

    $sql_update = "UPDATE ente_cursos SET centro_codigo = $1, curso_id = $2, rif_nit = $3, horas_duracion = $4, capacidad_maxima = $5, usuario_modificacion = $6, fecha_modificacion = NOW() WHERE id = $7";
    $res_update = pg_query_params($conexion, $sql_update, array($centro_codigo, $curso_id, $rif_nit, $horas_duracion, $capacidad_max, $usuario_activo, $asignacion_id));

    if ($res_update) {
        $sql_audit = "INSERT INTO auditoria_general (modulo, accion, registro_id, detalles, usuario) VALUES ($1, $2, $3, $4, $5)";
        pg_query_params($conexion, $sql_audit, array(
            'CURSOS_ENTES',
            'ACTUALIZACIÓN',
            "Asignación ID: {$asignacion_id}",
            "Actualizada asignación en centro {$centro_codigo} a {$horas_duracion} hrs y {$capacidad_max} cupos.",
            $usuario_activo
        ));

        $_SESSION['mensaje_exito'] = "✅ Asignación actualizada correctamente.";
        header("Location: cursos_entes_gestion.php");
        exit();
    } else {
        $_SESSION['mensaje_error'] = "❌ Error al actualizar: " . htmlspecialchars(pg_last_error($conexion));
        header("Location: cursos_entes_form.php?id=$asignacion_id");
        exit();
    }

// ==========================================
// 3. ELIMINAR ASIGNACIÓN
// ==========================================
} elseif ($accion === 'eliminar') {
    if (empty($asignacion_id)) {
        $_SESSION['mensaje_error'] = "❌ ID de asignación no especificado.";
        header("Location: cursos_entes_gestion.php");
        exit();
    }

    $res_info = pg_query_params($conexion, "SELECT * FROM ente_cursos WHERE id = $1", array($asignacion_id));
    $data_asig = ($res_info) ? pg_fetch_assoc($res_info) : null;

    $sql_delete = "DELETE FROM ente_cursos WHERE id = $1";
    $res_delete = pg_query_params($conexion, $sql_delete, array($asignacion_id));

    if ($res_delete) {
        if ($data_asig) {
            $sql_audit = "INSERT INTO auditoria_general (modulo, accion, registro_id, detalles, usuario) VALUES ($1, $2, $3, $4, $5)";
            pg_query_params($conexion, $sql_audit, array(
                'CURSOS_ENTES',
                'ELIMINACIÓN',
                "Asignación ID: {$asignacion_id}",
                "Removida asignación del curso ID {$data_asig['curso_id']} en el centro {$data_asig['centro_codigo']}.",
                $usuario_activo
            ));
        }

        $_SESSION['mensaje_exito'] = "✅ Asignación removida exitosamente.";
        header("Location: cursos_entes_gestion.php");
        exit();
    } else {
        $_SESSION['mensaje_error'] = "❌ Error al eliminar la asignación.";
        header("Location: cursos_entes_gestion.php");
        exit();
    }
}

if (isset($conexion)) {
    pg_close($conexion);
}
?>