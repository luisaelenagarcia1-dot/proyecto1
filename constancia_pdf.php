<?php
session_start();
require_once 'conexion.php';

// Validar sesión activa
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: login.php");
    exit();
}

$inscripcion_id = $_GET['id'] ?? '';

if (empty($inscripcion_id)) {
    die("ID de inscripción no especificado.");
}

// Consultar datos completos de la inscripción, privado, curso y centro (sin la columna ubicacion)
$sql = "SELECT ie.*, p.nombre, p.apellido, p.documento_identidad, p.correo, 
               c.nombre_curso, c.descripcion as descripcion_curso, 
               s.codigo_nomenclatura, cn.nombre as nombre_centro
        FROM inscripciones_educativas ie
        JOIN privados p ON ie.privado_documento = p.documento_identidad
        JOIN secciones_cursos s ON ie.seccion_id = s.id
        JOIN cursos c ON s.curso_id = c.id
        LEFT JOIN centros cn ON s.centro_codigo = cn.codigo
        WHERE ie.id = $1";

$res = pg_query_params($conexion, $sql, array($inscripcion_id));

if (!$res || pg_num_rows($res) === 0) {
    die("No se encontró el registro de inscripción.");
}

$data = pg_fetch_assoc($res);

$nombre_completo = $data['apellido'] . ', ' . $data['nombre'];
$documento       = $data['documento_identidad'];
$nombre_curso    = $data['nombre_curso'];
$nomenclatura    = $data['codigo_nomenclatura'];
$nombre_centro   = $data['nombre_centro'] ?? 'Centro de Reclusión Penitenciaria';
$fecha_inscripcion = date('d/m/Y', strtotime($data['fecha_inscripcion']));
$anio_actual     = date('Y');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Constancia de Inscripción - <?php echo $documento; ?></title>
    <style>
        body {
            font-family: 'Times New Roman', Times, serif;
            color: #000;
            line-height: 1.6;
            margin: 0;
            padding: 40px;
            background-color: #fff;
        }
        .constancia-container {
            max-width: 800px;
            margin: 0 auto;
            border: 2px solid #333;
            padding: 40px;
            position: relative;
        }
        .header-institucional {
            text-align: center;
            margin-bottom: 30px;
        }
        .header-institucional h2 {
            margin: 0;
            font-size: 18px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .header-institucional h3 {
            margin: 5px 0 0 0;
            font-size: 15px;
            color: #444;
            font-weight: normal;
        }
        .titulo-documento {
            text-align: center;
            margin: 40px 0;
        }
        .titulo-documento h1 {
            font-size: 24px;
            text-decoration: underline;
            letter-spacing: 2px;
            margin: 0;
        }
        .cuerpo-texto {
            text-align: justify;
            font-size: 16px;
            margin-bottom: 30px;
            text-indent: 50px;
        }
        .detalles-curso {
            margin: 20px 40px;
            font-size: 16px;
        }
        .detalles-curso ul {
            list-style-type: none;
            padding: 0;
        }
        .detalles-curso li {
            margin-bottom: 10px;
            padding: 5px;
            border-bottom: 1px dashed #ccc;
        }
        .pie-firma {
            margin-top: 80px;
            display: flex;
            justify-content: space-between;
            text-align: center;
        }
        .firma-box {
            width: 45%;
            margin: 0 auto;
        }
        .linea-firma {
            border-top: 1px solid #000;
            margin-top: 60px;
            margin-bottom: 5px;
        }
        /* Ocultar botones al imprimir */
        @media print {
            .no-print {
                display: none;
            }
            body {
                padding: 0;
            }
            .constancia-container {
                border: none;
                padding: 0;
            }
        }
        .btn-imprimir {
            display: block;
            width: 200px;
            margin: 0 auto 30px auto;
            padding: 10px;
            background-color: #0d6efd;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
            text-align: center;
        }
        .btn-imprimir:hover {
            background-color: #0b5ed7;
        }
    </style>
</head>
<body>

    <div class="no-print">
        <button onclick="window.print()" class="btn-imprimir">🖨️ Imprimir / Guardar PDF</button>
    </div>

    <div class="constancia-container">
        <!-- Membrete -->
        <div class="header-institucional">
            <h2>República Bolivariana de Venezuela</h2>
            <h2>Instituto Autónomo Caja de Trabajo Penitenciario</h2>
            <h3>Coordinación de Educación y Redención de Pena</h3>
            <h3><?php echo htmlspecialchars($nombre_centro); ?></h3>
        </div>

        <!-- Título -->
        <div class="titulo-documento">
            <h1>CONSTANCIA DE INSCRIPCIÓN</h1>
        </div>

        <!-- Texto Principal -->
        <div class="cuerpo-texto">
            Quien suscribe, en su carácter de Autoridad de la Coordinación de Educación del <strong>Instituto Autónomo Caja de Trabajo Penitenciario (IACTP)</strong>, por medio de la presente hace constar que el ciudadano que se detalla a continuación se encuentra formalmente inscrito(a) en el programa de formación regular correspondiente al período <?php echo $anio_actual; ?>:
        </div>

        <!-- Datos del Cursante y Curso -->
        <div class="detalles-curso">
            <ul>
                <li><strong>Apellidos y Nombres:</strong> <?php echo htmlspecialchars($nombre_completo); ?></li>
                <li><strong>Cédula de Identidad / Documento:</strong> V-<?php echo htmlspecialchars($documento); ?></li>
                <li><strong>Programa de Formación (Curso):</strong> <?php echo htmlspecialchars($nombre_curso); ?></li>
                <li><strong>Nomenclatura de Sección:</strong> <?php echo htmlspecialchars($nomenclatura); ?></li>
                <li><strong>Fecha de Inscripción:</strong> <?php echo $fecha_inscripcion; ?></li>
                <li><strong>Estatus Académico:</strong> Cursando Activo (Apto para Redención de Pena)</li>
            </ul>
        </div>

        <div class="cuerpo-texto" style="margin-top: 30px;">
            Constancia que se expide a petición de parte interesada en el Centro Penitenciario, a los <?php echo date('d'); ?> días del mes de <?php echo date('m'); ?> del año <?php echo $anio_actual; ?>.
        </div>

        <!-- Firma -->
        <div class="pie-firma">
            <div class="firma-box">
                <div class="linea-firma"></div>
                <strong>Coordinación de Educación</strong><br>
                <span>SGR - IACTP</span>
            </div>
        </div>
    </div>

</body>
</html>