<?php
session_start();
require_once 'conexion.php'; 

// Habilitar errores para depuración
ini_set('display_errors', 1);
error_reporting(E_ALL);

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // INICIO DE LA TRANSACCIÓN EN POSTGRESQL
    pg_query($conexion, "BEGIN");

    try {
        // === 1. RECOLECTAR DATOS DEL FORMULARIO ===
        $privado_documento =$_POST['privado_documento'] ?? '';
        $fecha_inicio_labor =$_POST['fecha_inicio_labor'] ?? '';
        $horario_desde =$_POST['horario_desde'] ?? '';
        $horario_hasta =$_POST['horario_hasta'] ?? '';
        
        // Validar campos obligatorios uno por uno
        if (empty($privado_documento)) {
            throw new Exception("Por favor, seleccione el privado de libertad.");
        }
        if (empty($fecha_inicio_labor)) {
            throw new Exception("Por favor, indique la fecha de inicio laboral.");
        }
        if (empty($horario_desde)) {
            throw new Exception("Por favor, indique el horario desde.");
        }
        if (empty($horario_hasta)) {
            throw new Exception("Por favor, indique el horario hasta.");
        }

        // Convertir el array de días a una cadena de texto
        $dias_laborales = isset($_POST['dias_laborales']) ? implode(',', $_POST['dias_laborales']) : '';
        if (empty($dias_laborales)) {
            throw new Exception("Debe seleccionar al menos un día laboral.");
        }

        // === 2. VALIDAR SI EL PRIVADO YA TIENE UN REGISTRO ACTIVO (PostgreSQL) ===
        $sql_check = "SELECT id FROM registros_laborales WHERE privado_documento = $1 AND fecha_fin_labor IS NULL";
        $res_check = pg_query_params($conexion, $sql_check, array($privado_documento));

        if ($res_check && pg_num_rows($res_check) > 0) {
            if ($res_check) pg_free_result($res_check);
            throw new Exception("El privado de libertad ya tiene un registro de trabajo activo.");
        }
        if ($res_check) pg_free_result($res_check);

        // === 3. INSERTAR EL NUEVO REGISTRO EN `registros_laborales` (PostgreSQL) ===
        $sql_insert = "INSERT INTO registros_laborales (privado_documento, fecha_inicio_labor, horario_desde, horario_hasta, dias_laborales) VALUES ($1, $2, $3, $4, $5)";
        $res_insert = pg_query_params($conexion, $sql_insert, array($privado_documento, $fecha_inicio_labor,$horario_desde, $horario_hasta,$dias_laborales));

        if (!$res_insert) {
            throw new Exception("Error al insertar el registro de trabajo: " . pg_last_error($conexion));
        }
        pg_free_result($res_insert);

        // Si todo fue exitoso, confirmar los cambios (COMMIT)
        pg_query($conexion, "COMMIT");
        $_SESSION['success_message'] = "Registro de trabajo creado con éxito.";
        
        if (isset($conexion)) { pg_close($conexion); }
        header('Location: trabajo_listar.php');
        exit();

    } catch (Exception $e) {
        // Si algo falla, revertir todos los cambios (ROLLBACK)
        pg_query($conexion, "ROLLBACK");
        $_SESSION['error_message'] = "Error en el registro de trabajo: " . $e->getMessage();
        
        if (isset($conexion)) { pg_close($conexion); }
        header('Location: trabajo_registro.php');
        exit();
    }
} else {
    if (isset($conexion)) { pg_close($conexion); }
    header('Location: trabajo_listar.php');
    exit();
}
?>