<?php
session_start();
require_once 'conexion.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/Exception.php';
require 'PHPMailer/PHPMailer.php';
require 'PHPMailer/SMTP.php';

// 1. Validar seguridad: Solo el Administrador puede hacer esto
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || $_SESSION['cargo'] !== 'Administrador') {
    $_SESSION['error_message'] = "Acceso denegado. Requiere permisos de Administrador.";
    header("Location: login.php");
    exit();
}

// 2. Capturar la cédula que viene por la URL (ej: reenviar_correo.php?cedula=V26530793)
$cedula = trim($_GET['cedula'] ?? '');

if (empty($cedula)) {
    $_SESSION['error_message'] = "❌ Error: Cédula de usuario no especificada.";
    header("Location: usuarios_listar.php");
    exit();
}

// 3. Buscar los datos del usuario en la base de datos de PostgreSQL
$sql = "SELECT nombre, apellido, nombre_usuario, correo, cargo FROM usuarios WHERE cedula = $1";
$resultado = pg_query_params($conexion, $sql, array($cedula));

if ($resultado && pg_num_rows($resultado) > 0) {
    $usuario = pg_fetch_assoc($resultado);
    
    $nombre_completo  = $usuario['nombre'] . ' ' . $usuario['apellido'];
    $nombre_usuario   = $usuario['nombre_usuario'];
    $correo           = $usuario['correo'];
    $cargo            = $usuario['cargo'];
    $pass_provisional = "12345678"; // O la contraseña actual si prefieres no resetearla

    // 4. Configurar y enviar el correo con PHPMailer
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'luisaelena252@gmail.com'; 
        $mail->Password   = 'tknq bsof oflb bmqr';     
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = 465;

        $mail->setFrom('luisaelena252@gmail.com', 'SGR - IACTP (Administracion del Sistema)');
        $mail->addAddress($correo, $nombre_completo);

        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = 'Reenvio de Credenciales de Acceso - SGR-IACTP';
        
        $mail->Body = "
        <div style='font-family: Arial, sans-serif; color: #333; line-height: 1.6; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 5px;'>
            <h2 style='color: #0d6efd; text-align: center;'>Reenvío de Credenciales - SGR-IACTP</h2>
            <p>Estimado(a) <strong>{$nombre_completo}</strong>,</p>
            <p>Se han generado nuevamente sus datos de acceso para el <strong>Sistema de Gestión de Redenciones (SGR-IACTP)</strong> con el cargo de <strong>{$cargo}</strong>.</p>
            
            <div style='background-color: #f8f9fa; padding: 15px; border-left: 4px solid #0d6efd; margin: 20px 0;'>
                <p style='margin: 0;'><strong>Nombre de Usuario:</strong> <code>{$nombre_usuario}</code></p>
                <p style='margin: 5px 0 0 0;'><strong>Contraseña Provisional:</strong> <code>{$pass_provisional}</code></p>
            </div>

            <p><strong>Indicaciones importantes:</strong></p>
            <ul>
                <li>Utilice el botón de abajo para ingresar al sistema.</li>
                <li>Al iniciar sesión por primera vez con esta clave provisional, el sistema le solicitará cambiarla obligatoriamente.</li>
            </ul>

            <p style='text-align: center; margin-top: 30px;'>
                <a href='http://localhost/proyecto/login.php' style='background-color: #0d6efd; color: #fff; padding: 10px 20px; text-decoration: none; border-radius: 4px; font-weight: bold;'>Ingresar al Sistema</a>
            </p>

            <hr style='border: none; border-top: 1px solid #eee; margin-top: 30px;'>
            <p style='font-size: 11px; color: #6c757d; text-align: center;'>Este es un mensaje automático generado por el Sistema de Gestión de Redenciones (IACTP).</p>
        </div>
        ";

        $mail->send();
        $_SESSION['mensaje_exito'] = "✅ El correo con las credenciales fue reenviado exitosamente a {$correo}.";

    } catch (Exception $e) {
        $_SESSION['mensaje_error'] = "❌ El usuario existe, pero ocurrió un error al enviar el correo: " . $mail->ErrorInfo;
    }

} else {
    $_SESSION['mensaje_error'] = "❌ No se encontró ningún usuario registrado con esa cédula.";
}

if (isset($conexion)) {
    pg_close($conexion);
}

// Redirigir de vuelta al listado de usuarios
header("Location: usuarios_listar.php");
exit();
?>