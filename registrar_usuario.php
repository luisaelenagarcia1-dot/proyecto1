<?php
// 1. Iniciamos el búfer de salida para evitar errores de cabeceras modificadas
ob_start();
session_start();
require_once 'conexion.php';

// 2. Validación de seguridad estricta y flexible (Acepta Administrador o Administradora)
$rol_usuario_actual = trim($_SESSION['cargo'] ?? '');
$es_admin_valido = (strcasecmp($rol_usuario_actual, 'Administradora') === 0 || strcasecmp($rol_usuario_actual, 'Administrador') === 0);

if (!isset($_SESSION['loggedin']) || !$es_admin_valido) {
    $_SESSION['error_message'] = "Acceso denegado. Requiere permisos de Administrador.";
    header('Location: index.php');
    exit;
}

// Obtener la lista de centros para el selector múltiple de Coordinador Regional
$res_centros = pg_query($conexion, "SELECT codigo, nombre FROM centros ORDER BY nombre ASC");
$lista_centros = [];
if ($res_centros) {
    while ($cen = pg_fetch_assoc($res_centros)) {
        $lista_centros[] = $cen;
    }
}

// Incluimos el header después de validar las redirecciones de sesión
require_once 'header.php'; 
?>

<main class="app-main">
    
    <div class="app-content-header">
        <div class="container-fluid px-4">
            <div class="row">
                <div class="col-sm-6">
                    <h3 class="mb-0"><i class="bi bi-person-badge"></i> Registrar Personal del Sistema</h3>
                </div>
            </div>
        </div>
    </div>

    <div class="app-content">
        <div class="container-fluid px-4">
            <div class="row justify-content-center">
                
                <div class="col-lg-10 col-xl-9"> 
                    <div class="card card-primary card-outline shadow-sm">
                        <div class="card-header bg-light">
                            <h3 class="card-title fw-bold">Formulario de Registro de Usuario</h3>
                        </div>
                        <div class="card-body p-4">
                            
                            <div class="alert alert-info py-2 mb-4 small d-flex align-items-center">
                                <i class="bi bi-info-circle-fill fs-5 me-2"></i>
                                <div>El usuario será creado con la contraseña provisional <strong>"12345678"</strong> y se le enviarán sus credenciales por correo electrónico automáticamente. Deberá cambiarla al ingresar por primera vez.</div>
                            </div>
                            
                            <?php
                            // --- MENSAJES DE ALERTA ---
                            if (isset($_GET['exito'])) {
                                echo '<div class="alert alert-success alert-dismissible fade show" role="alert">¡Usuario registrado y correo enviado exitosamente! <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
                            }
                            if (isset($_GET['error'])) {
                                $mensajeError = "Error desconocido.";
                                if ($_GET['error'] == 'duplicado') {
                                    $mensajeError = "Error: El 'Nombre de Usuario' o la 'Cédula' ya existen en el sistema.";
                                } 
                                echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">' . $mensajeError . ' <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
                            }
                            ?>

                            <form action="procesar_registros.php" method="POST" enctype="multipart/form-data"> 
                                
                                <!-- SECCIÓN 1: DATOS PERSONALES -->
                                <h5 class="text-secondary border-bottom pb-2 mb-3"><i class="bi bi-person-lines-fill"></i> Información Personal</h5>
                                
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label for="cedula" class="form-label fw-bold small">Cédula <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="cedula" name="cedula" required minlength="6" maxlength="8" placeholder="Ej: 26530793" oninput="this.value = this.value.toUpperCase()">
                                        <div id="mensaje-cedula" class="form-text"></div>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label for="nombre" class="form-label fw-bold small">Nombre <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="nombre" name="nombre" required placeholder="Ej: María" oninput="this.value = this.value.toUpperCase()">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label for="apellido" class="form-label fw-bold small">Apellido <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="apellido" name="apellido" required placeholder="Ej: Pérez" oninput="this.value = this.value.toUpperCase()">
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="correo" class="form-label fw-bold small">Correo (Email) <span class="text-danger">*</span></label>
                                        <input type="email" class="form-control" id="correo" name="correo" required placeholder="correo@ejemplo.com">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="telefono" class="form-label fw-bold small">Teléfono <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="telefono" name="telefono" required placeholder="Ej: 04241234567" oninput="this.value = this.value.toUpperCase()">
                                    </div>
                                </div>

                                <!-- SECCIÓN 2: CREDENCIALES Y ROL -->
                                <h5 class="text-secondary border-bottom pb-2 mb-3 mt-4"><i class="bi bi-shield-lock-fill"></i> 2. Credenciales y Permisos</h5>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="nombre_usuario" class="form-label fw-bold small">Nombre de Usuario (V+Cédula)</label>
                                        <input type="text" class="form-control bg-light text-muted fw-bold" id="nombre_usuario" name="nombre_usuario" placeholder="Se generará automáticamente" required readonly oninput="this.value = this.value.toUpperCase()">
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label for="cargo" class="form-label fw-bold small">Cargo / Rol en el Sistema <span class="text-danger">*</span></label>
                                        <select class="form-select" id="cargo" name="cargo" required onchange="toggleCentroAsignado()">
                                            <option value="" disabled selected>-- Seleccione un cargo --</option>
                                            <option value="Administradora">Administradora</option>
                                            <option value="Gerente">Gerente</option>
                                            <option value="Analista">Analista</option>
                                            <option value="Coordinador Regional">Coordinador Regional</option>
                                            <option value="Asistente Administrativo">Asistente Administrativo</option>
                                        </select>
                                    </div>
                                </div>

                                <!-- SECCIÓN DINÁMICA: CENTROS PENITENCIARIOS MÚLTIPLES (Solo para Coordinador Regional) -->
                                <div class="mb-4 d-none p-3 border border-primary rounded bg-light" id="seccion_centro">
                                    <label for="centros_codigos" class="form-label fw-bold text-primary mb-1">
                                        <i class="bi bi-building"></i> Centros Penitenciarios Asignados <span class="text-danger">*</span>
                                    </label>
                                    <div class="form-text mb-2 text-muted small">Mantenga presionado <b>Ctrl</b> (o <b>Cmd</b> en Mac) para seleccionar uno o varios recintos según corresponda.</div>
                                    
                                    <select class="form-select border-primary" id="centros_codigos" name="centros_codigos[]" multiple style="height: 130px;">
                                        <?php foreach ($lista_centros as $cen): ?>
                                            <option value="<?php echo $cen['codigo']; ?>">
                                                <?php echo htmlspecialchars($cen['codigo'] . ' - ' . $cen['nombre']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="text-end mt-4">
                                    <a href="usuarios_listar.php" class="btn btn-outline-secondary me-2">Cancelar</a>
                                    <button type="submit" id="btnSubmit" class="btn btn-primary px-4">
                                        <i class="bi bi-check-circle"></i> Registrar Usuario
                                    </button>
                                </div>
                            </form>
                            
                        </div>
                    </div>
                </div>
                
            </div>

            <script>
            // Función para mostrar u ocultar el selector múltiple de centros según el cargo seleccionado
            function toggleCentroAsignado() {
                const cargoSelect = document.getElementById('cargo');
                const seccionCentro = document.getElementById('seccion_centro');
                const centroSelect = document.getElementById('centros_codigos');

                if (cargoSelect.value === 'Coordinador Regional') {
                    seccionCentro.classList.remove('d-none');
                    centroSelect.setAttribute('required', 'required');
                } else {
                    seccionCentro.classList.add('d-none');
                    centroSelect.removeAttribute('required');
                    for (let option of centroSelect.options) {
                        option.selected = false;
                    }
                }
            }

            document.addEventListener('DOMContentLoaded', function() {
                const cedulaInput = document.getElementById('cedula');
                const usuarioInput = document.getElementById('nombre_usuario');
                const mensajeCedula = document.getElementById('mensaje-cedula');
                const submitButton = document.getElementById('btnSubmit');

                if(submitButton) {
                    submitButton.disabled = false;
                }

                // --- 1. Generar Nombre de Usuario ---
                cedulaInput.addEventListener('input', function() {
                    const cedulaValue = this.value.trim().toUpperCase().replace('V', '').replace('v', '');
                    usuarioInput.value = 'V' + cedulaValue;
                });

                // --- 2. Lógica de validación de cédula (AJAX) ---
                cedulaInput.addEventListener('blur', function() {
                    const cedula = this.value.trim();
                    const cedulaLength = cedula.length;
                    const minLength = 6;
                    const maxLength = 8;

                    if (cedula === '') {
                        mensajeCedula.textContent = '';
                        if(submitButton) submitButton.disabled = false;
                        return;
                    }

                    if (cedulaLength < minLength || cedulaLength > maxLength) {
                        mensajeCedula.textContent = `La cédula debe tener entre ${minLength} y ${maxLength} dígitos.`;
                        mensajeCedula.className = 'form-text text-danger';
                        if(submitButton) submitButton.disabled = true; 
                        return;
                    }
                    
                    mensajeCedula.textContent = 'Verificando...';
                    mensajeCedula.className = 'form-text text-muted';
                    if(submitButton) submitButton.disabled = true; 

                    fetch('verificar_cedula.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'cedula=' + encodeURIComponent(cedula)
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.error) {
                            console.error('Error de servidor en verificación:', data.error);
                            mensajeCedula.textContent = 'Error: No se pudo verificar la cédula. Intente de nuevo.';
                            mensajeCedula.className = 'form-text text-danger';
                            if(submitButton) submitButton.disabled = true;
                            return;
                        }

                        if (data.existe) {
                            mensajeCedula.textContent = 'Esta cédula ya está registrada.';
                            mensajeCedula.className = 'form-text text-danger';
                            if(submitButton) submitButton.disabled = true;
                        } else {
                            mensajeCedula.textContent = 'Cédula disponible.';
                            mensajeCedula.className = 'form-text text-success';
                            if(submitButton) submitButton.disabled = false;
                        }
                    })
                    .catch(error => {
                        console.error('Error de red en la verificación:', error);
                        mensajeCedula.textContent = '';
                        if(submitButton) submitButton.disabled = false; 
                    });
                });
            });
            </script>
            
        </div> 
    </div> 
</main> 
<?php
if (isset($conexion)) { pg_close($conexion); }
include 'footer.php'; 
ob_end_flush(); // Liberamos el búfer al final del script
?>