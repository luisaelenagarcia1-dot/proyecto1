<?php
require_once 'conexion.php';

$curso_id = intval($_GET['curso_id'] ?? 0);

if ($curso_id <= 0) {
    echo json_encode(['encontrado' => false]);
    exit();
}

$res = pg_query_params($conexion, "SELECT * FROM cursos WHERE id = $1", array($curso_id));
if ($res && pg_num_rows($res) > 0) {
    $curso = pg_fetch_assoc($res);
    echo json_encode([
        'encontrado'   => true,
        'codigo_curso' => htmlspecialchars($curso['codigo_curso']),
        'sigla'        => htmlspecialchars($curso['sigla']),
        'nombre_curso' => htmlspecialchars($curso['nombre_curso']),
        'descripcion'  => htmlspecialchars($curso['descripcion'] ?? 'Sin descripción programática registrada.')
    ]);
} else {
    echo json_encode(['encontrado' => false]);
}

if (isset($conexion)) { pg_close($conexion); }
?>