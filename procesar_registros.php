<?php
session_start();
require_once 'conexion.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/Exception.php';
require 'PHPMailer/PHPMailer.php';
require 'PHPMailer/SMTP.php';

// Validar que la petición sea por método POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: registrar_usuario.php");
    exit();
}

// --- VALIDACIÓN DE SEGURIDAD ESTRICTA ---
// Solo el rol de Administrador puede procesar y registrar personal
$rol_sesion = $_SESSION['cargo'] ?? $_SESSION['rol'] ?? '';

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || 
    (strcasecmp($rol_sesion, 'Administrador') !== 0 && strcasecmp($rol_sesion, 'Administradora') !== 0)) {
    $_SESSION['error_message'] = "Acceso denegado. Requiere permisos de Administrador.";
    header("Location: login.php");
    exit();
}

// Capturar y limpiar los datos del formulario
$cedula            = trim($_POST['cedula'] ?? '');
$nombre            = trim($_POST['nombre'] ?? '');
$apellido          = trim($_POST['apellido'] ?? '');
$nombre_usuario    = trim($_POST['nombre_usuario'] ?? '');
$correo            = trim($_POST['correo'] ?? '');
$telefono          = trim($_POST['telefono'] ?? '');
$cargo             = trim($_POST['cargo'] ?? '');
$centros_asignados = $_POST['centros_codigos'] ?? []; // Array con los centros seleccionados

if (empty($cedula) || empty($nombre) || empty($apellido) || empty($nombre_usuario) || empty($correo) || empty($cargo)) {
    header("Location: registrar_usuario.php?error=campos_vacios");
    exit();
}

// 1. Verificar si ya existe la cédula, el nombre de usuario, el correo o el teléfono para evitar duplicados
$sql_check = "SELECT cedula FROM usuarios WHERE cedula = $1 OR nombre_usuario = $2 OR correo = $3 OR telefono = $4";
$res_check = pg_query_params($conexion, $sql_check, array($cedula, $nombre_usuario, $correo, $telefono));

if ($res_check && pg_num_rows($res_check) > 0) {
    header("Location: registrar_usuario.php?error=duplicado");
    exit();
}

// 2. Definir contraseña provisional por defecto y cifrarla con password_hash
$pass_provisional = "12345678";
$contrasena_hash  = password_hash($pass_provisional, PASSWORD_DEFAULT);

// Iniciar transacción en PostgreSQL para asegurar integridad total
pg_query($conexion, "BEGIN");

// 3. Insertar el usuario principal en la tabla 'usuarios'
$sql_insert = "INSERT INTO usuarios (cedula, nombre_usuario, contrasena, nombre, apellido, correo, telefono, cargo, activo, debe_cambiar_pass) 
               VALUES ($1, $2, $3, $4, $5, $6, $7, $8, 1, 1)";
$res_insert = pg_query_params($conexion, $sql_insert, array(
    $cedula, 
    $nombre_usuario, 
    $contrasena_hash, 
    $nombre, 
    $apellido, 
    $correo, 
    $telefono, 
    $cargo
));

if (!$res_insert) {
    pg_query($conexion, "ROLLBACK");
    header("Location: registrar_usuario.php?error=bd");
    exit();
}

// 4. Si el cargo es Coordinador Regional y seleccionó centros, validarlos y guardarlos en 'usuario_centros'
if ($cargo === 'Coordinador Regional' && !empty($centros_asignados)) {
    foreach ($centros_asignados as $centro_cod) {
        
        // Validar si el centro ya se encuentra asignado a otro usuario activo
        $sql_check_centro = "SELECT usuario_cedula FROM usuario_centros WHERE centro_codigo = $1";
        $res_check_centro = pg_query_params($conexion, $sql_check_centro, array($centro_cod));
        
        if ($res_check_centro && pg_num_rows($res_check_centro) > 0) {
            pg_query($conexion, "ROLLBACK");
            header("Location: registrar_usuario.php?error=centro_ocupado");
            exit();
        }

        $sql_uc = "INSERT INTO usuario_centros (usuario_cedula, centro_codigo) VALUES ($1, $2)";
        $res_uc = pg_query_params($conexion, $sql_uc, array($cedula, $centro_cod));
        
        if (!$res_uc) {
            pg_query($conexion, "ROLLBACK");
            header("Location: registrar_usuario.php?error=bd_centros");
            exit();
        }
    }
}

// Confirmar transacción de manera exitosa
pg_query($conexion, "COMMIT");

// 5. Enviar correo electrónico con PHPMailer notificando su registro y credenciales provisionales
$nombre_completo = $nombre . ' ' . $apellido;
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
    $mail->Subject = 'Bienvenido al Sistema de Gestion de Redenciones (SGR-IACTP)';
    
    $mail->Body = "
    <div style='font-family: Arial, sans-serif; color: #333; line-height: 1.6; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 5px;'>
        <h2 style='color: #0d6efd; text-align: center;'>¡Registro Exitoso en el SGR-IACTP!</h2>
        <p>Estimado(a) <strong>{$nombre_completo}</strong>,</p>
        <p>Su cuenta de usuario ha sido registrada en el <strong>Sistema de Gestión de Redenciones (SGR-IACTP)</strong> con el cargo institucional de <strong>{$cargo}</strong>.</p>
        
        <div style='background-color: #f8f9fa; padding: 15px; border-left: 4px solid #0d6efd; margin: 20px 0;'>
            <p style='margin: 0;'><strong>Nombre de Usuario:</strong> <code>{$nombre_usuario}</code></p>
            <p style='margin: 5px 0 0 0;'><strong>Contraseña Provisional:</strong> <code>{$pass_provisional}</code></p>
        </div>

        <p><strong>Indicaciones importantes:</strong></p>
        <ul>
            <li>Al ingresar al sistema por primera vez con su contraseña provisional, el sistema le exigirá realizar el cambio obligatorio de su clave por motivos de seguridad.</li>
            <li>Su perfil cuenta con permisos y restricciones específicas según su rol asignado.</li>
        </ul>

        <p style='text-align: center; margin-top: 30px;'>
            <a href='http://localhost/proyecto/login.php' style='background-color: #0d6efd; color: #fff; padding: 10px 20px; text-decoration: none; border-radius: 4px; font-weight: bold;'>Ingresar al Sistema</a>
        </p>

        <hr style='border: none; border-top: 1px solid #eee; margin-top: 30px;'>
        <p style='font-size: 11px; color: #6c757d; text-align: center;'>Este es un mensaje automático generado por el Sistema de Gestión de Redenciones (IACTP). Por favor, no responda a este correo.</p>
    </div>
    ";

    $mail->send();
    header("Location: registrar_usuario.php?exito=1");
    exit();

} catch (Exception $e) {
    header("Location: registrar_usuario.php?exito=1&mail_error=1");
    exit();
}

if (isset($conexion)) {
    pg_close($conexion);
}
?>