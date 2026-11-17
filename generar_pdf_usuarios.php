<?php
session_start();
require_once 'conexion.php';
require_once 'fpdf/fpdf.php'; // Asegúrate de que esta ruta sea correcta

// 1. Seguridad
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || $_SESSION['cargo'] != 'Administradora') {
    die("Acceso denegado. Requiere permisos de Administrador.");
}

// 2. Obtener Filtros
$filtro_general = $_GET['general'] ?? '';
$filtro_cargo = $_GET['cargo'] ?? ''; 

// ======================================
// 3. Título Dinámico
// ======================================
$titulo_reporte = "LISTADO GENERAL DE USUARIOS DEL SISTEMA";
$filtros_activos = [];

if (!empty($filtro_general)) {
    $filtros_activos[] = "BUSQUEDA: " . strtoupper(htmlspecialchars($filtro_general));
}
if (!empty($filtro_cargo)) {
    $filtros_activos[] = "CARGO: " . strtoupper(htmlspecialchars($filtro_cargo));
}

if (!empty($filtros_activos)) {
    $titulo_reporte = "LISTADO DE USUARIOS FILTRADO (" . implode(' / ', $filtros_activos) . ")";
}
// ======================================

// 4. Consulta SQL (La misma de la página anterior)
$sql_base = "SELECT 
                cedula, nombre_usuario, nombre, apellido, correo, telefono, cargo
            FROM usuarios
        ";

$where_conditions = [];
$param_types = '';
$param_values = [];

if (!empty($filtro_general)) {
    $where_conditions[] = "(cedula LIKE ? OR nombre LIKE ? OR apellido LIKE ? OR nombre_usuario LIKE ?)";
    $param_like = "%" . $filtro_general . "%";
    $param_types .= 'ssss';
    $param_values[] = $param_like;
    $param_values[] = $param_like;
    $param_values[] = $param_like;
    $param_values[] = $param_like;
}
if (!empty($filtro_cargo)) {
    $where_conditions[] = "cargo = ?";
    $param_types .= 's';
    $param_values[] = $filtro_cargo;
}

$sql_usuarios = $sql_base;
if (count($where_conditions) > 0) {
    $sql_usuarios .= " WHERE " . implode(" AND ", $where_conditions);
}
$sql_usuarios .= " ORDER BY cargo, apellido, nombre"; // Ordenar por Cargo

$stmt_usuarios = $conexion->prepare($sql_usuarios);
if (count($param_values) > 0) {
    $stmt_usuarios->bind_param($param_types, ...$param_values);
}
$stmt_usuarios->execute();
$resultado = $stmt_usuarios->get_result();

// ======================================================
// 5. CLASE PDF (Con tu formato oficial)
// ======================================================
class PDF extends FPDF
{
    function Header()
    {
        global $titulo_reporte; 
        // Logos (Asegúrate que las rutas 'assets/img/...' sean correctas desde este archivo)
        $this->Image('assets/img/logoministerio.jpg', 10, 10, 25);
        $this->Image('assets/img/logoiactp1.jpg', 175, 10, 25); // Vertical (P)
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
        $this->Cell(50, 7, 'Apellido y Nombre', 1, 0, 'C', true);
        $this->Cell(25, 7, 'Documento', 1, 0, 'C', true);
        $this->Cell(30, 7, 'Usuario', 1, 0, 'C', true);
        $this->Cell(35, 7, 'Cargo', 1, 0, 'C', true);
        $this->Cell(40, 7, 'Correo', 1, 1, 'C', true); // 190 total width
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
$pdf = new PDF('P', 'mm', 'A4'); // 'P' = Portrait (Vertical)
$pdf->AliasNbPages();
$pdf->AddPage();
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 20); // Margen inferior de 2cm
$pdf->SetFont('Arial', '', 8);

$i = 1;
if ($resultado->num_rows > 0) {
    while ($usuario = $resultado->fetch_assoc()) {
        $pdf->Cell(10, 7, $i++, 1, 0, 'C'); // N°
        $pdf->Cell(50, 7, utf8_decode($usuario['apellido'] . ', ' . $usuario['nombre']), 1, 0, 'L');
        $pdf->Cell(25, 7, utf8_decode($usuario['cedula']), 1, 0, 'L');
        $pdf->Cell(30, 7, utf8_decode($usuario['nombre_usuario']), 1, 0, 'L');
        $pdf->Cell(35, 7, utf8_decode($usuario['cargo']), 1, 0, 'L');
        $pdf->Cell(40, 7, utf8_decode($usuario['correo']), 1, 1, 'L');
    }
} else {
    $pdf->Cell(190, 10, utf8_decode('No se encontraron usuarios con esos filtros.'), 1, 1, 'C');
}

$conexion->close();
$pdf->Output('I', 'Reporte_Usuarios_'.date('Y-m-d').'.pdf');
?>