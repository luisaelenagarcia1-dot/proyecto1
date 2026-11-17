<?php
   session_start();
require_once 'header.php';
 require_once 'conexion.php'; 

// Verifica que el usuario esté logueado
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: login.php');
    exit;
}

// Puedes obtener información adicional del usuario si lo necesitas
$usuario_id = $_SESSION['id']; // Asegúrate de guardar el ID del usuario en la sesión al iniciar sesión.

?>

<main class="app-main">
    <div class="app-content-header">
        <div class="container-fluid">
            <h3 class="mb-0">Configuración de la Cuenta</h3>
        </div>
    </div>

    <div class="app-content">
        <div class="container-fluid">
            <div class="card">
                <div class="card-body">
                    <form action="procesar_configuracion.php" method="POST">
                        <h4>Cambiar Contraseña</h4>
                        <div class="mb-3">
                            <label for="contrasena_actual" class="form-label">Contraseña Actual</label>
                            <input type="password" class="form-control" id="contrasena_actual" name="contrasena_actual" required>
                        </div>
                        <div class="mb-3">
                            <label for="contrasena_nueva" class="form-label">Nueva Contraseña</label>
                            <input type="password" class="form-control" id="contrasena_nueva" name="contrasena_nueva" required>
                        </div>
                        <div class="mb-3">
                            <label for="confirmar_contrasena" class="form-label">Confirmar Nueva Contraseña</label>
                            <input type="password" class="form-control" id="confirmar_contrasena" name="confirmar_contrasena" required>
                        </div>

                        <hr>

                        <h4>Actualizar Pregunta y Respuesta de Seguridad</h4>
                        <div class="mb-3">
                            <label for="pregunta_seguridad" class="form-label">Nueva Pregunta de Seguridad</label>
                            <input type="text" class="form-control" id="pregunta_seguridad" name="pregunta_seguridad" required>
                        </div>
                        <div class="mb-3">
                            <label for="respuesta_seguridad" class="form-label">Nueva Respuesta de Seguridad</label>
                            <input type="text" class="form-control" id="respuesta_seguridad" name="respuesta_seguridad" required>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">Guardar Cambios</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</main>
<?php require_once 'footer.php'; ?>