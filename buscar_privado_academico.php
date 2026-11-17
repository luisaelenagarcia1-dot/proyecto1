<?php
require_once 'conexion.php';

$cedula_ingresada = strtoupper(trim($_GET['cedula'] ?? ''));

if (!empty($cedula_ingresada)) {
    $solo_numeros = preg_replace('/[^0-9]/', '', $cedula_ingresada);

    // Buscar al privado en la tabla 'privados'
    $sql = "SELECT documento_identidad, nombre, apellido, genero, estado_civil, foto 
            FROM privados 
            WHERE UPPER(documento_identidad) = $1 
               OR UPPER(documento_identidad) = $2 
               OR documento_identidad LIKE $3";
               
    $res = pg_query_params($conexion, $sql, array(
        $cedula_ingresada,
        'V' . $cedula_ingresada,
        '%' . $solo_numeros . '%'
    ));

    if ($res && pg_num_rows($res) > 0) {
        $privado = pg_fetch_assoc($res);
        $documento_encontrado = $privado['documento_identidad'];

        // Verificar estrictamente si ya existe un registro en horas_historicas_educativas
        $sql_hist = "SELECT id, horas_acumuladas FROM horas_historicas_educativas WHERE privado_documento = $1";
        $res_hist = pg_query_params($conexion, $sql_hist, array($documento_encontrado));
        
        $tiene_registro = ($res_hist && pg_num_rows($res_hist) > 0);
        $datos_hist = $tiene_registro ? pg_fetch_assoc($res_hist) : null;

        echo json_encode([
            'encontrado' => true,
            'documento' => $documento_encontrado,
            'nombre' => $privado['nombre'],
            'apellido' => $privado['apellido'],
            'genero' => $privado['genero'] ?? 'No especificado',
            'estado_civil' => $privado['estado_civil'] ?? 'No especificado',
            'foto' => !empty($privado['foto']) ? $privado['foto'] : 'assets/img/user2-160x160.jpg',
            'tiene_registro_previo' => $tiene_registro,
            'horas_previas' => $tiene_registro ? $datos_hist['horas_acumuladas'] : 0
        ]);
        exit();
    }
}

echo json_encode(['encontrado' => false]);
?>