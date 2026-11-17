<?php
require_once 'conexion.php';
header('Content-Type: application/json');

// Establecer el charset para la conexión (importante para tildes)
$conexion->set_charset("utf8mb4");

$response = ['error' => 'Solicitud no válida'];

// --- ACCIÓN POR POST (Crear o Encontrar ID) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $accion = $_POST['accion'] ?? '';

    try {
        if ($accion === 'get_or_create_pais') {
            $nombre_pais = trim(mb_strtoupper($_POST['nombre_pais'], 'UTF-8'));
            // 1. Buscar
            $sql_check = "SELECT id FROM loc_paises WHERE nombre = ?";
            $stmt_check = $conexion->prepare($sql_check);
            $stmt_check->bind_param("s", $nombre_pais);
            $stmt_check->execute();
            $res = $stmt_check->get_result();
            if ($res->num_rows > 0) {
                $response = $res->fetch_assoc(); // Devuelve {'id': 1}
            } else {
                // 2. Crear
                $sql_insert = "INSERT INTO loc_paises (nombre) VALUES (?)";
                $stmt_insert = $conexion->prepare($sql_insert);
                $stmt_insert->bind_param("s", $nombre_pais);
                $stmt_insert->execute();
                $response = ['id' => $stmt_insert->insert_id];
            }
        } 
        elseif ($accion === 'get_or_create_estado') {
            $nombre_estado = trim(mb_strtoupper($_POST['nombre_estado'], 'UTF-8'));
            $id_pais = (int)$_POST['id_pais'];
            // 1. Buscar
            $sql_check = "SELECT id FROM loc_estados WHERE nombre = ? AND id_pais = ?";
            $stmt_check = $conexion->prepare($sql_check);
            $stmt_check->bind_param("si", $nombre_estado, $id_pais);
            $stmt_check->execute();
            $res = $stmt_check->get_result();
            if ($res->num_rows > 0) {
                $response = $res->fetch_assoc();
            } else {
                // 2. Crear
                $sql_insert = "INSERT INTO loc_estados (nombre, id_pais) VALUES (?, ?)";
                $stmt_insert = $conexion->prepare($sql_insert);
                $stmt_insert->bind_param("si", $nombre_estado, $id_pais);
                $stmt_insert->execute();
                $response = ['id' => $stmt_insert->insert_id];
            }
        }
        elseif ($accion === 'get_or_create_ciudad') {
            $nombre_ciudad = trim(mb_strtoupper($_POST['nombre_ciudad'], 'UTF-8'));
            $id_estado = (int)$_POST['id_estado'];
            // 1. Buscar
            $sql_check = "SELECT id FROM loc_ciudades WHERE nombre = ? AND id_estado = ?";
            $stmt_check = $conexion->prepare($sql_check);
            $stmt_check->bind_param("si", $nombre_ciudad, $id_estado);
            $stmt_check->execute();
            $res = $stmt_check->get_result();
            if ($res->num_rows > 0) {
                $response = $res->fetch_assoc();
            } else {
                // 2. Crear
                $sql_insert = "INSERT INTO loc_ciudades (nombre, id_estado) VALUES (?, ?)";
                $stmt_insert = $conexion->prepare($sql_insert);
                $stmt_insert->bind_param("si", $nombre_ciudad, $id_estado);
                $stmt_insert->execute();
                $response = ['id' => $stmt_insert->insert_id];
            }
        }
    } catch (Exception $e) {
        $response = ['error' => $e->getMessage()];
    }

} 
// --- ACCIÓN POR GET (Solo Buscar/Sugerir) ---
elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    
    $accion = $_GET['accion'] ?? '';
    $term = $_GET['term'] ?? '';
    $param = "%" . $term . "%";

    if ($accion === 'buscar_pais') {
        $sql = "SELECT id, nombre FROM loc_paises WHERE nombre LIKE ? ORDER BY nombre LIMIT 10";
        $stmt = $conexion->prepare($sql);
        $stmt->bind_param("s", $param);
    } 
    elseif ($accion === 'buscar_estado' && isset($_GET['id_pais'])) {
        $id_pais = (int)$_GET['id_pais'];
        $sql = "SELECT id, nombre FROM loc_estados WHERE id_pais = ? AND nombre LIKE ? ORDER BY nombre LIMIT 10";
        $stmt = $conexion->prepare($sql);
        $stmt->bind_param("is", $id_pais, $param);
    } 
    elseif ($accion === 'buscar_ciudad' && isset($_GET['id_estado'])) {
        $id_estado = (int)$_GET['id_estado'];
        $sql = "SELECT id, nombre FROM loc_ciudades WHERE id_estado = ? AND nombre LIKE ? ORDER BY nombre LIMIT 10";
        $stmt = $conexion->prepare($sql);
        $stmt->bind_param("is", $id_estado, $param);
    } 
    else {
        echo json_encode([]);
        $conexion->close();
        exit;
    }

    $stmt->execute();
    $resultado = $stmt->get_result();
    $response = $resultado->fetch_all(MYSQLI_ASSOC);
}

echo json_encode($response);
$conexion->close();
?>