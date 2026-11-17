<?php
session_start();
require_once 'conexion.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && (!isset($_GET['accion']) || $_GET['accion'] !== 'eliminar')) {
    header("Location: cursos_listado.php");
    exit();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: login.php");
    exit();
}

$accion       = trim($_POST['accion'] ?? $_GET['accion'] ?? '');
$id_curso     = intval($_POST['id'] ?? $_GET['id'] ?? 0);

// Recibir y forzar mayúsculas
$codigo_curso = strtoupper(trim($_POST['codigo_curso'] ?? ''));
$nombre_curso = strtoupper(trim($_POST['nombre_curso'] ?? ''));
$sigla        = strtoupper(trim($_POST['sigla'] ?? ''));
$descripcion  = strtoupper(trim($_POST['descripcion'] ?? ''));

// Obtener el usuario activo para la auditoría
$usuario_activo = $_SESSION['nombre_usuario'] ?? $_SESSION['usuario_activo'] ?? 'ADMINISTRADOR';

if ($accion === 'registrar' || $accion === 'actualizar') {
    if (empty($codigo_curso) || empty($nombre_curso) || empty($sigla)) {
        $_SESSION['mensaje_error'] = "❌ El código, el nombre y la sigla oficial del curso son obligatorios.";
        header("Location: cursos_form.php" . ($id_curso > 0 ? "?id=$id_curso" : ""));
        exit();
    }

    if ($accion === 'registrar') {
        // VALIDAR DUPLICIDAD POR SIGLA O POR NOMBRE
        $check = pg_query_params(
            $conexion, 
            "SELECT id FROM cursos WHERE sigla = $1 OR nombre_curso = $2", 
            array($sigla, $nombre_curso)
        );
        
        if ($check && pg_num_rows($check) > 0) {
            $_SESSION['mensaje_error'] = "❌ Ya existe un curso registrado con la misma Sigla ('{$sigla}') o el mismo Nombre ('{$nombre_curso}').";
            header("Location: cursos_form.php");
            exit();
        }

        $sql = "INSERT INTO cursos (codigo_curso, nombre_curso, sigla, descripcion, usuario_modificacion, fecha_modificacion) 
                VALUES ($1, $2, $3, $4, $5, NOW())";
        $res = pg_query_params($conexion, $sql, array($codigo_curso, $nombre_curso, $sigla, $descripcion, $usuario_activo));

        if ($res) {
            $_SESSION['mensaje_exito'] = "✅ Curso registrado exitosamente.";
            header("Location: cursos_listado.php");
            exit();
        } else {
            $_SESSION['mensaje_error'] = "❌ Error al registrar el curso: " . pg_last_error($conexion);
            header("Location: cursos_form.php");
            exit();
        }

    } elseif ($accion === 'actualizar') {
        // VALIDAR DUPLICIDAD EN OTRO REGISTRO AL ACTUALIZAR
        $check = pg_query_params(
            $conexion, 
            "SELECT id FROM cursos WHERE (sigla = $1 OR nombre_curso = $2) AND id != $3", 
            array($sigla, $nombre_curso, $id_curso)
        );
        
        if ($check && pg_num_rows($check) > 0) {
            $_SESSION['mensaje_error'] = "❌ Ya existe otro curso con la misma Sigla o Nombre que intentas actualizar.";
            header("Location: cursos_form.php?id=$id_curso");
            exit();
        }

        $sql = "UPDATE cursos SET codigo_curso = $1, nombre_curso = $2, sigla = $3, descripcion = $4, usuario_modificacion = $5, fecha_modificacion = NOW() WHERE id = $6";
        $res = pg_query_params($conexion, $sql, array($codigo_curso, $nombre_curso, $sigla, $descripcion, $usuario_activo, $id_curso));

        if ($res) {
            $_SESSION['mensaje_exito'] = "✅ Curso actualizado correctamente.";
            header("Location: cursos_listado.php");
            exit();
        } else {
            $_SESSION['mensaje_error'] = "❌ Error al actualizar el curso: " . pg_last_error($conexion);
            header("Location: cursos_form.php?id=$id_curso");
            exit();
        }
    }

} elseif ($accion === 'eliminar' && $id_curso > 0) {
    $check_secciones = pg_query_params($conexion, "SELECT id FROM secciones_cursos WHERE curso_id = $1", array($id_curso));
    if ($check_secciones && pg_num_rows($check_secciones) > 0) {
        $_SESSION['mensaje_error'] = "❌ No se puede eliminar este curso porque tiene secciones académicas asociadas.";
        header("Location: cursos_listado.php");
        exit();
    }

    $res = pg_query_params($conexion, "DELETE FROM cursos WHERE id = $1", array($id_curso));
    if ($res) {
        $_SESSION['mensaje_exito'] = "✅ Curso eliminado correctamente.";
    } else {
        $_SESSION['mensaje_error'] = "❌ No se pudo eliminar el curso de la base de datos.";
    }
    header("Location: cursos_listado.php");
    exit();
}

if (isset($conexion)) { pg_close($conexion); }
?>