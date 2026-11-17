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

$titulo_reporte = "LISTADO GENERAL DE SECCIONES ACADÉMICAS";

if (!empty($busqueda)) {
    $titulo_reporte = "LISTADO DE SECCIONES - BÚSQUEDA FILTRADA: " . strtoupper(htmlspecialchars($busqueda));
}

// ======================================
class PDF_SECCIONES extends FPDF
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
        // 10 + 45 + 60 + 62 + 25 + 20 + 55 = 277 mm
        $this->SetFont('Arial', 'B', 8);
        $this->SetFillColor(230, 230, 230); 
        $this->Cell(10, 7, utf8_decode('N°'), 1, 0, 'C', true);
        $this->Cell(45, 7, utf8_decode('Nomenclatura'), 1, 0, 'C', true);
        $this->Cell(60, 7, utf8_decode('Centro Penitenciario'), 1, 0, 'C', true);
        $this->Cell(62, 7, utf8_decode('Curso Asignado'), 1, 0, 'C', true);
        $this->Cell(25, 7, utf8_decode('Ente Impartidor'), 1, 0, 'C', true);
        $this->Cell(20, 7, utf8_decode('Año / Secc'), 1, 0, 'C', true);
        $this->Cell(55, 7, utf8_decode('Horario / Cupos'), 1, 1, 'C', true);
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
$sql = "SELECT s.*, c.nombre AS nombre_centro, cu.nombre_curso, e.nombre_ente, e.sigla AS sigla_ente 
        FROM secciones_cursos s
        INNER JOIN centros c ON s.centro_codigo = c.codigo
        INNER JOIN cursos cu ON s.curso_id = cu.id
        INNER JOIN entes_educativos e ON s.rif_nit = e.rif_nit
        WHERE 1=1";

$params = [];
if (!empty($busqueda)) {
    $sql .= " AND (s.codigo_nomenclatura ILIKE $1 OR c.nombre ILIKE $1 OR cu.nombre_curso ILIKE $1 OR e.nombre_ente ILIKE $1)";
    $params[] = "%" . $busqueda . "%";
}

$sql .= " ORDER BY s.anio DESC, s.codigo_nomenclatura ASC";

$resultado = ($params) ? pg_query_params($conexion, $sql, $params) : pg_query($conexion, $sql);

if ($resultado === false) {
    die("Error en la consulta SQL: " . pg_last_error($conexion));
}

$total_registros = pg_num_rows($resultado);

// ======================================
// 3. Generación del PDF (Horizontal 'L')
// ======================================
$pdf = new PDF_SECCIONES('L', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->AddPage();
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 12);
$pdf->SetFont('Arial', '', 8);

$i = 1;
if ($total_registros > 0) {
    while ($sec = pg_fetch_assoc($resultado)) {
        $nomenclatura  = utf8_decode($sec['codigo_nomenclatura']);
        $nombre_centro = utf8_decode($sec['nombre_centro']);
        $nombre_curso  = utf8_decode($sec['nombre_curso']);
        $nombre_ente   = utf8_decode($sec['nombre_ente'] . (!empty($sec['sigla_ente']) ? ' (' . $sec['sigla_ente'] . ')' : ''));
        $anio_secc     = $sec['anio'] . ' / Sec. ' . $sec['numero_seccion'];
        $horario_cupos = utf8_decode($sec['horario'] . " (Cupos: " . $sec['capacidad_maxima'] . ")");

        $h = 5;

        // Calcular altura dinámica de filas con celdas múltiples
        $nb_centro = $pdf->NbLines(60, $nombre_centro);
        $nb_curso  = $pdf->NbLines(62, $nombre_curso);
        $nb_ente   = $pdf->NbLines(25, $nombre_ente);
        $nb_horario= $pdf->NbLines(55, $horario_cupos);

        $nb = max($nb_centro, $nb_curso, $nb_ente, $nb_horario, 1);
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

        // 2. Nomenclatura
        $x_pos += 10;
        $pdf->Rect($x_pos, $y_pos, 45, $row_height);
        $pdf->SetXY($x_pos, $y_pos + (($row_height - 5) / 2));
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->Cell(45, 5, $nomenclatura, 0, 0, 'C');
        $pdf->SetFont('Arial', '', 8);

        // 3. Centro Penitenciario
        $x_pos += 45;
        $pdf->Rect($x_pos, $y_pos, 60, $row_height);
        $pdf->SetXY($x_pos, $y_pos + (($row_height - ($nb_centro * 5)) / 2));
        $pdf->MultiCell(60, 5, $nombre_centro, 0, 'L');

        // 4. Curso Asignado
        $x_pos += 60;
        $pdf->Rect($x_pos, $y_pos, 62, $row_height);
        $pdf->SetXY($x_pos, $y_pos + (($row_height - ($nb_curso * 5)) / 2));
        $pdf->MultiCell(62, 5, $nombre_curso, 0, 'L');

        // 5. Ente Impartidor
        $x_pos += 62;
        $pdf->Rect($x_pos, $y_pos, 25, $row_height);
        $pdf->SetXY($x_pos, $y_pos + (($row_height - ($nb_ente * 5)) / 2));
        $pdf->MultiCell(25, 5, $nombre_ente, 0, 'L');

        // 6. Año / Sección
        $x_pos += 25;
        $pdf->Rect($x_pos, $y_pos, 20, $row_height);
        $pdf->SetXY($x_pos, $y_pos + (($row_height - 5) / 2));
        $pdf->Cell(20, 5, $anio_secc, 0, 0, 'C');

        // 7. Horario / Cupos
        $x_pos += 20;
        $pdf->Rect($x_pos, $y_pos, 55, $row_height);
        $pdf->SetXY($x_pos, $y_pos + (($row_height - ($nb_horario * 5)) / 2));
        $pdf->MultiCell(55, 5, $horario_cupos, 0, 'L');

        // Mover cursor a la siguiente línea
        $pdf->SetY($y_pos + $row_height);
    }
} else {
    $pdf->Cell(277, 10, utf8_decode('No se encontraron secciones académicas aperturadas bajo los filtros indicados.'), 1, 1, 'C');
}

pg_free_result($resultado);
pg_close($conexion);
$pdf->Output('I', 'Reporte_Secciones_IACTP.pdf');
?>