<?php
require_once 'conexion.php';
header('Content-Type: application/json');
$conexion->set_charset("utf8mb4");

$response = ['error' => 'Solicitud no válida'];

// --- ACCIÓN POR POST (Crear o Encontrar ID) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $accion = $_POST['accion'] ?? '';
    $nombre = trim(mb_strtoupper($_POST['nombre'], 'UTF-8'));
    $tipo = $_POST['tipo'] ?? ''; // 'Juez', 'Fiscal', 'Abogado'
    $cedula = !empty($_POST['cedula']) ? trim(mb_strtoupper($_POST['cedula'], 'UTF-8')) : null;

    if ($accion === 'get_or_create' && !empty($nombre) && !empty($tipo)) {
        try {
            // 1. Buscar por Cédula (si se proporcionó y es única)
            if ($cedula) {
                $sql_check_ced = "SELECT id FROM personal_judicial WHERE cedula = ?";
                $stmt_check_ced = $conexion->prepare($sql_check_ced);
                $stmt_check_ced->bind_param("s", $cedula);
                $stmt_check_ced->execute();
                $res_ced = $stmt_check_ced->get_result();
                if ($res_ced->num_rows > 0) {
                    $response = $res_ced->fetch_assoc(); // Encontrado por Cédula
                    echo json_encode($response);
                    exit;
                }
            }

            // 2. Buscar por Nombre y Tipo
            $sql_check = "SELECT id FROM personal_judicial WHERE nombre_completo = ? AND tipo = ?";
            $stmt_check = $conexion->prepare($sql_check);
            $stmt_check->bind_param("ss", $nombre, $tipo);
            $stmt_check->execute();
            $res = $stmt_check->get_result();
            if ($res->num_rows > 0) {
                $response = $res->fetch_assoc(); // Encontrado por Nombre
            } else {
                // 3. Crear nuevo
                $sql_insert = "INSERT INTO personal_judicial (nombre_completo, tipo, cedula) VALUES (?, ?, ?)";
                $stmt_insert = $conexion->prepare($sql_insert);
                $stmt_insert->bind_param("sss", $nombre, $tipo, $cedula);
                $stmt_insert->execute();
                $response = ['id' => $stmt_insert->insert_id];
            }
        } catch (Exception $e) {
            $response = ['error' => $e->getMessage()];
        }
    }

} 
// --- ACCIÓN POR GET (Sugerir/Buscar) ---
elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    
    $term_nombre = $_GET['nombre'] ?? '';
    $term_cedula = $_GET['cedula'] ?? '';
    $tipo = $_GET['tipo'] ?? '';
    
    if (empty($tipo)) {
        echo json_encode([]);
        $conexion->close();
        exit;
    }

    // Buscar por Cédula (búsqueda exacta)
    if (!empty($term_cedula)) {
        $sql = "SELECT id, nombre_completo, cedula FROM personal_judicial 
                WHERE tipo = ? AND cedula = ? LIMIT 1";
        $stmt = $conexion->prepare($sql);
        $stmt->bind_param("ss", $tipo, $term_cedula);
    } 
    // Buscar por Nombre (búsqueda parcial)
    elseif (!empty($term_nombre)) {
        $param_nombre = "%" . $term_nombre . "%";
        $sql = "SELECT id, nombre_completo, cedula FROM personal_judicial 
                WHERE tipo = ? AND nombre_completo LIKE ? 
                ORDER BY nombre_completo LIMIT 10";
        $stmt = $conexion->prepare($sql);
        $stmt->bind_param("ss", $tipo, $param_nombre);
    } 
    // Búsqueda inicial (vacía)
    else {
        $sql = "SELECT id, nombre_completo, cedula FROM personal_judicial 
                WHERE tipo = ? ORDER BY nombre_completo LIMIT 10";
        $stmt = $conexion->prepare($sql);
        $stmt->bind_param("s", $tipo);
    }

    $stmt->execute();
    $resultado = $stmt->get_result();
    $response = $resultado->fetch_all(MYSQLI_ASSOC);
}

echo json_encode($response);
$conexion->close();
?>