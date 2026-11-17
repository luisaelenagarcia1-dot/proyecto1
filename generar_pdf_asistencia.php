<?php
session_start();
require_once 'conexion.php';
require_once 'fpdf/fpdf.php'; 

// 1. Seguridad
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    die("Acceso denegado. Por favor, inicie sesión.");
}

// 2. Obtener Filtros
$filtro_centro = $_GET['centro'] ?? '';
$filtro_area = $_GET['area'] ?? '';
$filtro_cedula = $_GET['cedula'] ?? '';

// 3. Título Dinámico
$titulo_reporte = "LISTA DE ASISTENCIA DIARIA - " . date('d/m/Y');
$filtros_activos = [];

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
if (!empty($filtro_cedula)) {
    $filtros_activos[] = "CEDULA: " . htmlspecialchars($filtro_cedula);
}
if (!empty($filtros_activos)) {
    $titulo_reporte = "ASISTENCIA FILTRADA (" . implode(' / ', $filtros_activos) . ") - " . date('d/m/Y');
}

// 4. Lógica de Fecha
$fecha_hoy_obj = new DateTime();
$fecha_hoy_sql = $fecha_hoy_obj->format('Y-m-d');
$dias_semana_letras = ['Mon'=>'L', 'Tue'=>'M', 'Wed'=>'X', 'Thu'=>'J', 'Fri'=>'V', 'Sat'=>'S', 'Sun'=>'D'];
$letra_dia_hoy = $dias_semana_letras[$fecha_hoy_obj->format('D')];

// =========================================================
// OBTENER LAS ASISTENCIAS DE HOY (PostgreSQL)
// =========================================================
$sql_asistencias = "SELECT registro_laboral_id, asistencia FROM asistencias WHERE fecha = $1";
$resultado_asistencias = pg_query_params($conexion, $sql_asistencias, array($fecha_hoy_sql));

$asistencias_hoy = [];
if ($resultado_asistencias) {
    while ($asistencia = pg_fetch_assoc($resultado_asistencias)) {
        $asistencias_hoy[$asistencia['registro_laboral_id']] = $asistencia['asistencia'];
    }
    pg_free_result($resultado_asistencias);
}
// =========================================================

// =========================================================
// CONSULTA PRINCIPAL DINÁMICA (PostgreSQL)
// =========================================================
$sql_base = "SELECT 
                rl.id,
                p.documento_identidad,
                (p.apellido || ', ' || p.nombre) AS nombre_completo,
                a.nombre_area
             FROM registros_laborales rl
             JOIN privados p ON rl.privado_documento = p.documento_identidad
             LEFT JOIN areas_socioproductivas a ON p.area_codigo = a.codigo
             WHERE 
                rl.fecha_fin_labor IS NULL 
                AND rl.fecha_inicio_labor <= CURRENT_DATE
                AND rl.dias_laborales ILIKE $1"; 

$where_conditions = [];
$param_values = ["%" . $letra_dia_hoy . "%"];
$param_index = 2;

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
if (!empty($filtro_cedula)) {
    $where_conditions[] = "p.documento_identidad ILIKE $" . $param_index;
    $param_values[] = "%" . $filtro_cedula . "%";
    $param_index++;
}

$sql_trabajadores = $sql_base;
if (count($where_conditions) > 0) {
    $sql_trabajadores .= " AND " . implode(" AND ", $where_conditions);
}
$sql_trabajadores .= " ORDER BY a.nombre_area, nombre_completo";

$resultado_trabajadores = pg_query_params($conexion, $sql_trabajadores, $param_values);
$total_trabajadores = ($resultado_trabajadores) ? pg_num_rows($resultado_trabajadores) : 0;


// 6. CLASE PDF
class PDF extends FPDF
{
    function Header()
    {
        global $titulo_reporte; 
        $this->Image('assets/img/logoministerio.jpg', 10, 10, 25);
        $this->Image('assets/img/logoiactp1.jpg', 175, 10, 25); 
        $this->SetFont('Arial', 'B', 11);
        $this->Cell(0, 5, utf8_decode('REPÚBLICA BOLIVARIANA DE VENEZUELA'), 0, 1, 'C');
        $this->SetFont('Arial', '', 10);
        $this->Cell(0, 5, utf8_decode('MINISTERIO DEL PODER POPULAR PARA EL SERVICIO PENITENCIARIO'), 0, 1, 'C');
        $this->Cell(0, 5, utf8_decode('INSTITUTO AUTÓNOMO CAJA DE TRABAJO PENITENCIARIO'), 0, 1, 'C');
        $this->Ln(15);
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 10, utf8_decode($titulo_reporte), 0, 1, 'C');
        $this->Ln(5);
        
        $this->SetFont('Arial', 'B', 8);
        $this->SetFillColor(230, 230, 230);
        $this->Cell(10, 7, utf8_decode('N°'), 1, 0, 'C', true);
        $this->Cell(65, 7, 'Apellido y Nombre', 1, 0, 'C', true);
        $this->Cell(30, 7, 'Documento', 1, 0, 'C', true);
        $this->Cell(50, 7, utf8_decode('Área'), 1, 0, 'C', true);
        $this->Cell(35, 7, 'Asistencia', 1, 1, 'C', true);
    }
    function Footer()
    {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(0, 10, utf8_decode('Página ') . $this->PageNo() . ' de {nb}', 0, 0, 'C');
    }
}
// ======================================

// 7. Creación y llenado del PDF
$pdf = new PDF('P', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->AddPage();
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 20); 
$pdf->SetFont('Arial', '', 8);

$i = 1;
if ($resultado_trabajadores && $total_trabajadores > 0) {
    while ($privado = pg_fetch_assoc($resultado_trabajadores)) {
        
        $registro_id = $privado['id']; 
        $estado = $asistencias_hoy[$registro_id] ?? 'Ausente';
        
        // Colores según estado
        if ($estado == 'Presente') $pdf->SetTextColor(0, 100, 0); // Verde
        elseif ($estado == 'Ausente') $pdf->SetTextColor(200, 0, 0); // Rojo
        elseif ($estado == 'Reposo' || $estado == 'Permiso') $pdf->SetTextColor(204, 102, 0); // Naranja

        // Imprimir celdas
        $pdf->Cell(10, 7, $i++, 1, 0, 'C'); 
        $pdf->SetTextColor(0); 
        $pdf->Cell(65, 7, utf8_decode($privado['nombre_completo']), 1, 0, 'L'); 
        $pdf->Cell(30, 7, utf8_decode($privado['documento_identidad']), 1, 0, 'L');
        $pdf->Cell(50, 7, utf8_decode($privado['nombre_area'] ?? 'N/A'), 1, 0, 'L'); 
        
        if ($estado == 'Presente') $pdf->SetTextColor(0, 100, 0);
        elseif ($estado == 'Ausente') $pdf->SetTextColor(200, 0, 0);
        elseif ($estado == 'Reposo' || $estado == 'Permiso') $pdf->SetTextColor(204, 102, 0);
        
        $pdf->Cell(35, 7, utf8_decode($estado), 1, 1, 'C'); 
        $pdf->SetTextColor(0); 
    }
    pg_free_result($resultado_trabajadores);
} else {
    $pdf->Cell(190, 10, utf8_decode('No se encontraron registros con esos filtros.'), 1, 1, 'C');
}

if (isset($conexion)) {
    pg_close($conexion);
}
$pdf->Output('I', 'Reporte_Asistencia_'.date('Y-m-d').'.pdf');
?>