<?php
session_start();
require_once 'conexion.php'; 

// 1. Seguridad: Solo el Administrador puede hacer esto
if (!isset($_SESSION['loggedin']) || $_SESSION['cargo'] != 'Administradora') {
    header('Location: index.php');
    exit;
}

// 2. Obtener datos POST del formulario Modal
$cedula_nuevo_director = $_POST['nueva_cedula_director'] ?? null;
$admin_password_confirm = $_POST['admin_password'] ?? null;
$admin_usuario_logueado = $_SESSION['nombre_usuario']; 

if (empty($cedula_nuevo_director) || empty($admin_password_confirm)) {
    header('Location: usuarios_listar.php?error=datos_faltantes');
    exit;
}

// 3. VERIFICACIÓN DE LA CONTRASEÑA DEL ADMINISTRADOR LOGUEADO
try {
    // 3.1. Obtener el hash de la contraseña del administrador logueado
    $sql_admin = "SELECT contrasena FROM usuarios WHERE nombre_usuario = ?";
    $stmt_admin = $conexion->prepare($sql_admin);
    $stmt_admin->bind_param("s", $admin_usuario_logueado);
    $stmt_admin->execute();
    $resultado_admin = $stmt_admin->get_result();
    $admin_data = $resultado_admin->fetch_assoc();
    $stmt_admin->close();

    // 3.2. Comprobar si el hash existe y si la contraseña proporcionada coincide
    if (!$admin_data || !password_verify($admin_password_confirm, $admin_data['contrasena'])) {
        header('Location: usuarios_listar.php?error=password_invalida');
        exit;
    }

    // --- CONTINUACIÓN DE LA LÓGICA DE TRANSACCIÓN (Pasa si la contraseña es correcta) ---
    $conexion->begin_transaction();

    // Paso A: Desactivar al Director actual
    $update_old_sql = "UPDATE usuarios SET es_director_actual = 0 WHERE es_director_actual = 1";
    if (!$conexion->query($update_old_sql)) {
        throw new Exception("Error al desactivar director anterior.");
    }

    // Paso B: Activar al nuevo director
    $update_new_sql = "UPDATE usuarios SET es_director_actual = 1 WHERE cedula = ?";
    $stmt = $conexion->prepare($update_new_sql);
    $stmt->bind_param("s", $cedula_nuevo_director);

    if (!$stmt->execute()) {
        throw new Exception("Error al activar nuevo director.");
    }

    $stmt->close();

    // 4. Confirmar la transacción
    $conexion->commit();
    
    header('Location: usuarios_listar.php?exito=director_asignado');

} catch (Exception $e) {
    // 5. Revertir y registrar error
    $conexion->rollback();
    error_log("Error al cambiar Director: " . $e->getMessage());
    header('Location: usuarios_listar.php?error=db_asignacion');
}

$conexion->close();
exit;
?>