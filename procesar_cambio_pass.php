<?php
session_start();

// Seguridad: Solo procesamos si existe la sesión temporal y el usuario
if (!isset($_SESSION['temp_login']) || !isset($_SESSION['usuario_cambio'])) {
    header('Location: login.php');
    exit;
}

require 'conexion.php'; // Tu archivo de conexión

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // --- ¡CAMBIO 3: OBTENER TODOS LOS DATOS! ---
    $nombre_usuario = $_SESSION['usuario_cambio'];
    
    // Datos de Contraseña
    $nueva = $_POST['nueva_contrasena'];
    $confirmar = $_POST['confirmar_contrasena'];
    
    // Datos de Seguridad
    $pregunta = $_POST['pregunta_seguridad'];
    $respuesta = $_POST['respuesta_seguridad'];

    // 1. Validaciones
    if ($nueva !== $confirmar) {
        header('Location: cambiar_contrasena.php?error=no_coinciden');
        exit;
    }
    if (strlen($nueva) < 8) {
        header('Location: cambiar_contrasena.php?error=corta');
        exit;
    }
    // Validar que los campos de seguridad no estén vacíos
    if (empty($pregunta) || empty(trim($respuesta))) {
        header('Location: cambiar_contrasena.php?error=campos_vacios');
        exit;
    }

    // 2. Hashear la nueva contraseña
    $nuevo_hash = password_hash($nueva, PASSWORD_DEFAULT);
    
    // 3. Estandarizar la respuesta (la guardamos en minúsculas y sin espacios)
    $respuesta_limpia = strtolower(trim($respuesta));

    // 4. Actualizar la base de datos (Contraseña, Bandera Y Preguntas)
    $sql = "UPDATE usuarios 
            SET contrasena = ?, 
                debe_cambiar_pass = 0, 
                pregunta_seguridad = ?, 
                respuesta_seguridad = ? 
            WHERE nombre_usuario = ?";
    
    $stmt = $conexion->prepare($sql);
    // s = string, s = string, s = string, s = string
    $stmt->bind_param("ssss", $nuevo_hash, $pregunta, $respuesta_limpia, $nombre_usuario);
    
    if ($stmt->execute()) {
        // ¡Éxito!
        session_unset();
        session_destroy();
        
        // Redirigimos al login con mensaje de éxito
        header('Location: login.php?exito=cambio');
        exit;
        
    } else {
        // Error de base de datos
        header('Location: cambiar_contrasena.php?error=db');
        exit;
    }
}
?>