<?php
session_start();
// Asegúrate de que el nombre del archivo de conexión es correcto
require 'conexion.php'; 

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $nombre_usuario = $_POST['nombre_usuario'] ?? '';
    $contrasena_ingresada = $_POST['contrasena'] ?? '';

    // Si falta algún dato, redirigir al login
    if (empty($nombre_usuario) || empty($contrasena_ingresada)) {
        header('Location: login.php?error=1');
        exit;
    }

    // 1. Preparar la consulta: En PostgreSQL usamos $1 en lugar de ?
    $sql = "SELECT cedula, nombre_usuario, contrasena, cargo, debe_cambiar_pass, nombre, apellido, activo, foto_perfil
            FROM usuarios 
            WHERE nombre_usuario = $1";
    
    // 2. Ejecutar la consulta de forma segura con los parámetros
    $resultado = pg_query_params($conexion, $sql, array($nombre_usuario));
    
    // Verificación de errores en la consulta
    if ($resultado === false) {
        error_log("Error de BD en validar.php: " . pg_last_error($conexion));
        header('Location: login.php?error=1');
        exit;
    }
    
    // 3. Contar los resultados
    if (pg_num_rows($resultado) == 1) {
        $usuario = pg_fetch_assoc($resultado);
        
        // 4. Verificar la contraseña
        if (password_verify($contrasena_ingresada, $usuario['contrasena'])) {
            
            // --- VERIFICACIÓN DE SEGURIDAD: Usuario Activo ---
            // PostgreSQL devuelve 't' (true) o 'f' (false) como texto para los campos booleanos
            if ($usuario['activo'] === 'f' || $usuario['activo'] === false) {
                // Si el usuario está inactivo, bloqueamos el acceso
                $_SESSION['error_message'] = "Su usuario se encuentra inactivo. Comuníquese con el administrador.";
                pg_free_result($resultado);
                pg_close($conexion);
                header('Location: login.php'); 
                exit;
            }
            // --- FIN VERIFICACIÓN ACTIVO ---

            // El usuario está ACTIVO y la contraseña es correcta
            
            // 5. Revisar la bandera 'debe_cambiar_pass' (Primer Ingreso)
            if ($usuario['debe_cambiar_pass'] === 't' || $usuario['debe_cambiar_pass'] === true) {
                
                $_SESSION['temp_login'] = true;
                $_SESSION['usuario_cambio'] = $usuario['nombre_usuario']; 
                $_SESSION['cedula'] = $usuario['cedula'];
                // Omitimos cargar la foto y el cargo completo si va a la pantalla de cambio de contraseña
                header('Location: cambiar_contrasena.php');
                exit;
                
            } else {
                // Iniciamos sesión normalmente.
                $_SESSION['loggedin'] = true;
                $_SESSION['cedula'] = $usuario['cedula'];
                $_SESSION['nombre_usuario'] = $usuario['nombre_usuario'];
                $_SESSION['cargo'] = $usuario['cargo'];
                
                // Guardamos el nombre y apellido en la sesión
                $_SESSION['nombre'] = $usuario['nombre'];
                $_SESSION['apellido'] = $usuario['apellido'];

                // 🔑 PASO CLAVE: Aseguramos que la ruta de la foto sea root-relative para el header.php
                $foto_db_path = $usuario['foto_perfil'];
                if (!empty($foto_db_path) && $foto_db_path[0] !== '/') {
                    // Si la ruta no está vacía y no comienza con /, la agregamos.
                    $foto_db_path = '/' . $foto_db_path;
                }
                
                $_SESSION['foto_perfil'] = $foto_db_path;
                // -------------------------------------------------------------------------------------
                
                // 🚀 Redirección final a la página principal
                header('Location: index.php'); 
                exit;
            }
            
        } else {
            // Contraseña incorrecta
            header('Location: login.php?error=1');
            exit;
        }
    } else {
        // Usuario no existe
        header('Location: login.php?error=1');
        exit;
    }
    
    // Limpiamos la memoria y cerramos la conexión
    pg_free_result($resultado);
    pg_close($conexion);
}
?>