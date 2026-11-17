<?php
session_start();
require_once 'conexion.php';
require_once 'fpdf/fpdf.php'; 

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    die("Acceso denegado.");
}

// 2. Obtener Filtros
$filtro_anio = $_GET['anio'] ?? '';
$filtro_estado = $_GET['estado'] ?? '';
$filtro_centro = $_GET['centro'] ?? '';

$titulo_reporte = "HISTÓRICO DE REDENCIONES (APROBADAS Y RECHAZADAS)";
$filtros_activos = [];

if (!empty($filtro_anio)) {
    $filtros_activos[] = "AÑO: " . htmlspecialchars($filtro_anio);
}
if (!empty($filtro_estado)) {
    $filtros_activos[] = "ESTADO: " . strtoupper(htmlspecialchars($filtro_estado));
}

if (!empty($filtro_centro)) {
    $res_c = pg_query_params($conexion, "SELECT nombre FROM centros WHERE codigo = $1", array($filtro_centro));
    $nombre_centro_txt = $filtro_centro;
    if ($res_c && pg_num_rows($res_c) > 0) {
        $nombre_centro_txt = pg_fetch_assoc($res_c)['nombre'];
        pg_free_result($res_c);
    }
    $filtros_activos[] = "CENTRO: " . strtoupper($nombre_centro_txt);
}

if (!empty($filtros_activos)) {
    $titulo_reporte = "HISTÓRICO FILTRADO (" . implode(' / ', $filtros_activos) . ")";
}

// ======================================
// 4. Consulta SQL (PostgreSQL)
// ======================================
$sql_historico = "SELECT s.*, p.nombre, p.apellido, c.nombre AS nombre_centro
                  FROM solicitudes_redencion s
                  JOIN privados p ON s.privado_documento = p.documento_identidad
                  LEFT JOIN centros c ON p.centro_codigo = c.codigo
                  WHERE s.estado IN ('Aprobado', 'Rechazado')";

$condiciones = [];
$param_values = [];
$param_index = 1;

if (!empty($filtro_anio)) {
    $condiciones[] = "EXTRACT(YEAR FROM s.fecha_decision) = $" . $param_index;
    $param_values[] = $filtro_anio;
    $param_index++;
}
if (!empty($filtro_estado)) {
    $condiciones[] = "s.estado = $" . $param_index;
    $param_values[] = $filtro_estado;
    $param_index++;
}
if (!empty($filtro_centro)) {
    $condiciones[] = "p.centro_codigo = $" . $param_index;
    $param_values[] = $filtro_centro;
    $param_index++;
}

if (count($condiciones) > 0) {
    $sql_historico .= " AND " . implode(' AND ', $condiciones);
}
$sql_historico .= " ORDER BY s.fecha_decision DESC"; 

$resultado = pg_query_params($conexion, $sql_historico, $param_values);
$total_filas = ($resultado) ? pg_num_rows($resultado) : 0;


class PDF extends FPDF
{
    function Header()
    {
        global $titulo_reporte; 
        $this->Image('assets/img/logoministerio.jpg', 10, 10, 25);
        $this->Image('assets/img/logoiactp1.jpg', 262, 10, 25); // Horizontal (L)
        $this->SetFont('Arial', 'B', 11);
        $this->Cell(0, 5, utf8_decode('REPÚBLICA BOLIVARIANA DE VENEZUELA'), 0, 1, 'C');
        $this->SetFont('Arial', '', 10);
        $this->Cell(0, 5, utf8_decode('MINISTERIO DEL PODER POPULAR PARA EL SERVICIO PENITENCIARIO'), 0, 1, 'C');
        $this->Cell(0, 5, utf8_decode('INSTITUTO AUTÓNOMO CAJA DE TRABAJO PENITENCIARIO'), 0, 1, 'C');
        $this->Ln(15);
        
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 10, utf8_decode($titulo_reporte), 0, 1, 'C');
        $this->Ln(5);

        // Cabecera de la tabla
        $this->SetFont('Arial', 'B', 8);
        $this->SetFillColor(230, 230, 230);
        $this->Cell(10, 7, utf8_decode('N°'), 1, 0, 'C', true);
        $this->Cell(45, 7, 'Apellido y Nombre', 1, 0, 'C', true);
        $this->Cell(25, 7, 'Documento', 1, 0, 'C', true);
        $this->Cell(45, 7, 'Centro', 1, 0, 'C', true);
        $this->Cell(20, 7, 'D. Contados', 1, 0, 'C', true);
        $this->Cell(20, 7, 'Estado', 1, 0, 'C', true);
        $this->Cell(25, 7, 'Fecha Decisión', 1, 0, 'C', true);
        $this->Cell(40, 7, 'Nro. Aprobacion', 1, 0, 'C', true);
        $this->Cell(45, 7, 'Juez', 1, 1, 'C', true);
    }

    function Footer()
    {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(0, 10, utf8_decode('Página ') . $this->PageNo() . ' de {nb}', 0, 0, 'C');
    }
}
// ======================================

// 6. Creación y llenado del PDF
$pdf = new PDF('L', 'mm', 'A4'); // 'L' = Landscape (Horizontal)
$pdf->AliasNbPages();
$pdf->AddPage();
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 15);
$pdf->SetFont('Arial', '', 8);

$i = 1;
if ($resultado && $total_filas > 0) {
    while ($fila = pg_fetch_assoc($resultado)) {
        $estado = htmlspecialchars($fila['estado']);
        
        $pdf->Cell(10, 7, $i++, 1, 0, 'C');
        $pdf->Cell(45, 7, utf8_decode($fila['apellido'] . ', ' . $fila['nombre']), 1, 0, 'L');
        $pdf->Cell(25, 7, utf8_decode($fila['privado_documento']), 1, 0, 'L');
        $pdf->Cell(45, 7, utf8_decode($fila['nombre_centro'] ?? 'N/A'), 1, 0, 'L');
        $pdf->Cell(20, 7, utf8_decode($fila['dias_presente_contados']), 1, 0, 'C');
        $pdf->Cell(20, 7, utf8_decode($estado), 1, 0, 'C');
        $pdf->Cell(25, 7, date('d/m/Y', strtotime($fila['fecha_decision'])), 1, 0, 'C');
        $pdf->Cell(40, 7, utf8_decode($fila['numero_aprobacion'] ?? 'N/A'), 1, 0, 'L');
        $pdf->Cell(45, 7, utf8_decode($fila['juez_aprobacion'] ?? 'N/A'), 1, 1, 'L');
    }
    pg_free_result($resultado);
} else {
    $pdf->Cell(275, 10, utf8_decode('No se encontraron registros históricos que coincidan con el filtro.'), 1, 1, 'C');
}

if (isset($conexion)) {
    pg_close($conexion);
}
$pdf->Output('I', 'Reporte_Historico_Redenciones.pdf');
?>