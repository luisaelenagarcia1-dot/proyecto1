<?php
require('fpdf/fpdf.php');
require('conexion.php');

// ======================================
// 1. OBTENER FILTROS
// ======================================
// Este archivo SIEMPRE espera un centro y un área específicos.
$filtro_centro = $_GET['centro'] ?? null;
$filtro_area = $_GET['area'] ?? null;

// Validación básica
if (empty($filtro_centro) || empty($filtro_area)) {
    die("Error: Se requiere el código del centro y del área para generar este reporte.");
}

// ======================================
// 2. OBTENER NOMBRES PARA EL ENCABEZADO
// ======================================
// Obtener el nombre del centro
$nombre_centro = 'Desconocido';
$stmt_centro = $conexion->prepare("SELECT nombre FROM centros WHERE codigo = ?");
$stmt_centro->bind_param('s', $filtro_centro);
$stmt_centro->execute();
$res_centro = $stmt_centro->get_result()->fetch_assoc();
if ($res_centro) {
    $nombre_centro = $res_centro['nombre'];
}
$stmt_centro->close();

// Obtener el nombre del área
$nombre_area = 'Desconocida';
$stmt_area = $conexion->prepare("SELECT nombre_area FROM areas_socioproductivas WHERE codigo = ?");
$stmt_area->bind_param('s', $filtro_area);
$stmt_area->execute();
$res_area = $stmt_area->get_result()->fetch_assoc();
if ($res_area) {
    $nombre_area = $res_area['nombre_area'];
}
$stmt_area->close();

// ======================================
// 3. CONSULTA SQL: LISTADO DE PRIVADOS
// ======================================
// Consulta para obtener los privados asignados a esta combinación centro/área
$sql = "SELECT p.documento_identidad, p.nombre, p.apellido, rl.fecha_inicio_labor 
        FROM privados p
        LEFT JOIN registros_laborales rl ON p.documento_identidad = rl.privado_documento
        WHERE p.centro_codigo = ? AND p.area_codigo = ?
        ORDER BY p.apellido, p.nombre";

$stmt = $conexion->prepare($sql);
$stmt->bind_param("ss", $filtro_centro, $filtro_area);
$stmt->execute();
$resultado = $stmt->get_result();


// ======================================
// 4. CLASE PDF (La original que maneja los filtros como propiedades)
// ======================================

class PDF extends FPDF
{
    private $nombre_centro;
    private $nombre_area;

    function setFiltros($centro, $area) {
        $this->nombre_centro = $centro;
        $this->nombre_area = $area;
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
        $this->Cell(0, 10, utf8_decode('LISTADO DE PRIVADOS DE LIBERTAD POR ÁREA Y CENTRO'), 0, 1, 'C');
        $this->SetFont('Arial', '', 10);
        $this->Cell(0, 5, utf8_decode("Centro de Reclusión: {$this->nombre_centro}"), 0, 1, 'C');
        $this->Cell(0, 5, utf8_decode("Área Socioproductiva: {$this->nombre_area}"), 0, 1, 'C');
        $this->Ln(5);

        // Cabecera de la tabla
        $this->SetX(53.5);
        $this->SetFont('Arial', 'B', 9);
        $this->SetFillColor(230, 230, 230);
        $this->Cell(15, 7, utf8_decode('N°'), 1, 0, 'C', true);
        $this->Cell(40, 7, 'Documento', 1, 0, 'C', true);
        $this->Cell(95, 7, 'Nombre Completo', 1, 0, 'C', true);
        $this->Cell(40, 7, 'Inicio Laboral', 1, 1, 'C', true);
    }

    function Footer()
    {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(0, 10, utf8_decode('Página ') . $this->PageNo() . ' de {nb}', 0, 0, 'C');
    }
}

// ======================================
// 5. GENERACIÓN DEL PDF
// ======================================
$pdf = new PDF();
$pdf->AliasNbPages();
// Establecer nombres de centro/área
$pdf->setFiltros($nombre_centro, $nombre_area); 
$pdf->AddPage('L', 'A4');
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 10);
$pdf->SetFont('Arial', '', 9);

$contador = 1;
if ($resultado && $resultado->num_rows > 0) {
    while ($fila = $resultado->fetch_assoc()) {
        // Rellenar tabla
        $pdf->SetX(53.5);
        $pdf->Cell(15, 7, $contador++, 1, 0, 'C');
        $pdf->Cell(40, 7, utf8_decode($fila['documento_identidad']), 1, 0, 'C');
        $pdf->Cell(95, 7, utf8_decode($fila['apellido'] . ', ' . $fila['nombre']), 1, 0, 'C');
        $fecha_inicio = !empty($fila['fecha_inicio_labor']) ? date("d/m/Y", strtotime($fila['fecha_inicio_labor'])) : 'N/A';
        $pdf->Cell(40, 7, utf8_decode($fecha_inicio), 1, 1, 'C');
    }
} else {
    $pdf->SetX(53.5);
    $pdf->Cell(190, 10, utf8_decode('No se encontraron privados en esta área y centro.'), 1, 1, 'C');
}

$stmt->close();
$conexion->close();
$pdf->Output();
?>