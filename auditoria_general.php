<?php
session_start();

// --- BLINDAJE DE SEGURIDAD: SOLO ADMINISTRADOR / ADMINISTRADORA ---
$rol_usuario = strtolower(trim($_SESSION['cargo'] ?? $_SESSION['rol'] ?? ''));
$es_admin_valido = ($rol_usuario === 'administrador' || $rol_usuario === 'administradora');

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !$es_admin_valido) {
    $_SESSION['mensaje_error'] = "❌ Acceso denegado: Esta sección es exclusiva para el rol de Administrador.";
    header("Location: entes_listado.php");
    exit();
}

require_once 'header.php';
require_once 'conexion.php';

// --- FILTROS DE BÚSQUEDA PARA LA AUDITORÍA ---
$filtro_modulo  = trim($_GET['modulo'] ?? '');
$filtro_usuario = trim($_GET['usuario'] ?? '');
$filtro_fecha   = trim($_GET['fecha'] ?? '');

$sql = "SELECT * FROM auditoria_general WHERE 1=1";
$params = [];
$param_index = 1;

if (!empty($filtro_modulo)) {
    $sql .= " AND modulo = $" . $param_index++;
    $params[] = $filtro_modulo;
}

if (!empty($filtro_usuario)) {
    $sql .= " AND usuario ILIKE $" . $param_index++;
    $params[] = "%" . $filtro_usuario . "%";
}

if (!empty($filtro_fecha)) {
    $sql .= " AND DATE(fecha_hora) = $" . $param_index++;
    $params[] = $filtro_fecha;
}

$sql .= " ORDER BY fecha_hora DESC LIMIT 100";
$resultado = ($params) ? pg_query_params($conexion, $sql, $params) : pg_query($conexion, $sql);
$total_filas = ($resultado) ? pg_num_rows($resultado) : 0;
?>

<!-- ESTILO CSS PARA ANULAR EL DESPLAZAMIENTO FORZADO DE ADMINLTE -->
<style>
    body:not(.sidebar-collapse) .app-main {
        margin-left: 250px !important;
    }
    .app-main {
        width: auto !important;
        float: none !important;
        display: block !important;
        padding: 15px 25px !important;
    }
    .container-fluid {
        max-width: 100% !important;
    }
</style>

<main class="app-main">
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h3 class="mb-0"><i class="bi bi-shield-lock-fill"></i> Auditoría General del Sistema</h3>
                </div>
                <div class="col-sm-6 text-end">
                    <a href="auditoria_general_pdf.php?modulo=<?php echo urlencode($filtro_modulo); ?>&usuario=<?php echo urlencode($filtro_usuario); ?>&fecha=<?php echo urlencode($filtro_fecha); ?>" target="_blank" class="btn btn-danger">
                        <i class="bi bi-file-earmark-pdf"></i> Exportar Auditoría PDF
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="app-content">
        <div class="container-fluid">

            <!-- BARRA DE FILTROS AVANZADOS -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card card-outline card-secondary shadow-sm">
                        <div class="card-body">
                            <form action="auditoria_general.php" method="GET" class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label fw-bold">Filtrar por Módulo:</label>
                                    <select name="modulo" class="form-select">
                                        <option value="">-- Todos los Módulos --</option>
                                        <option value="ENTES EDUCATIVOS" <?php echo ($filtro_modulo == 'ENTES EDUCATIVOS') ? 'selected' : ''; ?>>Entes Educativos</option>
                                        <option value="CURSOS" <?php echo ($filtro_modulo == 'CURSOS') ? 'selected' : ''; ?>>Cursos</option>
                                        <option value="SECCIONES" <?php echo ($filtro_modulo == 'SECCIONES') ? 'selected' : ''; ?>>Secciones</option>
                                        <option value="PRIVADOS" <?php echo ($filtro_modulo == 'PRIVADOS') ? 'selected' : ''; ?>>Privados de Libertad</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold">Usuario Responsable:</label>
                                    <input type="text" class="form-control" name="usuario" value="<?php echo htmlspecialchars($filtro_usuario); ?>" placeholder="Ej: V12345678...">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold">Fecha de Operación:</label>
                                    <input type="date" class="form-control" name="fecha" value="<?php echo htmlspecialchars($filtro_fecha); ?>">
                                </div>
                                <div class="col-12 text-end mt-3">
                                    <button type="submit" class="btn btn-primary me-2"><i class="bi bi-search"></i> Aplicar Filtros</button>
                                    <a href="auditoria_general.php" class="btn btn-outline-secondary"><i class="bi bi-x-lg"></i> Limpiar Filtros</a>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TABLA GENERAL DE TRAYECTORIA Y CAMBIOS -->
            <div class="row">
                <div class="col-12">
                    <div class="card card-primary card-outline shadow-sm">
                        <div class="card-header">
                            <h3 class="card-title">Historial de Trazabilidad y Operaciones Registradas</h3>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-striped table-hover align-middle mb-0">
                                    <thead class="table-dark">
                                        <tr>
                                            <th class="text-center" style="width: 60px;">ID</th>
                                            <th>Módulo</th>
                                            <th>Acción</th>
                                            <th>Registro Afectado</th>
                                            <th>Detalles de la Operación</th>
                                            <th>Usuario</th>
                                            <th>Fecha y Hora</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($resultado && $total_filas > 0): ?>
                                            <?php while ($row = pg_fetch_assoc($resultado)): 
                                                $badge_color = 'bg-secondary';
                                                $accion_txt = strtoupper($row['accion']);
                                                if ($accion_txt == 'REGISTRO') $badge_color = 'bg-success';
                                                if ($accion_txt == 'ACTUALIZACIÓN') $badge_color = 'bg-warning text-dark';
                                                if ($accion_txt == 'ELIMINACIÓN') $badge_color = 'bg-danger';
                                            ?>
                                                <tr>
                                                    <td class="text-center fw-bold"><?php echo $row['id']; ?></td>
                                                    <td><span class="badge bg-dark"><?php echo htmlspecialchars($row['modulo']); ?></span></td>
                                                    <td><span class="badge <?php echo $badge_color; ?>"><?php echo htmlspecialchars($row['accion']); ?></span></td>
                                                    <td><code><?php echo htmlspecialchars($row['registro_id']); ?></code></td>
                                                    <td><?php echo htmlspecialchars($row['detalles']); ?></td>
                                                    <td><strong><i class="bi bi-person-circle"></i> <?php echo htmlspecialchars($row['usuario']); ?></strong></td>
                                                    <td><small><i class="bi bi-clock"></i> <?php echo htmlspecialchars($row['fecha_hora']); ?></small></td>
                                                </tr>
                                            <?php endwhile; ?>
                                            <?php pg_free_result($resultado); ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="7" class="text-center py-4 text-muted">No se encontraron registros de auditoría con los filtros seleccionados.</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</main>

<?php 
if (isset($conexion)) { pg_close($conexion); }
require_once 'footer.php'; 
?>