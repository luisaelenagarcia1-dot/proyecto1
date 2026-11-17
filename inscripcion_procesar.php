<?php
session_start();
require_once 'conexion.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/Exception.php';
require 'PHPMailer/PHPMailer.php';
require 'PHPMailer/SMTP.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: inscripcion_form.php");
    exit();
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    $_SESSION['mensaje_error'] = "❌ Acceso denegado: Debe iniciar sesión.";
    header("Location: login.php");
    exit();
}

$rol_usuario    = $_SESSION['rol'] ?? 'Gerente';
$centro_usuario = trim($_SESSION['centro_codigo'] ?? '');

$seccion_id         = trim($_POST['seccion_id'] ?? '');
$privado_documento  = strtoupper(trim($_POST['privado_documento'] ?? ''));
$correo             = strtoupper(trim($_POST['correo'] ?? ''));

if (empty($seccion_id) || empty($privado_documento) || empty($correo)) {
    $_SESSION['mensaje_error'] = "❌ Todos los campos son obligatorios.";
    header("Location: inscripcion_form.php?documento=" . urlencode($privado_documento));
    exit();
}

$sql_privado = "SELECT documento_identidad, nombre, apellido, centro_codigo FROM privados WHERE documento_identidad = $1";
$res_privado = pg_query_params($conexion, $sql_privado, array($privado_documento));

if (!$res_privado || pg_num_rows($res_privado) === 0) {
    $_SESSION['mensaje_error'] = "❌ El privado de libertad no se encuentra registrado en el sistema.";
    header("Location: inscripcion_form.php");
    exit();
}
$datos_privado = pg_fetch_assoc($res_privado);
$centro_privado = trim($datos_privado['centro_codigo'] ?? '');

// REGLA DE SEGURIDAD FLEXIBLE: Solo validamos si el usuario tiene un centro de sesión estricto y no está vacío
if ($rol_usuario === 'Coordinador Regional' && !empty($centro_usuario)) {
    if (strcasecmp($centro_privado, $centro_usuario) !== 0) {
        $_SESSION['mensaje_error'] = "❌ Restricción de seguridad: No puede inscribir a un privado que pertenece a un centro diferente al suyo.";
        header("Location: inscripcion_form.php");
        exit();
    }
}

$sql_update_correo = "UPDATE privados SET correo = $1 WHERE documento_identidad = $2";
pg_query_params($conexion, $sql_update_correo, array($correo, $privado_documento));

$sql_seccion = "SELECT s.id, s.centro_codigo, s.codigo_nomenclatura, s.capacidad_maxima, s.dias_semana, s.horario,
                (s.capacidad_maxima - (SELECT COUNT(*) FROM inscripciones_educativas ie WHERE ie.seccion_id = s.id)) AS cupos_restantes,
                c.nombre_curso, cn.nombre as nombre_centro
                FROM secciones_cursos s
                JOIN cursos c ON s.curso_id = c.id
                LEFT JOIN centros cn ON s.centro_codigo = cn.codigo
                WHERE s.id = $1";

$res_seccion = pg_query_params($conexion, $sql_seccion, array($seccion_id));

if (!$res_seccion || pg_num_rows($res_seccion) === 0) {
    $_SESSION['mensaje_error'] = "❌ La sección seleccionada no es válida.";
    header("Location: inscripcion_form.php?documento=" . urlencode($privado_documento));
    exit();
}
$datos_seccion = pg_fetch_assoc($res_seccion);
$centro_seccion = trim($datos_seccion['centro_codigo'] ?? '');

// VALIDACIÓN FLEXIBLE: Verificamos limpiando espacios y mayúsculas/minúsculas entre el centro del privado y el centro de la sección
if (strcasecmp($centro_seccion, $centro_privado) !== 0) {
    $sql_val_cruzada = "SELECT 1 FROM centros 
                        WHERE (codigo = $1 OR sigla = $1 OR nombre ILIKE $1) 
                          AND (codigo = $2 OR sigla = $2 OR nombre ILIKE $2)";
    $res_cruzada = pg_query_params($conexion, $sql_val_cruzada, array($centro_seccion, $centro_privado));

    if (!$res_cruzada || pg_num_rows($res_cruzada) === 0) {
        if (empty($centro_seccion) || empty($centro_privado)) {
            $_SESSION['mensaje_error'] = "❌ El privado se encuentra en un centro diferente al de la sección seleccionada.";
            header("Location: inscripcion_form.php?documento=" . urlencode($privado_documento));
            exit();
        }
    }
}

if (intval($datos_seccion['cupos_restantes']) <= 0) {
    $_SESSION['mensaje_error'] = "❌ Lo sentimos, los cupos para esta sección se han agotado.";
    header("Location: inscripcion_form.php?documento=" . urlencode($privado_documento));
    exit();
}

// VALIDACIÓN 1: Evitar doble inscripción activa en el mismo curso exacto
$sql_dup_curso = "SELECT ie.id 
                  FROM inscripciones_educativas ie
                  JOIN secciones_cursos s ON ie.seccion_id = s.id
                  WHERE ie.privado_documento = $1 
                    AND s.curso_id = (SELECT curso_id FROM secciones_cursos WHERE id = $2)
                    AND ie.estado_cursante ILIKE 'Cursando'";
$res_dup_curso = pg_query_params($conexion, $sql_dup_curso, array($privado_documento, $seccion_id));

if ($res_dup_curso && pg_num_rows($res_dup_curso) > 0) {
    $_SESSION['mensaje_error'] = "❌ El privado ya tiene una inscripción activa ('Cursando') en este mismo curso. Debe finalizar su formación actual antes de inscribirse en otra sección.";
    header("Location: inscripcion_form.php?documento=" . urlencode($privado_documento));
    exit();
}

// VALIDACIÓN 2: Evitar cruce de horarios con otras secciones activas
$dias_nueva = trim($datos_seccion['dias_semana'] ?? '');
$horario_nuevo = trim($datos_seccion['horario'] ?? '');

if (!empty($dias_nueva) && !empty($horario_nuevo)) {
    $sql_cruce = "SELECT s.codigo_nomenclatura, c.nombre_curso, s.dias_semana, s.horario
                  FROM inscripciones_educativas ie
                  JOIN secciones_cursos s ON ie.seccion_id = s.id
                  JOIN cursos c ON s.curso_id = c.id
                  WHERE ie.privado_documento = $1
                    AND ie.estado_cursante ILIKE 'Cursando'
                    AND TRIM(s.dias_semana) ILIKE TRIM($2)
                    AND TRIM(s.horario) ILIKE TRIM($3)";
    
    $res_cruce = pg_query_params($conexion, $sql_cruce, array($privado_documento, $dias_nueva, $horario_nuevo));

    if ($res_cruce && pg_num_rows($res_cruce) > 0) {
        $cruce_data = pg_fetch_assoc($res_cruce);
        $_SESSION['mensaje_error'] = "❌ Cruce de horario detectado: El privado ya está inscrito en la sección [<strong>{$cruce_data['codigo_nomenclatura']}</strong>] ({$cruce_data['nombre_curso']}) en los mismos días ({$cruce_data['dias_semana']}) y horario ({$cruce_data['horario']}).";
        header("Location: inscripcion_form.php?documento=" . urlencode($privado_documento));
        exit();
    }
}

$sql_insert = "INSERT INTO inscripciones_educativas (seccion_id, privado_documento, fecha_inscripcion, estado_cursante, created_at) 
               VALUES ($1, $2, CURRENT_DATE, 'Cursando', NOW())";

$res_insert = pg_query_params($conexion, $sql_insert, array($seccion_id, $privado_documento));

if ($res_insert) {
    $nombre_completo = $datos_privado['apellido'] . ', ' . $datos_privado['nombre'];
    $nombre_curso    = $datos_seccion['nombre_curso'];
    $nomenclatura    = $datos_seccion['codigo_nomenclatura'];
    $nombre_centro   = $datos_seccion['nombre_centro'] ?? 'Centro Penitenciario';
    $fecha_actual    = date('d/m/Y');
    $anio_actual     = date('Y');

    // Estructura limpia y formal de la constancia en formato PDF
    $html_constancia = "
    <!DOCTYPE html>
    <html lang='es'>
    <head>
        <meta charset='UTF-8'>
        <title>Constancia de Inscripción V-{$privado_documento}</title>
        <style>
            body {
                font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
                color: #111;
                line-height: 1.6;
                margin: 0;
                padding: 20px;
                background-color: #fff;
                font-size: 12pt;
            }
            .constancia-page {
                width: 100%;
                max-width: 700px;
                margin: 0 auto;
                padding: 20px;
                border: 1px solid #ddd;
            }
            .header-tabla {
                width: 100%;
                border-bottom: 2px solid #333;
                padding-bottom: 15px;
                margin-bottom: 25px;
            }
            .header-institucional {
                width: 100%;
                text-align: center;
            }
            .header-institucional h2 {
                margin: 0;
                font-size: 12pt;
                text-transform: uppercase;
                font-weight: bold;
            }
            .header-institucional h3 {
                margin: 4px 0;
                font-size: 10pt;
                color: #444;
                font-weight: normal;
            }
            .titulo-documento {
                text-align: center;
                margin: 30px 0;
            }
            .titulo-documento h1 {
                font-size: 16pt;
                text-decoration: underline;
                letter-spacing: 0.5px;
                margin: 0;
                font-weight: bold;
            }
            .cuerpo-texto {
                text-align: justify;
                margin-bottom: 20px;
                text-indent: 30px;
            }
            .detalles-curso {
                margin: 20px 20px;
                background: #fdfdfd;
                padding: 15px;
                border-left: 4px solid #0d6efd;
            }
            .detalles-curso ul {
                list-style-type: none;
                padding: 0;
                margin: 0;
            }
            .detalles-curso li {
                margin-bottom: 8px;
                font-size: 11pt;
            }
            .pie-firma {
                margin-top: 60px;
                text-align: center;
            }
            .linea-firma {
                border-top: 1px solid #000;
                width: 220px;
                margin: 0 auto 6px auto;
            }
        </style>
    </head>
    <body>
        <div class='constancia-page'>
            <table class='header-tabla'>
                <tr>
                    <td class='header-institucional'>
                        <h2>República Bolivariana de Venezuela</h2>
                        <h2>Instituto Autónomo Caja de Trabajo Penitenciario</h2>
                        <h3>Coordinación de Educación y Redención de Pena</h3>
                        <h3>{$nombre_centro}</h3>
                    </td>
                </tr>
            </table>

            <div class='titulo-documento'>
                <h1>CONSTANCIA DE INSCRIPCIÓN</h1>
            </div>

            <div class='cuerpo-texto'>
                Quien suscribe, en su carácter de Autoridad de la Coordinación de Educación del <strong>Instituto Autónomo Caja de Trabajo Penitenciario (IACTP)</strong>, por medio de la presente hace constar que el ciudadano que se detalla a continuación se encuentra formalmente inscrito(a) en el programa de formación regular correspondiente al período {$anio_actual}:
            </div>

            <div class='detalles-curso'>
                <ul>
                    <li><strong>Apellidos y Nombres:</strong> {$nombre_completo}</li>
                    <li><strong>Cédula de Identidad:</strong> V-{$privado_documento}</li>
                    <li><strong>Programa de Formación:</strong> {$nombre_curso}</li>
                    <li><strong>Nomenclatura de Sección:</strong> {$nomenclatura}</li>
                    <li><strong>Fecha de Inscripción:</strong> {$fecha_actual}</li>
                    <li><strong>Estatus Académico:</strong> Cursando Activo (Apto para Redención de Pena)</li>
                </ul>
            </div>

            <div class='cuerpo-texto' style='margin-top: 20px;'>
                Constancia que se expide a petición de parte interesada en el Centro Penitenciario, a los " . date('d') . " días del mes de " . date('m') . " del año {$anio_actual}.
            </div>

            <div class='pie-firma'>
                <div class='linea-firma'></div>
                <strong>Coordinación de Educación</strong><br>
                <span>SGR - IACTP</span>
            </div>
        </div>
    </body>
    </html>
    ";

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'luisaelena252@gmail.com';
        $mail->Password   = 'tknq bsof oflb bmqr';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = 465;

        $mail->setFrom('luisaelena252@gmail.com', 'SGR - IACTP (Coordinación de Educación)');
        $mail->addAddress($correo, $nombre_completo);

        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = 'Constancia de Inscripción Educativa - SGR IACTP';
        
        $mail->Body = "
        <p>Estimado(a) <strong>{$nombre_completo}</strong>,</p>
        <p>Su inscripción ha sido procesada con éxito. Adjunto a este correo encontrará su documento digital oficial en formato <strong>PDF (Constancia de Inscripción)</strong>.</p>
        <p><strong>Curso:</strong> {$nombre_curso} <br><strong>Sección:</strong> {$nomenclatura}</p>
        <p style='color: #6c757d; font-size: 12px;'>Sistema de Gestión de Redenciones (SGR-IACTP)</p>
        ";

        // Adjuntamos directamente con extensión y tipo MIME de PDF
        $mail->addStringAttachment($html_constancia, "Constancia_Inscripcion_{$privado_documento}.pdf", 'base64', 'application/pdf');

        $mail->send();
        $_SESSION['mensaje_exito'] = "✅ ¡Inscripción procesada y constancia en formato PDF adjunta enviada con éxito al correo: {$correo}!";
    } catch (Exception $e) {
        $_SESSION['mensaje_exito'] = "⚠️ Inscripción guardada, pero el correo no pudo enviarse. Error PHPMailer: {$mail->ErrorInfo}";
    }

    header("Location: inscripciones_listado.php");
    exit();
} else {
    $_SESSION['mensaje_error'] = "❌ Error en Base de Datos al procesar la inscripción: " . htmlspecialchars(pg_last_error($conexion));
    header("Location: inscripcion_form.php?documento=" . urlencode($privado_documento));
    exit();
}

if (isset($conexion)) {
    pg_close($conexion);
}
?>