<?php
require('fpdf/fpdf.php');
require('conexion.php');

session_start();

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: login.php");
    exit();
}

$inscripcion_id = $_GET['id'] ?? null;

if (empty($inscripcion_id)) {
    die("Inscripción no especificada.");
}

// 1. Obtener los datos del estudiante y las horas oficiales del curso
$query_est = "
    SELECT 
        ie.id AS inscripcion_id,
        p.documento_identidad,
        p.nombre,
        p.apellido,
        ie.estado_aprobacion,
        c.nombre_curso,
        c.duracion_horas
    FROM inscripciones_educativas ie
    JOIN privados p ON ie.privado_documento = p.documento_identidad
    JOIN secciones_cursos sc ON ie.seccion_id = sc.id
    JOIN cursos c ON sc.curso_id = c.id
    WHERE ie.id = $1
    LIMIT 1
";
$res_est = pg_query_params($conexion, $query_est, array($inscripcion_id));
$estudiante = ($res_est) ? pg_fetch_assoc($res_est) : null;

if (!$estudiante) {
    die("Registro académico no encontrado.");
}

if ($estudiante['estado_aprobacion'] !== 'APROBADO') {
    die("El estudiante seleccionado no se encuentra en estatus APROBADO para emitir su certificado.");
}

$nombre_estudiante = $estudiante['nombre'] . ' ' . $estudiante['apellido'];
$cedula_limpia = preg_replace('/^(V-|v-|V|v)/', '', trim($estudiante['documento_identidad']));
$cedula_formateada = 'V-' . $cedula_limpia;
$nombre_curso = $estudiante['nombre_curso'];
$duracion_horas = intval($estudiante['duracion_horas'] ?? 120); 

class PDF_CERTIFICADO_INDIVIDUAL extends FPDF
{
    function Header()
    {
        // Marco exterior simétrico (Ancho total 279mm, Alto 216mm)
        $this->SetLineWidth(0.9);
        $this->SetDrawColor(0, 32, 96); // Azul institucional
        $this->Rect(12, 12, 255, 192);
        
        // Marco interior sutil
        $this->SetLineWidth(0.3);
        $this->SetDrawColor(180, 180, 180);
        $this->Rect(15, 15, 249, 186);
    }

    function Footer()
    {
        $this->SetY(-13);
        $this->SetFont('Arial', 'I', 8.5);
        $this->SetTextColor(110, 110, 110);
        $this->Cell(0, 10, utf8_decode('Instituto Autónomo Caja de Trabajo Penitenciario (IACTP) — Validación Oficial del Sistema'), 0, 0, 'C');
    }
}

// Crear PDF en horizontal (Landscape 'L', milímetros 'mm', tamaño 'Letter')
$pdf = new PDF_CERTIFICADO_INDIVIDUAL('L', 'mm', 'Letter');
$pdf->SetAutoPageBreak(false);
$pdf->AddPage();

// Logotipos institucionales en las esquinas superiores
if (file_exists('assets/img/logoministerio.jpg')) {
    $pdf->Image('assets/img/logoministerio.jpg', 20, 18, 22);
}
if (file_exists('assets/img/logoiactp1.jpg')) {
    $pdf->Image('assets/img/logoiactp1.jpg', 237, 18, 22);
}

// 1. Encabezado institucional superior
$pdf->SetY(16);
$pdf->SetFont('Arial', 'B', 11.5);
$pdf->SetTextColor(30, 30, 30);
$pdf->Cell(0, 5, utf8_decode('REPÚBLICA BOLIVARIANA DE VENEZUELA'), 0, 1, 'C');
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(0, 4.5, utf8_decode('MINISTERIO DEL PODER POPULAR PARA EL SERVICIO PENITENCIARIO'), 0, 1, 'C');
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(0, 4.5, utf8_decode('INSTITUTO AUTÓNOMO CAJA DE TRABAJO PENITENCIARIO (IACTP)'), 0, 1, 'C');

// 2. Título Principal del Certificado (Bajado y con su espacio respectivo)
$pdf->Ln(9);
$pdf->SetFont('Arial', 'B', 31);
$pdf->SetTextColor(0, 32, 96);
$pdf->Cell(0, 11, utf8_decode('CERTIFICADO DE APROBACIÓN'), 0, 1, 'C');

$pdf->Ln(4);
$pdf->SetFont('Arial', 'I', 13);
$pdf->SetTextColor(90, 90, 90);
$pdf->Cell(0, 6, utf8_decode('El presente documento se otorga a:'), 0, 1, 'C');

// 3. NOMBRE DEL ESTUDIANTE
$pdf->Ln(4);
$pdf->SetFont('Arial', 'B', 38);
$pdf->SetTextColor(0, 0, 0);
$pdf->Cell(0, 14, utf8_decode($nombre_estudiante), 0, 1, 'C');

// Cédula de Identidad
$pdf->SetFont('Arial', 'B', 14);
$pdf->SetTextColor(80, 80, 80);
$pdf->Cell(0, 7, utf8_decode('Cédula de Identidad: ' . $cedula_formateada), 0, 1, 'C');

// 4. Primer párrafo descriptivo
$pdf->Ln(6);
$pdf->SetFont('Arial', '', 13.5);
$pdf->SetTextColor(50, 50, 50);
$texto_logro = "Por haber cursado, aprobado y demostrado satisfactoriamente las competencias exigidas en el programa de formación correspondiente al curso:";
$pdf->MultiCell(0, 6.5, utf8_decode($texto_logro), 0, 'C');

// 5. Nombre del Curso Destacado
$pdf->Ln(4);
$pdf->SetFont('Arial', 'B', 26);
$pdf->SetTextColor(0, 32, 96);
$pdf->Cell(0, 10, utf8_decode('"' . $nombre_curso . '"'), 0, 1, 'C');

// 6. Segundo párrafo con duración en horas (sin mostrar el centro ni la sección)
$pdf->Ln(4);
$pdf->SetFont('Arial', '', 12.5);
$pdf->SetTextColor(70, 70, 70);
$detalles_curso = "Con una duración oficial de " . $duracion_horas . " Horas Académicas.";
$pdf->MultiCell(0, 6, utf8_decode($detalles_curso), 0, 'C');

// 7. Tercer párrafo con el ente que lo avala
$pdf->Ln(2);
$pdf->SetFont('Arial', '', 12.5);
$pdf->SetTextColor(70, 70, 70);
$aval_curso = "Avalado oficialmente por el Ministerio del Poder Popular para el Servicio Penitenciario a través del Instituto Autónomo Caja de Trabajo Penitenciario (IACTP).";
$pdf->MultiCell(0, 6, utf8_decode($aval_curso), 0, 'C');

// 8. Bloque de Firmas Autorizadas abajo
$pdf->SetY(162);
$pdf->SetFont('Arial', 'B', 11.5);
$pdf->SetTextColor(0, 0, 0);

$pdf->Line(55, 170, 115, 170); // Firma Izquierda
$pdf->Line(164, 170, 224, 170); // Firma Derecha

$pdf->SetXY(55, 172);
$pdf->Cell(60, 5, utf8_decode('Autoridad Académica'), 0, 0, 'C');
$pdf->SetXY(164, 172);
$pdf->Cell(60, 5, utf8_decode('Coordinación de Educación'), 0, 0, 'C');

if (isset($conexion)) {
    pg_close($conexion);
}

// Forzar la descarga directa del archivo PDF individual
$nombre_archivo = "Certificado_" . $cedula_limpia . "_" . date('Y-m-d') . ".pdf";
$pdf->Output('D', $nombre_archivo);
exit();
?>