<?php
require('fpdf/fpdf.php');
require('conexion.php');
session_start();

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: login.php");
    exit();
}

// ======================================
// 1. OBTENER FILTROS Y PREPARAR TÍTULO
// ======================================
$busqueda = trim($_GET['busqueda'] ?? '');

$titulo_principal = "REPORTE GENERAL DE SECCIONES ACADÉMICAS";
$subtitulo = "Control oficial de nomenclatura, centros y entes impartidores";

if (!empty($busqueda)) {
    $titulo_principal = "REPORTE DE SECCIONES - BÚSQUEDA FILTRADA";
    $subtitulo = utf8_decode("Filtro aplicado: '{$busqueda}'");
}

// ======================================
// 2. CONSULTA SQL PARA EL LISTADO DE SECCIONES
// ======================================
$sql = "SELECT s.*, c.nombre AS nombre_centro, cu.nombre_curso, e.nombre_ente 
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
$total_filas = ($resultado) ? pg_num_rows($resultado) : 0;


// ======================================
// 3. CLASE PDF ADAPTADA PARA SECCIONES
// ======================================

class PDF_SECCIONES extends FPDF
{
    private $subtitulo;
    private $titulo_principal;

    function setEncabezado($titulo, $subtitulo) {
        $this->titulo_principal = $titulo;
        $this->subtitulo = $subtitulo;
    }

    function Header()
    {
        // Encabezado Institucional con Logos
        if (file_exists('assets/img/logoministerio.jpg')) {
            $this->Image('assets/img/logoministerio.jpg', 10, 10, 25);
        }
        if (file_exists('assets/img/logoiactp1.jpg')) {
            $this->Image('assets/img/logoiactp1.jpg', 175, 10, 25);
        }

        $this->SetFont('Arial', 'B', 10);
        $this->Cell(0, 5, utf8_decode('REPÚBLICA BOLIVARIANA DE VENEZUELA'), 0, 1, 'C');
        $this->SetFont('Arial', '', 9);
        $this->Cell(0, 5, utf8_decode('MINISTERIO DEL PODER POPULAR PARA EL SERVICIO PENITENCIARIO'), 0, 1, 'C');
        $this->Cell(0, 5, utf8_decode('INSTITUTO AUTÓNOMO CAJA DE TRABAJO PENITENCIARIO'), 0, 1, 'C');
        $this->Ln(8);
        
        $this->SetFont('Arial', 'B', 11);
        $this->Cell(0, 7, utf8_decode($this->titulo_principal), 0, 1, 'C');
        $this->SetFont('Arial', '', 9);
        $this->Cell(0, 5, utf8_decode($this->subtitulo), 0, 1, 'C');
        $this->Ln(5);

        // Cabecera de la tabla (Ancho total útil en Carta vertical: 196 mm)
        // 10 + 42 + 42 + 45 + 18 + 15 + 24 = 196 mm
        $this->SetFont('Arial', 'B', 8);
        $this->SetFillColor(230, 230, 230);
        $this->Cell(10, 7, utf8_decode('N°'), 1, 0, 'C', true);
        $this->Cell(42, 7, utf8_decode('Nomenclatura'), 1, 0, 'C', true);
        $this->Cell(42, 7, utf8_decode('Centro Penitenciario'), 1, 0, 'L', true);
        $this->Cell(45, 7, utf8_decode('Curso / Ente'), 1, 0, 'L', true);
        $this->Cell(18, 7, utf8_decode('Año / Sec'), 1, 0, 'C', true);
        $this->Cell(15, 7, utf8_decode('Cupos'), 1, 0, 'C', true);
        $this->Cell(24, 7, utf8_decode('Horario'), 1, 1, 'C', true);
    }

    function Footer()
    {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(0, 10, utf8_decode('Página ') . $this->PageNo() . ' de {nb} - Generado el: ' . date('d/m/Y H:i:s'), 0, 0, 'C');
    }
}


// ======================================
// 4. INSTANCIACIÓN Y RELLENO DEL PDF
// ======================================

$pdf = new PDF_SECCIONES('P', 'mm', 'Letter'); // Formato Vertical (Portrait)
$pdf->AliasNbPages();
$pdf->setEncabezado($titulo_principal, $subtitulo); 
$pdf->AddPage();
$pdf->SetFont('Arial', '', 8);

$i = 1;
if ($resultado && $total_filas > 0) {
    while ($fila = pg_fetch_assoc($resultado)) {
        // Altura de celda adaptable o fija compacta
        $pdf->Cell(10, 7, $i++, 1, 0, 'C');
        $pdf->Cell(42, 7, utf8_decode($fila['codigo_nomenclatura']), 1, 0, 'C');
        
        // Recortar textos largos si es necesario para que entren bien en las celdas
        $centro_corto = (strlen($fila['nombre_centro']) > 26) ? substr($fila['nombre_centro'], 0, 24) . '...' : $fila['nombre_centro'];
        $pdf->Cell(42, 7, utf8_decode($centro_corto), 1, 0, 'L');
        
        $curso_ente = $fila['nombre_curso'];
        $curso_ente_corto = (strlen($curso_ente) > 28) ? substr($curso_ente, 0, 26) . '...' : $curso_ente;
        $pdf->Cell(45, 7, utf8_decode($curso_ente_corto), 1, 0, 'L');
        
        $anio_secc = $fila['anio'] . ' / ' . $fila['numero_seccion'];
        $pdf->Cell(18, 7, utf8_decode($anio_secc), 1, 0, 'C');
        
        $pdf->Cell(15, 7, utf8_decode($fila['capacidad_maxima']), 1, 0, 'C');
        
        $horario_corto = (strlen($fila['horario']) > 15) ? substr($fila['horario'], 0, 14) . '.' : $fila['horario'];
        $pdf->Cell(24, 7, utf8_decode($horario_corto), 1, 1, 'C');
    }
    pg_free_result($resultado);
} else {
    $pdf->Cell(196, 10, utf8_decode('No se encontraron secciones académicas aperturadas bajo los filtros indicados.'), 1, 1, 'C');
}

if (isset($conexion)) {
    pg_close($conexion);
}

$pdf->Output('I', 'Reporte_Secciones_IACTP.pdf');
?>