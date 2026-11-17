<?php
session_start();
require_once 'conexion.php';
require_once 'fpdf/fpdf.php'; 

// 1. Seguridad
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    die("Acceso denegado.");
}

// 2. Obtener Filtros
$filtro_centro = $_GET['centro'] ?? '';
$filtro_area = $_GET['area'] ?? '';
$filtro_general = $_GET['general'] ?? '';
$filtro_estado = $_GET['estado'] ?? ''; 
$filtro_anio = $_GET['anio'] ?? '';

// ======================================
// 3. Titulo Dinamico
// ======================================
$titulo_reporte = "LISTADO GENERAL DE REGISTROS LABORALES";
$filtros_activos = [];

if (!empty($filtro_general)) {
    $filtros_activos[] = "BUSQUEDA: " . strtoupper(htmlspecialchars($filtro_general));
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
if (!empty($filtro_area)) {
    $res_a = pg_query_params($conexion, "SELECT nombre_area FROM areas_socioproductivas WHERE codigo = $1", array($filtro_area));
    $nombre_area_txt = $filtro_area;
    if ($res_a && pg_num_rows($res_a) > 0) {
        $nombre_area_txt = pg_fetch_assoc($res_a)['nombre_area'];
        pg_free_result($res_a);
    }
    $filtros_activos[] = "AREA: " . strtoupper($nombre_area_txt);
}
if (!empty($filtro_anio)) {
    $filtros_activos[] = "ANIO INICIO: " . htmlspecialchars($filtro_anio);
}
if ($filtro_estado === 'activo') {
    $filtros_activos[] = "ESTADO: ACTIVOS";
} elseif ($filtro_estado === 'finalizado') {
    $filtros_activos[] = "ESTADO: FINALIZADOS";
}

if (!empty($filtros_activos)) {
    $titulo_reporte = "LISTADO LABORAL FILTRADO (" . implode(' / ', $filtros_activos) . ")";
}
// ======================================

// 4. Consulta SQL (Segura y Dinamica con PostgreSQL)
$sql_base = "SELECT 
               p.documento_identidad,
               (p.apellido || ', ' || p.nombre) AS nombre_completo,
               rl.fecha_inicio_labor,
               rl.horario_desde,
               rl.horario_hasta,
               rl.dias_laborales,
               rl.fecha_fin_labor,
               a.nombre_area,
               c.nombre AS nombre_centro
        FROM registros_laborales rl
        JOIN privados p ON rl.privado_documento = p.documento_identidad
        LEFT JOIN areas_socioproductivas a ON p.area_codigo = a.codigo
        LEFT JOIN centros c ON p.centro_codigo = c.codigo";

$where_conditions = [];
$param_values = [];
$param_index = 1;

if (!empty($filtro_general)) {
    $where_conditions[] = "(p.documento_identidad ILIKE $" . $param_index . " OR p.nombre ILIKE $" . ($param_index + 1) . " OR p.apellido ILIKE $" . ($param_index + 2) . ")";
    $param_like = "%" . $filtro_general . "%";
    $param_values[] = $param_like;
    $param_values[] = $param_like;
    $param_values[] = $param_like;
    $param_index += 3;
}
if (!empty($filtro_centro)) {
    $where_conditions[] = "p.centro_codigo = $" . $param_index;
    $param_values[] = $filtro_centro;
    $param_index++;
}
if (!empty($filtro_area)) {
    $where_conditions[] = "p.area_codigo = $" . $param_index;
    $param_values[] = $filtro_area;
    $param_index++;
}
if (!empty($filtro_anio)) {
    $where_conditions[] = "EXTRACT(YEAR FROM rl.fecha_inicio_labor) = $" . $param_index;
    $param_values[] = (int)$filtro_anio;
    $param_index++;
}
if ($filtro_estado === 'activo') {
    $where_conditions[] = "rl.fecha_fin_labor IS NULL";
} elseif ($filtro_estado === 'finalizado') {
    $where_conditions[] = "rl.fecha_fin_labor IS NOT NULL";
}

$sql_trabajadores = $sql_base;
if (count($where_conditions) > 0) {
    $sql_trabajadores .= " WHERE " . implode(" AND ", $where_conditions);
}
$sql_trabajadores .= " ORDER BY c.nombre, a.nombre_area, p.apellido, p.nombre";

$resultado = pg_query_params($conexion, $sql_trabajadores, $param_values);
if ($resultado === false) {
    die("Error al ejecutar la consulta SQL en PDF: " . pg_last_error($conexion));
}

// ======================================================
// 5. CLASE PDF
// ======================================================
class PDF extends FPDF
{
    function Header()
    {
        global $titulo_reporte; 
        $this->Image('assets/img/logoministerio.jpg', 10, 10, 25);
        $this->Image('assets/img/logoiactp1.jpg', 262, 10, 25); 
        $this->SetFont('Arial', 'B', 11);
        $this->Cell(0, 5, utf8_decode('REPUBLICA BOLIVARIANA DE VENEZUELA'), 0, 1, 'C');
        $this->SetFont('Arial', '', 10);
        $this->Cell(0, 5, utf8_decode('MINISTERIO DEL PODER POPULAR PARA EL SERVICIO PENITENCIARIO'), 0, 1, 'C');
        $this->Cell(0, 5, utf8_decode('INSTITUTO AUTONOMO CAJA DE TRABAJO PENITENCIARIO'), 0, 1, 'C');
        $this->Ln(15);
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 10, utf8_decode($titulo_reporte), 0, 1, 'C');
        $this->Ln(5);

        // --- ENCABEZADO DE LA TABLA (Anchos que suman exactamente 277mm) ---
        $this->SetFont('Arial', 'B', 8);
        $this->SetFillColor(230, 230, 230);

        $this->Cell(50, 7, 'Apellido y Nombre', 1, 0, 'C', true);   // 50
        $this->Cell(28, 7, 'Documento', 1, 0, 'C', true);          // 28
        $this->Cell(65, 7, 'Centro / Area', 1, 0, 'C', true);      // 65
        $this->Cell(22, 7, 'Inicio Labor', 1, 0, 'C', true);        // 22
        $this->Cell(42, 7, utf8_decode('Dias / Horario'), 1, 0, 'C', true); // 42
        $this->Cell(30, 7, 'Estado', 1, 1, 'C', true);              // 30
    }

    function Footer()
    {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(0, 10, utf8_decode('Pagina ') . $this->PageNo() . ' de {nb}', 0, 0, 'C');
    }
}
// ======================================

// 6. Creacion y llenado del PDF 
$pdf = new PDF('L', 'mm', 'A4'); // 'L' para modo horizontal
$pdf->AliasNbPages();
$pdf->AddPage();
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 15);
$pdf->SetFont('Arial', '', 8);

$i = 1; 
$total_filas = pg_num_rows($resultado);

if ($total_filas > 0) {
    while ($fila = pg_fetch_assoc($resultado)) {

        $dias_amigables = str_replace(['L','M','X','J','V','S','D'], ['L','Ma','Mi','J','V','S','D'], $fila['dias_laborales']);
        $horario = date('h:iA', strtotime($fila['horario_desde'])) . '-' . date('h:iA', strtotime($fila['horario_hasta']));

        if (!empty($fila['fecha_fin_labor'])) {
            $estado = 'Finalizado';
        } else {
            $estado = 'Activo';
        }

        // --- ALTURA DE FILA AMPLIADA (12mm) ---
        $h = 12; 

        $nombre_completo = utf8_decode($fila['nombre_completo']);
        $documento = utf8_decode($fila['documento_identidad']);
        $centro_txt = utf8_decode($fila['nombre_centro'] ?? 'N/A');
        $area_txt = utf8_decode($fila['nombre_area'] ?? 'N/A');
        $inicio_labor = date('d/m/Y', strtotime($fila['fecha_inicio_labor']));
        $dias_horario = utf8_decode($dias_amigables . ' / ' . $horario);
        $estado_txt = utf8_decode($estado);

        // 1. Apellido y Nombre
        $pdf->Cell(50, $h, $nombre_completo, 1, 0, 'L'); 
        // 2. Documento
        $pdf->Cell(28, $h, $documento, 1, 0, 'L'); 
        
        // 3. Columna Centro / Área en dos líneas reales separadas dentro de la misma celda
        $x = $pdf->GetX();
        $y = $pdf->GetY();
        
        // Dibujar el recuadro contenedor de la celda Centro/Área
        $pdf->Cell(65, $h, '', 1, 0, 'L'); 
        
        // Escribir el Centro en la línea superior
        $pdf->SetXY($x + 1, $y + 1.5);
        $pdf->SetFont('Arial', 'B', 7.5);
        $pdf->Cell(63, 4, $centro_txt, 0, 0, 'L');
        
        // Escribir el Área bajando de celda (en la línea inferior, color gris)
        $pdf->SetXY($x + 1, $y + 6.5);
        $pdf->SetFont('Arial', '', 7);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->Cell(63, 4, $area_txt, 0, 0, 'L');
        
        // Restaurar cursor al borde derecho exacto de la celda de 65mm y color de texto normal
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Arial', '', 8);
        $pdf->SetXY($x + 65, $y);

        // 4. Inicio Labor
        $pdf->Cell(22, $h, $inicio_labor, 1, 0, 'C'); 
        // 5. Días / Horario
        $pdf->Cell(42, $h, $dias_horario, 1, 0, 'L'); 
        // 6. Estado (Salto de línea finalizador con '1')
        $pdf->Cell(30, $h, $estado_txt, 1, 1, 'C'); 
        
        $i++; 
    }
    pg_free_result($resultado);
} else {
    $pdf->Cell(277, 10, utf8_decode('No se encontraron registros de trabajo con esos filtros.'), 1, 1, 'C'); 
}

// Cierre de conexión y output del PDF
pg_close($conexion);
$pdf->Output('I', 'Reporte_Laboral.pdf');
?>