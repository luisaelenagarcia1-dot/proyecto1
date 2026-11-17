<?php
session_start();
require_once 'conexion.php';

// --- 1. SEGURIDAD FLEXIBLE ---
// Acepta tanto "Administrador" como "Administradora" sin importar mayúsculas/minúsculas
$rol_sesion = $_SESSION['cargo'] ?? $_SESSION['rol'] ?? '';

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || 
    (strcasecmp($rol_sesion, 'Administrador') !== 0 && strcasecmp($rol_sesion, 'Administradora') !== 0)) {
    
    $_SESSION['error_message'] = "Acceso denegado. Requiere permisos de Administrador.";
    header('Location: index.php');
    exit;
}

// Una vez validada la seguridad, incluimos el header visual
require_once 'header.php';

$filtro_general = $_GET['general'] ?? '';
$filtro_cargo = $_GET['cargo'] ?? '';

// Obtener lista de cargos para el select de filtros usando PostgreSQL
$sql_cargos = "SELECT DISTINCT cargo FROM usuarios WHERE cargo IS NOT NULL AND cargo != '' ORDER BY cargo";
$res_cargos = pg_query($conexion, $sql_cargos);

// --- 2. CONSULTA SQL DINÁMICA (PostgreSQL) ---
$sql_base = "SELECT 
                u.cedula,
                u.nombre_usuario,
                u.nombre,
                u.apellido,
                u.correo,
                u.telefono,
                u.cargo,
                u.debe_cambiar_pass,
                u.es_director_actual,
                u.activo,
                (SELECT STRING_AGG(c.nombre, ', ') 
                 FROM usuario_centros uc 
                 JOIN centros c ON uc.centro_codigo = c.codigo 
                 WHERE uc.usuario_cedula = u.cedula) AS centros_asignados
            FROM usuarios u
            WHERE 1=1";

$where_conditions = [];
$params = [];
$param_index = 1;

// Filtro de Búsqueda General
if (!empty($filtro_general)) {
    $where_conditions[] = "(u.cedula ILIKE $" . $param_index . " OR u.nombre ILIKE $" . $param_index . " OR u.apellido ILIKE $" . $param_index . " OR u.nombre_usuario ILIKE $" . $param_index . ")";
    $params[] = "%" . $filtro_general . "%";
    $param_index++;
}

// Filtro de Cargo
if (!empty($filtro_cargo)) {
    $where_conditions[] = "u.cargo = $" . $param_index;
    $params[] = $filtro_cargo;
    $param_index++;
}

$sql_usuarios = $sql_base;
if (count($where_conditions) > 0) {
    $sql_usuarios .= " AND " . implode(" AND ", $where_conditions);
}
$sql_usuarios .= " ORDER BY u.apellido, u.nombre";

// Ejecutar consulta con parámetros seguros de PostgreSQL
$resultado = (!empty($params)) ? pg_query_params($conexion, $sql_usuarios, $params) : pg_query($conexion, $sql_usuarios);

// Para el enlace del PDF
$query_string_pdf = http_build_query($_GET);
?>

<main class="app-main">
    
    <div class="container-fluid pt-3">
        <?php 
        if (isset($_SESSION['error_message'])) {
             echo '<div class="alert alert-danger alert-dismissible fade show" role="alert"><i class="bi bi-exclamation-triangle-fill"></i> ' . htmlspecialchars($_SESSION['error_message']) . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
             unset($_SESSION['error_message']);
        }
        
        if (isset($_SESSION['mensaje_exito'])) {
            echo '<div class="alert alert-success alert-dismissible fade show" role="alert"><i class="bi bi-check-circle-fill"></i> ' . htmlspecialchars($_SESSION['mensaje_exito']) . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
            unset($_SESSION['mensaje_exito']);
        }

        if (isset($_SESSION['mensaje_error'])) {
            echo '<div class="alert alert-danger alert-dismissible fade show" role="alert"><i class="bi bi-exclamation-triangle-fill"></i> ' . htmlspecialchars($_SESSION['mensaje_error']) . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
            unset($_SESSION['mensaje_error']);
        }
        
        if (isset($_GET['exito'])): ?>
            <?php if ($_GET['exito'] == 'director_asignado'): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle-fill"></i> El nuevo Director ha sido asignado correctamente.
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        
        <?php if (isset($_GET['error'])): ?>
            <?php 
                $msg = "Error desconocido al procesar la solicitud.";
                if ($_GET['error'] == 'password_invalida') {
                    $msg = "🚨 Error de Seguridad: La contraseña de Administrador ingresada es incorrecta. El cambio NO se realizó.";
                } elseif ($_GET['error'] == 'datos_faltantes' || $_GET['error'] == 'estado_invalido') {
                    $msg = "Error: Faltan datos necesarios o son inválidos para la operación.";
                } elseif ($_GET['error'] == 'db_asignacion' || $_GET['error'] == 'db_update') {
                    $msg = "Error de Base de Datos: No se pudo completar la transacción.";
                } elseif ($_GET['error'] == 'autobloqueo_denegado') {
                    $msg = "🚨 Error de Seguridad: No puedes inactivar tu propia cuenta de Administrador.";
                }
            ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle-fill"></i> <?php echo $msg; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
    </div>

    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-sm-6">
                    <h3 class="mb-0">Listado de Usuarios del Sistema</h3>
                </div>
                <div class="col-sm-6 text-sm-end">
                    <a href="registrar_usuario.php" class="btn btn-primary">
                        <i class="bi bi-person-plus-fill me-1"></i> Nuevo Usuario
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="app-content">
        <div class="container-fluid">

            <div class="card card-outline card-secondary mb-4">
                <div class="card-header">
                    <h3 class="card-title"><i class="bi bi-filter"></i> Filtros de Búsqueda</h3>
                </div>
                <div class="card-body">
                    <form action="usuarios_listar.php" method="GET">
                        <div class="row">
                            <div class="col-md-6">
                                <label for="general" class="form-label">Búsqueda General</label>
                                <input type="text" class="form-control" name="general" id="general" value="<?php echo htmlspecialchars($filtro_general); ?>" placeholder="Nombre, Apellido, Cédula o Usuario...">
                            </div>
                            <div class="col-md-6">
                                <label for="cargo" class="form-label">Cargo</label>
                                <select class="form-select" name="cargo" id="cargo">
                                    <option value="">-- Todos --</option>
                                    <?php if ($res_cargos): ?>
                                        <?php while ($fila_cargo = pg_fetch_assoc($res_cargos)): ?>
                                            <option value="<?php echo htmlspecialchars($fila_cargo['cargo']); ?>" <?php echo ($filtro_cargo == $fila_cargo['cargo']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($fila_cargo['cargo']); ?>
                                            </option>
                                        <?php endwhile; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                        </div>
                        <div class="row mt-3">
                            <div class="col-12 text-end">
                                <a href="usuarios_listar.php" class="btn btn-secondary me-2">Limpiar Filtros</a>
                                <button type="submit" class="btn btn-primary me-2">Aplicar Filtros</button>
                                <a href="generar_pdf_usuarios.php?<?php echo $query_string_pdf; ?>" class="btn btn-danger" target="_blank">
                                    <i class="bi bi-file-earmark-pdf"></i> Imprimir PDF
                                </a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card card-outline card-primary">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped table-hover align-middle">
                            <thead class="table-dark">
                                <tr>
                                    <th>Nombre Completo</th>
                                    <th>Usuario / Cédula</th>
                                    <th>Contacto</th>
                                    <th>Cargo y Recintos Asignados</th>
                                    <th>Estado</th>
                                    <th>Acciones</th> 
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($resultado && pg_num_rows($resultado) > 0): ?>
                                    <?php while ($fila = pg_fetch_assoc($resultado)): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($fila['nombre'] . ' ' . $fila['apellido']); ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($fila['nombre_usuario']); ?></strong><br>
                                            <small class="text-muted"><?php echo htmlspecialchars($fila['cedula']); ?></small>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($fila['correo']); ?><br>
                                            <small class="text-muted"><?php echo htmlspecialchars($fila['telefono']); ?></small>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary mb-1"><?php echo htmlspecialchars($fila['cargo']); ?></span>
                                            <?php if (!empty($fila['centros_asignados'])): ?>
                                                <br><small class="text-primary fw-bold"><i class="bi bi-building"></i> <?php echo htmlspecialchars($fila['centros_asignados']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php
                                            if ($fila['activo'] == 0) {
                                                echo '<span class="badge text-bg-danger mb-1"><i class="bi bi-lock-fill"></i> INACTIVO</span><br>';
                                            } else {
                                                if ($fila['es_director_actual'] == 1) {
                                                    echo '<span class="badge text-bg-info mb-1"><i class="bi bi-crown"></i> Director Activo</span><br>';
                                                }
                                                if ($fila['debe_cambiar_pass'] == 1) {
                                                    echo '<span class="badge text-bg-warning">Pendiente (1er Ingreso)</span>';
                                                } else {
                                                    echo '<span class="badge text-bg-success">Activo</span>';
                                                }
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <?php 
                                                $nuevo_estado = ($fila['activo'] == 1) ? 0 : 1;
                                                $clase = ($fila['activo'] == 1) ? 'btn-danger' : 'btn-success';
                                                $icono = ($fila['activo'] == 1) ? 'bi-person-slash' : 'bi-person-check-fill';
                                                $texto = ($fila['activo'] == 1) ? 'Inactivar' : 'Activar';
                                            ?>
                                            
                                            <!-- Botón Activar / Inactivar -->
                                            <button type="button"
                                                class="btn btn-sm <?php echo $clase; ?> mb-1 btn-cambiar-estado"
                                                data-bs-toggle="modal"
                                                data-bs-target="#confirmEstadoModal"
                                                data-cedula="<?php echo htmlspecialchars($fila['cedula']); ?>"
                                                data-nombre="<?php echo htmlspecialchars($fila['nombre'] . ' ' . $fila['apellido']); ?>"
                                                data-estado="<?php echo $nuevo_estado; ?>"
                                                data-texto="<?php echo $texto; ?>"
                                                title="<?php echo $texto; ?>">
                                                <i class="bi <?php echo $icono; ?>"></i> <?php echo $texto; ?>
                                            </button>

                                            <!-- Botón Reenviar Correo -->
                                            <a href="reenviar_correo.php?cedula=<?php echo htmlspecialchars($fila['cedula']); ?>" class="btn btn-sm btn-outline-primary mb-1" title="Reenviar Credenciales por Correo">
                                                <i class="bi bi-envelope-at-fill"></i> Reenviar
                                            </a>
                                            
                                            <?php if ($fila['es_director_actual'] == 0): ?>
                                                <button type="button" 
                                                    class="btn btn-sm btn-outline-info btn-asignar-director mb-1" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#confirmDirectorModal" 
                                                    data-cedula="<?php echo htmlspecialchars($fila['cedula']); ?>"
                                                    data-nombre="<?php echo htmlspecialchars($fila['nombre'] . ' ' . $fila['apellido']); ?>"
                                                    title="Asignar como Director">
                                                    <i class="bi bi-crown"></i> Director
                                                </button>
                                            <?php else: ?>
                                                <span class="text-muted d-block small">Director Actual</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-3">No se encontraron usuarios con esos filtros.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- Modal Director -->
<div class="modal fade" id="confirmDirectorModal" tabindex="-1" aria-labelledby="confirmDirectorModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-warning text-dark">
        <h5 class="modal-title" id="confirmDirectorModalLabel"><i class="bi bi-exclamation-triangle-fill"></i> Confirmar Asignación de Director</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form action="cambiar_director.php" method="POST" id="formConfirmDirector">
        <div class="modal-body">
          <p>Usted está a punto de asignar a <strong id="directorNombre"></strong> (<strong id="directorCedula"></strong>) como el nuevo Director del sistema.</p>
          <p class="text-danger">Esta acción desactivará al Director actual. Para continuar, por favor, ingrese su contraseña de Administrador.</p>
          
          <div class="mb-3">
            <label for="admin_password" class="form-label">Contraseña de Administrador:</label>
            <input type="password" class="form-control" id="admin_password" name="admin_password" required>
          </div>
          
          <input type="hidden" name="nueva_cedula_director" id="nueva_cedula_director">
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-warning">Confirmar y Asignar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal Estado -->
<div class="modal fade" id="confirmEstadoModal" tabindex="-1" aria-labelledby="confirmEstadoModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-warning text-dark">
        <h5 class="modal-title" id="confirmEstadoModalLabel"><i class="bi bi-shield-lock-fill"></i> Confirmar Cambio de Estado</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form action="cambiar_estado_usuario.php" method="POST" id="formConfirmEstado">
        <div class="modal-body">
          <p>Usted está a punto de <strong id="estadoAccionTexto" class="text-uppercase"></strong> al usuario <strong id="estadoNombre"></strong> (<strong id="estadoCedula"></strong>).</p>
          <p class="text-danger">Para confirmar esta acción crítica, por favor, ingrese su contraseña de Administrador.</p>
          
          <div class="mb-3">
            <label for="admin_password_estado" class="form-label">Contraseña de Administrador:</label>
            <input type="password" class="form-control" id="admin_password_estado" name="admin_password" required>
          </div>
          
          <input type="hidden" name="cedula_usuario" id="cedula_usuario">
          <input type="hidden" name="nuevo_estado" id="nuevo_estado">
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-warning" id="btnConfirmarEstado">Confirmar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const confirmDirectorModal = document.getElementById('confirmDirectorModal');
        if (confirmDirectorModal) {
            confirmDirectorModal.addEventListener('show.bs.modal', function (event) {
                const button = event.relatedTarget;
                const cedula = button.getAttribute('data-cedula');
                const nombre = button.getAttribute('data-nombre');

                document.getElementById('directorNombre').textContent = nombre;
                document.getElementById('directorCedula').textContent = cedula;
                document.getElementById('nueva_cedula_director').value = cedula;
                document.getElementById('admin_password').value = '';
            });
        }
        
        const confirmEstadoModal = document.getElementById('confirmEstadoModal');
        if (confirmEstadoModal) {
            confirmEstadoModal.addEventListener('show.bs.modal', function (event) {
                const button = event.relatedTarget;
                const cedula = button.getAttribute('data-cedula');
                const nombre = button.getAttribute('data-nombre');
                const estado = button.getAttribute('data-estado'); 
                const textoAccion = button.getAttribute('data-texto'); 

                document.getElementById('estadoNombre').textContent = nombre;
                document.getElementById('estadoCedula').textContent = cedula;
                document.getElementById('estadoAccionTexto').textContent = textoAccion.toUpperCase();
                
                document.getElementById('cedula_usuario').value = cedula;
                document.getElementById('nuevo_estado').value = estado;
                document.getElementById('admin_password_estado').value = '';
            });
        }
    });
</script>

<?php
if (isset($conexion)) {
    pg_close($conexion);
}
require_once 'footer.php';
?>