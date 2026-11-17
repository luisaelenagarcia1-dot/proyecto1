<?php
session_start();
require_once 'conexion.php';

// Habilitar errores para depuración
ini_set('display_errors', 1);
error_reporting(E_ALL);

// 1. Seguridad
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: login.php');
    exit;
}

// 2. Validar que se recibió por POST y que los campos no están vacíos
if ($_SERVER["REQUEST_METHOD"] != "POST" || 
    empty($_POST['registro_id']) || 
    empty($_POST['contrasena_confirmacion']) || 
    empty($_POST['motivo_fin'])) {
    
    $_SESSION['error_message'] = "Datos incompletos. Debe seleccionar un motivo e ingresar su contraseña.";
    header('Location: trabajo_listar.php');
    exit();
}

// 3. Obtener datos del formulario del modal
$registro_id = (int)$_POST['registro_id'];
$contrasena_ingresada = $_POST['contrasena_confirmacion'];
$motivo_fin = trim($_POST['motivo_fin']);
$nombre_usuario_sesion = $_SESSION['nombre_usuario'] ?? '';

if (empty($nombre_usuario_sesion)) {
    $_SESSION['error_message'] = "Error: Sesión de usuario no válida.";
    header('Location: trabajo_listar.php');
    exit();
}

// 4. Verificar la contraseña del usuario logueado (PostgreSQL)
$sql_pass = "SELECT contrasena FROM usuarios WHERE nombre_usuario = $1";
$res_pass = pg_query_params($conexion, $sql_pass, array($nombre_usuario_sesion));

if ($res_pass && pg_num_rows($res_pass) === 1) {
    $usuario = pg_fetch_assoc($res_pass);
    pg_free_result($res_pass);
    $hash_contrasena_bd = $usuario['contrasena'];

    // 5. Verificación de Contraseña
    if (password_verify($contrasena_ingresada, $hash_contrasena_bd)) {
        
        $fecha_hoy = date('Y-m-d');
        
        // Actualizar la fecha de fin y el motivo en registros_laborales
        $sql_update = "UPDATE registros_laborales SET fecha_fin_labor = $1, motivo_fin = $2 WHERE id = $3";
        $res_update = pg_query_params($conexion, $sql_update, array($fecha_hoy, $motivo_fin, $registro_id));

        if ($res_update) {
            $_SESSION['success_message'] = "Actividad laboral finalizada correctamente.";
            pg_free_result($res_update);
        } else {
            $_SESSION['error_message'] = "Error al actualizar la base de datos: " . pg_last_error($conexion);
        }

    } else {
        // Contraseña INCORRECTA
        $_SESSION['error_message'] = "Error: Contraseña de confirmación incorrecta. No se finalizó la actividad.";
    }

} else {
    if ($res_pass) pg_free_result($res_pass);
    $_SESSION['error_message'] = "Error: No se pudo encontrar al usuario de la sesión.";
}

if (isset($conexion)) {
    pg_close($conexion);
}

header('Location: trabajo_listar.php');
exit();
?>