<?php
require('fpdf/fpdf.php');
require('conexion.php');

// ======================================
// 1. Obtener Filtros y Definir Título
// ======================================
$busqueda = trim($_GET['busqueda'] ?? '');
$titulo_reporte = "LISTADO GENERAL DE ENTES EDUCATIVOS";

if (!empty($busqueda)) {
    $titulo_reporte = "LISTADO DE ENTES EDUCATIVOS (BÚSQUEDA: " . strtoupper(htmlspecialchars($busqueda)) . ")";
}

class PDF extends FPDF
{
    function Header()
    {
        global $titulo_reporte; 

        // Logos de la institución
        @$this->Image('assets/img/logoministerio.jpg', 10, 10, 25);
        @$this->Image('assets/img/logoiactp1.jpg', 262, 10, 25);
        
        $this->SetFont('Arial', 'B', 11);
        $this->Cell(0, 5, utf8_decode('REPÚBLICA BOLIVARIANA DE VENEZUELA'), 0, 1, 'C');
        $this->SetFont('Arial', '', 10);
        $this->Cell(0, 5, utf8_decode('MINISTERIO DEL PODER POPULAR PARA EL SERVICIO PENITENCIARIO'), 0, 1, 'C');
        $this->Cell(0, 5, utf8_decode('INSTITUTO AUTÓNOMO CAJA DE TRABAJO PENITENCIARIO'), 0, 1, 'C');
        $this->Ln(10);
        
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 8, utf8_decode($titulo_reporte), 0, 1, 'C');
        $this->Ln(4);

        // Cabecera de la tabla para Entes Educativos
        $this->SetFont('Arial', 'B', 9);
        $this->SetFillColor(230, 230, 230); 
        $this->Cell(12, 7, utf8_decode('N°'), 1, 0, 'C', true);
        $this->Cell(35, 7, 'RIF / NIT', 1, 0, 'C', true);
        $this->Cell(70, 7, 'Nombre del Ente Educativo', 1, 0, 'C', true);
        $this->Cell(55, 7, 'Persona de Contacto', 1, 0, 'C', true);
        $this->Cell(45, 7, utf8_decode('Teléfonos'), 1, 0, 'C', true);
        $this->Cell(58, 7, 'Correo Electrónico', 1, 1, 'C', true);
    }

    function Footer()
    {
        $this->SetY(-12);
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(0, 10, utf8_decode('Página ') . $this->PageNo() . ' de {nb}', 0, 0, 'C');
    }

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
// 2. Consulta SQL con PostgreSQL y Filtros
// ======================================
$sql = "SELECT * FROM entes_educativos WHERE 1=1";
$params = [];

if (!empty($busqueda)) {
    $sql .= " AND (nombre_ente ILIKE $1 OR rif_nit ILIKE $1 OR contacto_persona ILIKE $1)";
    $params[] = "%" . $busqueda . "%";
}

// Ordenar por rif_nit ya que no usamos campo 'id' numérico
$sql .= " ORDER BY rif_nit DESC";

$resultado = ($params) ? pg_query_params($conexion, $sql, $params) : pg_query($conexion, $sql);

if ($resultado === false) {
    die("Error en la consulta SQL: " . pg_last_error($conexion));
}

$total_registros = pg_num_rows($resultado);

// Creación del PDF en formato Horizontal ('L')
$pdf = new PDF('L', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->AddPage();
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 12);
$pdf->SetFont('Arial', '', 8);

$i = 1;
if ($total_registros > 0) {
    while ($ente = pg_fetch_assoc($resultado)) {
        $rif_nit          = utf8_decode($ente['rif_nit']);
        $nombre_ente      = utf8_decode($ente['nombre_ente']);
        $contacto_persona = utf8_decode($ente['contacto_persona'] ?? 'N/A');
        $telefono         = utf8_decode($ente['telefono'] ?? 'N/A');
        $email            = utf8_decode($ente['email'] ?? 'N/A');

        $h = 5;

        $nb_nombre = $pdf->NbLines(70, $nombre_ente);
        $nb_contacto = $pdf->NbLines(55, $contacto_persona);
        $nb_tel = $pdf->NbLines(45, $telefono);
        $nb_email = $pdf->NbLines(58, $email);

        $nb = max($nb_nombre, $nb_contacto, $nb_tel, $nb_email, 1);
        $row_height = $nb * $h;

        if ($pdf->GetY() + $row_height > 190) {
            $pdf->AddPage();
        }

        $x_pos = $pdf->GetX();
        $y_pos = $pdf->GetY();

        // 1. Número
        $pdf->Rect($x_pos, $y_pos, 12, $row_height);
        $pdf->SetXY($x_pos, $y_pos + (($row_height - 5) / 2));
        $pdf->Cell(12, 5, $i++, 0, 0, 'C');

        // 2. RIF / NIT
        $x_pos += 12;
        $pdf->Rect($x_pos, $y_pos, 35, $row_height);
        $pdf->SetXY($x_pos, $y_pos + (($row_height - 5) / 2));
        $pdf->Cell(35, 5, $rif_nit, 0, 0, 'C');

        // 3. Nombre del Ente
        $x_pos += 35;
        $pdf->Rect($x_pos, $y_pos, 70, $row_height);
        $pdf->SetXY($x_pos, $y_pos + (($row_height - ($nb_nombre * 5)) / 2));
        $pdf->MultiCell(70, 5, $nombre_ente, 0, 'L');

        // 4. Persona de Contacto
        $x_pos += 70;
        $pdf->Rect($x_pos, $y_pos, 55, $row_height);
        $pdf->SetXY($x_pos, $y_pos + (($row_height - ($nb_contacto * 5)) / 2));
        $pdf->MultiCell(55, 5, $contacto_persona, 0, 'L');

        // 5. Teléfonos
        $x_pos += 55;
        $pdf->Rect($x_pos, $y_pos, 45, $row_height);
        $pdf->SetXY($x_pos, $y_pos + (($row_height - ($nb_tel * 5)) / 2));
        $pdf->MultiCell(45, 5, $telefono, 0, 'C');

        // 6. Correo Electrónico
        $x_pos += 45;
        $pdf->Rect($x_pos, $y_pos, 58, $row_height);
        $pdf->SetXY($x_pos, $y_pos + (($row_height - ($nb_email * 5)) / 2));
        $pdf->MultiCell(58, 5, $email, 0, 'L');

        $pdf->SetY($y_pos + $row_height);
    }
} else {
    $pdf->Cell(0, 8, utf8_decode('No se encontraron entes educativos registrados con esos filtros.'), 1, 1, 'C');
}

pg_free_result($resultado);
pg_close($conexion);
$pdf->Output('I', 'listado_entes_educativos.pdf');
?>