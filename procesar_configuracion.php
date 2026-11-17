<?php
session_start();
require_once 'conexion.php';

// Redirigir si no hay sesión activa
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: login.php');
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $nombre_usuario_actual = $_SESSION['usuario'];
    $contrasena_actual = $_POST['contrasena_actual'] ?? '';
    $contrasena_nueva = $_POST['contrasena_nueva'] ?? '';
    $confirmar_contrasena = $_POST['confirmar_contrasena'] ?? '';
    $pregunta_seguridad = $_POST['pregunta_seguridad'] ?? '';
    $respuesta_seguridad = $_POST['respuesta_seguridad'] ?? '';

    // Paso 1: Validar las contraseñas
    if ($contrasena_nueva !== $confirmar_contrasena) {
        // Redirigir con error si no coinciden
        header("Location: configuracion.php?error=1");
        exit;
    }

    // Paso 2: Obtener la contraseña cifrada de la base de datos para verificar
    $sql = "SELECT contrasena FROM usuarios WHERE nombre = ?";
    $stmt = $conexion->prepare($sql);
    $stmt->bind_param("s", $nombre_usuario_actual);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $usuario_db = $resultado->fetch_assoc();

    // Paso 3: Verificar la contraseña actual
    if (password_verify($contrasena_actual, $usuario_db['contrasena'])) {
        // La contraseña actual es correcta, ahora podemos actualizar los datos

        // Cifrar la nueva contraseña
        $contrasena_nueva_cifrada = password_hash($contrasena_nueva, PASSWORD_DEFAULT);

        // Paso 4: Preparar la consulta UPDATE
        $sql_update = "UPDATE usuarios SET contrasena = ?, pregunta_seguridad = ?, respuesta_seguridad = ? WHERE nombre = ?";
        $stmt_update = $conexion->prepare($sql_update);
        $stmt_update->bind_param("ssss", $contrasena_nueva_cifrada, $pregunta_seguridad, $respuesta_seguridad, $nombre_usuario_actual);

        // Paso 5: Ejecutar la actualización
        if ($stmt_update->execute()) {
            header("Location: configuracion.php?exito=1");
            exit;
        } else {
            header("Location: configuracion.php?error=2");
            exit;
        }
        $stmt_update->close();
    } else {
        // Contraseña actual incorrecta
        header("Location: configuracion.php?error=3");
        exit;
    }

    $stmt->close();
} else {
    // Si no es un POST, redirigir
    header("Location: configuracion.php");
    exit;
}
$conexion->close();
?>