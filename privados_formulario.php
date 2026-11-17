<?php
session_start();
require_once 'header.php';
require_once 'conexion.php';

// =======================================================
// PHP 1: OBTENER LISTA DE CENTROS (PostgreSQL)
// =======================================================
$sql_centros = "SELECT codigo, nombre, genero_permitido FROM centros ORDER BY nombre";
$res_centros = pg_query($conexion, $sql_centros);
$centros = [];
if ($res_centros) {
    while($fila = pg_fetch_assoc($res_centros)){
        $centros[] = $fila; 
    }
    pg_free_result($res_centros);
}
$centros_json = json_encode($centros);

// =======================================================
// PHP 2: OBTENER LISTA DE TRIBUNALES (PostgreSQL)
// =======================================================
$sql_tribunales = "SELECT DISTINCT tribunal FROM casos_judiciales WHERE tribunal IS NOT NULL AND tribunal != '' ORDER BY tribunal";
$res_tribunales = pg_query($conexion, $sql_tribunales);
$tribunales = [];
if ($res_tribunales) {
    while($fila = pg_fetch_assoc($res_tribunales)){
        $tribunales[] = $fila['tribunal'];
    }
    pg_free_result($res_tribunales);
}

// =======================================================
// PHP 3: OBTENER LISTA DE DELITOS (PostgreSQL)
// =======================================================
$sql_delitos = "SELECT DISTINCT tipo_delito FROM casos_judiciales WHERE tipo_delito IS NOT NULL AND tipo_delito != '' ORDER BY tipo_delito";
$res_delitos = pg_query($conexion, $sql_delitos);
$delitos = [];
if ($res_delitos) {
    while($fila = pg_fetch_assoc($res_delitos)){
        $delitos[] = $fila['tipo_delito'];
    }
    pg_free_result($res_delitos);
}

// La conexión se cierra al final del archivo.
?>

<style>
    .form-step { display: none; }
    .form-step.active { display: block; }
    .progress-bar-container { margin-bottom: 20px; }
    input[type="text"], textarea {
        text-transform: uppercase;
    }
    .capacidad-info { font-size: 0.9em; font-weight: bold; }
    .capacidad-full { color: #dc3545; }
    .capacidad-available { color: #198754; }
</style>

<main class="app-main">
    <div class="app-content-header">
        <div class="container-fluid">
            <h3 class="mb-0 text-center">Registrar Privado de Libertad</h3>
        </div>
    </div>
    <div class="app-content">
        <div class="container-fluid">
            <div class="row justify-content-center">
                <div class="col-lg-10">
                    <div class="card">
                        <div class="card-body">
                            
                            <div class="progress-bar-container">
                                <div class="progress" style="height: 25px;">
                                    <div id="progressBar" class="progress-bar bg-primary" role="progressbar" style="width: 33%" aria-valuenow="33" aria-valuemin="0" aria-valuemax="100">Paso 1 de 3</div>
                                </div>
                            </div>

                            <?php
                            if (isset($_SESSION['error_message'])) {
                                echo '<div class="alert alert-danger" role="alert">';
                                echo '<strong>Error al procesar:</strong><br>';
                                echo htmlspecialchars($_SESSION['error_message']);
                                echo '</div>';
                                unset($_SESSION['error_message']); 
                            }
                            ?>
                            
                            <form id="formulario" action="procesar_registro.php" method="post" enctype="multipart/form-data">
                                <p class="text-end text-muted fst-italic">
                                    Los campos marcados con <span class="text-danger">*</span> son obligatorios.
                                </p>
                                
                                <div id="step-1" class="form-step active">
                                    <h4 class="mt-4 mb-3">Información Personal</h4>
                                    <hr>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="tipoDocumento" class="form-label">Tipo de Documento <span class="text-danger">*</span></label>
                                                <div class="input-group">
                                                    <select class="form-select" id="tipoDocumento" name="tipoDocumento" required>
                                                        <option value="" disabled selected>Seleccione el tipo</option>
                                                        <optgroup label="Venezolano"><option value="V">V - Cédula</option></optgroup>
                                                        <optgroup label="Extranjero"><option value="E">E - Extranjero</option><option value="P">P - Pasaporte</option></optgroup>
                                                    </select>
                                                    <input type="text" class="form-control" id="numeroDocumento" name="numeroDocumento" placeholder="Número de documento" maxlength="8" pattern="[0-9]{6,8}" title="Debe contener entre 6 y 8 números.">
                                                </div>
                                                <div id="mensajeDocumento" class="form-text mt-1"></div>
                                            </div>
                                        </div>
                                        <div class="col-md-6"><div class="mb-3"><label for="fechaNacimiento" class="form-label">Fecha de Nacimiento <span class="text-danger">*</span></label><input type="date" class="form-control" id="fechaNacimiento" name="fechaNacimiento" required></div></div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6"><div class="mb-3"><label for="nombre" class="form-label">Nombres <span class="text-danger">*</span></label><input type="text" class="form-control" id="nombre" name="nombre" required></div></div>
                                        <div class="col-md-6"><div class="mb-3"><label for="apellido" class="form-label">Apellidos <span class="text-danger">*</span></label><input type="text" class="form-control" id="apellido" name="apellido" required></div></div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="genero" class="form-label">Género <span class="text-danger">*</span></label>
                                                <select class="form-select" id="genero" name="genero" required>
                                                    <option value="" disabled selected>Seleccione el género</option>
                                                    <option value="Masculino">Masculino</option>
                                                    <option value="Femenino">Femenino</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6"><div class="mb-3"><label for="estado_civil" class="form-label">Estado Civil <span class="text-danger">*</span></label><select class="form-select" id="estado_civil" name="estado_civil" required><option value="" disabled selected>Seleccione el estado civil</option><option value="Soltero(a)">Soltero(a)</option><option value="Casado(a)">Casado(a)</option><option value="Divorciado(a)">Divorciado(a)</option><option value="Viudo(a)">Viudo(a)</option></select></div></div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6"><div class="mb-3"><label for="nacionalidad" class="form-label">Nacionalidad <span class="text-danger">*</span></label><input type="text" class="form-control" id="nacionalidad" name="nacionalidad" required></div></div>
                                        <div class="col-md-6"><div class="mb-3"><label for="profesion" class="form-label">Profesión</label><input type="text" class="form-control" id="profesion" name="profesion" placeholder="Ej: Carpintero"></div></div>
                                    </div>
                                    
                                    <h4 class="mt-4 mb-3">Lugar de Nacimiento</h4>
                                    <hr>
                                    
                                    <div id="contenedorNacimiento">
                                        <div id="contenedorNacimientoVzla" style="display:none;">
                                            <div class="row">
                                                <div class="col-md-4"><div class="mb-3"><label class="form-label">País de Nacimiento <span class="text-danger">*</span></label><input type="text" class="form-control" name="paisNacimientoVzla" value="Venezuela" readonly></div></div>
                                                <div class="col-md-4">
                                                    <div class="mb-3">
                                                        <label for="estadoNacimiento" class="form-label">Estado de Nacimiento <span class="text-danger">*</span></label>
                                                        <select class="form-select" id="estadoNacimiento" name="estadoNacimiento">
                                                            <option value="" disabled selected>Seleccione un estado</option>
                                                            <?php 
                                                            $estados = ["Amazonas", "Anzoátegui", "Apure", "Aragua", "Barinas", "Bolívar", "Carabobo", "Cojedes", "Delta Amacuro", "Dependencias Federales", "Distrito Capital", "Falcón", "Guárico", "La Guaira", "Lara", "Mérida", "Miranda", "Monagas", "Nueva Esparta", "Portuguesa", "Sucre", "Táchira", "Trujillo", "Yaracuy", "Zulia"]; 
                                                            foreach ($estados as $estado) { 
                                                                echo "<option value='{$estado}'>{$estado}</option>"; 
                                                            } 
                                                            ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="mb-3">
                                                        <label for="ciudadNacimientoVzla" class="form-label">Ciudad de Nacimiento <span class="text-danger">*</span></label>
                                                        <input type="text" class="form-control" id="ciudadNacimientoVzla" name="ciudadNacimientoVzla" list="ciudades-list" placeholder="Escriba o seleccione la ciudad" autocomplete="off">
                                                        <datalist id="ciudades-list">
                                                            <!-- Se llena dinámicamente con las ciudades ya guardadas -->
                                                        </datalist>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div id="contenedorNacimientoExt" style="display:none;">
                                            <div class="row">
                                                <div class="col-md-4"><div class="mb-3"><label for="paisNacimientoExt" class="form-label">País de Nacimiento <span class="text-danger">*</span></label><input type="text" class="form-control" id="paisNacimientoExt" name="paisNacimientoExt" placeholder="Escriba el país"></div></div>
                                                <div class="col-md-4"><div class="mb-3"><label for="estadoNacimientoExt" class="form-label">Estado/Provincia/Región <span class="text-danger">*</span></label><input type="text" class="form-control" id="estadoNacimientoExt" name="estadoNacimientoExt" placeholder="Escriba el estado"></div></div>
                                                <div class="col-md-4"><div class="mb-3"><label for="ciudadNacimientoExt" class="form-label">Ciudad de Nacimiento <span class="text-danger">*</span></label><input type="text" class="form-control" id="ciudadNacimientoExt" name="ciudadNacimientoExt" placeholder="Escriba la ciudad"></div></div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="text-end mt-4">
                                        <button type="button" class="btn btn-primary btn-siguiente" id="btnSiguienteStep1">Siguiente &rarr;</button>
                                    </div>
                                </div>
                                
                                <div id="step-2" class="form-step">
                                    <h4 class="mt-4 mb-3">Información Judicial y Condena</h4>
                                    <hr>
                                    <div class="row">
                                        <div class="col-md-6 mb-3"><label for="numero_expediente" class="form-label">Número de Expediente <span class="text-danger">*</span></label><input type="text" class="form-control" id="numero_expediente" name="numero_expediente" placeholder="Ej: TP01-P-2024-001234" required></div>
                                        <div class="col-md-6 mb-3">
                                            <label for="tribunal" class="form-label">Tribunal</label>
                                            <select class="form-select" id="tribunal" name="tribunal">
                                                <option value="" selected>Seleccione un tribunal (Opcional)</option>
                                                <option value="OTRO">--- Ingresar Tribunal no listado ---</option>
                                                <?php foreach ($tribunales as $t) {
                                                    echo '<option value="' . htmlspecialchars($t) . '">' . htmlspecialchars($t) . '</option>';
                                                } ?>
                                            </select>
                                            <input type="text" class="form-control mt-2" id="tribunal_otro" name="tribunal_otro_texto" style="display:none;" placeholder="Escriba el nombre completo del Tribunal">
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label for="tipo_delito_select" class="form-label">Tipo de Delito <span class="text-danger">*</span></label>
                                            <select class="form-select" id="tipo_delito_select" name="tipo_delito" required>
                                                <option value="" disabled selected>Seleccione o escriba</option>
                                                <option value="OTRO">--- Ingresar Delito no listado ---</option>
                                                <?php foreach ($delitos as $d) {
                                                    echo '<option value="' . htmlspecialchars($d) . '">' . htmlspecialchars($d) . '</option>';
                                                } ?>
                                            </select>
                                            <input type="text" class="form-control mt-2" id="tipo_delito_otro" name="tipo_delito_otro_texto" style="display:none;" placeholder="Escriba el nombre completo del Delito">
                                        </div>
                                        <div class="col-md-6 mb-3"><label for="fechaCondena" class="form-label">Fecha de Condena</label><input type="date" class="form-control" id="fechaCondena" name="fechaCondena"></div>
                                    </div>
                                    <p class="form-label">Tiempo de Condena:</p>
                                    <div class="row">
                                        <div class="col-md-4 mb-3"><label for="duracionAnios" class="form-label">Años</label><input type="number" class="form-control" id="duracionAnios" name="duracionAnios" placeholder="0" min="0" value="0"></div>
                                        <div class="col-md-4 mb-3"><label for="duracionMeses" class="form-label">Meses</label><input type="number" class="form-control" id="duracionMeses" name="duracionMeses" placeholder="0" min="0" max="11" value="0"></div>
                                        <div class="col-md-4 mb-3"><label for="duracionDias" class="form-label">Días</label><input type="number" class="form-control" id="duracionDias" name="duracionDias" placeholder="0" min="0" max="29" value="0"></div>
                                    </div>
                                    <h5 class="mt-3">Personal Judicial Adscrito</h5>
                                    <div class="row">
                                        <div class="col-md-3 mb-3">
                                            <label for="juez_cedula" class="form-label">Cédula Juez</label>
                                            <input type="text" class="form-control judicial-input" id="juez_cedula" data-tipo="Juez" placeholder="Cédula (Opcional)">
                                        </div>
                                        <div class="col-md-9 mb-3">
                                            <label for="juez_nombre" class="form-label">Nombre Juez(a)</label>
                                            <input type="text" class="form-control judicial-input" id="juez_nombre" data-tipo="Juez" list="juez-list" placeholder="Escriba Cédula o Nombre">
                                            <datalist id="juez-list"></datalist>
                                            <input type="hidden" name="juez_id" id="juez_id">
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-3 mb-3">
                                            <label for="fiscal_cedula" class="form-label">Cédula Fiscal</label>
                                            <input type="text" class="form-control judicial-input" id="fiscal_cedula" data-tipo="Fiscal" placeholder="Cédula (Opcional)">
                                        </div>
                                        <div class="col-md-9 mb-3">
                                            <label for="fiscal_nombre" class="form-label">Nombre Fiscal</label>
                                            <input type="text" class="form-control judicial-input" id="fiscal_nombre" data-tipo="Fiscal" list="fiscal-list" placeholder="Escriba Cédula o Nombre">
                                            <datalist id="fiscal-list"></datalist>
                                            <input type="hidden" name="fiscal_id" id="fiscal_id">
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-3 mb-3">
                                            <label for="abogado_cedula" class="form-label">Cédula Abogado</label>
                                            <input type="text" class="form-control judicial-input" id="abogado_cedula" data-tipo="Abogado" placeholder="Cédula (Opcional)">
                                        </div>
                                        <div class="col-md-9 mb-3">
                                            <label for="abogado_nombre" class="form-label">Nombre Abogado(a)</label>
                                            <input type="text" class="form-control judicial-input" id="abogado_nombre" data-tipo="Abogado" list="abogado-list" placeholder="Escriba Cédula o Nombre">
                                            <datalist id="abogado-list"></datalist>
                                            <input type="hidden" name="abogado_id" id="abogado_id">
                                        </div>
                                    </div>
                                    
                                    <div class="d-flex justify-content-between mt-4">
                                        <button type="button" class="btn btn-secondary btn-anterior">&larr; Anterior</button>
                                        <button type="button" class="btn btn-primary btn-siguiente" id="btnSiguienteStep2">Siguiente &rarr;</button>
                                    </div>
                                </div>
                                
                                <div id="step-3" class="form-step">
                                    <h4 class="mt-5 mb-3">Información de Reclusión y Asignación</h4>
                                    <hr>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="centro_codigo_step3" class="form-label">Centro de Reclusión <span class="text-danger">*</span></label>
                                                <select class="form-select" id="centro_codigo_step3" name="centro_codigo" required>
                                                    <option value="" disabled selected>Seleccione un centro</option>
                                                </select>
                                                <div id="mensaje_filtro_centro" class="form-text text-danger fw-bold mt-1" style="display: none;"></div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="area_codigo_step3" class="form-label">Área Socioproductiva Asignada</label>
                                                <select class="form-select" id="area_codigo_step3" name="area_codigo" disabled><option value="">Primero seleccione un centro</option></select>
                                                <span id="infoCapacidadArea" class="form-text capacidad-info"></span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6"><div class="mb-3"><label for="fechaDetencion" class="form-label">Fecha de Detención <span class="text-danger">*</span></label><input type="date" class="form-control" id="fechaDetencionStep3" name="fechaDetencion" required></div></div>
                                        <div class="col-md-6"><div class="mb-3"><label for="foto" class="form-label">Foto</label><input type="file" class="form-control" id="foto" name="foto" accept="image/*"></div></div>
                                    </div>

                                    <div class="d-flex justify-content-between mt-4">
                                        <button type="button" class="btn btn-secondary btn-anterior">&larr; Anterior</button>
                                        <div>
                                            <button type="submit" class="btn btn-success">Registrar Privado</button>
                                            <a href="privados_listar.php" class="btn btn-secondary ms-2">Cancelar</a>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const tipoDocumentoSelect = document.getElementById('tipoDocumento');
    const numeroDocumentoInput = document.getElementById('numeroDocumento');
    const mensajeDocumentoEl = document.getElementById('mensajeDocumento');
    const nacionalidadInput = document.getElementById('nacionalidad');
    const generoSelect = document.getElementById('genero'); 
    
    const contenedorNacimientoVzla = document.getElementById('contenedorNacimientoVzla');
    const contenedorNacimientoExt = document.getElementById('contenedorNacimientoExt');
    const estadoNacimiento = document.getElementById('estadoNacimiento');
    const ciudadNacimientoVzla = document.getElementById('ciudadNacimientoVzla');
    const ciudadDatalist = document.getElementById('ciudades-list');

    const paisNacimientoExt = document.getElementById('paisNacimientoExt');
    const estadoNacimientoExt = document.getElementById('estadoNacimientoExt');
    const ciudadNacimientoExt = document.getElementById('ciudadNacimientoExt');

    const tribunalSelect = document.getElementById('tribunal'); 
    const tribunalOtroInput = document.getElementById('tribunal_otro'); 
    const delitoSelect = document.getElementById('tipo_delito_select'); 
    const delitoOtroInput = document.getElementById('tipo_delito_otro'); 
    
    const juezCedulaInput = document.getElementById('juez_cedula');
    const juezNombreInput = document.getElementById('juez_nombre');
    const juezDatalist = document.getElementById('juez-list');
    const juezHiddenId = document.getElementById('juez_id');
    const fiscalCedulaInput = document.getElementById('fiscal_cedula');
    const fiscalNombreInput = document.getElementById('fiscal_nombre');
    const fiscalDatalist = document.getElementById('fiscal-list');
    const fiscalHiddenId = document.getElementById('fiscal_id');
    const abogadoCedulaInput = document.getElementById('abogado_cedula');
    const abogadoNombreInput = document.getElementById('abogado_nombre');
    const abogadoDatalist = document.getElementById('abogado-list');
    const abogadoHiddenId = document.getElementById('abogado_id');

    const selectCentroStep3 = document.getElementById('centro_codigo_step3');
    const selectAreaStep3 = document.getElementById('area_codigo_step3');
    const infoCapacidadArea = document.getElementById('infoCapacidadArea');
    const mensajeFiltroCentro = document.getElementById('mensaje_filtro_centro'); 

    let currentStep = 1;
    const steps = document.querySelectorAll('.form-step');
    const progressBar = document.getElementById('progressBar');
    const btnSiguienteStep1 = document.getElementById('btnSiguienteStep1');
    const btnSiguienteStep2 = document.getElementById('btnSiguienteStep2'); 

    const todosLosCentros = <?php echo $centros_json; ?>; 

    function updateProgressBar() {
        const percent = (currentStep / steps.length) * 100;
        progressBar.style.width = percent + '%';
        progressBar.setAttribute('aria-valuenow', percent);
        progressBar.textContent = `Paso ${currentStep} de ${steps.length}`;
    }

    function showStep(stepNumber) {
        if (stepNumber < 1 || stepNumber > steps.length) return;
        document.querySelector('.form-step.active').classList.remove('active');
        document.getElementById('step-' + stepNumber).classList.add('active');
        currentStep = stepNumber;
        updateProgressBar();
    }

    function validateStep(stepNumber) {
        const currentStepEl = document.getElementById('step-' + stepNumber);
        const inputs = currentStepEl.querySelectorAll('input:required:not([disabled]), select:required:not([disabled])');
        
        for (const input of inputs) {
            if (!input.checkValidity()) {
                input.reportValidity();
                return false;
            }
        }
        return true;
    }
    
    function filtrarCentros() {
        const generoSeleccionado = generoSelect.value;
        
        selectCentroStep3.innerHTML = '<option value="" disabled selected>Seleccione un centro</option>';
        selectCentroStep3.disabled = true;
        mensajeFiltroCentro.style.display = 'none';
        mensajeFiltroCentro.className = 'form-text text-danger fw-bold mt-1';
        
        if (!generoSeleccionado) {
            mensajeFiltroCentro.textContent = 'Seleccione el género del privado (Paso 1) para filtrar centros.';
            mensajeFiltroCentro.style.display = 'block';
            return;
        }

        const centrosFiltrados = todosLosCentros.filter(centro => centro.genero_permitido === generoSeleccionado);

        if (centrosFiltrados.length > 0) {
            centrosFiltrados.forEach(centro => {
                const option = document.createElement('option');
                option.value = centro.codigo;
                option.textContent = centro.nombre;
                selectCentroStep3.appendChild(option);
            });
            selectCentroStep3.disabled = false;
        } else {
            mensajeFiltroCentro.textContent = `❌ No hay centros registrados para el género ${generoSeleccionado}.`;
            mensajeFiltroCentro.style.display = 'block';
        }
    }
    
    function verificarDocumento() {
        return new Promise((resolve) => {
            const tipo = tipoDocumentoSelect.value; 
            const numero = numeroDocumentoInput.value;
            if (!tipo || !numero || numero.length < 6 || !numeroDocumentoInput.checkValidity()) {
                mensajeDocumentoEl.textContent = '';
                resolve(false); 
                return;
            }
            mensajeDocumentoEl.textContent = 'Verificando...';
            mensajeDocumentoEl.className = 'form-text text-muted mt-1';
            fetch(`verificar_documento.php?tipo=${encodeURIComponent(tipo)}&numero=${encodeURIComponent(numero)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.existe) {
                        mensajeDocumentoEl.textContent = '🚨 ¡ERROR! Este documento YA ESTÁ REGISTRADO.';
                        mensajeDocumentoEl.className = 'form-text text-danger fw-bold mt-1';
                        resolve(false); 
                    } else {
                        mensajeDocumentoEl.textContent = '✅ Documento disponible.';
                        mensajeDocumentoEl.className = 'form-text text-success fw-bold mt-1';
                        resolve(true); 
                    }
                })
                .catch(error => {
                    console.error('Error en fetch:', error);
                    mensajeDocumentoEl.textContent = '⚠️ Error de conexión. No se pudo verificar.';
                    mensajeDocumentoEl.className = 'form-text text-warning mt-1';
                    resolve(true); 
                });
        });
    }

    function cargarAreas(centroCodigo) {
        infoCapacidadArea.textContent = ''; 
        selectAreaStep3.innerHTML = '<option value="">Cargando áreas...</option>';
        selectAreaStep3.disabled = true;
        if (!centroCodigo) {
            selectAreaStep3.innerHTML = '<option value="">Primero seleccione un centro</option>';
            return;
        }
        fetch('obtener_areas.php?centro_codigo=' + encodeURIComponent(centroCodigo))
            .then(response => response.json())
            .then(data => {
                selectAreaStep3.innerHTML = '<option value="">Sin asignar</option>'; 
                if (data.length > 0) {
                    data.forEach(area => {
                        const option = document.createElement('option');
                        option.value = area.codigo;
                        option.textContent = area.nombre_area;
                        option.setAttribute('data-capacidad', area.capacidad_maxima);
                        option.setAttribute('data-ocupacion', area.ocupacion_actual);
                        selectAreaStep3.appendChild(option);
                    });
                    selectAreaStep3.disabled = false;
                } else {
                    selectAreaStep3.innerHTML = '<option value="">No hay áreas asignadas a este centro</option>';
                }
            })
            .catch(error => {
                console.error('Error al cargar las áreas:', error);
                selectAreaStep3.innerHTML = '<option value="">Error al cargar áreas</option>';
            });
    }
    
    if(selectCentroStep3) {
        selectCentroStep3.addEventListener('change', function() {
            cargarAreas(this.value);
        });
    }

    if(selectAreaStep3) {
        selectAreaStep3.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            if (!this.value) {
                infoCapacidadArea.textContent = '';
                return;
            }
            const capacidad = parseInt(selectedOption.getAttribute('data-capacidad'));
            const ocupacion = parseInt(selectedOption.getAttribute('data-ocupacion'));
            const disponibles = capacidad - ocupacion;
            infoCapacidadArea.textContent = `Capacidad: ${ocupacion} / ${capacidad} (Disponibles: ${disponibles})`;
            if (disponibles <= 0) infoCapacidadArea.className = 'form-text capacidad-info capacidad-full';
            else if (disponibles <= 5) infoCapacidadArea.className = 'form-text capacidad-info text-warning';
            else infoCapacidadArea.className = 'form-text capacidad-info capacidad-available';
        });
    }

    // Cargar ciudades existentes para evitar duplicados según el estado seleccionado
    if (estadoNacimiento) {
        estadoNacimiento.addEventListener('change', async function() {
            const estado = this.value;
            ciudadDatalist.innerHTML = '';
            ciudadNacimientoVzla.value = '';
            if (!estado) return;

            try {
                const response = await fetch(`obtener_ciudades.php?estado=${encodeURIComponent(estado)}`);
                const ciudades = await response.json();
                ciudades.forEach(c => {
                    ciudadDatalist.innerHTML += `<option value="${c.nombre}">`;
                });
            } catch (error) {
                console.error('Error al cargar ciudades:', error);
            }
        });
    }

    function actualizarNacionalidadYNacimiento() {
        const tipo = tipoDocumentoSelect.value;

        if (tipo === 'V') {
            nacionalidadInput.value = 'Venezolana';
            nacionalidadInput.readOnly = true;
            contenedorNacimientoVzla.style.display = 'block';
            contenedorNacimientoExt.style.display = 'none';
            estadoNacimiento.required = true;
            ciudadNacimientoVzla.required = true;
            paisNacimientoExt.required = false;
            estadoNacimientoExt.required = false;
            ciudadNacimientoExt.required = false;
        } else if (tipo === 'E' || tipo === 'P') {
            nacionalidadInput.value = '';
            nacionalidadInput.placeholder = 'Escriba la nacionalidad';
            nacionalidadInput.readOnly = false;
            contenedorNacimientoVzla.style.display = 'none';
            contenedorNacimientoExt.style.display = 'block';
            estadoNacimiento.required = false;
            ciudadNacimientoVzla.required = false;
            paisNacimientoExt.required = true;
            estadoNacimientoExt.required = true;
            ciudadNacimientoExt.required = true;
        } else {
            nacionalidadInput.value = '';
            nacionalidadInput.readOnly = false;
            contenedorNacimientoVzla.style.display = 'none';
            contenedorNacimientoExt.style.display = 'none';
            estadoNacimiento.required = false;
            ciudadNacimientoVzla.required = false;
            paisNacimientoExt.required = false;
            estadoNacimientoExt.required = false;
            ciudadNacimientoExt.required = false;
        }
    }
    
    async function llenarDataList(datalist, url) {
        datalist.innerHTML = '';
        try {
            const response = await fetch(url);
            const data = await response.json();
            data.forEach(item => {
                datalist.innerHTML += `<option value="${item.nombre_completo}" data-cedula="${item.cedula}">${item.cedula || ''}</option>`;
            });
        } catch (error) { console.error('Error al llenar datalist:', error); }
    }
    
    async function getOrCreate(accion, data) {
        const formData = new FormData();
        formData.append('accion', accion);
        for(const key in data) { formData.append(key, data[key]); }
        try {
            const response = await fetch('buscar_personal_judicial.php', { method: 'POST', body: formData });
            const result = await response.json();
            return result.id || null;
        } catch (error) { console.error('Error en getOrCreate:', error); return null; }
    }

    function setupJudicialFields(tipo, cedulaInput, nombreInput, hiddenInput, datalist) {
        cedulaInput.addEventListener('change', async function() {
            const cedula = this.value.trim().toUpperCase();
            this.value = cedula;
            if (!cedula) {
                nombreInput.value = '';
                hiddenInput.value = '';
                return;
            }
            const response = await fetch(`buscar_personal_judicial.php?tipo=${tipo}&cedula=${cedula}`);
            const data = await response.json();
            if (data.length > 0) {
                nombreInput.value = data[0].nombre_completo;
                hiddenInput.value = data[0].id;
            } else {
                nombreInput.value = '';
                hiddenInput.value = '';
            }
        });

        nombreInput.addEventListener('input', function() {
            llenarDataList(datalist, `buscar_personal_judicial.php?tipo=${tipo}&nombre=${this.value}`);
        });

        nombreInput.addEventListener('change', async function() {
            const nombre = this.value.trim().toUpperCase();
            this.value = nombre;
            const cedula = cedulaInput.value.trim().toUpperCase();
            
            if (!nombre) {
                hiddenInput.value = '';
                return;
            }
            
            const id = await getOrCreate('get_or_create', { nombre: nombre, tipo: tipo, cedula: cedula });
            if (id) {
                hiddenInput.value = id;
            }
        });
    }

    setupJudicialFields('Juez', juezCedulaInput, juezNombreInput, juezHiddenId, juezDatalist);
    setupJudicialFields('Fiscal', fiscalCedulaInput, fiscalNombreInput, fiscalHiddenId, fiscalDatalist);
    setupJudicialFields('Abogado', abogadoCedulaInput, abogadoNombreInput, abogadoHiddenId, abogadoDatalist);

    if (tribunalSelect) {
        tribunalSelect.addEventListener('change', function() {
            if (this.value === 'OTRO') {
                tribunalOtroInput.style.display = 'block';
                tribunalOtroInput.name = 'tribunal'; 
                tribunalOtroInput.required = true;
                this.name = 'tribunal_select_oculto'; 
            } else {
                tribunalOtroInput.style.display = 'none';
                tribunalOtroInput.required = false;
                tribunalOtroInput.name = 'tribunal_otro_texto'; 
                tribunalOtroInput.value = ''; 
                this.name = 'tribunal'; 
            }
        });
        tribunalSelect.dispatchEvent(new Event('change'));
    }
    
    if (delitoSelect) {
        delitoSelect.addEventListener('change', function() {
            if (this.value === 'OTRO') {
                delitoOtroInput.style.display = 'block';
                delitoOtroInput.name = 'tipo_delito'; 
                delitoOtroInput.required = true;
                this.name = 'tipo_delito_select_oculto'; 
            } else {
                delitoOtroInput.style.display = 'none';
                delitoOtroInput.required = false;
                delitoOtroInput.name = 'tipo_delito_otro_texto'; 
                delitoOtroInput.value = ''; 
                this.name = 'tipo_delito'; 
            }
        });
        delitoSelect.dispatchEvent(new Event('change'));
    }
    
    if(tipoDocumentoSelect) { 
        tipoDocumentoSelect.addEventListener('change', function() {
            verificarDocumento();
            actualizarNacionalidadYNacimiento(); 
        }); 
    }
    actualizarNacionalidadYNacimiento(); 
    
    if(numeroDocumentoInput) { 
        numeroDocumentoInput.addEventListener('blur', verificarDocumento); 
    }
    
    if(generoSelect) {
        generoSelect.addEventListener('change', function() {
            selectCentroStep3.value = ''; 
            cargarAreas(''); 
            filtrarCentros(); 
        });
    }
    filtrarCentros();

    if(btnSiguienteStep1) {
        btnSiguienteStep1.addEventListener('click', async (e) => {
            if (!validateStep(1)) return; 
            
            e.target.disabled = true; 
            e.target.textContent = "Verificando...";
            const cedulaDisponible = await verificarDocumento();
            
            if (cedulaDisponible) {
                if (!generoSelect.value) {
                    generoSelect.reportValidity();
                    e.target.disabled = false; 
                    e.target.textContent = "Siguiente \u2192";
                    return;
                }
                filtrarCentros(); 
                showStep(2);
            }
            
            e.target.disabled = false;
            e.target.textContent = "Siguiente \u2192";
        });
    }
    
    if (btnSiguienteStep2) {
        btnSiguienteStep2.addEventListener('click', async (e) => {
            if (!validateStep(2)) return;
            showStep(3);
        });
    }

    document.querySelectorAll('.btn-anterior').forEach(btn => {
        btn.addEventListener('click', () => {
            showStep(currentStep - 1);
        });
    });
});
</script>

<?php 
if (isset($conexion)) { pg_close($conexion); } 
require_once 'footer.php'; 
?>