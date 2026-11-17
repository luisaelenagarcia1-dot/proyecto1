<?php
session_start();
require_once 'conexion.php';

// Validar sesión activa
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: login.php");
    exit();
}

// Obtener el cargo o rol del usuario logueado
$rol_usuario = trim(strtolower($_SESSION['cargo'] ?? $_SESSION['rol'] ?? ''));

// Definir los cargos permitidos para registrar horas históricas (Administrador, Gerente, Analista)
$cargos_permitidos = ['administrador', 'gerente', 'analista'];

$autorizado = false;
foreach ($cargos_permitidos as $cargo) {
    if (strpos($rol_usuario, $cargo) !== false) {
        $autorizado = true;
        break;
    }
}

if (!$autorizado) {
    $_SESSION['mensaje_error'] = "⛔ Acceso denegado. Este módulo es exclusivo para Administradores, Gerentes y Analistas.";
    header("Location: asistencias_listado.php");
    exit();
}

// Limpiar cédula ingresada en la búsqueda por GET de forma flexible
$busqueda_cedula = strtoupper(trim($_GET['cedula'] ?? ''));
$solo_num_busq = preg_replace('/[^0-9]/', '', $busqueda_cedula);

// Procesar el formulario de registro por POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $privado_ingresado = strtoupper(trim($_POST['privado_documento'] ?? ''));
    $curso_descripcion = trim($_POST['curso_descripcion'] ?? 'Certificados y Cursos Anteriores');
    $horas_acumuladas  = intval($_POST['horas_acumuladas'] ?? 0);
    $observacion       = trim($_POST['observacion'] ?? 'Soporte físico de certificados');
    
    $ruta_foto = null;

    if (empty($privado_ingresado)) {
        $_SESSION['mensaje_error'] = "❌ La cédula del privado de libertad es obligatoria.";
    } else {
        $solo_numeros_post = preg_replace('/[^0-9]/', '', $privado_ingresado);

        // 1. Validar que el privado exista en la tabla 'privados'
        $sql_valida_privado = "SELECT documento_identidad, nombre, apellido FROM privados 
                               WHERE UPPER(documento_identidad) = $1 
                                  OR UPPER(documento_identidad) = $2 
                                  OR documento_identidad LIKE $3";
                                  
        $res_valida_privado = pg_query_params($conexion, $sql_valida_privado, array(
            $privado_ingresado,
            'V' . $privado_ingresado,
            '%' . $solo_numeros_post . '%'
        ));

        if (!$res_valida_privado || pg_num_rows($res_valida_privado) === 0) {
            $_SESSION['mensaje_error'] = "❌ La cédula '{$privado_ingresado}' NO se encuentra registrada en el sistema. Verifique el número.";
        } else {
            $row_privado_encontrado = pg_fetch_assoc($res_valida_privado);
            $documento_exacto_bd = $row_privado_encontrado['documento_identidad'];

            // 2. Verificar si YA tiene un registro histórico guardado en la tabla
            $sql_verif_existente = "SELECT id FROM horas_historicas_educativas WHERE privado_documento = $1";
            $res_verif_existente = pg_query_params($conexion, $sql_verif_existente, array($documento_exacto_bd));

            if ($res_verif_existente && pg_num_rows($res_verif_existente) > 0) {
                $_SESSION['mensaje_error'] = "❌ El privado con cédula {$documento_exacto_bd} **ya posee un registro de horas históricas**. No se permite un nuevo registro.";
            } else {
                // 3. Validar obligatoriedad del archivo del certificado
                if (!isset($_FILES['foto_certificado']) || $_FILES['foto_certificado']['error'] !== UPLOAD_ERR_OK) {
                    $_SESSION['mensaje_error'] = "❌ El archivo o foto del certificado es obligatorio como soporte.";
                } else {
                    $file_tmp = $_FILES['foto_certificado']['tmp_name'];
                    $file_name = $_FILES['foto_certificado']['name'];
                    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                    
                    $ext_permitidas = ['jpg', 'jpeg', 'png', 'pdf'];
                    if (!in_array($file_ext, $ext_permitidas)) {
                        $_SESSION['mensaje_error'] = "❌ Formato de archivo no permitido. Solo se aceptan JPG, JPEG, PNG o PDF.";
                    } else {
                        $nuevo_nombre = "cert_" . preg_replace('/[^0-9]/', '', $documento_exacto_bd) . "_" . time() . "." . $file_ext;
                        $directorio_destino = "uploads/certificados/";
                        
                        if (!is_dir($directorio_destino)) {
                            mkdir($directorio_destino, 0777, true);
                        }
                        
                        $ruta_destino = $directorio_destino . $nuevo_nombre;
                        if (move_uploaded_file($file_tmp, $ruta_destino)) {
                            $ruta_foto = $ruta_destino;
                        } else {
                            $_SESSION['mensaje_error'] = "❌ Error al cargar y mover el archivo del certificado al servidor.";
                        }
                    }
                }

                if ($horas_acumuladas <= 0) {
                    $_SESSION['mensaje_error'] = "❌ El total de horas es obligatorio y debe ser mayor a cero.";
                }

                if (!isset($_SESSION['mensaje_error'])) {
                    if (empty($ruta_foto)) {
                        $_SESSION['mensaje_error'] = "❌ El soporte del certificado es obligatorio.";
                    } else {
                        // Guardamos el registro histórico único
                        $sql_insert = "INSERT INTO horas_historicas_educativas (privado_documento, curso_descripcion, horas_acumuladas, observacion, foto_certificado) 
                                       VALUES ($1, $2, $3, $4, $5)";
                        $res_insert = pg_query_params($conexion, $sql_insert, array($documento_exacto_bd, $curso_descripcion, $horas_acumuladas, $observacion, $ruta_foto));

                        if ($res_insert) {
                            $_SESSION['mensaje_exito'] = "✅ ¡Se han registrado exitosamente {$horas_acumuladas} horas históricas para el privado {$documento_exacto_bd}!";
                            header("Location: horas_historicas_registrar.php?cedula=" . urlencode($documento_exacto_bd));
                            exit();
                        } else {
                            $_SESSION['mensaje_error'] = "❌ Error en Base de Datos al registrar: " . htmlspecialchars(pg_last_error($conexion));
                        }
                    }
                }
            }
        }
    }
}

// Consultar datos y registros para la sección derecha
$res_historicas = null;
$res_asistencias_sistema = null;
$datos_privado_info = null;
$total_horas_sistema = 0;
$documento_consultado = '';

if (!empty($busqueda_cedula)) {
    $sql_info = "SELECT documento_identidad, nombre, apellido, genero, estado_civil FROM privados 
                 WHERE UPPER(documento_identidad) = $1 
                    OR UPPER(documento_identidad) = $2 
                    OR documento_identidad LIKE $3";
                    
    $res_info = pg_query_params($conexion, $sql_info, array(
        $busqueda_cedula,
        'V' . $busqueda_cedula,
        '%' . $solo_num_busq . '%'
    ));

    if ($res_info && pg_num_rows($res_info) > 0) {
        $datos_privado_info = pg_fetch_assoc($res_info);
        $documento_consultado = $datos_privado_info['documento_identidad'];

        // Historial de horas anteriores
        $sql_hist = "SELECT * FROM horas_historicas_educativas WHERE privado_documento = $1 ORDER BY fecha_registro DESC";
        $res_historicas = pg_query_params($conexion, $sql_hist, array($documento_consultado));

        // Asistencias del sistema actual
        $sql_asis = "SELECT ae.fecha, ae.asistencia, s.codigo_nomenclatura, c.nombre_curso 
                     FROM asistencia_educativa ae
                     JOIN inscripciones_educativas ie ON ae.inscripcion_id = ie.id
                     JOIN secciones_cursos s ON ie.seccion_id = s.id
                     JOIN cursos c ON s.curso_id = c.id
                     WHERE ie.privado_documento = $1 AND UPPER(ae.asistencia) = 'PRESENTE'
                     ORDER BY ae.fecha DESC";
        $res_asistencias_sistema = pg_query_params($conexion, $sql_asis, array($documento_consultado));
        
        if ($res_asistencias_sistema) {
            $total_horas_sistema = pg_num_rows($res_asistencias_sistema) * 2;
        }
    }
}

require_once 'header.php';
?>

<main class="app-main" style="width: 100%; min-height: 100vh;">
    <div class="app-content-header">
        <div class="container-fluid px-4">
            <h3 class="mb-0">
                <i class="bi bi-clock-history"></i> Carga de Horas con Restricción de Registro Único
                <span class="badge bg-danger fs-6 ms-2">Restringido (Admin / Gerente / Analista)</span>
            </h3>
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

            <div class="row">
                <!-- Formulario de Registro -->
                <div class="col-md-5">
                    <div class="card shadow-sm mb-4">
                        <div class="card-header bg-dark text-white">
                            <h5 class="mb-0">Registrar Horas y Certificado</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="horas_historicas_registrar.php" enctype="multipart/form-data" id="formRegistroHoras">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Cédula del Privado de Libertad: <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="privado_documento" id="privado_documento" placeholder="Ej: V27028136 o 27028136" required autocomplete="off">
                                    <small id="estado_cedula" class="form-text mt-1"></small>
                                </div>

                                <!-- Tarjeta AJAX modificada para mostrar datos y alerta de registro previo -->
                                <div id="cardDatosAjax" class="card bg-light mb-3 border-secondary d-none">
                                    <div class="card-body py-2 px-3" id="cuerpoCardAjax">
                                        <!-- Se llena dinámicamente con JS -->
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-bold">Descripción del Lote / Certificados:</label>
                                    <input type="text" class="form-control" name="curso_descripcion" placeholder="Ej: Varios certificados previos" value="Certificados Varios" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Total de Horas a Sumar: <span class="text-danger">*</span></label>
                                    <input type="number" class="form-control" name="horas_acumuladas" min="1" placeholder="Ej: 120" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Foto o Archivo del Certificado (Obligatorio): <span class="text-danger">*</span></label>
                                    <input type="file" class="form-control" name="foto_certificado" accept=".jpg, .jpeg, .png, .pdf" required>
                                    <small class="text-muted">Formatos permitidos: JPG, PNG, PDF.</small>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Observación / Detalles:</label>
                                    <textarea class="form-control" name="observacion" rows="2" placeholder="Notas sobre los documentos..."></textarea>
                                </div>
                                <div class="text-end">
                                    <button type="submit" class="btn btn-success w-100" id="btnGuardar" disabled>
                                        <i class="bi bi-cloud-upload"></i> Guardar Soporte y Sumar Horas
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Consulta y Cómputo Total -->
                <div class="col-md-7">
                    <div class="card shadow-sm mb-4">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0">Expediente Educativo y Cómputo Global</h5>
                        </div>
                        <div class="card-body">
                            <form method="GET" action="horas_historicas_registrar.php" class="row g-2 mb-3">
                                <div class="col-md-9">
                                    <input type="text" class="form-control" name="cedula" placeholder="Ingrese cédula (Ej: V27028136)" value="<?php echo htmlspecialchars($busqueda_cedula); ?>" required>
                                </div>
                                <div class="col-md-3">
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="bi bi-search"></i> Buscar
                                    </button>
                                </div>
                            </form>

                            <?php if (!empty($busqueda_cedula)): ?>
                                <hr>
                                <?php if ($datos_privado_info): ?>
                                    <div class="alert alert-success mb-3">
                                        <h5 class="alert-heading fw-bold mb-1"><i class="bi bi-person-badge"></i> Datos Básicos del Privado:</h5>
                                        <p class="mb-0">
                                            <strong>Cédula:</strong> <?php echo htmlspecialchars($documento_consultado); ?><br>
                                            <strong>Apellidos y Nombres:</strong> <?php echo htmlspecialchars($datos_privado_info['apellido'] . ', ' . $datos_privado_info['nombre']); ?><br>
                                            <strong>Género:</strong> <?php echo htmlspecialchars($datos_privado_info['genero'] ?? 'N/D'); ?> | 
                                            <strong>Estado Civil:</strong> <?php echo htmlspecialchars($datos_privado_info['estado_civil'] ?? 'N/D'); ?>
                                        </p>
                                    </div>
                                    
                                    <h6 class="text-dark fw-bold mt-3">📁 Soportes y Certificados Registrados:</h6>
                                    <div class="table-responsive">
                                        <table class="table table-bordered table-striped align-middle">
                                            <thead class="table-dark">
                                                <tr>
                                                    <th>Descripción</th>
                                                    <th>Horas</th>
                                                    <th>Soporte / Archivo</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php 
                                                $total_historico = 0;
                                                if ($res_historicas && pg_num_rows($res_historicas) > 0): 
                                                    while ($h = pg_fetch_assoc($res_historicas)): 
                                                        $total_historico += intval($h['horas_acumuladas']);
                                                ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($h['curso_descripcion']); ?><br><small class="text-muted"><?php echo htmlspecialchars($h['observacion']); ?></small></td>
                                                        <td class="text-center"><strong><?php echo $h['horas_acumuladas']; ?> h</strong></td>
                                                        <td class="text-center">
                                                            <?php if (!empty($h['foto_certificado'])): ?>
                                                                <a href="<?php echo htmlspecialchars($h['foto_certificado']); ?>" target="_blank" class="btn btn-sm btn-outline-info">
                                                                    <i class="bi bi-file-earmark-image"></i> Ver Archivo
                                                                </a>
                                                            <?php else: ?>
                                                                <span class="text-muted small">Sin soporte</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endwhile; else: ?>
                                                    <tr>
                                                        <td colspan="3" class="text-center text-muted">No hay soportes de certificados cargados.</td>
                                                    </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>

                                    <div class="alert alert-secondary py-2 mt-2">
                                        <small><strong>Asistencias en el Sistema Actual:</strong> <?php echo ($res_asistencias_sistema) ? pg_num_rows($res_asistencias_sistema) : 0; ?> clases presentes (<strong><?php echo $total_horas_sistema; ?> horas</strong>).</small>
                                    </div>

                                    <?php 
                                        $gran_total_horas = $total_historico + $total_horas_sistema;

                                        if ($gran_total_horas > 0): 
                                            $dias_redencion = floor($gran_total_horas / 16);
                                            $horas_restantes = $gran_total_horas % 16;
                                    ?>
                                        <div class="alert alert-info py-3 mb-0">
                                            <div class="row text-center">
                                                <div class="col-md-6 border-end">
                                                    <span class="d-block text-muted small">GRAN TOTAL DE HORAS</span>
                                                    <span class="fs-4 text-dark fw-bold"><?php echo $gran_total_horas; ?> Horas</span>
                                                </div>
                                                <div class="col-md-6">
                                                    <span class="d-block text-muted small">DÍAS DE REDENCIÓN (16h = 1 día)</span>
                                                    <span class="fs-4 text-success fw-bold"><?php echo $dias_redencion; ?> Días</span>
                                                    <?php if ($horas_restantes > 0): ?>
                                                        <br><small class="text-muted">(Restan <?php echo $horas_restantes; ?> horas)</small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                <?php else: ?>
                                    <div class="alert alert-warning">No se encontró ningún privado registrado con la cédula <?php echo htmlspecialchars($busqueda_cedula); ?>.</div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</main>

<!-- Script AJAX actualizado con alerta visual de registro previo -->
<script>
document.getElementById('privado_documento').addEventListener('blur', function() {
    let cedula = this.value.trim();
    let estadoCedula = document.getElementById('estado_cedula');
    let cardDatosAjax = document.getElementById('cardDatosAjax');
    let cuerpoCardAjax = document.getElementById('cuerpoCardAjax');
    let btnGuardar = document.getElementById('btnGuardar');

    if (cedula.length > 0) {
        estadoCedula.innerHTML = '<span class="text-primary">🔍 Verificando cédula y registros...</span>';
        
        fetch('buscar_privado_academico.php?cedula=' + encodeURIComponent(cedula))
            .then(response => response.json())
            .then(data => {
                if (data.encontrado) {
                    if (data.tiene_registro_previo) {
                        // SI YA TIENE REGISTRO ANTERIOR: Muestra sus datos pero avisa en rojo y bloquea
                        estadoCedula.innerHTML = '<span class="text-warning fw-bold">⚠ Ya posee un registro histórico</span>';
                        cuerpoCardAjax.innerHTML = `
                            <h6 class="card-title text-danger fw-bold mb-1"><i class="bi bi-exclamation-triangle-fill"></i> Privado Encontrado (Con Registro Previo):</h6>
                            <p class="card-text mb-0 small">
                                <strong>Documento:</strong> ${data.documento}<br>
                                <strong>Nombre:</strong> ${data.apellido}, ${data.nombre}<br>
                                <strong>Género:</strong> ${data.genero} | <strong>Estado Civil:</strong> ${data.estado_civil}<br>
                                <span class="text-danger fw-bold mt-2 d-block">❌ La cédula de identidad ya posee un registro de ${data.horas_previas} horas históricas. No se puede registrar nuevamente.</span>
                            </p>
                        `;
                        cardDatosAjax.classList.remove('d-none');
                        cardDatosAjax.classList.remove('bg-light');
                        cardDatosAjax.classList.add('bg-danger-subtle', 'border-danger');
                        btnGuardar.setAttribute('disabled', 'true'); // BLOQUEA EL BOTÓN
                    } else {
                        // SI NO TIENE REGISTRO: Muestra sus datos en verde y habilita
                        estadoCedula.innerHTML = '<span class="text-success fw-bold">✔ Cédula válida (' + data.documento + ')</span>';
                        cuerpoCardAjax.innerHTML = `
                            <h6 class="card-title text-success fw-bold mb-1"><i class="bi bi-check-circle-fill"></i> Privado Encontrado:</h6>
                            <p class="card-text mb-0 small">
                                <strong>Documento:</strong> ${data.documento}<br>
                                <strong>Nombre:</strong> ${data.apellido}, ${data.nombre}<br>
                                <strong>Género:</strong> ${data.genero} | <strong>Estado Civil:</strong> ${data.estado_civil}<br>
                                <span class="text-success fw-bold">✔ Sin registros previos. Puede proceder.</span>
                            </p>
                        `;
                        cardDatosAjax.classList.remove('d-none');
                        cardDatosAjax.classList.remove('bg-danger-subtle', 'border-danger');
                        cardDatosAjax.classList.add('bg-light', 'border-secondary');
                        btnGuardar.removeAttribute('disabled'); // HABILITA EL BOTÓN
                    }
                } else {
                    estadoCedula.innerHTML = '<span class="text-danger fw-bold">❌ Cédula no registrada en el sistema</span>';
                    cardDatosAjax.classList.add('d-none');
                    cuerpoCardAjax.innerHTML = '';
                    btnGuardar.setAttribute('disabled', 'true');
                }
            })
            .catch(error => {
                console.error('Error en AJAX:', error);
                estadoCedula.innerHTML = '<span class="text-danger">Error al validar la cédula</span>';
            });
    } else {
        estadoCedula.innerHTML = '';
        cardDatosAjax.classList.add('d-none');
        btnGuardar.setAttribute('disabled', 'true');
    }
});
</script>

<?php 
if (isset($conexion)) { pg_close($conexion); }
require_once 'footer.php'; 
?>