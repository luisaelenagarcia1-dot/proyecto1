<?php
session_start();
require_once 'conexion.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/Exception.php';
require 'PHPMailer/PHPMailer.php';
require 'PHPMailer/SMTP.php';

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: login.php");
    exit();
}

$inscripcion_id = $_GET['id'] ?? '';

if (empty($inscripcion_id)) {
    $_SESSION['mensaje_error'] = "❌ ID de inscripción no especificado para el reenvío.";
    header("Location: inscripciones_listado.php");
    exit();
}

// Consultar datos completos del inscrito, curso, centro y correo
$sql = "SELECT ie.*, p.nombre, p.apellido, p.documento_identidad, p.correo, 
               c.nombre_curso, s.codigo_nomenclatura, cn.nombre as nombre_centro
        FROM inscripciones_educativas ie
        JOIN privados p ON ie.privado_documento = p.documento_identidad
        JOIN secciones_cursos s ON ie.seccion_id = s.id
        JOIN cursos c ON s.curso_id = c.id
        LEFT JOIN centros cn ON s.centro_codigo = cn.codigo
        WHERE ie.id = $1";

$res = pg_query_params($conexion, $sql, array($inscripcion_id));

if (!$res || pg_num_rows($res) === 0) {
    $_SESSION['mensaje_error'] = "❌ No se encontró el registro para enviar el correo.";
    header("Location: inscripciones_listado.php");
    exit();
}

$data = pg_fetch_assoc($res);
$correo = $data['correo'];
$nombre_completo = $data['apellido'] . ', ' . $data['nombre'];
$documento = $data['documento_identidad'];
$nombre_curso = $data['nombre_curso'];
$nomenclatura = $data['codigo_nomenclatura'];
$nombre_centro = $data['nombre_centro'] ?? 'Centro Penitenciario';
$fecha_inscripcion = date('d/m/Y', strtotime($data['fecha_inscripcion']));
$anio_actual = date('Y');

if (empty($correo) || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['mensaje_error'] = "❌ El estudiante no posee un correo electrónico válido registrado.";
    header("Location: inscripciones_listado.php");
    exit();
}

// Generar el contenido HTML en formato Carta con espacios para logotipos
$html_constancia = "
<!DOCTYPE html>
<html lang='es'>
<head>
    <meta charset='UTF-8'>
    <title>Constancia de Inscripción - V-{$documento}</title>
    <style>
        @page {
            size: letter;
            margin: 2.5cm;
        }
        body {
            font-family: 'Times New Roman', Times, serif;
            color: #000;
            line-height: 1.8;
            margin: 0;
            padding: 0;
            background-color: #fff;
            font-size: 13pt;
        }
        .constancia-page {
            width: 100%;
            max-width: 750px;
            margin: 0 auto;
            padding: 10px;
            box-sizing: border-box;
        }
        .header-tabla {
            width: 100%;
            border-bottom: 3px double #000;
            padding-bottom: 15px;
            margin-bottom: 30px;
        }
        .header-tabla td {
            vertical-align: middle;
        }
        .logo-izq, .logo-der {
            width: 15%;
            text-align: center;
            font-size: 9pt;
            color: #555;
        }
        .logo-placeholder {
            width: 65px;
            height: 65px;
            border: 1px dashed #999;
            display: inline-block;
            line-height: 65px;
            text-align: center;
            font-size: 8pt;
            background: #f9f9f9;
        }
        .header-institucional {
            width: 70%;
            text-align: center;
        }
        .header-institucional h2 {
            margin: 0;
            font-size: 13pt;
            text-transform: uppercase;
            font-weight: bold;
        }
        .header-institucional h3 {
            margin: 3px 0;
            font-size: 11pt;
            color: #333;
            font-weight: normal;
        }
        .titulo-documento {
            text-align: center;
            margin: 35px 0;
        }
        .titulo-documento h1 {
            font-size: 18pt;
            text-decoration: underline;
            letter-spacing: 1px;
            margin: 0;
            font-weight: bold;
        }
        .cuerpo-texto {
            text-align: justify;
            margin-bottom: 20px;
            text-indent: 40px;
        }
        .detalles-curso {
            margin: 15px 30px;
        }
        .detalles-curso ul {
            list-style-type: none;
            padding: 0;
        }
        .detalles-curso li {
            margin-bottom: 8px;
            padding-bottom: 4px;
            border-bottom: 1px dotted #ccc;
        }
        .pie-firma {
            margin-top: 80px;
            text-align: center;
        }
        .linea-firma {
            border-top: 1px solid #000;
            width: 250px;
            margin: 0 auto 8px auto;
        }
    </style>
</head>
<body>
    <div class='constancia-page'>
        <!-- Encabezado institucional con tablas para logotipos -->
        <table class='header-tabla'>
            <tr>
                <td class='logo-izq'>
                    <div class='logo-placeholder'>LOGO 1</div>
                </td>
                <td class='header-institucional'>
                    <h2>República Bolivariana de Venezuela</h2>
                    <h2>Instituto Autónomo Caja de Trabajo Penitenciario</h2>
                    <h3>Coordinación de Educación y Redención de Pena</h3>
                    <h3>{$nombre_centro}</h3>
                </td>
                <td class='logo-der'>
                    <div class='logo-placeholder'>LOGO 2</div>
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
                <li><strong>Cédula de Identidad / Documento:</strong> V-{$documento}</li>
                <li><strong>Programa de Formación (Curso):</strong> {$nombre_curso}</li>
                <li><strong>Nomenclatura de Sección:</strong> {$nomenclatura}</li>
                <li><strong>Fecha de Inscripción:</strong> {$fecha_inscripcion}</li>
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
    $mail->Subject = 'Reenvío de Constancia de Inscripción Educativa - SGR IACTP';
    
    // Cuerpo del mensaje del correo
    $mail->Body = "
    <p>Estimado(a) <strong>{$nombre_completo}</strong>,</p>
    <p>Adjunto a este correo encontrará el documento digital de su <strong>Constancia de Inscripción Oficial</strong> en formato carta emitida por el sistema SGR-IACTP.</p>
    <p><strong>Curso:</strong> {$nombre_curso} <br><strong>Sección:</strong> {$nomenclatura}</p>
    <p style='color: #6c757d; font-size: 12px;'>Sistema de Gestión de Redenciones (SGR-IACTP)</p>
    ";

    // Adjuntar la constancia generada
    $mail->addStringAttachment($html_constancia, "Constancia_Inscripcion_{$documento}.html", 'base64', 'text/html');

    $mail->send();
    $_SESSION['mensaje_exito'] = "✅ Constancia en formato carta adjunta reenviada exitosamente a: {$correo}";
} catch (Exception $e) {
    $_SESSION['mensaje_error'] = "⚠️ No se pudo enviar el correo. Error: {$mail->ErrorInfo}";
}

header("Location: inscripciones_listado.php");
exit();
?>