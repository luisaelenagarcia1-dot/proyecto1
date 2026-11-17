<?php
require_once 'conexion.php';
header('Content-Type: application/json');

$centro_codigo = trim($_GET['centro_codigo'] ?? '');
$curso_id = intval($_GET['curso_id'] ?? 0);
$accion_tipo = trim($_GET['tipo'] ?? 'cursos'); 

if ($accion_tipo === 'secciones_ocupadas' && !empty($centro_codigo) && $curso_id > 0) {
    $sql_sec = "SELECT numero_seccion FROM secciones_cursos WHERE centro_codigo = $1 AND curso_id = $2 ORDER BY CAST(numero_seccion AS INTEGER) ASC";
    $res_sec = pg_query_params($conexion, $sql_sec, array($centro_codigo, $curso_id));
    
    $secciones_ocupadas = [];
    if ($res_sec) {
        while ($row = pg_fetch_assoc($res_sec)) {
            $secciones_ocupadas[] = intval($row['numero_seccion']);
        }
        pg_free_result($res_sec);
    }

    $siguiente_permitido = 1;
    foreach ($secciones_ocupadas as $num) {
        if ($num === $siguiente_permitido) {
            $siguiente_permitido++;
        } else {
            break;
        }
    }

    echo json_encode([
        'ocupadas' => $secciones_ocupadas,
        'siguiente_permitido' => $siguiente_permitido
    ]);
    exit;
}

if ($accion_tipo === 'entes' && !empty($centro_codigo) && $curso_id > 0) {
    $sql_entes = "SELECT DISTINCT e.rif_nit, e.nombre_ente, e.sigla 
                  FROM entes_educativos e
                  INNER JOIN ente_cursos ec ON e.rif_nit = ec.rif_nit
                  WHERE ec.centro_codigo = $1 AND ec.curso_id = $2
                  ORDER BY e.nombre_ente ASC";
    
    $res_entes = pg_query_params($conexion, $sql_entes, array($centro_codigo, $curso_id));
    $entes = [];
    
    if ($res_entes) {
        while ($row = pg_fetch_assoc($res_entes)) {
            $entes[] = [
                'rif_nit' => $row['rif_nit'],
                'nombre_ente' => $row['nombre_ente'],
                'sigla' => $row['sigla'] ?? 'EXT'
            ];
        }
        pg_free_result($res_entes);
    }
    
    echo json_encode($entes);
    exit;
}

if (empty($centro_codigo)) {
    echo json_encode([]);
    exit;
}

// Incluir duracion_horas en la consulta de cursos
$sql = "SELECT DISTINCT c.id, c.codigo_curso, c.nombre_curso, c.sigla, c.duracion_horas 
        FROM cursos c
        INNER JOIN ente_cursos ec ON c.id = ec.curso_id
        WHERE ec.centro_codigo = $1
        ORDER BY c.nombre_curso ASC";

$resultado = pg_query_params($conexion, $sql, array($centro_codigo));
$cursos = [];

if ($resultado) {
    while ($row = pg_fetch_assoc($resultado)) {
        $cursos[] = [
            'id' => $row['id'],
            'codigo_curso' => $row['codigo_curso'],
            'nombre_curso' => $row['nombre_curso'],
            'sigla' => !empty($row['sigla']) ? $row['sigla'] : 'CURSO',
            'duracion_horas' => intval($row['duracion_horas'] ?? 40)
        ];
    }
    pg_free_result($resultado);
}

echo json_encode($cursos);

if (isset($conexion)) {
    pg_close($conexion);
}
?>