<?php
require('fpdf/fpdf.php');
require('conexion.php');

session_start();

// Validar sesión activa
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: login.php");
    exit();
}

$usuario_cedula = $_SESSION['nombre_usuario'] ?? '';
$rolUsuario     = $_SESSION['cargo'] ?? '';
$es_administrador = ($rolUsuario === 'Administradora' || $rolUsuario === 'Administrador');
$es_coordinador   = (strcasecmp($rolUsuario, 'Coordinador Regional') === 0);

$centro_usuario = '';
if ($es_coordinador) {
    $query_c = "SELECT centro_codigo FROM usuario_centros WHERE usuario_cedula = $1 LIMIT 1";
    $res_c = pg_query_params($conexion, $query_c, array($usuario_cedula));
    if ($res_c && $row_c = pg_fetch_assoc($res_c)) { 
        $centro_usuario = $row_c['centro_codigo']; 
    } else {
        $centro_usuario = 'TIGRE01';
    }
}

// Capturar filtros de la interfaz
$centro_seleccionado = $_GET['centro_codigo'] ?? ($es_coordinador ? $centro_usuario : '');
$seccion_seleccionada = $_GET['seccion_id'] ?? null;
$filtro_estatus = $_GET['filtro_estatus'] ?? 'TODOS';
$busqueda_texto = trim($_GET['busqueda_texto'] ?? '');

// Generar texto descriptivo de los filtros aplicados para el reporte
$detalles_filtros = [];
if (!empty($centro_seleccionado)) {
    $res_nc = pg_query_params($conexion, "SELECT nombre FROM centros WHERE codigo = $1", array($centro_seleccionado));
    if ($res_nc && $row_nc = pg_fetch_assoc($res_nc)) {
        $detalles_filtros[] = "Centro: " . $row_nc['nombre'];
    }
} else {
    $detalles_filtros[] = "Centro: Todos";
}

if (!empty($seccion_seleccionada)) {
    $res_ns = pg_query_params($conexion, "SELECT s.codigo_nomenclatura, c.nombre_curso FROM secciones_cursos s JOIN cursos c ON s.curso_id = c.id WHERE s.id = $1", array($seccion_seleccionada));
    if ($res_ns && $row_ns = pg_fetch_assoc($res_ns)) {
        $detalles_filtros[] = "Sección: " . $row_ns['codigo_nomenclatura'] . " (" . $row_ns['nombre_curso'] . ")";
    }
}

if ($filtro_estatus !== 'TODOS') {
    $detalles_filtros[] = "Estatus: " . $filtro_estatus;
}

if (!empty($busqueda_texto)) {
    $detalles_filtros[] = "Búsqueda: \"" . $busqueda_texto . "\"";
}

$titulo_principal = "CONSOLIDADO GENERAL DE CALIFICACIONES Y RENDIMIENTO";
$subtitulo = utf8_decode("Filtros aplicados — " . implode(" | ", $detalles_filtros));

// Consulta de estudiantes con filtros avanzados
$query_estudiantes = "
    SELECT 
        ie.id AS inscripcion_id,
        p.documento_identidad,
        p.nombre,
        p.apellido,
        sc.codigo_nomenclatura,
        c.nombre_curso,
        sc.centro_codigo,
        cn.nombre AS nombre_centro,
        ie.nota_eval_1,
        ie.nota_eval_2,
        ie.nota_eval_3,
        ie.evaluacion_practica,
        ie.calificacion_final,
        ie.estado_aprobacion,
        ie.estatus_sobresaliente,
        COALESCE(
            (
                SELECT ROUND((SUM(CASE WHEN a.asistencia = 'Presente' THEN 1 ELSE 0 END)::numeric / NULLIF(COUNT(a.id), 0)) * 100, 2)
                FROM asistencia_educativa a
                WHERE a.inscripcion_id = ie.id
            ), 0.00
        ) AS porcentaje_asistencia_calculado
    FROM inscripciones_educativas ie
    JOIN privados p ON ie.privado_documento = p.documento_identidad
    JOIN secciones_cursos sc ON ie.seccion_id = sc.id
    JOIN cursos c ON sc.curso_id = c.id
    LEFT JOIN centros cn ON sc.centro_codigo = cn.codigo
    WHERE 1=1
";

$params_est = array();
$param_index = 1;

if (!empty($seccion_seleccionada)) {
    $query_estudiantes .= " AND ie.seccion_id = $" . $param_index;
    $params_est[] = $seccion_seleccionada;
    $param_index++;
} elseif ($es_coordinador) {
    $query_estudiantes .= " AND sc.centro_codigo = $" . $param_index;
    $params_est[] = $centro_usuario;
    $param_index++;
} elseif ($es_administrador && !empty($centro_seleccionado)) {
    $query_estudiantes .= " AND sc.centro_codigo = $" . $param_index;
    $params_est[] = $centro_seleccionado;
    $param_index++;
}

if (!empty($busqueda_texto)) {
    $query_estudiantes .= " AND (p.documento_identidad ILIKE $" . $param_index . " OR p.nombre ILIKE $" . $param_index . " OR p.apellido ILIKE $" . $param_index . ")";
    $params_est[] = "%" . $busqueda_texto . "%";
    $param_index++;
}

if ($filtro_estatus === 'APROBADO') {
    $query_estudiantes .= " AND ie.estado_aprobacion = 'APROBADO'";
} elseif ($filtro_estatus === 'REPROBADO') {
    $query_estudiantes .= " AND (ie.estado_aprobacion = 'REPROBADO' OR ie.estado_aprobacion = 'REPROBADO_FALTAS')";
} elseif ($filtro_estatus === 'SOBRESALIENTE') {
    $query_estudiantes .= " AND ie.estatus_sobresaliente = 't'";
}

$query_estudiantes .= " ORDER BY p.apellido ASC";
$resultado = pg_query_params($conexion, $query_estudiantes, $params_est);
$total_filas = ($resultado) ? pg_num_rows($resultado) : 0;

class PDF_CALIFICACIONES extends FPDF
{
    private $subtitulo;
    private $titulo_principal;

    function setEncabezado($titulo, $subtitulo) {
        $this->titulo_principal = $titulo;
        $this->subtitulo = $subtitulo;
    }

    function Header()
    {
        // Logotipos institucionales (Asegúrate de tener las rutas correctas)
        if (file_exists('assets/img/logoministerio.jpg')) {
            $this->Image('assets/img/logoministerio.jpg', 10, 10, 22);
        }
        if (file_exists('assets/img/logoiactp1.jpg')) {
            $this->Image('assets/img/logoiactp1.jpg', 245, 10, 22);
        }
        
        $this->SetFont('Arial', 'B', 11);
        $this->Cell(0, 5, utf8_decode('REPÚBLICA BOLIVARIANA DE VENEZUELA'), 0, 1, 'C');
        $this->SetFont('Arial', '', 9.5);
        $this->Cell(0, 5, utf8_decode('MINISTERIO DEL PODER POPULAR PARA EL SERVICIO PENITENCIARIO'), 0, 1, 'C');
        $this->Cell(0, 5, utf8_decode('INSTITUTO AUTÓNOMO CAJA DE TRABAJO PENITENCIARIO'), 0, 1, 'C');
        $this->Ln(8);
        
        $this->SetFont('Arial', 'B', 11);
        $this->Cell(0, 7, utf8_decode($this->titulo_principal), 0, 1, 'C');
        $this->SetFont('Arial', 'B', 9);
        $this->Cell(0, 5, utf8_decode($this->subtitulo), 0, 1, 'C');
        $this->Ln(6);

        // Cabecera de la tabla (Ancho total exacto para hoja Letter Horizontal: 259 mm)
        // Columnas: Centro(42) | Sección/Curso(42) | Cédula(26) | Apellidos y Nombres(48) | Asist.(18) | E1(13) | E2(13) | E3(13) | Proy.(15) | Nota(14) | Estatus(15) = 259 mm
        $this->SetFont('Arial', 'B', 8);
        $this->SetFillColor(230, 230, 230);
        $this->Cell(42, 7, utf8_decode('Centro Penitenciario'), 1, 0, 'C', true);
        $this->Cell(42, 7, utf8_decode('Sección / Curso'), 1, 0, 'C', true);
        $this->Cell(26, 7, utf8_decode('Cédula'), 1, 0, 'C', true);
        $this->Cell(48, 7, utf8_decode('Apellidos y Nombres'), 1, 0, 'C', true);
        $this->Cell(18, 7, utf8_decode('% Asist.'), 1, 0, 'C', true);
        $this->Cell(13, 7, utf8_decode('Eval. 1'), 1, 0, 'C', true);
        $this->Cell(13, 7, utf8_decode('Eval. 2'), 1, 0, 'C', true);
        $this->Cell(13, 7, utf8_decode('Eval. 3'), 1, 0, 'C', true);
        $this->Cell(15, 7, utf8_decode('Proyecto'), 1, 0, 'C', true);
        $this->Cell(14, 7, utf8_decode('Final'), 1, 0, 'C', true);
        $this->Cell(15, 7, utf8_decode('Estatus'), 1, 1, 'C', true);
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
$pdf = new PDF_CALIFICACIONES('L', 'mm', 'Letter');
$pdf->SetMargins(10, 10, 10);
$pdf->AliasNbPages();
$pdf->setEncabezado($titulo_principal, $subtitulo); 
$pdf->AddPage();
$pdf->SetFont('Arial', '', 8.5);

if ($resultado && $total_filas > 0) {
    while ($est = pg_fetch_assoc($resultado)) {
        $centro = $est['nombre_centro'] ?? $est['centro_codigo'];
        $seccion_curso = $est['codigo_nomenclatura'] . ' - ' . $est['nombre_curso'];
        $cedula_limpia = preg_replace('/^(V-|v-|V|v)/', '', trim($est['documento_identidad']));
        $cedula = 'V-' . $cedula_limpia;
        $nombre_completo = $est['apellido'] . ', ' . $est['nombre'];
        $asistencia = number_format(floatval($est['porcentaje_asistencia_calculado']), 2) . '%';
        $n1 = $est['nota_eval_1'] ?? '0';
        $n2 = $est['nota_eval_2'] ?? '0';
        $n3 = $est['nota_eval_3'] ?? '0';
        $n_prac = $est['evaluacion_practica'] ?? '0';
        $nota_fin = number_format(floatval($est['calificacion_final'] ?? 0), 0);
        $estatus = $est['estado_aprobacion'];

        // Definir anchos de columna fijos
        $w_centro = 42;
        $w_curso = 42;
        $w_cedula = 26;
        $w_nombre = 48;
        $w_asist = 18;
        $w_n1 = 13;
        $w_n2 = 13;
        $w_n3 = 13;
        $w_proy = 15;
        $w_nota = 14;
        $w_estatus = 15;

        // Calcular cuántas líneas ocuparán los textos largos
        $nb_centro = $pdf->NbLines($w_centro, utf8_decode($centro));
        $nb_curso  = $pdf->NbLines($w_curso, utf8_decode($seccion_curso));
        $nb_nombre = $pdf->NbLines($w_nombre, utf8_decode($nombre_completo));
        
        $nb = max($nb_centro, $nb_curso, $nb_nombre, 1);
        $alto_fila = 5.5 * $nb;

        // Validar salto de página automático si la fila no cabe en la hoja
        if ($pdf->GetY() + $alto_fila > 190) {
            $pdf->AddPage();
        }

        $x = $pdf->GetX();
        $y = $pdf->GetY();

        // Imprimir cada celda alineada a la altura calculada
        $pdf->Rect($x, $y, $w_centro, $alto_fila);
        $pdf->SetXY($x, $y + (($alto_fila - ($nb_centro * 4.5)) / 2));
        $pdf->MultiCell($w_centro, 4.5, utf8_decode($centro), 0, 'L');

        $pdf->Rect($x + $w_centro, $y, $w_curso, $alto_fila);
        $pdf->SetXY($x + $w_centro, $y + (($alto_fila - ($nb_curso * 4.5)) / 2));
        $pdf->MultiCell($w_curso, 4.5, utf8_decode($seccion_curso), 0, 'L');

        $pdf->Rect($x + $w_centro + $w_curso, $y, $w_cedula, $alto_fila);
        $pdf->SetXY($x + $w_centro + $w_curso, $y);
        $pdf->Cell($w_cedula, $alto_fila, utf8_decode($cedula), 0, 0, 'C');

        $pdf->Rect($x + $w_centro + $w_curso + $w_cedula, $y, $w_nombre, $alto_fila);
        $pdf->SetXY($x + $w_centro + $w_curso + $w_cedula, $y + (($alto_fila - ($nb_nombre * 4.5)) / 2));
        $pdf->MultiCell($w_nombre, 4.5, utf8_decode($nombre_completo), 0, 'L');

        // Columnas numéricas y de estatus de una sola línea
        $pdf->Rect($x + $w_centro + $w_curso + $w_cedula + $w_nombre, $y, $w_asist, $alto_fila);
        $pdf->SetXY($x + $w_centro + $w_curso + $w_cedula + $w_nombre, $y);
        $pdf->Cell($w_asist, $alto_fila, $asistencia, 0, 0, 'C');

        $pdf->Rect($x + $w_centro + $w_curso + $w_cedula + $w_nombre + $w_asist, $y, $w_n1, $alto_fila);
        $pdf->SetXY($x + $w_centro + $w_curso + $w_cedula + $w_nombre + $w_asist, $y);
        $pdf->Cell($w_n1, $alto_fila, $n1, 0, 0, 'C');

        $pdf->Rect($x + $w_centro + $w_curso + $w_cedula + $w_nombre + $w_asist + $w_n1, $y, $w_n2, $alto_fila);
        $pdf->SetXY($x + $w_centro + $w_curso + $w_cedula + $w_nombre + $w_asist + $w_n1, $y);
        $pdf->Cell($w_n2, $alto_fila, $n2, 0, 0, 'C');

        $pdf->Rect($x + $w_centro + $w_curso + $w_cedula + $w_nombre + $w_asist + $w_n1 + $w_n2, $y, $w_n3, $alto_fila);
        $pdf->SetXY($x + $w_centro + $w_curso + $w_cedula + $w_nombre + $w_asist + $w_n1 + $w_n2, $y);
        $pdf->Cell($w_n3, $alto_fila, $n3, 0, 0, 'C');

        $pdf->Rect($x + $w_centro + $w_curso + $w_cedula + $w_nombre + $w_asist + $w_n1 + $w_n2 + $w_n3, $y, $w_proy, $alto_fila);
        $pdf->SetXY($x + $w_centro + $w_curso + $w_cedula + $w_nombre + $w_asist + $w_n1 + $w_n2 + $w_n3, $y);
        $pdf->Cell($w_proy, $alto_fila, $n_prac, 0, 0, 'C');

        $pdf->Rect($x + $w_centro + $w_curso + $w_cedula + $w_nombre + $w_asist + $w_n1 + $w_n2 + $w_n3 + $w_proy, $y, $w_nota, $alto_fila);
        $pdf->SetXY($x + $w_centro + $w_curso + $w_cedula + $w_nombre + $w_asist + $w_n1 + $w_n2 + $w_n3 + $w_proy, $y);
        $pdf->Cell($w_nota, $alto_fila, $nota_fin, 0, 0, 'C');

        $pdf->Rect($x + $w_centro + $w_curso + $w_cedula + $w_nombre + $w_asist + $w_n1 + $w_n2 + $w_n3 + $w_proy + $w_nota, $y, $w_estatus, $alto_fila);
        $pdf->SetXY($x + $w_centro + $w_curso + $w_cedula + $w_nombre + $w_asist + $w_n1 + $w_n2 + $w_n3 + $w_proy + $w_nota, $y);
        $pdf->Cell($w_estatus, $alto_fila, utf8_decode($estatus), 0, 1, 'C');

        // Mover el cursor a la siguiente posición debajo de la fila actual
        $pdf->SetXY($x, $y + $alto_fila);
    }
    pg_free_result($resultado);
} else {
    $pdf->Cell(259, 10, utf8_decode('No se encontraron registros académicos con los filtros seleccionados.'), 1, 1, 'C');
}

if (isset($conexion)) {
    pg_close($conexion);
}
$pdf->Output('D', 'Consolidado_Calificaciones_' . date('Y-m-d') . '.pdf');
?>