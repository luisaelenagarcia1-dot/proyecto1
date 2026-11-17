<?php
session_start();

// Seguridad: Si el usuario no tiene la sesión "temporal", no puede estar aquí.
if (!isset($_SESSION['temp_login']) || $_SESSION['temp_login'] !== true) {
    header('Location: login.php');
    exit;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Configuración Inicial de Cuenta</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { display: flex; align-items: center; justify-content: center; height: 100vh; background-color: #f4f4f4; }
        .card { width: 100%; max-width: 500px; } /* Un poco más ancho */
    </style>
</head>
<body>
    <div class="card shadow">
        <div class="card-body p-4">
            <h3 class="card-title text-center mb-3">Configuración de Cuenta</h3>
            <p class="text-center text-muted">Por motivos de seguridad, debes establecer una nueva contraseña y configurar tu pregunta de seguridad.</p>
            
            <?php if (isset($_GET['error'])): ?>
                <div class="alert alert-danger">
                    <?php 
                        if ($_GET['error'] == 'no_coinciden') echo "Las contraseñas no coinciden.";
                        if ($_GET['error'] == 'corta') echo "La contraseña debe tener al menos 8 caracteres.";
                        if ($_GET['error'] == 'campos_vacios') echo "Debes llenar todos los campos (contraseña y seguridad).";
                    ?>
                </div>
            <?php endif; ?>

            <form action="procesar_cambio_pass.php" method="POST">
                
                <h5 class="mt-4">1. Nueva Contraseña</h5>
                <div class="mb-3">
                    <label for="nueva_contrasena" class="form-label">Nueva Contraseña</label>
                    <input type="password" class="form-control" id="nueva_contrasena" name="nueva_contrasena" required>
                </div>
                <div class="mb-3">
                    <label for="confirmar_contrasena" class="form-label">Confirmar Contraseña</label>
                    <input type="password" class="form-control" id="confirmar_contrasena" name="confirmar_contrasena" required>
                </div>

                <h5 class="mt-4">2. Pregunta de Seguridad</h5>
                <div class="mb-3">
                    <label for="pregunta_seguridad" class="form-label">Elige una pregunta</label>
                    <select class="form-select" id="pregunta_seguridad" name="pregunta_seguridad" required>
                        <option value="" disabled selected>-- Selecciona una --</option>
                        <option value="nombre de mi mascota">¿Nombre de tu primera mascota?</option>
                        <option value="ciudad de nacimiento">¿Ciudad de nacimiento?</option>
                        <option value="nombre de la madre">¿Segundo nombre de tu madre?</option>
                        <option value="comida favorita">¿Cuál es tu comida favorita?</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label for="respuesta_seguridad" class="form-label">Tu Respuesta</label>
                    <input type="text" class="form-control" id="respuesta_seguridad" name="respuesta_seguridad" required>
                </div>
                <div class="d-grid mt-4">
                    <button type="submit" class="btn btn-primary">Guardar Configuración</button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>