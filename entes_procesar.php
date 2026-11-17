<?php
session_start();
require_once 'conexion.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && (!isset($_GET['accion']) || $_GET['accion'] !== 'eliminar')) {
    header("Location: entes_listado.php");
    exit();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: login.php");
    exit();
}

// Tomar el usuario directamente de la sesión activa
$usuario_activo = $_SESSION['nombre_usuario'] ?? $_SESSION['usuario_activo'] ?? 'Administrador';

$accion           = trim($_POST['accion'] ?? $_GET['accion'] ?? '');
$rif_original     = trim($_POST['rif_original'] ?? '');
$nombre_ente      = trim($_POST['nombre_ente'] ?? '');
$sigla            = strtoupper(trim($_POST['sigla'] ?? ''));

$tipo_rif         = trim($_POST['tipo_rif'] ?? 'G');
$numero_rif       = trim($_POST['numero_rif'] ?? '');
$rif_nit          = $tipo_rif . '-' . $numero_rif;

$contacto_persona = trim($_POST['contacto_persona'] ?? '');
$email            = trim($_POST['email'] ?? '');

// ==========================================================
// VALIDACIÓN ESTRICTA DE CORREO ELECTRÓNICO EN EL BACKEND
// ==========================================================
if ($accion === 'registrar' || $accion === 'actualizar') {
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['mensaje_error'] = "❌ Error: El correo electrónico ingresado no tiene un formato válido.";
        $redireccion = ($accion === 'actualizar' && !empty($rif_original)) ? "entes_form.php?rif=" . urlencode($rif_original) : "entes_form.php";
        header("Location: " . $redireccion);
        exit();
    }
}

// Procesar teléfonos
$p1 = trim($_POST['prefijo_tel1'] ?? '');
$m1 = trim($_POST['manual_tel1'] ?? '');
$n1 = trim($_POST['num_tel1'] ?? '');
$pref1 = ($p1 === 'OTRO') ? $m1 : $p1;
$telefono_final = $pref1 . '-' . $n1;

$p2 = trim($_POST['prefijo_tel2'] ?? '');
$m2 = trim($_POST['manual_tel2'] ?? '');
$n2 = trim($_POST['num_tel2'] ?? '');
if (!empty($n2)) {
    $pref2 = ($p2 === 'OTRO') ? $m2 : $p2;
    $telefono_final .= ' / ' . $pref2 . '-' . $n2;
}

$p3 = trim($_POST['prefijo_tel3'] ?? '');
$m3 = trim($_POST['manual_tel3'] ?? '');
$n3 = trim($_POST['num_tel3'] ?? '');
if (!empty($n3)) {
    $pref3 = ($p3 === 'OTRO') ? $m3 : $p3;
    $telefono_final .= ' / ' . $pref3 . '-' . $n3;
}

if ($accion === 'registrar') {
    if (empty($numero_rif) || empty($nombre_ente) || empty($sigla)) {
        $_SESSION['mensaje_error'] = "❌ El RIF, el Nombre y la Sigla son obligatorios.";
        header("Location: entes_form.php");
        exit();
    }

    $check = pg_query_params($conexion, "SELECT rif_nit FROM entes_educativos WHERE rif_nit = $1", array($rif_nit));
    if ($check && pg_num_rows($check) > 0) {
        $_SESSION['mensaje_error'] = "❌ Ya existe un ente registrado con este RIF/NIT.";
        header("Location: entes_form.php");
        exit();
    }

    // Insertar el ente incluyendo quién lo registró (usuario_modificacion / auditoría)
    $sql = "INSERT INTO entes_educativos (rif_nit, nombre_ente, sigla, telefono, persona_contacto, email, usuario_modificacion, fecha_modificacion) 
            VALUES ($1, $2, $3, $4, $5, $6, $7, NOW())";
    $res = pg_query_params($conexion, $sql, array($rif_nit, $nombre_ente, $sigla, $telefono_final, $contacto_persona, $email, $usuario_activo));

    if ($res) {
        $_SESSION['mensaje_exito'] = "✅ Ente educativo registrado exitosamente.";
        header("Location: entes_listado.php");
        exit();
    } else {
        $_SESSION['mensaje_error'] = "❌ Error al registrar: " . pg_last_error($conexion);
        header("Location: entes_form.php");
        exit();
    }

} elseif ($accion === 'actualizar') {
    $id_a_usar = !empty($rif_original) ? $rif_original : $rif_nit;
    
    // Actualizar registrando quién hizo la modificación
    $sql = "UPDATE entes_educativos 
            SET rif_nit = $1, nombre_ente = $2, sigla = $3, telefono = $4, persona_contacto = $5, email = $6, usuario_modificacion = $7, fecha_modificacion = NOW() 
            WHERE rif_nit = $8";
    $res = pg_query_params($conexion, $sql, array($rif_nit, $nombre_ente, $sigla, $telefono_final, $contacto_persona, $email, $usuario_activo, $id_a_usar));

    if ($res) {
        $_SESSION['mensaje_exito'] = "✅ Datos del ente actualizados correctamente.";
        header("Location: entes_listado.php");
        exit();
    } else {
        $_SESSION['mensaje_error'] = "❌ Error al actualizar: " . pg_last_error($conexion);
        header("Location: entes_form.php?rif=$id_a_usar");
        exit();
    }

} elseif ($accion === 'eliminar') {
    $rif_eliminar = $_GET['rif'] ?? '';
    $res = pg_query_params($conexion, "DELETE FROM entes_educativos WHERE rif_nit = $1", array($rif_eliminar));
    if ($res) {
        $_SESSION['mensaje_exito'] = "✅ Ente educativo eliminado correctamente.";
    } else {
        $_SESSION['mensaje_error'] = "❌ No se pudo eliminar el ente (puede estar asociado a cursos).";
    }
    header("Location: entes_listado.php");
    exit();
}

if (isset($conexion)) { pg_close($conexion); }
?>