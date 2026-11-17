<?php
require_once 'conexion.php';

$campo = trim($_GET['campo'] ?? '');
$valor = strtoupper(trim($_GET['valor'] ?? ''));
$id_actual = intval($_GET['id_actual'] ?? 0);

if (empty($campo) || empty($valor)) {
    echo json_encode(['existe' => false]);
    exit();
}

if ($campo === 'sigla') {
    $stmt = pg_query_params($conexion, "SELECT id FROM cursos WHERE sigla = $1 AND id != $2", array($valor, $id_actual));
} elseif ($campo === 'nombre_curso') {
    $stmt = pg_query_params($conexion, "SELECT id FROM cursos WHERE nombre_curso = $1 AND id != $2", array($valor, $id_actual));
} else {
    echo json_encode(['existe' => false]);
    exit();
}

if ($stmt && pg_num_rows($stmt) > 0) {
    echo json_encode(['existe' => true, 'mensaje' => "⚠️ Este/a {$campo} ya está registrado/a en el sistema."]);
} else {
    echo json_encode(['existe' => false]);
}

if (isset($conexion)) { pg_close($conexion); }
?>