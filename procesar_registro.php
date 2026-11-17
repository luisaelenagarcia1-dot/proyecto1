<?php
ob_start();
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'conexion.php';

function getOrCreatePais($conexion, $nombre_pais) {
    $nombre_pais = trim(mb_strtoupper($nombre_pais, 'UTF-8'));
    if (empty($nombre_pais)) return null;
    
    $sql = "SELECT id FROM loc_paises WHERE nombre = $1";
    $res = pg_query_params($conexion, $sql, array($nombre_pais));
    
    if ($res && pg_num_rows($res) > 0) {
        $id = pg_fetch_assoc($res)['id'];
        pg_free_result($res);
        return $id;
    } else {
        if ($res) pg_free_result($res);
        $sql_ins = "INSERT INTO loc_paises (nombre) VALUES ($1) RETURNING id";
        $res_ins = pg_query_params($conexion, $sql_ins, array($nombre_pais));
        if ($res_ins) {
            $id = pg_fetch_assoc($res_ins)['id'];
            pg_free_result($res_ins);
            return $id;
        }
        return null;
    }
}

function getOrCreateEstado($conexion, $nombre_estado, $id_pais) {
    $nombre_estado = trim(mb_strtoupper($nombre_estado, 'UTF-8'));
    if (empty($nombre_estado) || empty($id_pais)) return null;

    $sql = "SELECT id FROM loc_estados WHERE nombre = $1 AND id_pais = $2";
    $res = pg_query_params($conexion, $sql, array($nombre_estado, $id_pais));
    
    if ($res && pg_num_rows($res) > 0) {
        $id = pg_fetch_assoc($res)['id'];
        pg_free_result($res);
        return $id;
    } else {
        if ($res) pg_free_result($res);
        $sql_ins = "INSERT INTO loc_estados (nombre, id_pais) VALUES ($1, $2) RETURNING id";
        $res_ins = pg_query_params($conexion, $sql_ins, array($nombre_estado, $id_pais));
        if ($res_ins) {
            $id = pg_fetch_assoc($res_ins)['id'];
            pg_free_result($res_ins);
            return $id;
        }
        return null;
    }
}

function getOrCreateCiudad($conexion, $nombre_ciudad, $id_estado) {
    $nombre_ciudad = trim(mb_strtoupper($nombre_ciudad, 'UTF-8'));
    if (empty($nombre_ciudad) || empty($id_estado)) return null;

    $sql = "SELECT id FROM loc_ciudades WHERE nombre = $1 AND id_estado = $2";
    $res = pg_query_params($conexion, $sql, array($nombre_ciudad, $id_estado));
    
    if ($res && pg_num_rows($res) > 0) {
        $id = pg_fetch_assoc($res)['id'];
        pg_free_result($res);
        return $id;
    } else {
        if ($res) pg_free_result($res);
        $sql_ins = "INSERT INTO loc_ciudades (nombre, id_estado) VALUES ($1, $2) RETURNING id";
        $res_ins = pg_query_params($conexion, $sql_ins, array($nombre_ciudad, $id_estado));
        if ($res_ins) {
            $id = pg_fetch_assoc($res_ins)['id'];
            pg_free_result($res_ins);
            return $id;
        }
        return null;
    }
}

function getPersonalJudicialId($conexion, $nombre_completo, $tipo) {
    $nombre_completo = trim(mb_strtoupper($nombre_completo, 'UTF-8'));
    if (empty($nombre_completo)) return null;

    $sql = "SELECT id FROM personal_judicial WHERE nombre_completo = $1 AND tipo = $2";
    $res = pg_query_params($conexion, $sql, array($nombre_completo, $tipo));
    
    if ($res && pg_num_rows($res) > 0) {
        $id = pg_fetch_assoc($res)['id'];
        pg_free_result($res);
        return $id;
    } else {
        if ($res) pg_free_result($res);
        return null;
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    pg_query($conexion, "BEGIN");

    try {
        $prefijo = $_POST['tipoDocumento'] ?? '';
        $numero = $_POST['numeroDocumento'] ?? '';
        $doc_completo = $prefijo . $numero;
        
        $nombre = trim(mb_strtoupper($_POST['nombre'], 'UTF-8'));
        $apellido = trim(mb_strtoupper($_POST['apellido'], 'UTF-8'));
        $f_nac = !empty($_POST['fechaNacimiento']) ? $_POST['fechaNacimiento'] : null;
        $genero = $_POST['genero'] ?? '';
        $est_civil = $_POST['estado_civil'] ?? '';
        $profesion = !empty($_POST['profesion']) ? trim(mb_strtoupper($_POST['profesion'], 'UTF-8')) : null;
        $nacionalidad = trim(mb_strtoupper($_POST['nacionalidad'], 'UTF-8'));

        $pais_txt = ($prefijo === 'V') ? 'VENEZUELA' : ($_POST['paisNacimientoExt'] ?? '');
        $est_txt = ($prefijo === 'V') ? ($_POST['estadoNacimiento'] ?? '') : ($_POST['estadoNacimientoExt'] ?? '');
        $ciu_txt = ($prefijo === 'V') ? ($_POST['ciudadNacimientoVzla'] ?? '') : ($_POST['ciudadNacimientoExt'] ?? '');
        
        $pais_id = getOrCreatePais($conexion, $pais_txt);
        $est_id = getOrCreateEstado($conexion, $est_txt, $pais_id);
        $ciu_id = getOrCreateCiudad($conexion, $ciu_txt, $est_id); 

        if (!$ciu_id) {
            throw new Exception("El campo 'Ciudad de Nacimiento' es obligatorio y no pudo ser procesado.");
        }
        
        $centro_cod = $_POST['centro_codigo'] ?? null;
        $area_cod = !empty($_POST['area_codigo']) ? $_POST['area_codigo'] : null;
        $f_det = !empty($_POST['fechaDetencion']) ? $_POST['fechaDetencion'] : null;
        
        $expediente = trim(mb_strtoupper($_POST['numero_expediente'], 'UTF-8'));
        $tribunal = !empty($_POST['tribunal']) ? trim(mb_strtoupper($_POST['tribunal'], 'UTF-8')) : null;
        $delito = trim(mb_strtoupper($_POST['tipo_delito'], 'UTF-8'));
        $f_cond = !empty($_POST['fechaCondena']) ? $_POST['fechaCondena'] : null;
        $anios = (int)($_POST['duracionAnios'] ?? 0);
        $meses = (int)($_POST['duracionMeses'] ?? 0);
        $dias = (int)($_POST['duracionDias'] ?? 0);
        $total_dias = ($anios * 365) + ($meses * 30) + ($dias);
        
        $juez_txt = $_POST['juez_nombre'] ?? '';
        $fiscal_txt = $_POST['fiscal_nombre'] ?? '';
        $abogado_txt = $_POST['abogado_nombre'] ?? ''; 
        
        $juez_id = getPersonalJudicialId($conexion, $juez_txt, 'Juez');
        $fiscal_id = getPersonalJudicialId($conexion, $fiscal_txt, 'Fiscal');
        $abogado_id = getPersonalJudicialId($conexion, $abogado_txt, 'Abogado');

        $foto_path = null;
        if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
            $dir = 'uploads/fotos/';
            if (!is_dir($dir)) { mkdir($dir, 0777, true); }
            $foto_path = $dir . uniqid('foto_', true) . '.' . pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION);
            move_uploaded_file($_FILES['foto']['tmp_name'], $foto_path);
        }
        
        $sql_p = "INSERT INTO privados (documento_identidad, prefijo_documento, numero_documento, nombre, apellido, fecha_nacimiento, genero, estado_civil, nacionalidad, profesion, foto, fecha_detencion, centro_codigo, area_codigo, ciudad_nacimiento_id) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13, $14, $15)";
        $params_p = array($doc_completo, $prefijo, $numero, $nombre, $apellido, $f_nac, $genero, $est_civil, $nacionalidad, $profesion, $foto_path, $f_det, $centro_cod, $area_cod, $ciu_id);
        
        $res_p = pg_query_params($conexion, $sql_p, $params_p);
        if (!$res_p) throw new Exception("Error al registrar el privado: " . pg_last_error($conexion));

        $sql_chk_c = "SELECT numero_expediente FROM casos_judiciales WHERE numero_expediente = $1";
        $res_chk_c = pg_query_params($conexion, $sql_chk_c, array($expediente));
        
        if ($res_chk_c && pg_num_rows($res_chk_c) === 0) {
            if ($res_chk_c) pg_free_result($res_chk_c);
            $sql_c = "INSERT INTO casos_judiciales (numero_expediente, tribunal, tipo_delito, juez_id, fiscal_id, abogado_id, fecha_condena, duracion_condena_dias) VALUES ($1, $2, $3, $4, $5, $6, $7, $8)";
            $params_c = array($expediente, $tribunal, $delito, $juez_id, $fiscal_id, $abogado_id, $f_cond, $total_dias);
            
            $res_c = pg_query_params($conexion, $sql_c, $params_c);
            if (!$res_c) throw new Exception("Error al registrar el caso judicial: " . pg_last_error($conexion));
        } else {
            if ($res_chk_c) pg_free_result($res_chk_c);
        }
        
        $sql_chk_e = "SELECT 1 FROM privados_casos WHERE privado_documento = $1 AND caso_expediente = $2";
        $res_chk_e = pg_query_params($conexion, $sql_chk_e, array($doc_completo, $expediente));
        
        if ($res_chk_e && pg_num_rows($res_chk_e) === 0) {
            if ($res_chk_e) pg_free_result($res_chk_e);
            $sql_e = "INSERT INTO privados_casos (privado_documento, caso_expediente) VALUES ($1, $2)";
            $res_e = pg_query_params($conexion, $sql_e, array($doc_completo, $expediente));
            if (!$res_e) throw new Exception("Error al asociar privado con caso judicial: " . pg_last_error($conexion));
        } else {
            if ($res_chk_e) pg_free_result($res_chk_e);
        }
        
        pg_query($conexion, "COMMIT");
        $_SESSION['success_message'] = "Privado de libertad registrado exitosamente.";
        header("Location: privados_listar.php"); 
        exit();

    } catch (Exception $e) {
        pg_query($conexion, "ROLLBACK");
        error_log("Error en procesar_registro.php: " . $e->getMessage()); 
        $_SESSION['error_message'] = $e->getMessage();
        header("Location: privados_formulario.php?error=1");
        exit();
    }
}
?>