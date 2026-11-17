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

// Obtener rol y limpiar la cédula de forma flexible con la "V"
$rol_usuario     = $_SESSION['cargo'] ?? $_SESSION['rol'] ?? 'Gerente';
$es_coordinador  = (strcasecmp($rol_usuario, 'Coordinador Regional') === 0);

$cedula_cruda    = $_SESSION['nombre_usuario'] ?? $_SESSION['cedula'] ?? '';
$cedula_sesion   = preg_replace('/[^0-9]/', '', $cedula_cruda); // Extrae solo números

// Si es coordinador, obtenemos de forma segura su centro desde 'usuario_centros'
$centro_usuario = '';
if ($es_coordinador) {
    $sql_c_coord = "SELECT centro_codigo FROM usuario_centros WHERE REPLACE(REPLACE(usuario_cedula, 'V', ''), 'v', '') = $1 LIMIT 1";
    $res_c_coord = pg_query_params($conexion, $sql_c_coord, array($cedula_sesion));
    if ($res_c_coord && pg_num_rows($res_c_coord) > 0) {
        $row_cc = pg_fetch_assoc($res_c_coord);
        $centro_usuario = trim($row_cc['centro_codigo']);
    }
} else {
    $centro_usuario = trim($_SESSION['centro_codigo'] ?? '');
}

$seccion_id         = trim($_POST['seccion_id'] ?? '');
$privado_documento  = trim($_POST['privado_documento'] ?? '');
$correo             = trim($_POST['correo'] ?? '');

if (empty($seccion_id) || empty($privado_documento) || empty($correo)) {
    $_SESSION['mensaje_error'] = "❌ Todos los campos son obligatorios.";
    header("Location: inscripcion_form.php?documento=" . urlencode($privado_documento));
    exit();
}

// Verificar privado, su centro y sus datos de condena judicial
$sql_privado = "SELECT p.documento_identidad, p.nombre, p.apellido, p.centro_codigo, p.fecha_detencion,
                       cj.duracion_condena_dias
                FROM privados p 
                LEFT JOIN privados_casos pc ON p.documento_identidad = pc.privado_documento
                LEFT JOIN casos_judiciales cj ON pc.caso_expediente = cj.numero_expediente
                WHERE p.documento_identidad = $1";
$res_privado = pg_query_params($conexion, $sql_privado, array($privado_documento));

if (!$res_privado || pg_num_rows($res_privado) === 0) {
    $_SESSION['mensaje_error'] = "❌ El privado de libertad no se encuentra registrado en el sistema.";
    header("Location: inscripcion_form.php");
    exit();
}
$datos_privado = pg_fetch_assoc($res_privado);
$centro_privado = trim($datos_privado['centro_codigo'] ?? '');

// REGLA DE SEGURIDAD: Si es Coordinador Regional, validar de forma flexible y robusta contra su centro asignado
if ($es_coordinador) {
    $sql_val_centro = "SELECT 1 FROM usuario_centros 
                       WHERE centro_codigo = $1 
                       AND (
                           REPLACE(REPLACE(usuario_cedula, 'V', ''), 'v', '') = $2 
                           OR usuario_cedula ILIKE $3
                       ) LIMIT 1";
    $res_val_centro = pg_query_params($conexion, $sql_val_centro, array($centro_privado, $cedula_sesion, '%' . $cedula_sesion . '%'));

    if (!$res_val_centro || pg_num_rows($res_val_centro) === 0) {
        $_SESSION['mensaje_error'] = "❌ Restricción de seguridad: No puede inscribir a un privado que pertenece a un centro diferente al suyo.";
        header("Location: inscripcion_form.php");
        exit();
    }
}

// REGLA DE NEGOCIO: Validar nuevamente que la condena NO esté cumplida
$condena_cumplida = false;
if (!empty($datos_privado['fecha_detencion']) && !empty($datos_privado['duracion_condena_dias']) && (int)$datos_privado['duracion_condena_dias'] > 0) {
    try {
        $fechaDetencion = new DateTime($datos_privado['fecha_detencion']);
        $diasCondena = (int)$datos_privado['duracion_condena_dias'];
        $fechaFinCondena = (clone $fechaDetencion)->modify("+$diasCondena days");
        $fechaHoy = new DateTime();

        if ($fechaHoy >= $fechaFinCondena) {
            $condena_cumplida = true;
        }
    } catch (Exception $e) {
        // Ignorar si hay error de fecha
    }
}

if ($condena_cumplida) {
    $_SESSION['mensaje_error'] = "❌ Operación bloqueada: El ciudadano ya cumplió su condena y se encuentra en libertad, por lo que no puede ser inscrito.";
    header("Location: inscripciones_listado.php");
    exit();
}

$sql_update_correo = "UPDATE privados SET correo = $1 WHERE documento_identidad = $2";
pg_query_params($conexion, $sql_update_correo, array($correo, $privado_documento));

// Incluimos s.fecha_inicio en la consulta para validar que el curso no haya empezado
$sql_seccion = "SELECT s.id, s.centro_codigo, s.codigo_nomenclatura, s.capacidad_maxima, s.dias_semana, s.horario, s.fecha_inicio,
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

if (intval($datos_seccion['cupos_restantes']) <= 0) {
    $_SESSION['mensaje_error'] = "❌ Lo sentimos, los cupos para esta sección se han agotado.";
    header("Location: inscripcion_form.php?documento=" . urlencode($privado_documento));
    exit();
}

// VALIDACIÓN DE FECHA DE INICIO: No permitir inscripciones si la fecha de inicio ya pasó
if (!empty($datos_seccion['fecha_inicio'])) {
    $fecha_actual_obj = new DateTime();
    $fecha_inicio_seccion = new DateTime($datos_seccion['fecha_inicio']);

    if ($fecha_actual_obj > $fecha_inicio_seccion) {
        $_SESSION['mensaje_error'] = "❌ Operación bloqueada: La fecha de inicio de esta sección (" . $fecha_inicio_seccion->format('d/m/Y') . ") ya pasó, por lo que no se permiten más inscripciones.";
        header("Location: inscripcion_form.php?documento=" . urlencode($privado_documento));
        exit();
    }
}

// VALIDACIÓN 1: Bloquear solo si ya tiene una inscripción ACTIVA ('Cursando') en el mismo curso exacto
$sql_dup_curso = "SELECT ie.id 
                  FROM inscripciones_educativas ie
                  JOIN secciones_cursos s ON ie.seccion_id = s.id
                  WHERE ie.privado_documento = $1 
                    AND s.curso_id = (SELECT curso_id FROM secciones_cursos WHERE id = $2)
                    AND ie.estado_cursante ILIKE 'Cursando'";
$res_dup_curso = pg_query_params($conexion, $sql_dup_curso, array($privado_documento, $seccion_id));

if ($res_dup_curso && pg_num_rows($res_dup_curso) > 0) {
    $_SESSION['mensaje_error'] = "❌ El privado ya tiene una inscripción activa ('Cursando') en este mismo curso. Debe finalizar su formación actual antes de inscribirse en otra sección del mismo programa.";
    header("Location: inscripcion_form.php?documento=" . urlencode($privado_documento));
    exit();
}

// VALIDACIÓN 2: Evitar cruce de horarios mediante solapamiento de rangos de tiempo y días en común
$dias_nueva = trim($datos_seccion['dias_semana'] ?? '');
$horario_nuevo = trim($datos_seccion['horario'] ?? '');

function parsearHorarioParaSolapamiento($strHorario) {
    if (preg_match('/(\d{1,2}:\d{2}\s*(?:AM|PM)?)\s*-\s*(\d{1,2}:\d{2}\s*(?:AM|PM)?)/i', $strHorario, $matches)) {
        $inicio = @strtotime($matches[1]);
        $fin = @strtotime($matches[2]);
        if ($inicio !== false && $fin !== false) {
            return [$inicio, $fin];
        }
    }
    return [0, 0];
}

if (!empty($dias_nueva) && !empty($horario_nuevo)) {
    $dias_nueva_lista = array_map('trim', array_map('strtoupper', explode(',', $dias_nueva)));
    list($inicio_nuevo, $fin_nuevo) = parsearHorarioParaSolapamiento($horario_nuevo);

    $sql_activas = "SELECT s.codigo_nomenclatura, c.nombre_curso, s.dias_semana, s.horario
                    FROM inscripciones_educativas ie
                    JOIN secciones_cursos s ON ie.seccion_id = s.id
                    JOIN cursos c ON s.curso_id = c.id
                    WHERE ie.privado_documento = $1
                      AND ie.estado_cursante ILIKE 'Cursando'";
    
    $res_activas = pg_query_params($conexion, $sql_activas, array($privado_documento));

    if ($res_activas && pg_num_rows($res_activas) > 0) {
        while ($curso_activo = pg_fetch_assoc($res_activas)) {
            $dias_activo_lista = array_map('trim', array_map('strtoupper', explode(',', $curso_activo['dias_semana'] ?? '')));
            $horario_activo = trim($curso_activo['horario'] ?? '');

            $dias_en_comun = array_intersect($dias_nueva_lista, $dias_activo_lista);

            if (!empty($dias_en_comun)) {
                $dias_cruce_str = implode(', ', $dias_en_comun);
                list($inicio_activo, $fin_activo) = parsearHorarioParaSolapamiento($horario_activo);

                $hay_cruce_horas = false;
                if ($inicio_nuevo > 0 && $fin_nuevo > 0 && $inicio_activo > 0 && $fin_activo > 0) {
                    if (($inicio_nuevo < $fin_activo) && ($fin_nuevo > $inicio_activo)) {
                        $hay_cruce_horas = true;
                    }
                } else {
                    if (strcasecmp($horario_activo, $horario_nuevo) === 0) {
                        $hay_cruce_horas = true;
                    }
                }

                if ($hay_cruce_horas) {
                    $_SESSION['mensaje_error'] = "❌ Cruce de horario detectado: El privado ya está inscrito en la sección [<strong>{$curso_activo['codigo_nomenclatura']}</strong>] ({$curso_activo['nombre_curso']}) que coincide en el/los día/s ({$dias_cruce_str}) con el horario ({$curso_activo['horario']}).";
                    header("Location: inscripcion_form.php?documento=" . urlencode($privado_documento));
                    exit();
                }
            }
        }
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

    // Generar documento adjunto formato Carta con espacios para logotipos
    $html_constancia = "
    <!DOCTYPE html>
    <html lang='es'>
    <head>
        <meta charset='UTF-8'>
        <title>Constancia de Inscripción - V-{$privado_documento}</title>
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
            <!-- Encabezado con tablas para logotipos institucionales -->
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
                    <li><strong>Cédula de Identidad / Documento:</strong> V-{$privado_documento}</li>
                    <li><strong>Programa de Formación (Curso):</strong> {$nombre_curso}</li>
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
        $mail->Subject = 'Constancia de Inscripcion Educativa - SGR IACTP';
        
        $mail->Body = "
        <p>Estimado(a) <strong>{$nombre_completo}</strong>,</p>
        <p>Su inscripción ha sido procesada con éxito. Adjunto a este correo encontrará el documento digital de su <strong>Constancia de Inscripción Oficial</strong> en formato carta.</p>
        <p><strong>Curso:</strong> {$nombre_curso} <br><strong>Sección:</strong> {$nomenclatura}</p>
        <p style='color: #6c757d; font-size: 12px;'>Sistema de Gestión de Redenciones (SGR-IACTP)</p>
        ";

        // Adjuntar el documento digital
        $mail->addStringAttachment($html_constancia, "Constancia_Inscripcion_{$privado_documento}.html", 'base64', 'text/html');

        $mail->send();
        $_SESSION['mensaje_exito'] = "✅ ¡Inscripción procesada y constancia en formato carta adjunta enviada con éxito al correo: {$correo}!";
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