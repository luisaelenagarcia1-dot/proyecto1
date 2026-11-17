<?php
/**
 * buscar_juez.php
 * Busca un juez o funcionario en la tabla 'personal_judicial' por su cédula,
 * aplicando limpieza total en la entrada de datos (PHP) y forzando la limpieza 
 * de la columna en la base de datos (con TRIM).
 */
require_once 'conexion.php';

// --- (Opcional) Habilitar visualización de errores solo para depuración ---
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);
// --------------------------------------------------------------------------

header('Content-Type: application/json');

$response = [
    'encontrado' => false,
    'nombre' => '',
    'mensaje' => 'Juez no encontrado.' // Mensaje por defecto
];

// 1. Verificación inicial de la entrada
if (!isset($_GET['cedula']) || empty($_GET['cedula'])) {
    $response['mensaje'] = "Cédula no recibida. Verifique el envío de datos del modal (JavaScript).";
    echo json_encode($response);
    exit;
}

$cedula_cruda = $_GET['cedula'];

// 2. --- LIMPIEZA Y NORMALIZACIÓN DE LA CÉDULA ENTRADA (PHP) ---
// Quita todo tipo de espacio en blanco y deja solo dígitos [0-9].
$cedula_sin_espacios = preg_replace('/\s+/', '', $cedula_cruda); 
$cedula = preg_replace('/[^0-9]/', '', $cedula_sin_espacios); 
// ---------------------------------------------

// 3. Verificación de conexión a la base de datos
if (!isset($conexion) || $conexion->connect_error) {
    $response['mensaje'] = "Error de conexión a la base de datos.";
    error_log("Error de conexión: " . $conexion->connect_error);
    echo json_encode($response);
    exit;
}

try {
    // 4. Consulta SQL: Usamos TRIM() para limpiar cualquier espacio oculto en la columna 'cedula' de la base de datos.
    $sql = "SELECT nombre_completo 
            FROM personal_judicial 
            WHERE TRIM(cedula) = ? LIMIT 1";
            
    $stmt = $conexion->prepare($sql);
    
    if (!$stmt) {
        throw new Exception("Error al preparar la consulta: " . $conexion->error);
    }
    
    $stmt->bind_param("s", $cedula); 
    $stmt->execute();
    
    $resultado = $stmt->get_result();
    $juez = $resultado->fetch_assoc();
    $stmt->close();

    // 5. Evaluar el resultado
    if ($juez) {
        $response['encontrado'] = true;
        $response['nombre'] = htmlspecialchars($juez['nombre_completo']);
        $response['mensaje'] = 'Juez encontrado y nombre autocompletado.';
    } else {
        $response['mensaje'] = "Cédula no registrada. (Buscado: " . $cedula . ")"; 
        $response['cedula_original'] = $cedula_cruda;
    }

} catch (Exception $e) {
    error_log("Error en buscar_juez.php: " . $e->getMessage());
    $response['mensaje'] = "Error interno del sistema al buscar el Juez.";
}

echo json_encode($response);

// 6. Cierre de conexión (Línea corregida)
if (isset($conexion)) {
    $conexion->close(); // CORRECCIÓN: Se llama al método close() correctamente.
}
?>