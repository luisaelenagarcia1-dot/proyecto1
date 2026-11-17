<?php
// redenciones.php
session_start();

require_once 'header.php';
require_once 'conexion.php';

// -----------------------------------------------------------
// 1.2. MENSAJES DE SESIÓN Y CONSULTA PENDIENTE (PostgreSQL)
// -----------------------------------------------------------
$mensaje_exito =$_SESSION['mensaje_exito'] ?? null;
$mensaje_error =$_SESSION['mensaje_error'] ?? null;

unset($_SESSION['mensaje_exito']);
unset($_SESSION['mensaje_error']);

$resultado = null;
$error_db = null;

if (isset($conexion)) {$sql_solicitudes = "SELECT s.*, p.nombre, p.apellido
                        FROM solicitudes_redencion s
                        JOIN privados p ON s.privado_documento = p.documento_identidad
                        WHERE s.estado = 'En Proceso'
                        ORDER BY s.fecha_solicitud ASC";

    $resultado = pg_query($conexion,$sql_solicitudes);

    if (!$resultado) {
        error_log("Error en consulta de solicitudes: " . pg_last_error($conexion));$error_db = "Error al cargar las solicitudes pendientes.";
    }
} else {
    $error_db = "Error: La base de datos no está inicializada.";
}
?>

<main class="app-main flex-grow">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <style>
        .form-container {
            background-color: #ffffff;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            border-left: 5px solid #0d6efd;
        }
        .app-main { min-height: 100vh; display: flex; flex-direction: column; }
        .app-content { flex-grow: 1; padding-top: 2rem !important; padding-bottom: 4rem !important; background-color: #f7f9fc; }
        #nombre_resultado { display: block; margin-top: 10px; font-weight: 600; min-height: 20px; }
        .text-success { color: #198754; }
        .text-danger { color: #dc3545; }
        .btn-primary { background-color: #0d6efd; border-color: #0d6efd; }
    </style>

    <div class="app-content-header bg-light py-4 border-bottom shadow-sm">
        <div class="container-fluid">
            <h1 class="display-6"><i class="bi bi-person-workspace"></i> Panel de Gestión de Redenciones</h1>
            <p class="lead text-muted">Administración y seguimiento de expedientes de redención de penas.</p>
        </div>
    </div>

    <div class="app-content">
        <div class="container-fluid">

            <?php if ($mensaje_exito): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <strong>¡Éxito!</strong> <?php echo $mensaje_exito; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            
            <?php if ($mensaje_error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <strong>Error:</strong> <?php echo $mensaje_error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            
            <?php if ($error_db): ?>
                <div class="alert alert-danger" role="alert">
                    <strong>Error de Sistema:</strong> <?php echo $error_db; ?>
                </div>
            <?php endif; ?>

            <div class="row justify-content-center">
                <div class="col-lg-8 mb-4">
                    <div class="form-container">
                        <h3><i class="bi bi-file-earmark-plus"></i> Generar Solicitud</h3>
                        <p class="lead text-muted">Verifique la cédula y la elegibilidad del privado de libertad.</p>

                        <form action="procesar_solicitud.php" method="POST">
                            <label for="cedula_input" class="form-label">Cédula del Privado de Libertad:</label>
                            <div class="input-group mb-3">
                                <input type="text" class="form-control" id="cedula_input" name="privado_documento" placeholder="Cédula (Ej: V27028136)" required>
                                <button class="btn btn-primary" type="button" id="btn_buscar_cedula" title="Buscar Elegibilidad">
                                    <i class="bi bi-search"></i> Verificar
                                </button>
                            </div>

                            <span id="nombre_resultado"></span>
                            
                            <div class="mb-3 mt-3">
                                <label for="fecha_corte_input" class="form-label fw-bold">Fecha de Corte para el Cálculo (*):</label>
                                <input type="date" class="form-control" id="fecha_corte_input" name="fecha_calculo_inicio" required value="<?php echo date('Y-m-d'); ?>">
                                <small class="form-text text-muted">La redención se calculará hasta esta fecha.</small>
                            </div>
                            <input type="hidden" name="dias_contados_oculto" id="dias_contados_oculto" value="0"> 
                            <input type="hidden" name="horas_contadas_oculto" id="horas_contadas_oculto" value="0.00"> 

                            <input type="hidden" name="usuario_solicitante" value="<?php echo htmlspecialchars($_SESSION['nombre_usuario'] ?? 'Sistema_Desconocido'); ?>">

                            <button type="submit" class="btn btn-success mt-3 w-100" id="btn_generar_solicitud" disabled>
                                <i class="bi bi-check-circle"></i> Generar Nueva Solicitud
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="h3"><i class="bi bi-hourglass-split"></i> Solicitudes en Proceso</h2>
                <a href="historico_redenciones.php" class="btn btn-secondary shadow-sm">
                    <i class="bi bi-clock-history"></i> Ver Histórico de Redenciones
                </a>
            </div>

            <div class="card shadow-lg">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">Expedientes Pendientes de Decisión</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th scope="col">Privado</th>
                                    <th scope="col">Documento</th>
                                    <th scope="col">Fecha Solicitud</th>
                                    <th scope="col">Días Laborados</th> 
                                    <th scope="col" class="text-center">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($resultado && pg_num_rows($resultado) > 0): ?>
                                    <?php while ($solicitud = pg_fetch_assoc($resultado)): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($solicitud['nombre'] . ' ' .$solicitud['apellido']); ?></td>
                                            <td><?php echo htmlspecialchars($solicitud['privado_documento']); ?></td>
                                            
                                            <td>
                                                <?php 
                                                    $fecha_solicitud =$solicitud['fecha_solicitud'];
                                                    if (empty($fecha_solicitud)) {
                                                        echo '<span class="text-danger">Fecha Inválida</span>';
                                                    } else {
                                                        echo date('d/m/Y', strtotime($fecha_solicitud));
                                                    }
                                                ?>
                                            </td>
                                            <td><span class="badge bg-warning text-dark"><?php echo htmlspecialchars($solicitud['dias_presente_contados']); ?> días</span></td>
                                            
                                            <td class="text-center">
                                                
                                                <button type="button" class="btn btn-success btn-sm btn-aprobar me-1"
                                                    title="Aprobar Solicitud Judicialmente"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#modalAprobar"
                                                    data-id-solicitud="<?php echo $solicitud['id_solicitud']; ?>">
                                                    <i class="bi bi-check-lg"></i> Aprobar
                                                </button>

                                                <form action="decidir_solicitud.php" method="POST" style="display: inline-block; margin-right: 5px;" onsubmit="return confirm('ATENCIÓN: ¿Está seguro de RECHAZAR la solicitud de <?php echo htmlspecialchars($solicitud['nombre'] . ' ' .$solicitud['apellido']); ?>? Esta acción pasará el estado a "Rechazado" de forma permanente.');">
                                                    <input type="hidden" name="id_solicitud" value="<?php echo $solicitud['id_solicitud']; ?>">
                                                    <input type="hidden" name="decision" value="Rechazado">
                                                    <button type="submit" class="btn btn-danger btn-sm me-1" title="Rechazar">
                                                        <i class="bi bi-x-lg"></i> Rechazar
                                                    </button>
                                                </form>

                                                <a href="generar_pdf.php?id=<?php echo $solicitud['id_solicitud']; ?>" class="btn btn-info btn-sm text-white" title="Imprimir PDF del Expediente" target="_blank" style="display: inline-block;">
                                                    <i class="bi bi-printer"></i> PDF
                                                </a>

                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                    <?php pg_free_result($resultado); ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" class="text-center py-4"> <i class="bi bi-info-circle text-muted me-2"></i> No hay solicitudes activas en estado 'En Proceso' en este momento.
                                        </td>
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

<div class="modal fade" id="modalAprobar" tabindex="-1" aria-labelledby="modalAprobarLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title" id="modalAprobarLabel"><i class="bi bi-check-circle-fill"></i> Confirmar Aprobación Judicial</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <form action="decidir_solicitud.php" method="POST">
                <div class="modal-body">
                    <p class="text-muted">Ingrese la cédula del juez/funcionario.</p>

                    <div class="mb-3">
                        <label for="cedula_juez_aprobacion_modal" class="form-label fw-bold">Cédula del Juez/Funcionario (*):</label>
                        <input type="text" class="form-control" id="cedula_juez_aprobacion_modal" name="cedula_juez_aprobacion" required placeholder="Ej: V12345678">
                    </div>

                    <div class="mb-3">
                        <label for="juez_aprobacion_modal" class="form-label fw-bold">Nombre del Juez o Funcionario Judicial (*):</label>
                        <input type="text" class="form-control" id="juez_aprobacion_modal" name="juez_aprobacion" required placeholder="Ej: Dr. Juan Pérez">
                        <small class="form-text text-muted" id="juez_autocomplete_msg">Ingrese la cédula para buscar automáticamente el nombre.</small>
                    </div>

                    <div class="mb-3">
                        <label for="numero_aprobacion" class="form-label fw-bold">Número de Oficio / Sentencia:</label>
                        <input type="text" class="form-control" id="numero_aprobacion" name="numero_aprobacion" required placeholder="Ej: Oficio N° 123/2024">
                    </div>

                    <input type="hidden" name="decision" value="Aprobado">
                    <input type="hidden" id="id_solicitud_modal" name="id_solicitud" value="">

                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar / Cancelar</button>
                    <button type="submit" class="btn btn-success"><i class="bi bi-save"></i> Registrar Aprobación</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {

    const inputCedula = document.getElementById('cedula_input');
    const spanResultado = document.getElementById('nombre_resultado');
    const btnGenerar = document.getElementById('btn_generar_solicitud');
    const btnBuscar = document.getElementById('btn_buscar_cedula');
    
    const inputDiasContados = document.getElementById('dias_contados_oculto');
    const inputHorasContadas = document.getElementById('horas_contadas_oculto');

    if (btnBuscar) {
        btnBuscar.addEventListener('click', function() {
            const cedula = inputCedula.value.trim();
            spanResultado.innerHTML = '';
            spanResultado.className = '';
            btnGenerar.disabled = true;
            btnGenerar.textContent = 'Generar Nueva Solicitud';
            inputDiasContados.value = 0;
            inputHorasContadas.value = 0.00;

            if (cedula !== '') {
                spanResultado.innerHTML = '<i class="bi bi-hourglass-split me-1"></i> Verificando datos, por favor espere...';

                fetch('buscar_privado.php?documento_identidad=' + encodeURIComponent(cedula))
                    .then(response => {
                        if (!response.ok) { throw new Error('Error de red o script no encontrado.'); }
                        return response.json();
                    })
                    .then(data => {
                        if (data.encontrado) {
                            const nombreHtml = '<strong>Privado: ' + data.nombre_completo + '</strong>';
                            const elegibilidadHtml = '<br><span style="font-size: 0.9em; font-style: italic;">Resultado: ' + data.mensaje + '</span>';
                            spanResultado.innerHTML = nombreHtml + elegibilidadHtml;

                            if (data.elegible) {
                                spanResultado.classList.add('text-success');
                                btnGenerar.disabled = false;
                                inputDiasContados.value = data.dias_contados;
                                btnGenerar.textContent = `Crear Solicitud: Propuesta de ${data.dias_redencion_propuestos} días de redención`;
                            } else {
                                spanResultado.classList.add('text-danger');
                                btnGenerar.disabled = true;
                            }
                        } else {
                            spanResultado.textContent = 'Error: ' + (data.mensaje || 'Cédula no registrada.');
                            spanResultado.classList.add('text-danger');
                        }
                    })
                    .catch(error => {
                        console.error('Error fetch:', error);
                        spanResultado.textContent = `Error interno: ${error.message}`;
                        spanResultado.classList.add('text-danger');
                    });
            }
        });
    }

    const cedulaJuezInput = document.getElementById('cedula_juez_aprobacion_modal');
    const nombreJuezInput = document.getElementById('juez_aprobacion_modal');
    const msgJuez = document.getElementById('juez_autocomplete_msg');

    if (cedulaJuezInput && nombreJuezInput) {
        cedulaJuezInput.addEventListener('keyup', function() {
            const cedula = this.value.trim();
            nombreJuezInput.removeAttribute('readonly');
            nombreJuezInput.style.backgroundColor = 'white';
            msgJuez.textContent = 'Ingrese la cédula para buscar nombre.';
            msgJuez.className = 'form-text text-muted';

            if (cedula.length < 5) {
                nombreJuezInput.value = '';
                return;
            }

            msgJuez.textContent = 'Buscando...';
            msgJuez.className = 'form-text text-info';

            fetch('buscar_juez.php?cedula=' + encodeURIComponent(cedula))
                .then(response => response.json())
                .then(data => {
                    if (data.encontrado) {
                        nombreJuezInput.value = data.nombre;
                        nombreJuezInput.setAttribute('readonly', true);
                        nombreJuezInput.style.backgroundColor = '#e9ecef';
                        msgJuez.textContent = 'Funcionario encontrado.';
                        msgJuez.className = 'form-text text-success';
                    } else {
                        nombreJuezInput.value = '';
                        nombreJuezInput.removeAttribute('readonly');
                        nombreJuezInput.style.backgroundColor = 'white';
                        msgJuez.textContent = 'No registrado. Ingrese nombre manualmente.';
                        msgJuez.className = 'form-text text-warning';
                    }
                })
                .catch(error => {
                    msgJuez.textContent = 'Error en búsqueda.';
                    msgJuez.className = 'form-text text-danger';
                });
        });
    }

    var modalAprobar = document.getElementById('modalAprobar');
    if (modalAprobar) {
        modalAprobar.addEventListener('show.bs.modal', function(event) {
            var boton = event.relatedTarget;
            var idSolicitud = boton.getAttribute('data-id-solicitud');
            modalAprobar.querySelector('#id_solicitud_modal').value = idSolicitud;

            modalAprobar.querySelector('#numero_aprobacion').value = '';
            modalAprobar.querySelector('#juez_aprobacion_modal').value = '';
            modalAprobar.querySelector('#cedula_juez_aprobacion_modal').value = '';
            nombreJuezInput.removeAttribute('readonly');
            nombreJuezInput.style.backgroundColor = 'white';
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