<?php
session_start();
require_once 'conexion.php'; 

// 1. Seguridad: Solo el Administrador puede hacer esto
if (!isset($_SESSION['loggedin']) || $_SESSION['cargo'] != 'Administradora') {
    header('Location: index.php');
    exit;
}

// 2. Obtener datos POST del formulario Modal
$cedula = $_POST['cedula_usuario'] ?? null;
$estado = $_POST['nuevo_estado'] ?? null; // 0 para inactivo, 1 para activo
$admin_password_confirm = $_POST['admin_password'] ?? null;
$admin_usuario_logueado = $_SESSION['nombre_usuario']; 

// 3. Validación Inicial
if (empty($cedula) || !isset($estado) || !is_numeric($estado) || ($estado != 0 && $estado != 1) || empty($admin_password_confirm)) {
    header('Location: usuarios_listar.php?error=datos_faltantes');
    exit;
}

// 4. PREVENIR LA INACTIVACIÓN DEL PROPIO ADMINISTRADOR
if ($cedula == $_SESSION['cedula']) {
    header('Location: usuarios_listar.php?error=autobloqueo_denegado');
    exit;
}

// 5. VERIFICACIÓN DE LA CONTRASEÑA DEL ADMINISTRADOR LOGUEADO
try {
    // 5.1. Obtener el hash de la contraseña del administrador logueado
    $sql_admin = "SELECT contrasena FROM usuarios WHERE nombre_usuario = ?";
    $stmt_admin = $conexion->prepare($sql_admin);
    $stmt_admin->bind_param("s", $admin_usuario_logueado);
    $stmt_admin->execute();
    $resultado_admin = $stmt_admin->get_result();
    $admin_data = $resultado_admin->fetch_assoc();
    $stmt_admin->close();

    // 5.2. Comprobar si el hash existe y si la contraseña proporcionada coincide
    if (!$admin_data || !password_verify($admin_password_confirm, $admin_data['contrasena'])) {
        header('Location: usuarios_listar.php?error=password_invalida');
        exit;
    }

    // --- CONTINUACIÓN DE LA LÓGICA DE ACTUALIZACIÓN (Pasa si la contraseña es correcta) ---
    $sql = "UPDATE usuarios SET activo = ? WHERE cedula = ?";
    $stmt = $conexion->prepare($sql);
    $stmt->bind_param("is", $estado, $cedula); 

    if ($stmt->execute()) {
        $mensaje = ($estado == 0) ? 'usuario_inactivado' : 'usuario_activado';
        $stmt->close();
        header('Location: usuarios_listar.php?exito=' . $mensaje);
    } else {
        throw new Exception("Error al actualizar el estado en la DB.");
    }

} catch (Exception $e) {
    error_log("Error al cambiar estado: " . $e->getMessage());
    header('Location: usuarios_listar.php?error=db_update');
}

$conexion->close();
exit;
?>