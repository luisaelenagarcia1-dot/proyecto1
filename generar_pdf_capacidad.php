<?php
require('fpdf/fpdf.php');
require('conexion.php');

// ======================================
// 1. OBTENER FILTROS Y PREPARAR TÍTULO
// ======================================
$filtro_centro = $_GET['centro'] ?? null;
$filtro_area = $_GET['area'] ?? null;

$titulo_principal = "REPORTE GENERAL DE CAPACIDAD POR ÁREA Y CENTRO";
$subtitulo = "Todos los Centros / Todas las Áreas";

// Si hay filtros, ajustamos el título
if (!empty($filtro_centro) || !empty($filtro_area)) {
    
    // Consulta para obtener el nombre del Centro (si se filtró)
    $nombre_centro = 'Todos';
    if (!empty($filtro_centro)) {
        $res_c = pg_query_params($conexion, "SELECT nombre FROM centros WHERE codigo = $1", array($filtro_centro));
        if ($res_c && pg_num_rows($res_c) > 0) {
            $nombre_centro = pg_fetch_assoc($res_c)['nombre'];
            pg_free_result($res_c);
        }
    }

    // Consulta para obtener el nombre del Área (si se filtró)
    $nombre_area = 'Todas';
    if (!empty($filtro_area)) {
        $res_a = pg_query_params($conexion, "SELECT nombre_area FROM areas_socioproductivas WHERE codigo = $1", array($filtro_area));
        if ($res_a && pg_num_rows($res_a) > 0) {
            $nombre_area = pg_fetch_assoc($res_a)['nombre_area'];
            pg_free_result($res_a);
        }
    }
    
    $titulo_principal = "REPORTE FILTRADO DE CAPACIDAD";
    $subtitulo = utf8_decode("Centro: {$nombre_centro} / Área: {$nombre_area}");
}


// ======================================
// 2. CONSULTA SQL PARA EL LISTADO DE CAPACIDADES
// ======================================
$sql_listado = "SELECT 
                    c.nombre AS nombre_centro, 
                    a.nombre_area, 
                    ca.capacidad_maxima,
                    COUNT(p.documento_identidad) AS total_privados
                FROM centros_areas ca
                LEFT JOIN privados p ON ca.centro_codigo = p.centro_codigo AND ca.area_codigo = p.area_codigo
                JOIN centros c ON ca.centro_codigo = c.codigo
                JOIN areas_socioproductivas a ON ca.area_codigo = a.codigo
                WHERE 1=1";

$params = [];
$param_index = 1;

if (!empty($filtro_centro)) {
    $sql_listado .= " AND c.codigo = $" . $param_index;
    $params[] = $filtro_centro;
    $param_index++;
}
if (!empty($filtro_area)) {
    $sql_listado .= " AND a.codigo = $" . $param_index;
    $params[] = $filtro_area;
    $param_index++;
}

$sql_listado .= " GROUP BY c.nombre, a.nombre_area, ca.capacidad_maxima 
                  ORDER BY c.nombre, a.nombre_area";

$resultado = pg_query_params($conexion, $sql_listado, $params);
$total_filas = ($resultado) ? pg_num_rows($resultado) : 0;


// ======================================
// 3. CLASE PDF ADAPTADA
// ======================================

class PDF_CAPACIDAD extends FPDF
{
    private $subtitulo;
    private $titulo_principal;

    function setEncabezado($titulo, $subtitulo) {
        $this->titulo_principal = $titulo;
        $this->subtitulo = $subtitulo;
    }

    function Header()
    {
        // Encabezado General
        $this->Image('assets/img/logoministerio.jpg', 10, 10, 25);
        $this->Image('assets/img/logoiactp1.jpg', 262, 10, 25); 
        $this->SetFont('Arial', 'B', 11);
        $this->Cell(0, 5, utf8_decode('REPÚBLICA BOLIVARIANA DE VENEZUELA'), 0, 1, 'C');
        $this->SetFont('Arial', '', 10);
        $this->Cell(0, 5, utf8_decode('MINISTERIO DEL PODER POPULAR PARA EL SERVICIO PENITENCIARIO'), 0, 1, 'C');
        $this->Cell(0, 5, utf8_decode('INSTITUTO AUTÓNOMO CAJA DE TRABAJO PENITENCIARIO'), 0, 1, 'C');
        $this->Ln(15);
        
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 10, utf8_decode($this->titulo_principal), 0, 1, 'C');
        $this->SetFont('Arial', '', 10);
        $this->Cell(0, 5, utf8_decode($this->subtitulo), 0, 1, 'C');
        $this->Ln(5);

        // Cabecera de la tabla
        $this->SetFont('Arial', 'B', 9);
        $this->SetFillColor(230, 230, 230);
        $this->Cell(70, 7, 'Centro de Reclusión', 1, 0, 'C', true);
        $this->Cell(70, 7, utf8_decode('Área Socioproductiva'), 1, 0, 'C', true);
        $this->Cell(35, 7, 'Capacidad Max.', 1, 0, 'C', true);
        $this->Cell(35, 7, 'Total Privados', 1, 0, 'C', true);
        $this->Cell(35, 7, 'Espacio Disponible', 1, 1, 'C', true);
    }

    function Footer()
    {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(0, 10, utf8_decode('Página ') . $this->PageNo() . ' de {nb}', 0, 0, 'C');
    }
}


// ======================================
// 4. INSTANCIACIÓN Y RELLENO DEL PDF
// ======================================

$pdf = new PDF_CAPACIDAD('L', 'mm', 'Letter');
$pdf->AliasNbPages();
$pdf->setEncabezado($titulo_principal, $subtitulo); 
$pdf->AddPage();
$pdf->SetFont('Arial', '', 9);

if ($resultado && $total_filas > 0) {
    while ($fila = pg_fetch_assoc($resultado)) {
        $capacidad = isset($fila['capacidad_maxima']) ? (int)$fila['capacidad_maxima'] : 0;
        $privados = (int)$fila['total_privados'];
        $disponible = $capacidad - $privados;

        $pdf->Cell(70, 7, utf8_decode($fila['nombre_centro']), 1, 0);
        $pdf->Cell(70, 7, utf8_decode($fila['nombre_area']), 1, 0);
        $pdf->Cell(35, 7, $capacidad, 1, 0, 'C');
        $pdf->Cell(35, 7, $privados, 1, 0, 'C');
        $pdf->Cell(35, 7, $disponible, 1, 1, 'C');
    }
    pg_free_result($resultado);
} else {
    $pdf->Cell(0, 10, utf8_decode('No se encontraron asignaciones que coincidan con los filtros.'), 1, 1, 'C');
}

if (isset($conexion)) {
    pg_close($conexion);
}
$pdf->Output();
?>