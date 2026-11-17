<?php
session_start();
require_once 'conexion.php'; 

// Habilitar errores para depuración
ini_set('display_errors', 1);
error_reporting(E_ALL);

// 1. Verificar el método y los datos
if ($_SERVER["REQUEST_METHOD"] != "POST") {
    $_SESSION['error_message'] = "Método de solicitud no permitido.";
    header("Location: areas_listado.php");
    exit();
}

$codigo = trim($_POST['codigo'] ?? '');
$nombre_area = trim($_POST['nombre_area'] ?? '');

// 2. Validación y sanitización
if (empty($codigo) || empty($nombre_area)) {
    $_SESSION['error_message'] = "Error: El código y el nombre del área son obligatorios.";
    header("Location: registro_area_base.php");
    exit();
}

// 3. Insertar la nueva área utilizando PostgreSQL (pg_query_params)
$sql = "INSERT INTO areas_socioproductivas (codigo, nombre_area) VALUES ($1, $2)";
$resultado = pg_query_params($conexion, $sql, array($codigo, $nombre_area));

if ($resultado) {
    $_SESSION['success_message'] = "✅ ¡Área socioproductiva **" . htmlspecialchars($nombre_area) . "** registrada con éxito! Ya puede ser asignada a un centro.";
    pg_free_result($resultado);
} else {
    // Capturar el error de la base de datos
    $error_msg = pg_last_error($conexion);
    
    // En PostgreSQL, el código de estado SQLSTATE para violación de unicidad (duplicado) es '23505'
    // O podemos verificar la cadena del mensaje de error
    if (strpos($error_msg, 'unique constraint') !== false || strpos($error_msg, 'duplicate key') !== false) {
        $_SESSION['error_message'] = "❌ Error: El código o el nombre del área ya existen. Use un código y nombre únicos.";
    } else {
        $_SESSION['error_message'] = "❌ Error al guardar el área: " . $error_msg;
    }
}

if (isset($conexion)) {
    pg_close($conexion);
}

// 4. Redirige al formulario de registro para mostrar el mensaje
header("Location: registro_area_base.php");
exit();
?>