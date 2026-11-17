<?php
require('fpdf/fpdf.php');
require('conexion.php');
session_start();

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: login.php");
    exit();
}

// ======================================
// 1. Obtener Filtros y Definir Título
// ======================================
$busqueda = trim($_GET['busqueda'] ?? '');

$titulo_reporte = "LISTADO GENERAL DE CURSOS ASIGNADOS POR CENTRO Y ENTE";

if (!empty($busqueda)) {
    $titulo_reporte = "LISTADO DE CURSOS ASIGNADOS - BÚSQUEDA FILTRADA: " . strtoupper(htmlspecialchars($busqueda));
}

// ======================================
class PDF_CURSOS_ENTES extends FPDF
{
    function Header()
    {
        global $titulo_reporte; 

        // Logos de la institución (ajustados para formato horizontal 'L')
        if (file_exists('assets/img/logoministerio.jpg')) {
            $this->Image('assets/img/logoministerio.jpg', 10, 10, 25);
        }
        if (file_exists('assets/img/logoiactp1.jpg')) {
            $this->Image('assets/img/logoiactp1.jpg', 262, 10, 25);
        }
        
        $this->SetFont('Arial', 'B', 11);
        $this->Cell(0, 5, utf8_decode('REPÚBLICA BOLIVARIANA DE VENEZUELA'), 0, 1, 'C');
        $this->SetFont('Arial', '', 10);
        $this->Cell(0, 5, utf8_decode('MINISTERIO DEL PODER POPULAR PARA EL SERVICIO PENITENCIARIO'), 0, 1, 'C');
        $this->Cell(0, 5, utf8_decode('INSTITUTO AUTÓNOMO CAJA DE TRABAJO PENITENCIARIO'), 0, 1, 'C');
        $this->Ln(8);
        
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 8, utf8_decode($titulo_reporte), 0, 1, 'C');
        $this->Ln(4);

        // Cabecera de la tabla (Ancho total útil en A4 Horizontal: 277 mm)
        // 10 + 65 + 75 + 50 + 25 + 25 + 27 = 277 mm
        $this->SetFont('Arial', 'B', 8);
        $this->SetFillColor(230, 230, 230); 
        $this->Cell(10, 7, utf8_decode('N°'), 1, 0, 'C', true);
        $this->Cell(65, 7, utf8_decode('Centro Penitenciario'), 1, 0, 'C', true);
        $this->Cell(75, 7, utf8_decode('Curso del Catálogo'), 1, 0, 'C', true);
        $this->Cell(50, 7, utf8_decode('Ente Educativo / Avala'), 1, 0, 'C', true);
        $this->Cell(25, 7, utf8_decode('Duración'), 1, 0, 'C', true);
        $this->Cell(25, 7, utf8_decode('Capacidad'), 1, 0, 'C', true);
        $this->Cell(27, 7, utf8_decode('Género'), 1, 1, 'C', true);
    }

    function Footer()
    {
        $this->SetY(-12);
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(0, 10, utf8_decode('Página ') . $this->PageNo() . ' de {nb} - Generado el: ' . date('d/m/Y H:i:s'), 0, 0, 'C');
    }

    // Función auxiliar para calcular el número de líneas en celdas múltiples
    function NbLines($w, $txt)
    {
        $cw = &$this->CurrentFont['cw'];
        if ($w == 0)
            $w = $this->w - $this->rMargin - $this->x;
        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $s = str_replace("\r", '', $txt);
        $nb = strlen($s);
        if ($nb > 0 && $s[$nb-1] == "\n")
            $nb--;
        $sep = -1;
        $i = 0;
        $j = 0;
        $l = 0;
        $nl = 1;
        while ($i < $nb) {
            $c = $s[$i];
            if ($c == "\n") {
                $i++;
                $sep = -1;
                $j = $i;
                $l = 0;
                $nl++;
                continue;
            }
            if ($c == ' ')
                $sep = $i;
            $l += $cw[$c];
            if ($l > $wmax) {
                if ($sep == -1) {
                    if ($i == $j)
                        $i++;
                } else
                    $i = $sep + 1;
                $sep = -1;
                $j = $i;
                $l = 0;
                $nl++;
            } else
                $i++;
        }
        return $nl;
    }
}

// ======================================
// 2. Consulta SQL con PostgreSQL
// ======================================
$sql = "SELECT ec.id as asignacion_id, 
               c.codigo_curso, 
               c.nombre_curso, 
               ee.nombre_ente, 
               cen.nombre as nombre_centro,
               cen.genero_permitido,
               ec.horas_duracion, 
               ec.capacidad_maxima
        FROM ente_cursos ec 
        JOIN cursos c ON ec.curso_id = c.id 
        JOIN entes_educativos ee ON ec.rif_nit = ee.rif_nit 
        JOIN centros cen ON ec.centro_codigo = cen.codigo 
        WHERE 1=1";

$params = [];
if (!empty($busqueda)) {
    $sql .= " AND (c.nombre_curso ILIKE $1 OR c.codigo_curso ILIKE $1 OR ee.nombre_ente ILIKE $1 OR cen.nombre ILIKE $1)";
    $params[] = "%" . $busqueda . "%";
}

$sql .= " ORDER BY cen.nombre ASC, c.nombre_curso ASC";

$resultado = ($params) ? pg_query_params($conexion, $sql, $params) : pg_query($conexion, $sql);

if ($resultado === false) {
    die("Error en la consulta SQL: " . pg_last_error($conexion));
}

$total_registros = pg_num_rows($resultado);

// ======================================
// 3. Generación del PDF (Horizontal 'L')
// ======================================
$pdf = new PDF_CURSOS_ENTES('L', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->AddPage();
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 12);
$pdf->SetFont('Arial', '', 8);

$i = 1;
if ($total_registros > 0) {
    while ($row = pg_fetch_assoc($resultado)) {
        $nombre_centro = utf8_decode($row['nombre_centro']);
        $nombre_curso  = utf8_decode($row['nombre_curso'] . ' (' . $row['codigo_curso'] . ')');
        $nombre_ente   = utf8_decode($row['nombre_ente']);
        $duracion      = utf8_decode($row['horas_duracion'] . ' Hrs');
        $capacidad     = utf8_decode($row['capacidad_maxima'] . ' Cupos');
        $genero        = utf8_decode($row['genero_permitido'] ?? 'N/D');

        $h = 5;

        // Calcular altura dinámica de filas con celdas múltiples
        $nb_centro = $pdf->NbLines(65, $nombre_centro);
        $nb_curso  = $pdf->NbLines(75, $nombre_curso);
        $nb_ente   = $pdf->NbLines(50, $nombre_ente);

        $nb = max($nb_centro, $nb_curso, $nb_ente, 1);
        $row_height = $nb * $h;

        // Salto de página si no hay espacio
        if ($pdf->GetY() + $row_height > 190) {
            $pdf->AddPage();
        }

        $x_pos = $pdf->GetX();
        $y_pos = $pdf->GetY();

        // 1. Número
        $pdf->Rect($x_pos, $y_pos, 10, $row_height);
        $pdf->SetXY($x_pos, $y_pos + (($row_height - 5) / 2));
        $pdf->Cell(10, 5, $i++, 0, 0, 'C');

        // 2. Centro Penitenciario
        $x_pos += 10;
        $pdf->Rect($x_pos, $y_pos, 65, $row_height);
        $pdf->SetXY($x_pos + 1, $y_pos + (($row_height - ($nb_centro * 5)) / 2));
        $pdf->MultiCell(65, 5, $nombre_centro, 0, 'L');

        // 3. Curso Asignado
        $x_pos += 65;
        $pdf->Rect($x_pos, $y_pos, 75, $row_height);
        $pdf->SetXY($x_pos + 1, $y_pos + (($row_height - ($nb_curso * 5)) / 2));
        $pdf->MultiCell(75, 5, $nombre_curso, 0, 'L');

        // 4. Ente Impartidor
        $x_pos += 75;
        $pdf->Rect($x_pos, $y_pos, 50, $row_height);
        $pdf->SetXY($x_pos + 1, $y_pos + (($row_height - ($nb_ente * 5)) / 2));
        $pdf->MultiCell(50, 5, $nombre_ente, 0, 'L');

        // 5. Duración (Horas)
        $x_pos += 50;
        $pdf->Rect($x_pos, $y_pos, 25, $row_height);
        $pdf->SetXY($x_pos, $y_pos + (($row_height - 5) / 2));
        $pdf->Cell(25, 5, $duracion, 0, 0, 'C');

        // 6. Capacidad (Cupos)
        $x_pos += 25;
        $pdf->Rect($x_pos, $y_pos, 25, $row_height);
        $pdf->SetXY($x_pos, $y_pos + (($row_height - 5) / 2));
        $pdf->Cell(25, 5, $capacidad, 0, 0, 'C');

        // 7. Género
        $x_pos += 25;
        $pdf->Rect($x_pos, $y_pos, 27, $row_height);
        $pdf->SetXY($x_pos, $y_pos + (($row_height - 5) / 2));
        $pdf->Cell(27, 5, $genero, 0, 0, 'C');

        // Mover cursor a la siguiente línea
        $pdf->SetY($y_pos + $row_height);
    }
} else {
    $pdf->Cell(277, 10, utf8_decode('No se encontraron asignaciones registradas bajo los filtros indicados.'), 1, 1, 'C');
}

pg_free_result($resultado);
pg_close($conexion);
$pdf->Output('I', 'Reporte_Cursos_Entes_IACTP.pdf');
?>