<?php
require('fpdf/fpdf.php');
require('conexion.php');

// ======================================
// 1. Obtener Filtros y Definir Título
// ======================================
$busqueda = $_GET['busqueda'] ?? '';
$filtro_anio_detencion = $_GET['anio_detencion'] ?? '';
$filtro_anio_condena = $_GET['anio_condena'] ?? '';
$filtro_anio_registro = $_GET['anio_registro'] ?? ''; 
$filtro_estado = $_GET['estado'] ?? ''; 

$titulo_reporte = "LISTADO DE PRIVADOS DE LIBERTAD GENERAL";

$filtros_activos = [];
if (!empty($filtro_anio_detencion)) $filtros_activos[] = "DET. " . htmlspecialchars($filtro_anio_detencion);
if (!empty($filtro_anio_condena)) $filtros_activos[] = "COND. " . htmlspecialchars($filtro_anio_condena);
if (!empty($filtro_anio_registro)) $filtros_activos[] = "REG. " . htmlspecialchars($filtro_anio_registro);

if (!empty($filtros_activos)) {
    $titulo_reporte = "LISTADO FILTRADO POR AÑO: (" . implode(' / ', $filtros_activos) . ")";
}
if ($filtro_estado === 'regular') {
    $titulo_reporte = "LISTADO DE REGULARES (MENOS DE 50% CUMPLIDO)";
} elseif ($filtro_estado === 'candidato') {
    $titulo_reporte = "LISTADO DE CANDIDATOS A BENEFICIO (50%+ CUMPLIDO)";
} elseif ($filtro_estado === 'incompleto') {
    $titulo_reporte = "LISTADO DE REGISTROS CON DATOS INCOMPLETOS";
}
if (!empty($busqueda)) {
     $titulo_reporte .= " (BÚSQUEDA: " . strtoupper(htmlspecialchars($busqueda)) . ")";
}
// ======================================

class PDF extends FPDF
{
    function Header()
    {
        global $titulo_reporte; 

        // Logos de la institución
        $this->Image('assets/img/logoministerio.jpg', 10, 10, 25);
        $this->Image('assets/img/logoiactp1.jpg', 262, 10, 25);
        
        $this->SetFont('Arial', 'B', 11);
        $this->Cell(0, 5, utf8_decode('REPÚBLICA BOLIVARIANA DE VENEZUELA'), 0, 1, 'C');
        $this->SetFont('Arial', '', 10);
        $this->Cell(0, 5, utf8_decode('MINISTERIO DEL PODER POPULAR PARA EL SERVICIO PENITENCIARIO'), 0, 1, 'C');
        $this->Cell(0, 5, utf8_decode('INSTITUTO AUTÓNOMO CAJA DE TRABAJO PENITENCIARIO'), 0, 1, 'C');
        $this->Ln(10);
        
        $this->SetFont('Arial', 'B', 12);
        // Título dinámico
        $this->Cell(0, 8, utf8_decode($titulo_reporte), 0, 1, 'C');
        $this->Ln(4);

        // Cabecera de la tabla
        $this->SetFont('Arial', 'B', 8);
        $this->SetFillColor(230, 230, 230); 
        $this->Cell(10, 7, utf8_decode('N°'), 1, 0, 'C', true);
        $this->Cell(25, 7, 'Documento', 1, 0, 'C', true);
        $this->Cell(50, 7, 'Nombre Completo', 1, 0, 'C', true);
        $this->Cell(40, 7, 'Expediente', 1, 0, 'C', true);
        $this->Cell(25, 7, 'Fecha Condena', 1, 0, 'C', true);
        $this->Cell(30, 7, 'Tiempo Condena', 1, 0, 'C', true);
        $this->Cell(50, 7, utf8_decode('Centro'), 1, 0, 'C', true);
        $this->Cell(40, 7, utf8_decode('Área'), 1, 1, 'C', true);
    }

    function Footer()
    {
        $this->SetY(-12);
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(0, 10, utf8_decode('Página ') . $this->PageNo() . ' de {nb}', 0, 0, 'C');
    }

    // Función auxiliar corregida para calcular el número de líneas
    function NbLines($w, $txt)
    {
        $cw = &$this->CurrentFont['cw'];
        if ($w == 0)
            $w = $this->w - $this->rMargin - $this->x;
        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $s = str_replace("\r", '', $txt);
        $nb = strlen($s);
        if ($nb > 0 && $s[$nb - 1] == "\n")
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
// 2. Consulta SQL Dinámica con PostgreSQL
// ======================================
$sql = "SELECT p.documento_identidad, p.nombre, p.apellido, p.fecha_detencion, 
            c.nombre AS nombre_centro, 
            a.nombre_area AS nombre_area,
            cj.numero_expediente,
            cj.fecha_condena, 
            cj.duracion_condena_dias
        FROM privados p
        LEFT JOIN centros c ON p.centro_codigo = c.codigo
        LEFT JOIN areas_socioproductivas a ON p.area_codigo = a.codigo
        LEFT JOIN privados_casos pc ON p.documento_identidad = pc.privado_documento
        LEFT JOIN casos_judiciales cj ON pc.caso_expediente = cj.numero_expediente";

$condiciones = [];
$params = [];
$param_index = 1;

if (!empty($busqueda)) {
    $condiciones[] = "(p.documento_identidad ILIKE $" . $param_index . " 
            OR p.nombre ILIKE $" . $param_index . " 
            OR p.apellido ILIKE $" . $param_index . "
            OR cj.numero_expediente ILIKE $" . $param_index . "
            OR c.nombre ILIKE $" . $param_index . "
            OR a.nombre_area ILIKE $" . $param_index . "
            OR CAST(p.fecha_detencion AS TEXT) ILIKE $" . $param_index . ")";
    $params[] = "%" . $busqueda . "%";
    $param_index++;
}

if (!empty($filtro_anio_detencion)) {
    $condiciones[] = "EXTRACT(YEAR FROM p.fecha_detencion) = $" . $param_index;
    $params[] = (int)$filtro_anio_detencion;
    $param_index++;
}
if (!empty($filtro_anio_condena)) {
    $condiciones[] = "EXTRACT(YEAR FROM cj.fecha_condena) = $" . $param_index;
    $params[] = (int)$filtro_anio_condena;
    $param_index++;
}
if (!empty($filtro_anio_registro)) {
    $condiciones[] = "EXTRACT(YEAR FROM p.fecha_registro) = $" . $param_index; 
    $params[] = (int)$filtro_anio_registro;
    $param_index++;
}

if ($filtro_estado === 'candidato') {
    $condiciones[] = "(p.fecha_detencion IS NOT NULL 
        AND cj.duracion_condena_dias > 0 
        AND cj.numero_expediente IS NOT NULL 
        AND p.centro_codigo IS NOT NULL 
        AND EXTRACT(DAY FROM (NOW() - p.fecha_detencion)) >= (cj.duracion_condena_dias / 2))";
} elseif ($filtro_estado === 'incompleto') {
    $condiciones[] = "(p.fecha_detencion IS NULL 
        OR cj.duracion_condena_dias IS NULL 
        OR cj.duracion_condena_dias = 0 
        OR cj.numero_expediente IS NULL 
        OR p.centro_codigo IS NULL)";
} elseif ($filtro_estado === 'regular') {
    $condiciones[] = "(p.fecha_detencion IS NOT NULL 
        AND cj.duracion_condena_dias > 0 
        AND cj.numero_expediente IS NOT NULL 
        AND p.centro_codigo IS NOT NULL 
        AND EXTRACT(DAY FROM (NOW() - p.fecha_detencion)) < (cj.duracion_condena_dias / 2))";
}

if (count($condiciones) > 0) {
    $sql .= " WHERE " . implode(' AND ', $condiciones);
}

$sql .= " ORDER BY p.apellido, p.nombre";

$resultado = pg_query_params($conexion, $sql, $params);

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
    while ($privado = pg_fetch_assoc($resultado)) {
        $totalDiasCondena = $privado['duracion_condena_dias'] ?? 0;
        $aniosCondena = floor($totalDiasCondena / 365);
        $mesesCondena = floor(($totalDiasCondena % 365) / 30);
        $diasCondena = ($totalDiasCondena % 365) % 30;
        $tiempoCondenaStr = "{$aniosCondena}a, {$mesesCondena}m, {$diasCondena}d";

        $nombre_centro = utf8_decode($privado['nombre_centro'] ?? 'N/A');
        $nombre_area = utf8_decode($privado['nombre_area'] ?? 'Sin asignar');
        $nombre_completo = utf8_decode($privado['apellido'] . ', ' . $privado['nombre']);
        $expediente = utf8_decode($privado['numero_expediente'] ?? 'N/A');

        $h = 5;

        $nb_centro = $pdf->NbLines(50, $nombre_centro);
        $nb_area = $pdf->NbLines(40, $nombre_area);
        $nb_nombre = $pdf->NbLines(50, $nombre_completo);
        $nb_exp = $pdf->NbLines(40, $expediente);

        $nb = max($nb_centro, $nb_area, $nb_nombre, $nb_exp, 1);
        $row_height = $nb * $h;

        if ($pdf->GetY() + $row_height > 190) {
            $pdf->AddPage();
        }

        $x_pos = $pdf->GetX();
        $y_pos = $pdf->GetY();

        // 1. Número
        $pdf->Rect($x_pos, $y_pos, 10, $row_height);
        $pdf->SetXY($x_pos, $y_pos + (($row_height - 5) / 2));
        $pdf->Cell(10, 5, $i++, 0, 0, 'C');

        // 2. Documento
        $x_pos += 10;
        $pdf->Rect($x_pos, $y_pos, 25, $row_height);
        $pdf->SetXY($x_pos, $y_pos + (($row_height - 5) / 2));
        $pdf->Cell(25, 5, utf8_decode($privado['documento_identidad']), 0, 0, 'C');

        // 3. Nombre Completo
        $x_pos += 25;
        $pdf->Rect($x_pos, $y_pos, 50, $row_height);
        $pdf->SetXY($x_pos, $y_pos + (($row_height - ($nb_nombre * 5)) / 2));
        $pdf->MultiCell(50, 5, $nombre_completo, 0, 'L');

        // 4. Expediente
        $x_pos += 50;
        $pdf->Rect($x_pos, $y_pos, 40, $row_height);
        $pdf->SetXY($x_pos, $y_pos + (($row_height - ($nb_exp * 5)) / 2));
        $pdf->MultiCell(40, 5, $expediente, 0, 'C');

        // 5. Fecha Condena
        $x_pos += 40;
        $pdf->Rect($x_pos, $y_pos, 25, $row_height);
        $pdf->SetXY($x_pos, $y_pos + (($row_height - 5) / 2));
        $pdf->Cell(25, 5, !empty($privado['fecha_condena']) ? date("d/m/Y", strtotime($privado['fecha_condena'])) : 'N/A', 0, 0, 'C');

        // 6. Tiempo Condena
        $x_pos += 25;
        $pdf->Rect($x_pos, $y_pos, 30, $row_height);
        $pdf->SetXY($x_pos, $y_pos + (($row_height - 5) / 2));
        $pdf->Cell(30, 5, $tiempoCondenaStr, 0, 0, 'C');

        // 7. Centro (Con ajuste automático de líneas)
        $x_pos += 30;
        $pdf->Rect($x_pos, $y_pos, 50, $row_height);
        $pdf->SetXY($x_pos, $y_pos + (($row_height - ($nb_centro * 5)) / 2));
        $pdf->MultiCell(50, 5, $nombre_centro, 0, 'L');

        // 8. Área
        $x_pos += 50;
        $pdf->Rect($x_pos, $y_pos, 40, $row_height);
        $pdf->SetXY($x_pos, $y_pos + (($row_height - ($nb_area * 5)) / 2));
        $pdf->MultiCell(40, 5, $nombre_area, 0, 'L');

        $pdf->SetY($y_pos + $row_height);
    }
} else {
    $pdf->Cell(0, 8, utf8_decode('No se encontraron registros con esos filtros.'), 1, 1, 'C');
}

pg_free_result($resultado);
pg_close($conexion);
$pdf->Output();
?>