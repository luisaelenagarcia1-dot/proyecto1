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
    $_SESSION['mensaje_error'] = "Acceso denegado: Solo los Administradores pueden registrar nuevas asignaciones de área.";
    if (isset($conexion)) { pg_close($conexion); }
    header('Location: areas_listado.php');
    exit; 
}
// =======================================================

// Consulta para obtener la lista de Centros (PostgreSQL)
$sql_centros = "SELECT codigo, nombre FROM centros ORDER BY nombre";
$res_centros = pg_query($conexion, $sql_centros);
$centros_lista = [];
if ($res_centros) {
    while ($f = pg_fetch_assoc($res_centros)) { $centros_lista[] = $f; }
    pg_free_result($res_centros);
}

// Consulta para obtener la lista de Áreas Socioproductivas (PostgreSQL)
$sql_areas = "SELECT codigo, nombre_area FROM areas_socioproductivas ORDER BY nombre_area";
$res_areas = pg_query($conexion, $sql_areas);
$areas_lista = [];
if ($res_areas) {
    while ($f = pg_fetch_assoc($res_areas)) { $areas_lista[] = $f; }
    pg_free_result($res_areas);
}
?>

<main class="app-main">
    <div class="app-content-header">
        <div class="container-fluid">
            <h3 class="mb-0 text-center">Registrar Nueva Asignación de Área</h3>
        </div>
    </div>

    <div class="app-content">
        <div class="container-fluid">
            <div class="row justify-content-center">
                <div class="col-lg-8">
                    
                    <?php if (isset($_SESSION['mensaje_error'])): ?>
                        <div class="alert alert-danger"><?php echo $_SESSION['mensaje_error']; unset($_SESSION['mensaje_error']); ?></div>
                    <?php endif; ?>
                    
                    <div class="card">
                        <div class="card-body">
                            
                            <form action="procesar_areas_gestion.php" method="POST">
                                <input type="hidden" name="accion" value="registrar"> 

                                <div class="mb-3">
                                    <label for="centro_codigo" class="form-label">Centro Penitenciario:</label>
                                    <select class="form-select" id="centro_codigo" name="centro_codigo" required>
                                        <option value="">Seleccione un Centro</option>
                                        <?php foreach ($centros_lista as $centro): ?>
                                            <option value="<?php echo htmlspecialchars($centro['codigo']); ?>">
                                                <?php echo htmlspecialchars($centro['nombre']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label for="area_codigo" class="form-label">Área Socioproductiva:</label>
                                    <select class="form-select" id="area_codigo" name="area_codigo" required>
                                        <option value="">Seleccione un Área</option>
                                        <?php foreach ($areas_lista as $area): ?>
                                            <option value="<?php echo htmlspecialchars($area['codigo']); ?>">
                                                <?php echo htmlspecialchars($area['nombre_area']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label for="capacidad_maxima" class="form-label">Capacidad Máxima Asignada:</label>
                                    <input 
                                        type="number" 
                                        class="form-control" 
                                        id="capacidad_maxima" 
                                        name="capacidad_maxima" 
                                        value="0" 
                                        min="0" 
                                        required
                                    >
                                </div>

                                <div class="d-grid gap-2">
                                    <button type="submit" class="btn btn-primary btn-lg">Registrar Asignación</button>
                                    <a href="areas_listado.php" class="btn btn-secondary">Volver al Listado</a>
                                </div>
                            </form>

                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<?php 
if (isset($conexion)) {
    pg_close($conexion);
}
require_once 'footer.php'; 
?>