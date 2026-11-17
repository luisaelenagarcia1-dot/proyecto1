
<?php
ob_start(); // Iniciar buffer de salida
require('fpdf/fpdf.php'); 
require('conexion.php'); 

if (!isset($_GET['documento']) || empty($_GET['documento'])) { 
    die("Error: Documento de identidad no proporcionado."); 
} 
$documento = $_GET['documento']; 

// --- CONSULTA PARA OBTENER EL DIRECTOR ACTIVO ---
$sql_director = "SELECT 
                    CONCAT(nombre, ' ', apellido) AS nombre_completo_director, 
                    cedula AS cedula_director 
                  FROM usuarios 
                  WHERE es_director_actual = 1 
                  LIMIT 1";
                
$resultado_director = pg_query($conexion, $sql_director);
$nombre_director = "(Director No Asignado)"; 
$cedula_director = "C.I. N/A"; 

if ($resultado_director && pg_num_rows($resultado_director) > 0) {
    $fila_director = pg_fetch_assoc($resultado_director);
    $nombre_director = $fila_director['nombre_completo_director']; 
    $cedula_director = 'C.I. ' . ($fila_director['cedula_director'] ?? 'N/A');
    pg_free_result($resultado_director);
}

// =======================================================
// CONSULTA 1: DATOS DEL PRIVADO (Información Personal y Localidad)
// =======================================================
$sql_privado = "SELECT p.*, 
                       ci.nombre AS ciudad, 
                       e.nombre AS estado, 
                       pa.nombre AS pais,
                       c.nombre as centro_nombre, 
                       a.nombre_area 
                FROM privados p 
                LEFT JOIN loc_ciudades ci ON p.ciudad_nacimiento_id = ci.id
                LEFT JOIN loc_estados e ON ci.id_estado = e.id
                LEFT JOIN loc_paises pa ON e.id_pais = pa.id
                LEFT JOIN centros c ON p.centro_codigo = c.codigo 
                LEFT JOIN areas_socioproductivas a ON p.area_codigo = a.codigo 
                WHERE p.documento_identidad = $1"; 

$resultado_privado = pg_query_params($conexion, $sql_privado, array($documento)); 

if (!$resultado_privado || pg_num_rows($resultado_privado) === 0) { 
    if ($resultado_privado) pg_free_result($resultado_privado);
    die("Error: Privado de libertad no encontrado."); 
} 
$privado = pg_fetch_assoc($resultado_privado); 
pg_free_result($resultado_privado);
// =======================================================

// =======================================================
// CONSULTA 2: CASOS JUDICIALES Y PERSONAL 
// =======================================================
$sql_casos = "SELECT cj.*, 
                     j.nombre_completo AS juez_nombre,
                     f.nombre_completo AS fiscal_nombre,
                     a.nombre_completo AS abogado_defensor
              FROM privados_casos pc 
              JOIN casos_judiciales cj ON pc.caso_expediente = cj.numero_expediente 
              LEFT JOIN personal_judicial j ON cj.juez_id = j.id
              LEFT JOIN personal_judicial f ON cj.fiscal_id = f.id
              LEFT JOIN personal_judicial a ON cj.abogado_id = a.id
              WHERE pc.privado_documento = $1
              LIMIT 1"; 

$resultado_casos = pg_query_params($conexion, $sql_casos, array($documento)); 
$caso = ($resultado_casos && pg_num_rows($resultado_casos) > 0) ? pg_fetch_assoc($resultado_casos) : [];
if ($resultado_casos) pg_free_result($resultado_casos);

// CONSULTA 3: OBTENER EL REGISTRO LABORAL ACTIVO
$sql_laboral = "SELECT id, fecha_inicio_labor, fecha_fin_labor, horario_desde, horario_hasta
                FROM registros_laborales 
                WHERE privado_documento = $1 
                ORDER BY fecha_inicio_labor DESC 
                LIMIT 1";
$resultado_laboral = pg_query_params($conexion, $sql_laboral, array($documento));
$laboral = ($resultado_laboral && pg_num_rows($resultado_laboral) > 0) ? pg_fetch_assoc($resultado_laboral) : null;
if ($resultado_laboral) pg_free_result($resultado_laboral);

// =======================================================
// CONSULTA 4: CÁLCULO DE DÍAS TOTALES TRABAJADOS (Liquidación)
// =======================================================
$sql_dias_liquidados = "SELECT SUM(ll.dias_trabajados) AS total_dias_liquidados
                        FROM liquidacion_laboral ll
                        JOIN registros_laborales rl ON ll.registro_laboral_id = rl.id
                        WHERE rl.privado_documento = $1";
                        
$resultado_dias = pg_query_params($conexion, $sql_dias_liquidados, array($documento));
$dias_liquidados = ($resultado_dias && pg_num_rows($resultado_dias) > 0) ? pg_fetch_assoc($resultado_dias) : [];
if ($resultado_dias) pg_free_result($resultado_dias);

$dias_laborados = (int)($dias_liquidados['total_dias_liquidados'] ?? 0); 
$horas_por_dia_laboral = 8; 
$horas_laboradas = round($dias_laborados * $horas_por_dia_laboral, 2); 

// --- ASIGNACIÓN DE VARIABLES FINALES PARA EL PDF ---
$juez_nombre = $caso['juez_nombre'] ?? 'N/A';
$fiscal_nombre = $caso['fiscal_nombre'] ?? 'N/A';
$abogado_defensor = $caso['abogado_defensor'] ?? 'N/A';
$tribunal = $caso['tribunal'] ?? 'N/A';
$tipo_delito = $caso['tipo_delito'] ?? 'N/A';
$numero_expediente = $caso['numero_expediente'] ?? 'N/A';
$duracion_condena_dias = $caso['duracion_condena_dias'] ?? 0;

$fecha_inicio_labor_str = ($laboral && $laboral['fecha_inicio_labor']) ? date('d/m/Y', strtotime($laboral['fecha_inicio_labor'])) : 'N/A';
$horario_desde = ($laboral && $laboral['horario_desde']) ? date('h:i A', strtotime($laboral['horario_desde'])) : 'N/A';
$horario_hasta = ($laboral && $laboral['horario_hasta']) ? date('h:i A', strtotime($laboral['horario_hasta'])) : 'N/A';
$telefono_contacto = 'N/A'; 

// --- CÁLCULOS DE FECHA Y EDAD ---
$fecha_nacimiento_valida = !empty($privado['fecha_nacimiento']);
if ($fecha_nacimiento_valida) {
    $fecha_nacimiento_dt = new DateTime($privado['fecha_nacimiento']); 
    $hoy = new DateTime(); 
    $edad = $hoy->diff($fecha_nacimiento_dt)->y; 
} else {
    $edad = 'N/A';
}

$fecha_emision = new DateTime(); 

// --- INICIO DE LA GENERACIÓN DEL PDF ---
$pdf = new FPDF('P', 'mm', 'A4'); 
$pdf->AddPage(); 
$pdf->SetMargins(10, 10, 10); 
$pdf->SetAutoPageBreak(true, 10); 
$pdf->AliasNbPages(); 

// =======================================================
//       DOCUMENTO 1: FICHA TÉCNICA 
// =======================================================
$pdf->Image('assets/img/logoministerio.jpg', 10, 10, 25); 
$pdf->Image('assets/img/logoiactp1.jpg', 178, 10, 25); 
$pdf->SetFont('Arial', 'B', 11); 
$pdf->SetXY(40, 15); 
$pdf->Cell(130, 5, utf8_decode('REPÚBLICA BOLIVARIANA DE VENEZUELA'), 0, 1, 'C'); 
$pdf->SetFont('Arial', '', 10); 
$pdf->SetX(40); 
$pdf->Cell(130, 5, utf8_decode('MINISTERIO DEL PODER POPULAR PARA EL SERVICIO PENITENCIARIO'), 0, 1, 'C'); 
$pdf->SetX(40); 
$pdf->Cell(130, 5, utf8_decode('INSTITUTO AUTÓNOMO CAJA DE TRABAJO PENITENCIARIO'), 0, 1, 'C'); 
$pdf->Ln(15); 
$pdf->SetY($pdf->GetY() - 5); 

if (!empty($privado['foto']) && file_exists($privado['foto'])) { 
    $x_foto = ($pdf->GetPageWidth() - 30) / 2; 
    $pdf->Image($privado['foto'], $x_foto, $pdf->GetY(), 30, 30); 
} else { 
    $x_foto = ($pdf->GetPageWidth() - 30) / 2; 
    $pdf->Rect($x_foto, $pdf->GetY(), 30, 30); 
} 
$pdf->Ln(35); 
$pdf->SetFont('Arial', 'B', 13); 
$pdf->Cell(0, 10, utf8_decode('FICHA TÉCNICA DEL PRIVADO DE LIBERTAD'), 0, 1, 'C'); 
$pdf->Ln(5); 

// --- DETALLES DE LA FICHA --- 
$pdf->SetFont('Arial', 'B', 10); 
$pdf->Cell(55, 7, utf8_decode('Nombres y Apellidos:'), 0, 0); 
$pdf->SetFont('Arial', '', 10); 
$pdf->Cell(0, 7, utf8_decode(($privado['nombre'] ?? 'N/A') . ' ' . ($privado['apellido'] ?? '')), 0, 1); 
$pdf->Ln(2); 

$pdf->SetFont('Arial', 'B', 10); 
$pdf->Cell(55, 7, utf8_decode('Lugar y Fecha de Nacimiento:'), 0, 0); 
$pdf->SetFont('Arial', '', 10); 
$fecha_nac_str = $fecha_nacimiento_valida ? date('d/m/Y', strtotime($privado['fecha_nacimiento'])) : 'N/A';
$pdf->Cell(0, 7, utf8_decode(($privado['ciudad'] ?? 'N/A') . ', ' . ($privado['estado'] ?? 'N/A') . ', ' . ($privado['pais'] ?? 'N/A') . ', ' . $fecha_nac_str), 0, 1); 
$pdf->Ln(2); 

$pdf->SetFont('Arial', 'B', 10); 
$pdf->Cell(20, 7, 'Edad:', 0, 0); 
$pdf->SetFont('Arial', '', 10); 
$pdf->Cell(20, 7, $edad . utf8_decode(' años'), 0, 0); 
$pdf->SetFont('Arial', 'B', 10); 
$pdf->Cell(30, 7, utf8_decode('Estado Civil:'), 0, 0); 
$pdf->SetFont('Arial', '', 10); 
$pdf->Cell(40, 7, utf8_decode($privado['estado_civil'] ?? 'N/A'), 0, 0); 
$pdf->SetFont('Arial', 'B', 10); 
$pdf->Cell(40, 7, utf8_decode('Cédula o Pasaporte:'), 0, 0); 
$pdf->SetFont('Arial', '', 10); 
$pdf->Cell(0, 7, utf8_decode(($privado['prefijo_documento'] ?? '') . '-' . ($privado['numero_documento'] ?? 'N/A')), 0, 1); 
$pdf->Ln(2); 

$pdf->SetFont('Arial', 'B', 10); 
$pdf->Cell(25, 7, utf8_decode('Profesión:'), 0, 0); 
$pdf->SetFont('Arial', '', 10); 
$pdf->Cell(50, 7, utf8_decode($privado['profesion'] ?? 'N/A'), 0, 0); 
$pdf->SetFont('Arial', 'B', 10); 
$pdf->Cell(30, 7, utf8_decode('Bajo el Régimen:'), 0, 0); 
$pdf->SetFont('Arial', '', 10); 
$pdf->Cell(0, 7, 'No especificado', 0, 1); 
$pdf->Ln(2); 

// ---- SECCIÓN JUDICIAL ----
$pdf->SetFont('Arial', 'B', 10); 
$pdf->Cell(50, 7, utf8_decode('Juez de Causa:'), 0, 0); 
$pdf->SetFont('Arial', '', 10); 
$pdf->Cell(0, 7, utf8_decode($juez_nombre), 0, 1); 
$pdf->Ln(2); 

$pdf->SetFont('Arial', 'B', 10); 
$pdf->Cell(20, 7, utf8_decode('Delito:'), 0, 0); 
$pdf->SetFont('Arial', '', 10); 
$pdf->Cell(0, 7, utf8_decode($tipo_delito), 0, 1); 
$pdf->Ln(2); 

$pdf->SetFont('Arial', 'B', 10); 
$pdf->Cell(25, 7, utf8_decode('Expediente:'), 0, 0); 
$pdf->SetFont('Arial', '', 10); 
$pdf->Cell(50, 7, utf8_decode($numero_expediente), 0, 0); 
$pdf->SetFont('Arial', 'B', 10); 
$pdf->Cell(35, 7, utf8_decode('Años de Condena:'), 0, 0); 
$pdf->SetFont('Arial', '', 10); 

$total_dias = $duracion_condena_dias; 
$anios = floor($total_dias / 365); 
$resto = $total_dias % 365; 
$meses_condena = floor($resto / 30); 
$dias_condena = $resto % 30; 
$pdf->Cell(0, 7, utf8_decode("{$anios} años, {$meses_condena} meses, {$dias_condena} dias"), 0, 1); 
$pdf->Ln(2); 

$pdf->SetFont('Arial', 'B', 10); 
$pdf->Cell(25, 7, utf8_decode('Tribunal:'), 0, 0); 
$pdf->SetFont('Arial', '', 10); 
$pdf->Cell(0, 7, utf8_decode($tribunal), 0, 1); 
$pdf->Ln(2); 

$pdf->SetFont('Arial', 'B', 10); 
$pdf->Cell(25, 7, utf8_decode('Abogado:'), 0, 0); 
$pdf->SetFont('Arial', '', 10); 
$pdf->Cell(65, 7, utf8_decode($abogado_defensor), 0, 0); 
$pdf->SetFont('Arial', 'B', 10); 
$pdf->Cell(20, 7, 'Fiscal:', 0, 0); 
$pdf->SetFont('Arial', '', 10); 
$pdf->Cell(0, 7, utf8_decode($fiscal_nombre), 0, 1); 
$pdf->Ln(2); 

// ---- RESTO DEL DOCUMENTO (Laboral) ----
$pdf->SetFont('Arial', 'B', 8); 
$pdf->Cell(65, 7, utf8_decode('Área de Producción donde presta el Servicio:'), 0, 0); 
$pdf->SetFont('Arial', '', 10); 
$pdf->MultiCell(0, 7, utf8_decode($privado['nombre_area'] ?? 'No asignada')); 
$pdf->Ln(2); 

$pdf->SetFont('Arial', 'B', 9); 
$pdf->Cell(65, 7, utf8_decode('Centro Penitenciario o Internado Judicial:'), 0, 0); 
$pdf->SetFont('Arial', '', 10); 
$pdf->MultiCell(0, 7, utf8_decode($privado['centro_nombre'] ?? 'N/A')); 
$pdf->Ln(2); 

$pdf->SetFont('Arial', 'B', 10); 
$pdf->Cell(25, 7, utf8_decode('Desde el día:'), 0, 0); 
$pdf->SetFont('Arial', '', 10); 
$pdf->Cell(0, 7, utf8_decode($fecha_inicio_labor_str), 'B', 1); 
$pdf->Ln(2); 

$pdf->SetFont('Arial', 'B', 10); 
$pdf->Cell(25, 7, utf8_decode('Hasta el día:'), 0, 0); 
$pdf->SetFont('Arial', '', 10); 
$pdf->Cell(0, 7, utf8_decode($fecha_emision->format('d/m/Y')), 'B', 1); 
$pdf->Ln(2); 

$pdf->SetFont('Arial', 'B', 10); 
$pdf->Cell(65, 7, utf8_decode('Con un total de días laborados:'), 0, 0); 
$pdf->SetFont('Arial', '', 10); 
$pdf->Cell(0, 7, utf8_decode($dias_laborados), 'B', 1); 
$pdf->Ln(2); 

$pdf->SetFont('Arial', 'B', 10); 
$pdf->Cell(35, 7, utf8_decode('En un horario desde:'), 0, 0); 
$pdf->SetFont('Arial', '', 10); 
$pdf->Cell(25, 7, utf8_decode($horario_desde), 'B', 0, 'C'); 
$pdf->SetFont('Arial', 'B', 10); 
$pdf->Cell(10, 7, utf8_decode('hasta:'), 0, 0); 
$pdf->SetFont('Arial', '', 10); 
$pdf->Cell(0, 7, utf8_decode($horario_hasta), 'B', 1); 
$pdf->Ln(2); 

$pdf->SetFont('Arial', 'B', 10); 
$pdf->Cell(25, 7, utf8_decode('Equivalente a'), 0, 0); 
$pdf->SetFont('Arial', '', 10); 
$pdf->Cell(20, 7, utf8_decode($horas_laboradas), 'B', 0); 
$pdf->SetFont('Arial', 'B', 10); 
$pdf->Cell(0, 7, utf8_decode('horas laboradas.'), 0, 1); 
$pdf->Ln(2); 

$pdf->SetFont('Arial', 'B', 10); 
$pdf->Cell(40, 7, utf8_decode('Teléfono de Contacto:'), 0, 0); 
$pdf->SetFont('Arial', '', 10); 
$pdf->Cell(0, 7, utf8_decode($telefono_contacto), 'B', 1); 
$pdf->Ln(15); 

$y_firma = $pdf->GetY(); 
$pdf->SetY($y_firma); 
$pdf->SetFont('Arial', 'B', 10); 
$pdf->Cell(80, 5, utf8_decode('Firma del Privado'), 0, 0, 'C'); 
$pdf->SetXY(115, $y_firma); 
$pdf->Cell(30, 5, utf8_decode('Pulgar Derecho'), 0, 1, 'C'); 
$pdf->SetXY(115, $y_firma + 5); 
$pdf->Rect(115, $y_firma + 5, 30, 30); 

// --- Salida Final ---
$pdf->Output('I', 'Ficha_Privado_' . $documento . '.pdf');
pg_close($conexion); 
ob_end_flush(); 
?>