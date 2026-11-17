<?php
session_start();
if ($_SERVER["REQUEST_METHOD"] != "POST") {
    header('Location: recuperar_paso1.html');
    exit;
}

require_once 'conexion.php'; 

$usuario = $_POST['usuario'] ?? '';
$respuesta_form = $_POST['respuesta'] ?? '';
$nueva_contrasena = $_POST['nueva_contrasena'] ?? '';
$confirmar_contrasena = $_POST['confirmar_contrasena'] ?? '';

$error_msg = "";

if ($nueva_contrasena !== $confirmar_contrasena) {
    $error_msg = "Las nuevas contraseñas no coinciden.";
} elseif (strlen($nueva_contrasena) < 8) {
    $error_msg = "La contraseña debe tener al menos 8 caracteres.";
} else {
    $sql = "SELECT respuesta_seguridad FROM usuarios WHERE nombre_usuario = $1";
    $resultado = pg_query_params($conexion, $sql, array($usuario));

    if (!$resultado || pg_num_rows($resultado) === 0) {
        $error_msg = "El nombre de usuario no fue encontrado.";
    } else {
        $datos = pg_fetch_assoc($resultado);
        $respuesta_guardada = $datos['respuesta_seguridad'];
        pg_free_result($resultado);

        if (strtolower(trim($respuesta_form)) == strtolower(trim($respuesta_guardada))) {
            $contrasena_encriptada = password_hash($nueva_contrasena, PASSWORD_DEFAULT);
            
            // CORRECCIÓN: Usamos '0' en lugar de 'f' porque el campo es smallint
            $sql_update = "UPDATE usuarios SET contrasena = $1, debe_cambiar_pass = 0 WHERE nombre_usuario = $2";
            $resultado_update = pg_query_params($conexion, $sql_update, array($contrasena_encriptada, $usuario));

            if ($resultado_update) {
                pg_close($conexion);
                header('Location: login.php?exito=recuperada');
                exit;
            } else {
                $error_msg = "Error al actualizar la base de datos: " . pg_last_error($conexion);
            }
        } else {
            $error_msg = "La respuesta a la pregunta de seguridad es incorrecta. Verifique e intente de nuevo.";
        }
    }
    pg_close($conexion);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Error en Recuperación</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root { --color-principal: #6c7b91ff; --color-texto-claro: #f8f9fa; }
        body { display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; background-color: #212529; font-size: 1.15rem; }
        .background-image-container { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: -1; background-image: url('assets/img/iactp.jpg'); background-size: cover; background-position: center; filter: brightness(0.4) blur(8px); }
        .recovery-card { width: 100%; max-width: 550px; padding: 40px; background-color: rgba(0, 0, 0, 0.7); backdrop-filter: blur(5px); border: 1px solid rgba(255, 255, 255, 0.2); color: var(--color-texto-claro); border-radius: 15px; box-shadow: 0 10px 40px rgba(0,0,0,0.7); }
        .btn-action { background-color: var(--color-principal); border: none; font-weight: 600; padding: 15px; width: 100%; color: white; text-decoration: none; display: inline-block; text-align: center; }
        .btn-action:hover { background-color: #5d7092; color: white; }
    </style>
</head>
<body>
    <div class="background-image-container"></div>
    <div class="container d-flex justify-content-center align-items-center h-100">
        <div class="card recovery-card text-center">
            <h3 class="text-danger mb-3"><i class="bi bi-exclamation-triangle-fill"></i> Atención</h3>
            <div class="alert alert-danger mb-4" role="alert">
                <?php echo htmlspecialchars($error_msg); ?>
            </div>
            <a href="recuperar_paso1.html" class="btn btn-action">Volver a Intentar</a>
        </div>
    </div>
</body>
</html>