<?php
require_once 'conexion.php';

header('Content-Type: application/json');

$tipo  = $_GET['tipo'] ?? 'G';
$num   = trim($_GET['num'] ?? '');
$id_actual = intval($_GET['id_actual'] ?? 0); // Útil para permitir el mismo RIF al editar el mismo registro

if (empty($num)) {
    echo json_encode(['existe' => false]);
    exit;
}

$rif_completo = $tipo . '-' . $num;

// Consultar si ya existe el RIF (excluyendo el ID actual si se está editando)
$sql = "SELECT id FROM entes_educativos WHERE rif_nit = $1 AND id != $2";
$res = pg_query_params($conexion, $sql, array($rif_completo, $id_actual));

if ($res && pg_num_rows($res) > 0) {
    echo json_encode(['existe' => true, 'mensaje' => '⚠️ Este RIF/NIT ya se encuentra registrado en el sistema.']);
} else {
    echo json_encode(['existe' => false]);
}

if (isset($conexion)) {
    pg_close($conexion);
}
?>