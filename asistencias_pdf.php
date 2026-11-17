<?php
require('fpdf/fpdf.php');
require('conexion.php');

session_start();

// Validar sesión activa
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: login.php");
    exit();
}

$rol_usuario     = $_SESSION['cargo'] ?? $_SESSION['rol'] ?? 'Gerente';
$es_coordinador  = (strcasecmp($rol_usuario, 'Coordinador Regional') === 0);
$cedula_cruda    = $_SESSION['nombre_usuario'] ?? $_SESSION['cedula'] ?? '';
$cedula_sesion   = preg_replace('/[^0-9]/', '', $cedula_cruda);

$centro_usuario = '';
if ($es_coordinador) {
    $sql_c_coord = "SELECT centro_codigo FROM usuario_centros WHERE REPLACE(REPLACE(usuario_cedula, 'V', ''), 'v', '') = $1 LIMIT 1";
    $res_c_coord = pg_query_params($conexion, $sql_c_coord, array($cedula_sesion));
    if ($res_c_coord && pg_num_rows($res_c_coord) > 0) {
        $row_cc = pg_fetch_assoc($res_c_coord);
        $centro_usuario = trim($row_cc['centro_codigo']);
    }
} else {
    $centro_usuario = trim($_SESSION['centro_codigo'] ?? '');
}

$filtro_seccion = trim($_GET['seccion_id'] ?? '');

$titulo_principal = "REPORTE GENERAL DE ASISTENCIAS EDUCATIVAS";
$subtitulo = "Todas las Secciones";

if (!empty($filtro_seccion)) {
    $res_s = pg_query_params($conexion, "SELECT s.codigo_nomenclatura, c.nombre_curso FROM secciones_cursos s JOIN cursos c ON s.curso_id = c.id WHERE s.id = $1", array($filtro_seccion));
    if ($res_s && pg_num_rows($res_s) > 0) {
        $datos_sec = pg_fetch_assoc($res_s);
        $subtitulo = utf8_decode("Sección: {$datos_sec['codigo_nomenclatura']} - Curso: {$datos_sec['nombre_curso']}");
        pg_free_result($res_s);
    }
}

// Consulta de registros
if ($es_coordinador) {
    $sql_asistencias = "SELECT ae.*, ie.privado_documento, p.nombre, p.apellido, s.codigo_nomenclatura, c.nombre_curso, cn.nombre as nombre_centro
                        FROM asistencia_educativa ae
                        JOIN inscripciones_educativas ie ON ae.inscripcion_id = ie.id
                        JOIN privados p ON ie.privado_documento = p.documento_identidad
                        JOIN secciones_cursos s ON ie.seccion_id = s.id
                        JOIN cursos c ON s.curso_id = c.id
                        LEFT JOIN centros cn ON s.centro_codigo = cn.codigo
                        WHERE s.centro_codigo = $1 " . (!empty($filtro_seccion) ? "AND s.id = $2 " : "") . "
                        ORDER BY ae.fecha DESC, p.apellido ASC";
    
    if (!empty($filtro_seccion)) {
        $resultado = pg_query_params($conexion, $sql_asistencias, array($centro_usuario, $filtro_seccion));
    } else {
        $resultado = pg_query_params($conexion, $sql_asistencias, array($centro_usuario));
    }
} else {
    $sql_asistencias = "SELECT ae.*, ie.privado_documento, p.nombre, p.apellido, s.codigo_nomenclatura, c.nombre_curso, cn.nombre as nombre_centro
                        FROM asistencia_educativa ae
                        JOIN inscripciones_educativas ie ON ae.inscripcion_id = ie.id
                        JOIN privados p ON ie.privado_documento = p.documento_identidad
                        JOIN secciones_cursos s ON ie.seccion_id = s.id
                        JOIN cursos c ON s.curso_id = c.id
                        LEFT JOIN centros cn ON s.centro_codigo = cn.codigo
                        " . (!empty($filtro_seccion) ? "WHERE s.id = $1 " : "") . "
                        ORDER BY ae.fecha DESC, p.apellido ASC";
    
    if (!empty($filtro_seccion)) {
        $resultado = pg_query_params($conexion, $sql_asistencias, array($filtro_seccion));
    } else {
        $resultado = pg_query($conexion, $sql_asistencias);
    }
}

$total_filas = ($resultado) ? pg_num_rows($resultado) : 0;

class PDF_ASISTENCIAS extends FPDF
{
    private $subtitulo;
    private $titulo_principal;

    function setEncabezado($titulo, $subtitulo) {
        $this->titulo_principal = $titulo;
        $this->subtitulo = $subtitulo;
    }

    function Header()
    {
        // Encabezado General con Logotipos institucionales
        $this->Image('assets/img/logoministerio.jpg', 10, 10, 25);
        $this->Image('assets/img/logoiactp1.jpg', 244, 10, 25);
        
        $this->SetFont('Arial', 'B', 11);
        $this->Cell(0, 5, utf8_decode('REPÚBLICA BOLIVARIANA DE VENEZUELA'), 0, 1, 'C');
        $this->SetFont('Arial', '', 10);
        $this->Cell(0, 5, utf8_decode('MINISTERIO DEL PODER POPULAR PARA EL SERVICIO PENITENCIARIO'), 0, 1, 'C');
        $this->Cell(0, 5, utf8_decode('INSTITUTO AUTÓNOMO CAJA DE TRABAJO PENITENCIARIO'), 0, 1, 'C');
        $this->Ln(12);
        
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 8, utf8_decode($this->titulo_principal), 0, 1, 'C');
        $this->SetFont('Arial', '', 10);
        $this->Cell(0, 5, utf8_decode($this->subtitulo), 0, 1, 'C');
        $this->Ln(8);

        // Cabecera de la tabla (Ancho total exacto: 259 mm)
        $this->SetFont('Arial', 'B', 9);
        $this->SetFillColor(230, 230, 230);
        $this->Cell(24, 8, 'Fecha', 1, 0, 'C', true);
        $this->Cell(28, 8, utf8_decode('Cédula'), 1, 0, 'C', true);
        $this->Cell(72, 8, utf8_decode('Apellidos y Nombres'), 1, 0, 'C', true);
        $this->Cell(65, 8, utf8_decode('Sección / Curso'), 1, 0, 'C', true);
        $this->Cell(35, 8, 'Centro', 1, 0, 'C', true);
        $this->Cell(35, 8, 'Asistencia', 1, 1, 'C', true);
    }

    function Footer()
    {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(0, 10, utf8_decode('Página ') . $this->PageNo() . ' de {nb}', 0, 0, 'C');
    }

    // Método auxiliar para calcular las líneas de un texto largo en celdas MultiCell
    function NbLines($w, $txt)
    {
        $cw = &$this->CurrentFont['cw'];
        if($w == 0)
            $w = $this->w - $this->rMargin - $this->x;
        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $s = str_replace("\r", '', $txt);
        $nb = strlen($s);
        if($nb > 0 && $s[$nb-1] == "\n")
            $nb--;
        $sep = -1;
        $i = 0;
        $j = 0;
        $l = 0;
        $nl = 1;
        while($i < $nb)
        {
            $c = $s[$i];
            if($c == "\n")
            {
                $i++;
                $sep = -1;
                $j = $i;
                $l = 0;
                $nl++;
                continue;
            }
            if($c == ' ')
                $sep = $i;
            $l += $cw[$c];
            if($l > $wmax)
            {
                if($sep == -1)
                {
                    if($i == $j)
                        $i++;
                }
                else
                    $i = $sep + 1;
                $sep = -1;
                $j = $i;
                $l = 0;
                $nl++;
            }
            else
                $i++;
        }
        return $nl;
    }
}

// Configuración en orientación Horizontal (Landscape 'L', milímetros 'mm', tamaño 'Letter')
$pdf = new PDF_ASISTENCIAS('L', 'mm', 'Letter');
$pdf->SetMargins(10, 10, 10);
$pdf->AliasNbPages();
$pdf->setEncabezado($titulo_principal, $subtitulo); 
$pdf->AddPage();
$pdf->SetFont('Arial', '', 9);

if ($resultado && $total_filas > 0) {
    while ($fila = pg_fetch_assoc($resultado)) {
        $fecha_formateada = date('d/m/Y', strtotime($fila['fecha']));
        $cedula_limpia = preg_replace('/^(V-|v-|V|v)/', '', trim($fila['privado_documento']));
        $cedula = 'V-' . $cedula_limpia;
        $nombre_completo = $fila['apellido'] . ', ' . $fila['nombre'];
        $seccion_curso = '[' . $fila['codigo_nomenclatura'] . '] - ' . $fila['nombre_curso'];
        $centro = $fila['nombre_centro'] ?? 'N/A';
        $asistencia = $fila['asistencia'];

        // Definir anchos de columna fijos
        $w_fecha = 24;
        $w_cedula = 28;
        $w_nombre = 72;
        $w_curso = 65;
        $w_centro = 35;
        $w_asist = 35;

        // Calcular cuántas líneas ocuparán los textos largos
        $nb_nombre = $pdf->NbLines($w_nombre, utf8_decode($nombre_completo));
        $nb_curso  = $pdf->NbLines($w_curso, utf8_decode($seccion_curso));
        $nb_centro = $pdf->NbLines($w_centro, utf8_decode($centro));
        
        // La altura de la fila será la del texto más largo (calculando 6mm por línea)
        $nb = max($nb_nombre, $nb_curso, $nb_centro, 1);
        $alto_fila = 6 * $nb;

        // Validar salto de página automático si la fila no cabe en la hoja
        if ($pdf->GetY() + $alto_fila > 190) {
            $pdf->AddPage();
        }

        $x = $pdf->GetX();
        $y = $pdf->GetY();

        // Imprimir cada celda alineada a la altura calculada
        $pdf->Rect($x, $y, $w_fecha, $alto_fila);
        $pdf->Cell($w_fecha, $alto_fila, $fecha_formateada, 0, 0, 'C');

        $pdf->Rect($x + $w_fecha, $y, $w_cedula, $alto_fila);
        $pdf->Cell($w_cedula, $alto_fila, utf8_decode($cedula), 0, 0, 'C');

        // Columnas con texto largo
        $pdf->Rect($x + $w_fecha + $w_cedula, $y, $w_nombre, $alto_fila);
        $pdf->SetXY($x + $w_fecha + $w_cedula, $y + (($alto_fila - ($nb_nombre * 6)) / 2));
        $pdf->MultiCell($w_nombre, 6, utf8_decode($nombre_completo), 0, 'L');

        $pdf->Rect($x + $w_fecha + $w_cedula + $w_nombre, $y, $w_curso, $alto_fila);
        $pdf->SetXY($x + $w_fecha + $w_cedula + $w_nombre, $y + (($alto_fila - ($nb_curso * 6)) / 2));
        $pdf->MultiCell($w_curso, 6, utf8_decode($seccion_curso), 0, 'L');

        $pdf->Rect($x + $w_fecha + $w_cedula + $w_nombre + $w_curso, $y, $w_centro, $alto_fila);
        $pdf->SetXY($x + $w_fecha + $w_cedula + $w_nombre + $w_curso, $y + (($alto_fila - ($nb_centro * 6)) / 2));
        $pdf->MultiCell($w_centro, 6, utf8_decode($centro), 0, 'C');

        $pdf->Rect($x + $w_fecha + $w_cedula + $w_nombre + $w_curso + $w_centro, $y, $w_asist, $alto_fila);
        $pdf->SetXY($x + $w_fecha + $w_cedula + $w_nombre + $w_curso + $w_centro, $y);
        $pdf->Cell($w_asist, $alto_fila, utf8_decode($asistencia), 0, 1, 'C');

        // Mover el cursor a la siguiente posición debajo de la fila actual
        $pdf->SetXY($x, $y + $alto_fila);
    }
    pg_free_result($resultado);
} else {
    $pdf->Cell(259, 10, utf8_decode('No se encontraron registros de asistencia con los filtros seleccionados.'), 1, 1, 'C');
}

if (isset($conexion)) {
    pg_close($conexion);
}
$pdf->Output();
?>