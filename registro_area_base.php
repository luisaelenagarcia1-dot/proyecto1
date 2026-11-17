<?php
session_start();
require_once 'header.php';
require_once 'conexion.php'; 

// =======================================================
// 1. RESTRICCIÓN DE ACCESO (SOLO ADMINISTRADOR) 🚨
// =======================================================
$rol_usuario = $_SESSION['cargo'] ?? 'Invitado';
$es_administrador = ($rol_usuario === 'Administradora' || $rol_usuario === 'Administrador'); 

if (!$es_administrador) {
    $_SESSION['mensaje_error'] = "Acceso denegado: Solo los Administradores pueden registrar nuevas áreas socioproductivas.";
    if (isset($conexion)) { pg_close($conexion); }
    header('Location: areas_listado.php');
    exit; 
}
// =======================================================

// Inicializar variable de mensaje
$mensaje = '';

// Si hay mensajes de sesión (del procesador), mostrarlos
if (isset($_SESSION['success_message'])) {
    $mensaje = '<div class="alert alert-success">' . $_SESSION['success_message'] . '</div>';
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    $mensaje = '<div class="alert alert-danger">' . $_SESSION['error_message'] . '</div>';
    unset($_SESSION['error_message']);
}
?>

<main class="app-main">
    <div class="app-content-header">
        <div class="container-fluid">
            <h3 class="mb-0">Registrar Nueva Área Socioproductiva</h3>
        </div>
    </div>
    <div class="app-content">
        <div class="container-fluid">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title">Datos del Área Base</h5>
                </div>
                <div class="card-body">
                    <?php echo $mensaje; // Mostrar mensajes de proceso ?>
                    
                    <form method="POST" action="areas_procesar_registro.php">
                        
                        <div class="mb-3">
                            <label for="codigo" class="form-label">Código del Área (*)</label>
                            <input type="text" class="form-control" id="codigo" name="codigo" maxlength="20" placeholder="Ej: A-03" required>
                            <div class="form-text">Debe ser un código único y corto para identificar el área.</div>
                        </div>

                        <div class="mb-3">
                            <label for="nombre_area" class="form-label">Nombre del Área (*)</label>
                            <input type="text" class="form-control" id="nombre_area" name="nombre_area" maxlength="255" placeholder="Ej: Herrería Artística" required>
                        </div>
                        
                        <button type="submit" class="btn btn-success"><i class="bi bi-save"></i> Guardar Nueva Área</button>
                        <a href="areas_listado.php" class="btn btn-secondary">Cancelar y Volver al Control</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
</main>

<?php 
// 2. CIERRE SEGURO DE LA CONEXIÓN EN POSTGRESQL
if (isset($conexion)) {
    pg_close($conexion);
}
require_once 'footer.php'; 
?>