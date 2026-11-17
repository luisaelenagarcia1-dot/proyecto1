<?php
session_start();
require_once 'conexion.php';
require_once 'fpdf/fpdf.php'; 

// --- CONSULTA PARA OBTENER EL DIRECTOR ACTIVO DESDE LA TABLA 'usuarios' (PostgreSQL) ---
$sql_director = "SELECT 
                    (nombre || ' ' || apellido) AS nombre_completo_director, 
                    cedula AS cedula_director 
                  FROM usuarios 
                  WHERE es_director_actual = 1 
                  LIMIT 1";
                
$resultado_director = pg_query($conexion, $sql_director);

if ($resultado_director === false) {
    die("Error en la consulta SQL del director: " . pg_last_error($conexion));
}

$nombre_director = "(Director No Asignado)"; 
$cedula_director = "C.I. N/A"; 

if (pg_num_rows($resultado_director) > 0) {
    $fila_director = pg_fetch_assoc($resultado_director);
    $nombre_director = $fila_director['nombre_completo_director']; 
    $cedula_director = 'C.I. ' . ($fila_director['cedula_director'] ?? 'N/A');
    pg_free_result($resultado_director);
}

// 1. OBTENER EL ID DE LA SOLICITUD
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) { 
    die("Error: ID de solicitud no proporcionado."); 
} 
$id_solicitud = (int)$_GET['id']; 

// =======================================================
// 2. CONSULTA MAESTRA (PostgreSQL)
// =======================================================
$sql_master = "SELECT 
            s.*, 
            p.nombre, p.apellido, p.documento_identidad, p.fecha_nacimiento, p.genero, p.estado_civil, p.nacionalidad, p.fecha_detencion, p.foto, p.prefijo_documento, p.numero_documento, p.profesion,

            -- Datos de localidad --
            ci.nombre AS ciudad,
            e.nombre AS estado,
            pa.nombre AS pais,

            c.nombre as centro_nombre,
            cj.numero_expediente, cj.tribunal, cj.tipo_delito, cj.duracion_condena_dias,

            -- Nombres del personal judicial --
            j.nombre_completo AS juez_nombre,
            f.nombre_completo AS fiscal_nombre,
            a.nombre_completo AS abogado_defensor,

            rl.fecha_inicio_labor, rl.horario_desde, rl.horario_hasta,
            asp.nombre_area,
            
            -- TELÉFONO DE CONTACTO --
            u.telefono AS telefono_contacto
            
        FROM solicitudes_redencion s
        JOIN privados p ON s.privado_documento = p.documento_identidad

        -- JOINS de localidad --
        LEFT JOIN loc_ciudades ci ON p.ciudad_nacimiento_id = ci.id
        LEFT JOIN loc_estados e ON ci.id_estado = e.id
        LEFT JOIN loc_paises pa ON e.id_pais = pa.id

        LEFT JOIN centros c ON p.centro_codigo = c.codigo 
        
        -- JOINS A CASOS
        LEFT JOIN privados_casos pc ON p.documento_identidad = pc.privado_documento
        LEFT JOIN casos_judiciales cj ON pc.caso_expediente = cj.numero_expediente

        -- JOINS AL PERSONAL JUDICIAL
        LEFT JOIN personal_judicial j ON cj.juez_id = j.id
        LEFT JOIN personal_judicial f ON cj.fiscal_id = f.id
        LEFT JOIN personal_judicial a ON cj.abogado_id = a.id

        LEFT JOIN registros_laborales rl ON p.documento_identidad = rl.privado_documento
        LEFT JOIN areas_socioproductivas asp ON p.area_codigo = asp.codigo
        
        -- JOIN A USUARIOS PARA OBTENER EL TELÉFONO
        LEFT JOIN usuarios u ON s.solicitado_por_usuario = u.nombre_usuario

        WHERE s.id_solicitud = $1
        LIMIT 1";

$res_master = pg_query_params($conexion, $sql_master, array($id_solicitud));

if (!$res_master || pg_num_rows($res_master) === 0) {
    if ($res_master) pg_free_result($res_master);
    die("Error: No se encontraron datos para esta solicitud.");
}

$datos = pg_fetch_assoc($res_master);
pg_free_result($res_master);

// 3. CONSULTA SECUNDARIA: Asistencias (PostgreSQL)
$sql_asistencias = "SELECT fecha, asistencia 
                      FROM asistencias a
                      JOIN registros_laborales rl ON a.registro_laboral_id = rl.id
                      WHERE rl.privado_documento = $1
                      AND a.fecha BETWEEN $2 AND $3
                      ORDER BY a.fecha ASC";

$res_asistencias = pg_query_params($conexion, $sql_asistencias, array(
    $datos['privado_documento'], 
    $datos['fecha_calculo_inicio'], 
    $datos['fecha_solicitud']
));

$lista_asistencias = [];
if ($res_asistencias) {
    while ($fila = pg_fetch_assoc($res_asistencias)) {
        $lista_asistencias[] = $fila;
    }
    pg_free_result($res_asistencias);
}

// --- CÁLCULOS Y VARIABLES DE FECHA ---
$fecha_nacimiento_valida = !empty($datos['fecha_nacimiento']);
if ($fecha_nacimiento_valida) {
    $fecha_nacimiento = new DateTime($datos['fecha_nacimiento']); 
    $hoy = new DateTime(); 
    $edad = $hoy->diff($fecha_nacimiento)->y; 
} else {
    $edad = 'N/A';
}

$dias_laborados = $datos['dias_presente_contados']; 
$fecha_inicio = date('d/m/Y', strtotime($datos['fecha_calculo_inicio'])); 
$fecha_fin = date('d/m/Y', strtotime($datos['fecha_solicitud'])); 
$horario_desde = date('h:i A', strtotime($datos['horario_desde'] ?? '00:00:00')); 
$horario_hasta = date('h:i A', strtotime($datos['horario_hasta'] ?? '00:00:00')); 
$horas_laboradas = $datos['horas_contadas']; 
$telefono_contacto = $datos['telefono_contacto'] ?? 'N/A'; 

// --- Configuración para nombres de meses en español ---
$meses = [
    1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril', 5 => 'mayo', 6 => 'junio', 
    7 => 'julio', 8 => 'agosto', 9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre'
];

$fecha_emision = new DateTime(); 
$dia = $fecha_emision->format('d');
$mes_num = $fecha_emision->format('n');
$anio = $fecha_emision->format('Y');

// --- INICIO DE LA GENERACIÓN DEL PDF ---
ob_start(); 
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

$ruta_foto_verificada = $datos['foto'] ?? ''; 

if (!empty($ruta_foto_verificada) && file_exists($ruta_foto_verificada)) { 
    $x_foto = ($pdf->GetPageWidth() - 30) / 2; 
    $pdf->Image($ruta_foto_verificada, $x_foto, $pdf->GetY(), 30, 30); 
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
$pdf->Cell(0, 7, utf8_decode(($datos['nombre'] ?? 'N/A') . ' ' . ($datos['apellido'] ?? '')), 0, 1); 
$pdf->Ln(2); 

$pdf->SetFont('Arial', 'B', 10); 
$pdf->Cell(55, 7, utf8_decode('Lugar y Fecha de Nacimiento:'), 0, 0); 
$pdf->SetFont('Arial', '', 10); 
$fecha_nac_str = $fecha_nacimiento_valida ? date('d/m/Y', strtotime($datos['fecha_nacimiento'])) : 'N/A';
$pdf->Cell(0, 7, utf8_decode(($datos['ciudad'] ?? 'N/A') . ', ' . ($datos['estado'] ?? 'N/A') . ', ' . ($datos['pais'] ?? 'N/A') . ', ' . $fecha_nac_str), 0, 1); 
$pdf->Ln(2); 

$pdf->SetFont('Arial', 'B', 10); 
$pdf->Cell(20, 7, 'Edad:', 0, 0); 
$pdf->SetFont('Arial', '', 10); 
$pdf->Cell(20, 7, $edad . utf8_decode(' años'), 0, 0); 
$pdf->SetFont('Arial', 'B', 10); 
$pdf->Cell(30, 7, utf8_decode('Estado Civil:'), 0, 0); 
$pdf->SetFont('Arial', '', 10); 
$pdf->Cell(40, 7, utf8_decode($datos['estado_civil'] ?? 'N/A'), 0, 0); 
$pdf->SetFont('Arial', 'B', 10); 
$pdf->Cell(40, 7, utf8_decode('Cédula o Pasaporte:'), 0, 0); 
$pdf->SetFont('Arial', '', 10); 
$pdf->Cell(0, 7, utf8_decode(($datos['prefijo_documento'] ?? '') . '-' . ($datos['numero_documento'] ?? 'N/A')), 0, 1); 
$pdf->Ln(2); 

$pdf->SetFont('Arial', 'B', 10); 
$pdf->Cell(25, 7, utf8_decode('Profesión:'), 0, 0); 
$pdf->SetFont('Arial', '', 10); 
$pdf->Cell(50, 7, utf8_decode($datos['profesion'] ?? 'N/A'), 0, 0); 
$pdf->SetFont('Arial', 'B', 10); 
$pdf->Cell(30, 7, utf8_decode('Bajo el Régimen:'), 0, 0); 
$pdf->SetFont('Arial', '', 10); 
$pdf->Cell(0, 7, 'No especificado', 0, 1); 
$pdf->Ln(2); 

// ---- SECCIÓN JUDICIAL ----
$pdf->SetFont('Arial', 'B', 10); 
$pdf->Cell(50, 7, utf8_decode('Juez de Causa:'), 0, 0); 
$pdf->SetFont('Arial', '', 10); 
$pdf->Cell(0, 7, utf8_decode($datos['juez_nombre'] ?? 'N/A'), 0, 1); 
$pdf->Ln(2); 

$pdf->SetFont('Arial', 'B', 10); 
$pdf->Cell(20, 7, utf8_decode('Delito:'), 0, 0); 
$pdf->SetFont('Arial', '', 10); 
$pdf->Cell(0, 7, utf8_decode($datos['tipo_delito'] ?? 'N/A'), 0, 1); 
$pdf->Ln(2); 

$pdf->SetFont('Arial', 'B', 10); 
$pdf->Cell(25, 7, utf8_decode('Expediente:'), 0, 0); 
$pdf->SetFont('Arial', '', 10); 
$pdf->Cell(50, 7, utf8_decode($datos['numero_expediente'] ?? 'N/A'), 0, 0); 
$pdf->SetFont('Arial', 'B', 10); 
$pdf->Cell(35, 7, utf8_decode('Años de Condena:'), 0, 0); 
$pdf->SetFont('Arial', '', 10); 
$total_dias = $datos['duracion_condena_dias'] ?? 0; 
$anios = floor($total_dias / 365); 
$resto = $total_dias % 365; 
$meses_condena = floor($resto / 30); 
$dias_condena = $resto % 30; 
$pdf->Cell(0, 7, utf8_decode("{$anios} años, {$meses_condena} meses, {$dias_condena} dias"), 0, 1); 
$pdf->Ln(2); 

$pdf->SetFont('Arial', 'B', 10); 
$pdf->Cell(25, 7, utf8_decode('Tribunal:'), 0, 0); 
$pdf->SetFont('Arial', '', 10); 
$pdf->Cell(0, 7, utf8_decode($datos['tribunal'] ?? 'N/A'), 0, 1); 
$pdf->Ln(2); 

$pdf->SetFont('Arial', 'B', 10); 
$pdf->Cell(25, 7, utf8_decode('Abogado:'), 0, 0); 
$pdf->SetFont('Arial', '', 10); 
$pdf->Cell(65, 7, utf8_decode($datos['abogado_defensor'] ?? 'N/A'), 0, 0); 
$pdf->SetFont('Arial', 'B', 10); 
$pdf->Cell(20, 7, 'Fiscal:', 0, 0); 
$pdf->SetFont('Arial', '', 10); 
$pdf->Cell(0, 7, utf8_decode($datos['fiscal_nombre'] ?? 'N/A'), 0, 1); 
$pdf->Ln(2); 

// ---- RESTO DEL DOCUMENTO ----
$pdf->SetFont('Arial', 'B', 8); 
$pdf->Cell(65, 7, utf8_decode('Área de Producción donde presta el Servicio:'), 0, 0); 
$pdf->SetFont('Arial', '', 10); 
$pdf->MultiCell(0, 7, utf8_decode($datos['nombre_area'] ?? 'No asignada')); 
$pdf->Ln(2); 

$pdf->SetFont('Arial', 'B', 9); 
$pdf->Cell(65, 7, utf8_decode('Centro Penitenciario o Internado Judicial:'), 0, 0); 
$pdf->SetFont('Arial', '', 10); 
$pdf->MultiCell(0, 7, utf8_decode($datos['centro_nombre'] ?? 'N/A')); 
$pdf->Ln(2); 

$pdf->SetFont('Arial', 'B', 10); 
$pdf->Cell(25, 7, utf8_decode('Desde el día:'), 0, 0); 
$pdf->SetFont('Arial', '', 10); 
$pdf->Cell(15, 7, utf8_decode(date('d', strtotime($datos['fecha_calculo_inicio']))), 'B', 0, 'C'); 
$pdf->SetFont('Arial', 'B', 10); 
$pdf->Cell(15, 7, utf8_decode(' del mes:'), 0, 0); 
$pdf->SetFont('Arial', '', 10); 
$pdf->Cell(15, 7, utf8_decode(date('m', strtotime($datos['fecha_calculo_inicio']))), 'B', 0, 'C'); 
$pdf->SetFont('Arial', 'B', 9); 
$pdf->Cell(20, 7, utf8_decode(' del año:'), 0, 0); 
$pdf->SetFont('Arial', '', 9); 
$pdf->Cell(0, 7, utf8_decode(date('Y', strtotime($datos['fecha_calculo_inicio']))), 'B', 1); 
$pdf->Ln(2); 

$pdf->SetFont('Arial', 'B', 10); 
$pdf->Cell(25, 7, utf8_decode('Hasta el día:'), 0, 0); 
$pdf->SetFont('Arial', '', 10); 
$pdf->Cell(15, 7, utf8_decode(date('d', strtotime($datos['fecha_solicitud']))), 'B', 0, 'C'); 
$pdf->SetFont('Arial', 'B', 10); 
$pdf->Cell(15, 7, utf8_decode(' del mes:'), 0, 0); 
$pdf->SetFont('Arial', '', 10); 
$pdf->Cell(15, 7, utf8_decode(date('m', strtotime($datos['fecha_solicitud']))), 'B', 0, 'C'); 
$pdf->SetFont('Arial', 'B', 9); 
$pdf->Cell(20, 7, utf8_decode(' del año:'), 0, 0); 
$pdf->SetFont('Arial', '', 10); 
$pdf->Cell(0, 7, utf8_decode(date('Y', strtotime($datos['fecha_solicitud']))), 'B', 1); 
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

// =======================================================
//       DOCUMENTO 2: CONSTANCIA DE ACTIVIDADES
// =======================================================
$pdf->AddPage();
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

$pdf->SetFont('Arial', 'B', 13); 
$pdf->Cell(0, 10, utf8_decode('CONSTANCIA DE ACTIVIDADES LABORALES'), 0, 1, 'C'); 
$pdf->Ln(10); 

$pdf->SetFont('Arial', '', 12); 
$pdf->MultiCell(0, 7, utf8_decode(
    'Por medio de la presente se hace constar que el privado de libertad ' . 
    $datos['nombre'] . ' ' . $datos['apellido'] . 
    ', titular de la cédula de identidad ' . $datos['documento_identidad'] . 
    ', ha realizado actividades laborales en el área de ' . ($datos['nombre_area'] ?? 'N/A') . '.'
), 0, 'J');
$pdf->Ln(5); 

$pdf->MultiCell(0, 7, utf8_decode(
    'El presente cálculo de redención abarca el período comprendido entre el ' . 
    date('d/m/Y', strtotime($datos['fecha_calculo_inicio'])) . ' y el ' . 
    date('d/m/Y', strtotime($datos['fecha_solicitud'])) . '.'
), 0, 'J');
$pdf->Ln(5); 

$pdf->Cell(0, 7, utf8_decode('A continuación, se detalla el registro de asistencias durante dicho período:'), 0, 1);
$pdf->Ln(5); 

$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(95, 10, 'Fecha', 1, 0, 'C');
$pdf->Cell(95, 10, 'Estado', 1, 1, 'C');
$pdf->SetFont('Arial', '', 12);
foreach ($lista_asistencias as $asistencia) {
    $pdf->Cell(95, 10, date('d/m/Y', strtotime($asistencia['fecha'])), 1, 0, 'C');
    $pdf->Cell(95, 10, utf8_decode($asistencia['asistencia']), 1, 1, 'C');
}
$pdf->Ln(5); 

$pdf->SetFont('Arial', 'B', 12); 
$pdf->Cell(0, 10, 'Total dias redimibles contabilizados: ' . $datos['dias_presente_contados'], 0, 1);
$pdf->Cell(0, 10, 'Total horas contabilizadas: ' . $datos['horas_contadas'], 0, 1);
$pdf->Ln(10); 

$pdf->SetFont('Arial', '', 12); 
$pdf->Cell(0, 10, utf8_decode('Constancia que se expide a los ' . $dia . ' días del mes de ' . $meses[$mes_num] . ' del ' . $anio . '.'), 0, 1, 'R');
$pdf->Cell(0, 10, '_________________________', 0, 1, 'C');
$pdf->Cell(0, 7, utf8_decode($nombre_director), 0, 1, 'C'); 
$pdf->Cell(0, 7, utf8_decode($cedula_director), 0, 1, 'C'); 
$pdf->Cell(0, 7, utf8_decode('Director(a) del Centro'), 0, 1, 'C');

// =======================================================
//       DOCUMENTO 3: CARTA OCUPACIONAL
// =======================================================
$pdf->AddPage();
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

$pdf->SetFont('Arial', 'B', 13); 
$pdf->Cell(0, 10, utf8_decode('CARTA OCUPACIONAL'), 0, 1, 'C'); 
$pdf->Ln(10); 

$pdf->SetFont('Arial', '', 12); 
$pdf->Cell(0, 10, utf8_decode('Caracas, ' . $dia . ' de ' . $meses[$mes_num] . ' de ' . $anio), 0, 1, 'R');
$pdf->Ln(10); 

$pdf->SetFont('Arial', 'B', 12); 
$pdf->Cell(0, 10, utf8_decode('A QUIEN PUEDA INTERESAR:'), 0, 1, 'L');
$pdf->Ln(5); 

$pdf->SetFont('Arial', '', 12); 
$pdf->MultiCell(0, 7, utf8_decode(
    'Quien suscribe, ' . $nombre_director . ' , en mi carácter de Director(a) del ' . ($datos['centro_nombre'] ?? '(Nombre del Centro Penitenciario)') . 
    ', por medio de la presente hago constar que el ciudadano ' . $datos['nombre'] . ' ' . $datos['apellido'] . 
    ', C.I. ' . $datos['documento_identidad'] . 
    ', se encuentra recluido en este establecimiento y participa activamente en el área socioproductiva de ' . 
    ($datos['nombre_area'] ?? 'N/A') . '.'
), 0, 'J');
$pdf->Ln(5); 

$pdf->MultiCell(0, 7, utf8_decode(
    'Dicha labor se realiza en un horario comprendido de ' . 
    date('h:i A', strtotime($datos['horario_desde'] ?? '00:00:00')) . ' a ' . 
    date('h:i A', strtotime($datos['horario_hasta'] ?? '00:00:00')) . 
    ', contribuyendo a su formación y redención de pena.'
), 0, 'J');
$pdf->Ln(5); 

$pdf->MultiCell(0, 7, utf8_decode(
    'Esta constancia se emite para fines legales interesados, en relación a la solicitud de redención Nro. ' . 
    $datos['id_solicitud'] . '.'
), 0, 'J');
$pdf->Ln(20); 

$pdf->SetFont('Arial', 'B', 12); 
$pdf->Cell(0, 10, 'Atentamente,', 0, 1, 'C');
$pdf->Ln(20); 

$pdf->Cell(0, 10, '_________________________', 0, 1, 'C');
$pdf->Cell(0, 7, utf8_decode($nombre_director), 0, 1, 'C');
$pdf->Cell(0, 7, utf8_decode($cedula_director), 0, 1, 'C'); 
$pdf->Cell(0, 7, utf8_decode('Director(a) del Centro'), 0, 1, 'C');

// --- Salida Final ---
$pdf->Output();
if (isset($conexion)) {
    pg_close($conexion);
}
ob_end_flush(); 
?>