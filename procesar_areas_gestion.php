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

$centro_codigo = trim($_POST['centro_codigo'] ?? '');
$area_codigo = trim($_POST['area_codigo'] ?? '');
$capacidad_maxima = $_POST['capacidad_maxima'] ?? null;
$accion = $_POST['accion'] ?? 'desconocida'; // 'registrar' o 'editar'

// 2. Validación básica de datos
if (empty($centro_codigo) || empty($area_codigo) || !isset($capacidad_maxima)) {
    $_SESSION['error_message'] = "Error: Faltan códigos de Centro y Área o el valor de Capacidad.";
    header("Location: areas_listado.php"); 
    exit();
}

// Aseguramos que la capacidad sea un entero no negativo
$capacidad_maxima = (int)$capacidad_maxima;
if ($capacidad_maxima < 0) {
    $capacidad_maxima = 0;
}

// 3. Lógica según la ACCIÓN (PostgreSQL)
if ($accion === 'registrar') {
    
    // 3A. Intentar INSERTAR (Nueva Asignación)
    $sql = "INSERT INTO centros_areas (centro_codigo, area_codigo, capacidad_maxima) VALUES ($1, $2, $3)";
    $resultado = pg_query_params($conexion, $sql, array($centro_codigo, $area_codigo, $capacidad_maxima));
    
    if ($resultado) {
        $_SESSION['success_message'] = "✅ ¡Asignación registrada con éxito! El Centro {$centro_codigo} ahora tiene capacidad de {$capacidad_maxima} para el área {$area_codigo}.";
        pg_free_result($resultado);
    } else {
        $error_msg = pg_last_error($conexion);
        // En PostgreSQL, el código SQLSTATE para violación de llave única (duplicado) es '23505'
        if (strpos($error_msg, 'unique constraint') !== false || strpos($error_msg, 'duplicate key') !== false) {
            $_SESSION['error_message'] = "❌ Error: Esta Área (**{$area_codigo}**) ya está asignada a este Centro (**{$centro_codigo}**). Use la función de Gestión para cambiar la capacidad.";
        } else {
            $_SESSION['error_message'] = "❌ Error al registrar la asignación: " . $error_msg;
        }
    }
    
} elseif ($accion === 'editar') {
    
    // 3B. Intentar ACTUALIZAR (Edición de Capacidad)
    $sql = "UPDATE centros_areas SET capacidad_maxima = $1 WHERE centro_codigo = $2 AND area_codigo = $3";
    $resultado = pg_query_params($conexion, $sql, array($capacidad_maxima, $centro_codigo, $area_codigo));

    if ($resultado) {
        $_SESSION['success_message'] = "✅ Capacidad actualizada con éxito a **{$capacidad_maxima}** para el Centro {$centro_codigo}.";
        pg_free_result($resultado);
    } else {
        $_SESSION['error_message'] = "❌ Error al actualizar la capacidad: " . pg_last_error($conexion);
    }
} else {
    $_SESSION['error_message'] = "Acción no reconocida.";
}

if (isset($conexion)) {
    pg_close($conexion);
}

header("Location: areas_listado.php");
exit();
?>