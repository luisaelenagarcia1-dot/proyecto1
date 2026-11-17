<?php
session_start();
require_once 'conexion.php';

// Validar sesión activa
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: login.php");
    exit();
}

// Obtener datos del usuario logueado y limpiar la cédula de forma flexible
$rol_usuario     = $_SESSION['cargo'] ?? $_SESSION['rol'] ?? 'Gerente';
$es_coordinador  = (strcasecmp($rol_usuario, 'Coordinador Regional') === 0);

$cedula_cruda    = $_SESSION['nombre_usuario'] ?? $_SESSION['cedula'] ?? '';
$cedula_sesion   = preg_replace('/[^0-9]/', '', $cedula_cruda); // Extrae solo números

$documento_buscar = trim($_GET['documento'] ?? '');
$privado_data = null;
$secciones_disponibles = null;

if (!empty($documento_buscar)) {
    // 1. Verificar si el privado existe, su centro y su caso judicial de condena
    $sql_privado = "SELECT p.*, c.nombre as nombre_centro, c.sigla as sigla_centro,
                           cj.duracion_condena_dias, 
                           (SELECT MIN(cj_sub.fecha_condena) FROM privados_casos pc_sub JOIN casos_judiciales cj_sub ON pc_sub.caso_expediente = cj_sub.numero_expediente WHERE pc_sub.privado_documento = p.documento_identidad) as fecha_condena_inicio
                    FROM privados p 
                    LEFT JOIN centros c ON p.centro_codigo = c.codigo 
                    LEFT JOIN privados_casos pc ON p.documento_identidad = pc.privado_documento
                    LEFT JOIN casos_judiciales cj ON pc.caso_expediente = cj.numero_expediente
                    WHERE p.documento_identidad ILIKE $1";
    
    $res_privado = pg_query_params($conexion, $sql_privado, array($documento_buscar));
    
    if ($res_privado && pg_num_rows($res_privado) > 0) {
        $privado_data = pg_fetch_assoc($res_privado);
        $centro_privado = trim($privado_data['centro_codigo'] ?? '');
        $sigla_centro = trim($privado_data['sigla_centro'] ?? '');

        // ==========================================
        // PRIORIDAD 1: Validar si el privado ya cumplió su condena (Libertad)
        // ==========================================
        $condena_cumplida = false;
        if (!empty($privado_data['fecha_detencion']) && !empty($privado_data['duracion_condena_dias']) && (int)$privado_data['duracion_condena_dias'] > 0) {
            try {
                $fechaDetencion = new DateTime($privado_data['fecha_detencion']);
                $diasCondena = (int)$privado_data['duracion_condena_dias'];
                $fechaFinCondena = (clone $fechaDetencion)->modify("+$diasCondena days");
                $fechaHoy = new DateTime();

                if ($fechaHoy >= $fechaFinCondena) {
                    $condena_cumplida = true;
                }
            } catch (Exception $e) {
                // En caso de error de fecha, se ignora
            }
        }

        if ($condena_cumplida) {
            $_SESSION['mensaje_error'] = "❌ No se puede procesar la inscripción del ciudadano(a) {$privado_data['nombre']} {$privado_data['apellido']} porque **ya cumplió su condena** y se encuentra en libertad.";
            $privado_data = null; // Anulamos los datos para bloquear el formulario
        } else {
            // ==========================================
            // PRIORIDAD 2: Validar restricción de centro para el Coordinador Regional
            // ==========================================
            if ($es_coordinador) {
                $sql_val_centro = "SELECT 1 FROM usuario_centros 
                                   WHERE (centro_codigo = $1 OR centro_codigo = $2)
                                   AND (
                                       REPLACE(REPLACE(usuario_cedula, 'V', ''), 'v', '') = $3 
                                       OR usuario_cedula ILIKE $4
                                   ) LIMIT 1";
                $res_val_centro = pg_query_params($conexion, $sql_val_centro, array($centro_privado, $sigla_centro, $cedula_sesion, '%' . $cedula_sesion . '%'));

                if (!$res_val_centro || pg_num_rows($res_val_centro) === 0) {
                    $sql_cualquier_centro = "SELECT 1 FROM usuario_centros WHERE REPLACE(REPLACE(usuario_cedula, 'V', ''), 'v', '') = $1 LIMIT 1";
                    $res_cualquiera = pg_query_params($conexion, $sql_cualquier_centro, array($cedula_sesion));
                    
                    if ($res_cualquiera && pg_num_rows($res_cualquiera) > 0) {
                        $_SESSION['mensaje_error'] = "❌ Restricción de seguridad: El privado de libertad pertenece a otro centro penitenciario.";
                        $privado_data = null;
                    }
                }
            }

            // Si pasó todas las validaciones, cargamos las secciones activas incluyendo dias_semana y horario de la BD
            if ($privado_data) {
                $sql_secciones_f = "SELECT DISTINCT s.id, s.codigo_nomenclatura, cu.nombre_curso, s.capacidad_maxima, s.dias_semana, s.horario,
                                    (s.capacidad_maxima - (SELECT COUNT(*) FROM inscripciones_educativas ie WHERE ie.seccion_id = s.id)) AS cupos_disponibles
                                    FROM secciones_cursos s
                                    JOIN cursos cu ON s.curso_id = cu.id
                                    WHERE s.centro_codigo = $1 
                                       OR s.centro_codigo = $2
                                       OR s.centro_codigo IN (SELECT codigo FROM centros WHERE codigo = $1 OR sigla = $2 OR nombre ILIKE $3)
                                    ORDER BY cu.nombre_curso ASC";
                
                $secciones_disponibles = pg_query_params($conexion, $sql_secciones_f, array($centro_privado, $sigla_centro, '%' . ($privado_data['nombre_centro'] ?? '') . '%'));
            }
        }
    } else {
        $_SESSION['mensaje_error'] = "❌ No se encontró ningún privado de libertad registrado con la cédula o documento '{$documento_buscar}'. Debe registrarlo previamente en el sistema.";
    }
}

require_once 'header.php';
?>

<main class="app-main" style="width: 100%; min-height: 100vh;">
    <div class="app-content-header">
        <div class="container-fluid px-4">
            <div class="row align-items-center mb-2">
                <div class="col-sm-6">
                    <h3 class="mb-0">
                        <i class="bi bi-person-plus-fill"></i> Inscripción de Privados en Secciones Educativas
                        <?php if ($es_coordinador): ?>
                            <span class="badge bg-info text-dark fs-6 ms-2">Recinto Asignado</span>
                        <?php endif; ?>
                    </h3>
                </div>
                <div class="col-sm-6 text-end">
                    <a href="inscripciones_listado.php" class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i> Volver al Listado
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="app-content">
        <div class="container-fluid px-4">

            <?php if (isset($_SESSION['mensaje_exito'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?php echo $_SESSION['mensaje_exito']; unset($_SESSION['mensaje_exito']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['mensaje_error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php echo $_SESSION['mensaje_error']; unset($_SESSION['mensaje_error']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- PASO 1: BARRA DE BÚSQUEDA DE CÉDULA -->
            <div class="row mb-4">
                <div class="col-md-8 mx-auto">
                    <div class="card card-primary card-outline shadow-sm">
                        <div class="card-header">
                            <h3 class="card-title">Paso 1: Verificar Cédula del Privado de Libertad</h3>
                        </div>
                        <div class="card-body">
                            <form action="inscripcion_form.php" method="GET" class="row g-3">
                                <div class="col-md-9">
                                    <input type="text" class="form-control" name="documento" required value="<?php echo htmlspecialchars($documento_buscar); ?>" placeholder="Ingrese la cédula o documento (Ej: V-12345678)...">
                                </div>
                                <div class="col-md-3 d-flex">
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="bi bi-search"></i> Verificar
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- PASO 2: FORMULARIO COMPLETO SI EL PRIVADO ESTÁ REGISTRADO Y VIGENTE -->
            <?php if ($privado_data): ?>
                <div class="row">
                    <div class="col-md-8 mx-auto">
                        <div class="card card-success card-outline shadow-sm">
                            <div class="card-header">
                                <h3 class="card-title">Paso 2: Datos del Cursante y Selección de Sección</h3>
                            </div>
                            
                            <form action="inscripciones_procesar.php" method="POST">
                                <input type="hidden" name="privado_documento" value="<?php echo htmlspecialchars($privado_data['documento_identidad']); ?>">

                                <div class="card-body">
                                    <!-- Tarjeta informativa con los datos del privado -->
                                    <div class="alert alert-info py-3 mb-4">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <strong>Apellidos y Nombres:</strong><br>
                                                <?php echo htmlspecialchars($privado_data['apellido'] . ', ' . $privado_data['nombre']); ?>
                                            </div>
                                            <div class="col-md-6">
                                                <strong>Cédula / Documento:</strong><br>
                                                <?php echo htmlspecialchars($privado_data['documento_identidad']); ?>
                                            </div>
                                        </div>
                                        <hr class="my-2">
                                        <div class="row">
                                            <div class="col-12">
                                                <strong>Centro de Reclusión Actual:</strong> 
                                                <span class="badge bg-dark fs-6"><?php echo htmlspecialchars($privado_data['nombre_centro'] ?? 'Sin centro asignado'); ?></span>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Campo para correo electrónico -->
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Correo Electrónico (Para envío de constancia) <span class="text-danger">*</span>:</label>
                                        <input type="email" class="form-control" name="correo" required value="<?php echo htmlspecialchars($privado_data['correo'] ?? ''); ?>" placeholder="ejemplo@correo.com">
                                        <small class="text-muted">Si el privado no poseía correo registrado, ingrese uno válido para anexarlo a su ficha y enviarle su constancia.</small>
                                    </div>

                                    <!-- Selector de Secciones con días, horarios y cupos visibles -->
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Sección / Curso Disponible en su Recinto <span class="text-danger">*</span>:</label>
                                        <select class="form-select" name="seccion_id" required>
                                            <option value="">-- Seleccione una sección activa --</option>
                                            <?php if ($secciones_disponibles && pg_num_rows($secciones_disponibles) > 0): ?>
                                                <?php while ($sec = pg_fetch_assoc($secciones_disponibles)): 
                                                    $cupos = intval($sec['cupos_disponibles']);
                                                    $deshabilitado = ($cupos <= 0) ? 'disabled' : '';
                                                    $txt_cupos = ($cupos > 0) ? "({$cupos} cupos disponibles)" : "(AGOTADO)";
                                                    
                                                    // Construimos la etiqueta usando dias_semana de la base de datos
                                                    $dias_horario = !empty($sec['dias_semana']) ? " | Días: {$sec['dias_semana']} ({$sec['horario']})" : "";
                                                ?>
                                                    <option value="<?php echo $sec['id']; ?>" <?php echo $deshabilitado; ?>>
                                                        [<?php echo htmlspecialchars($sec['codigo_nomenclatura']); ?>] - <?php echo htmlspecialchars($sec['nombre_curso']); ?><?php echo htmlspecialchars($dias_horario); ?> <?php echo $txt_cupos; ?>
                                                    </option>
                                                <?php endwhile; ?>
                                            <?php else: ?>
                                                <option value="" disabled>No hay secciones activas o con cursos aperturados en este centro penitenciario.</option>
                                            <?php endif; ?>
                                        </select>
                                    </div>

                                </div>

                                <div class="card-footer text-end">
                                    <a href="inscripcion_form.php" class="btn btn-secondary me-2">Cancelar / Cambiar Cédula</a>
                                    <button type="submit" class="btn btn-success">
                                        <i class="bi bi-check-circle"></i> Confirmar Inscripción y Descontar Cupo
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

        </div>
    </div>
</main>

<?php 
if (isset($conexion)) { pg_close($conexion); }
require_once 'footer.php'; 
?>