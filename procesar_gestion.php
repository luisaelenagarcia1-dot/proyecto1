
<?php
session_start();
require_once 'conexion.php';

// Habilitar errores para depuración
ini_set('display_errors', 1);
error_reporting(E_ALL);

// 1. Recoger y validar los datos del formulario
if ($_SERVER["REQUEST_METHOD"] != "POST") {
    $_SESSION['error_message'] = "Método de solicitud no permitido.";
    header("Location: privados_listar.php");
    exit();
}

$documento_identidad =$_POST['documento_original'] ?? null;
$numero_expediente =$_POST['numero_expediente_original'] ?? null;

if (empty($documento_identidad) || empty($numero_expediente)) {$_SESSION['error_message'] = "Error: Documento o Expediente no proporcionados.";
    header("Location: privados_listar.php");
    exit();
}

// --- Recolección de Datos ---
$profesion = !empty($_POST['profesion']) ? trim(mb_strtoupper($_POST['profesion'], 'UTF-8')) : null;
$estado_civil = trim($_POST['estado_civil'] ?? '');$area_codigo = !empty($_POST['area_codigo']) ?$_POST['area_codigo'] : null;

// Datos de Condena
$fecha_condena = !empty($_POST['fechaCondena']) ?$_POST['fechaCondena'] : null;
$duracionAnios = (int)($_POST['duracionAnios'] ?? 0);$duracionMeses = (int)($_POST['duracionMeses'] ?? 0);$duracionDias = (int)($_POST['duracionDias'] ?? 0);$tribunal = !empty($_POST['tribunal']) ? trim(mb_strtoupper($_POST['tribunal'], 'UTF-8')) : null;

// IDs de Personal Judicial
$juez_id = !empty($_POST['juez_id']) ? (int)$_POST['juez_id'] : null;
$fiscal_id = !empty($_POST['fiscal_id']) ? (int)$_POST['fiscal_id'] : null;
$abogado_id = !empty($_POST['abogado_id']) ? (int)$_POST['abogado_id'] : null;

// Datos de Foto
$foto_actual =$_POST['foto_actual'] ?? null;
$ruta_foto =$foto_actual;

// 2. Calcular la duración total de la condena en días
$total_dias_condena = ($duracionAnios * 365) + ($duracionMeses * 30) +$duracionDias;
$duracion_dias_int =$total_dias_condena; 

// 3. Procesar la subida de foto
if (isset($_FILES['foto']) && $_FILES['foto']['error'] == 0) {$directorio_subida = "uploads/fotos/"; 
    if (!file_exists($directorio_subida)) {
        mkdir($directorio_subida, 0777, true);
    }

    $extension = pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION);
    $nombre_archivo = 'foto_' . uniqid() . '.' . $extension; 
    $ruta_foto = $directorio_subida .$nombre_archivo;

    if (!move_uploaded_file($_FILES['foto']['tmp_name'], $ruta_foto)) {$_SESSION['error_message'] = "Error al subir la nueva foto.";
        header("Location: privados_gestionar.php?documento=" . urlencode($documento_identidad));
        exit();
    }
    
    if (!empty($foto_actual) && file_exists($foto_actual)) {
        @unlink($foto_actual);
    }
}

// INICIO DE LA TRANSACCIÓN EN POSTGRESQL
pg_query($conexion, "BEGIN");
$exito = true;

// ===============================================
// A. ACTUALIZAR TABLA `PRIVADOS`
// ===============================================
try {
    $sql_privados = "UPDATE privados SET 
                        profesion = $1, 
                        estado_civil = $2, 
                        area_codigo = $3, 
                        foto = $4 
                    WHERE documento_identidad = $5";
    
    $params_privados = array($profesion, $estado_civil,$area_codigo, $ruta_foto,$documento_identidad);
    $res_privados = pg_query_params($conexion, $sql_privados,$params_privados);
    
    if (!$res_privados) {
        throw new Exception("Error al actualizar la tabla PRIVADOS: " . pg_last_error($conexion));
    }

} catch (Exception $e) {$exito = false;
    $_SESSION['error_message'] = "Error (1/2): " . $e->getMessage();
    pg_query($conexion, "ROLLBACK");
}

// ===============================================
// B. ACTUALIZAR TABLA `CASOS_JUDICIALES`
// ===============================================
if ($exito) {
    try {
        $sql_casos = "UPDATE casos_judiciales SET 
                        fecha_condena = $1, 
                        duracion_condena_dias = $2, 
                        tribunal = $3,
                        juez_id = $4,
                        fiscal_id = $5,
                        abogado_id = $6
                    WHERE numero_expediente = $7";
            
        $params_casos = array($fecha_condena, $duracion_dias_int,$tribunal, $juez_id,$fiscal_id, $abogado_id,$numero_expediente);
        $res_casos = pg_query_params($conexion, $sql_casos,$params_casos);
            
        if (!$res_casos) {
            throw new Exception("Error al actualizar la tabla CASOS_JUDICIALES: " . pg_last_error($conexion));
        }

    } catch (Exception $e) {$exito = false;
        $_SESSION['error_message'] = "Error (2/2): " . $e->getMessage();
        pg_query($conexion, "ROLLBACK");
    }
}

// 4. FINALIZAR TRANSACCIÓN
if ($exito) {
    pg_query($conexion, "COMMIT");
    $_SESSION['success_message'] = "✅ Información del privado actualizada con éxito.";
}

pg_close($conexion);

// Redirigir de vuelta al listado general
header("Location: privados_listar.php");
exit();
?>