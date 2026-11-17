<?php
session_start();
require_once 'conexion.php';

// Habilitar errores para depuración
net_set: ini_set('display_errors', 1);
error_reporting(E_ALL);

if ($_SERVER["REQUEST_METHOD"] != "POST" || empty($_POST['registro_id'])) {
    header('Location: carga_historica.php');
    exit;
}

$registro_id = (int)$_POST['registro_id'];
$fecha_corte = $_POST['fecha_corte']; 
$total_ausente = (int)$_POST['total_ausente'];
$total_reposo = (int)$_POST['total_reposo'];
$admin_usuario = $_SESSION['nombre_usuario'] ?? 'V26530793'; 

// 3. Obtener datos del privado y el inicio de la labor (PostgreSQL)
$sql_registro = "SELECT p.documento_identidad, rl.fecha_inicio_labor, rl.dias_laborales, rl.horario_desde, rl.horario_hasta
                 FROM registros_laborales rl
                 JOIN privados p ON rl.privado_documento = p.documento_identidad
                 WHERE rl.id = $1";

$res_select = pg_query_params($conexion, $sql_registro, array($registro_id));

if (!$res_select || pg_num_rows($res_select) === 0) {
    if ($res_select) pg_free_result($res_select);
    header('Location: carga_historica.php?error=registro_invalido');
    exit;
}

$registro = pg_fetch_assoc($res_select);
pg_free_result($res_select);

$documento_privado = $registro['documento_identidad'];
$fecha_inicio = $registro['fecha_inicio_labor']; 
$dias_laborales = explode(',', $registro['dias_laborales']); 
$horario_desde = $registro['horario_desde'];
$horario_hasta = $registro['horario_hasta'];

// Mapeo de días (PHP 'D' a formato 'L,M,X...')
$dias_semana_letras = ['Mon'=>'L', 'Tue'=>'M', 'Wed'=>'X', 'Thu'=>'J', 'Fri'=>'V', 'Sat'=>'S', 'Sun'=>'D'];

// =======================================================
// 4. CÁLCULO DE DÍAS LABORABLES Y PRESENTES
// =======================================================
$total_dias_laborables = 0;
$fecha_actual = new DateTime($fecha_inicio);
$fecha_fin_dt = new DateTime($fecha_corte);

while ($fecha_actual <= $fecha_fin_dt) {
    $letra_dia_hoy = $dias_semana_letras[$fecha_actual->format('D')];
    
    if (in_array($letra_dia_hoy, $dias_laborales)) {
        $total_dias_laborables++;
    }
    
    $fecha_actual->modify('+1 day');
}

$total_dias_presente = $total_dias_laborables - $total_ausente; 

// 5. CALCULAR HORAS TOTALES
$tiempo_inicio = new DateTime($horario_desde);
$tiempo_fin = new DateTime($horario_hasta);
$diferencia = $tiempo_inicio->diff($tiempo_fin);
$horas_por_dia = $diferencia->h + ($diferencia->i / 60);

$total_horas_contadas = $total_dias_presente * $horas_por_dia;

// =======================================================
// 6. GUARDAR EL RESULTADO COMO LIQUIDACIÓN LABORAL (Transacción PostgreSQL)
// =======================================================
pg_query($conexion, "BEGIN");

try {
    // 6.1. INSERTAR EL REGISTRO EN 'liquidacion_laboral'
    $sql_insert_liquidacion = "INSERT INTO liquidacion_laboral 
                               (registro_laboral_id, fecha_inicio_periodo, fecha_fin_periodo, dias_trabajados, horas_trabajadas, usuario_liquidador)
                               VALUES ($1, $2, $3, $4, $5, $6)";

    $res_insert = pg_query_params($conexion, $sql_insert_liquidacion, array(
        $registro_id,
        $fecha_inicio,
        $fecha_corte,
        $total_dias_presente,
        $total_horas_contadas,
        $admin_usuario
    ));

    if (!$res_insert) {
        throw new Exception("Error al insertar la liquidación histórica: " . pg_last_error($conexion));
    }
    pg_free_result($res_insert);

    // 6.2. ACTUALIZAR EL MARCADOR DE CORTE EN 'registros_laborales'
    $sql_update_registro = "UPDATE registros_laborales 
                            SET ultima_fecha_liquidada = $1 
                            WHERE id = $2";
                            
    $res_update = pg_query_params($conexion, $sql_update_registro, array(
        $fecha_corte, 
        $registro_id
    ));

    if (!$res_update) {
        throw new Exception("Error al actualizar el marcador laboral: " . pg_last_error($conexion));
    }
    pg_free_result($res_update);
    
    // 6.3. Confirmar transacción
    pg_query($conexion, "COMMIT"); 
    
    if (isset($conexion)) { pg_close($conexion); }
    header('Location: trabajo_listar.php?exito=carga_historica_liquidacion'); 
    exit;

} catch (Exception $e) {
    // 6.4. Revertir transacción si algo falla
    pg_query($conexion, "ROLLBACK"); 
    if (isset($conexion)) { pg_close($conexion); }
    die("Error crítico en la carga histórica de liquidación: " . $e->getMessage());
}
?>