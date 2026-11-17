<?php
session_start();
require_once 'header.php';
require_once 'conexion.php';

$mensaje = '';
if (isset($_SESSION['success_message'])) {
    $mensaje = '<div class="alert alert-success alert-dismissible fade show" role="alert">' . $_SESSION['success_message'] . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    $mensaje = '<div class="alert alert-danger alert-dismissible fade show" role="alert">' . $_SESSION['error_message'] . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
    unset($_SESSION['error_message']);
}

if (!isset($_GET['documento']) || empty($_GET['documento'])) {
    $_SESSION['error_message'] = "No se especificó un registro para gestionar.";
    header('Location: privados_listar.php');
    exit();
}
$documento = $_GET['documento'];

$sql_privado = "SELECT 
    p.*, 
    c.nombre AS nombre_centro, 
    a.nombre_area AS nombre_area,
    cj.numero_expediente, cj.tribunal, cj.tipo_delito, cj.fecha_condena, cj.duracion_condena_dias,
    cj.juez_id, cj.fiscal_id, cj.abogado_id,
    j.nombre_completo AS juez_nombre,
    f.nombre_completo AS fiscal_nombre,
    ab.nombre_completo AS abogado_nombre,
    j.cedula AS juez_cedula,
    f.cedula AS fiscal_cedula,
    ab.cedula AS abogado_cedula,
    ci.nombre AS ciudad_nacimiento,
    e.nombre AS estado_nacimiento,
    pa.nombre AS pais_nacimiento
FROM privados p
LEFT JOIN centros c ON p.centro_codigo = c.codigo
LEFT JOIN areas_socioproductivas a ON p.area_codigo = a.codigo
LEFT JOIN privados_casos pc ON p.documento_identidad = pc.privado_documento
LEFT JOIN casos_judiciales cj ON pc.caso_expediente = cj.numero_expediente
LEFT JOIN personal_judicial j ON cj.juez_id = j.id
LEFT JOIN personal_judicial f ON cj.fiscal_id = f.id
LEFT JOIN personal_judicial ab ON cj.abogado_id = ab.id
LEFT JOIN loc_ciudades ci ON p.ciudad_nacimiento_id = ci.id
LEFT JOIN loc_estados e ON ci.id_estado = e.id
LEFT JOIN loc_paises pa ON e.id_pais = pa.id
WHERE p.documento_identidad = $1";

$resultado = pg_query_params($conexion, $sql_privado, array($documento));

if (!$resultado || pg_num_rows($resultado) === 0) {
    if ($resultado) pg_free_result($resultado);
    $_SESSION['error_message'] = "Registro no encontrado.";
    header('Location: privados_listar.php');
    exit();
}
$privado = pg_fetch_assoc($resultado);
pg_free_result($resultado);

$totalDias = $privado['duracion_condena_dias'] ?? 0;
$anios = floor($totalDias / 365);
$diasRestantes = $totalDias % 365;
$meses = floor($diasRestantes / 30);
$dias = $diasRestantes % 30;
?>

<style>
    input[type="text"], textarea {
        text-transform: uppercase;
    }
    .capacidad-info { font-size: 0.9em; font-weight: bold; }
    .capacidad-full { color: #dc3545; }
    .capacidad-available { color: #198754; }
</style>

<main class="app-main">
    <div class="app-content-header"><div class="container-fluid"><h3 class="mb-0">Gestionar Registro</h3></div></div>
    <div class="app-content">
        <div class="container-fluid">
            <div class="row justify-content-center">
                <div class="col-lg-10">
                    <div class="card">
                        <div class="card-body">
                            <?php echo $mensaje; ?>

                            <h4 class="card-title">Actualizar información de: <?php echo htmlspecialchars($privado['nombre'] . ' ' . $privado['apellido']); ?></h4>
                            <hr>

                            <h5 class="mt-4">Información de Registro (No Editable)</h5>
                            <div class="row p-3 mb-3 bg-light rounded" style="font-size: 0.9rem;">
                                <div class="col-md-4"><p><strong>Documento:</strong><br><?php echo htmlspecialchars($privado['documento_identidad']); ?></p></div>
                                <div class="col-md-4"><p><strong>Nombre Completo:</strong><br><?php echo htmlspecialchars($privado['nombre'] . ' ' . $privado['apellido']); ?></p></div>
                                <div class="col-md-4"><p><strong>Fecha de Nacimiento:</strong><br><?php echo !empty($privado['fecha_nacimiento']) ? date("d/m/Y", strtotime($privado['fecha_nacimiento'])) : 'N/A'; ?></p></div>
                                <div class="col-md-4"><p><strong>Nacionalidad:</strong><br><?php echo htmlspecialchars($privado['nacionalidad']); ?></p></div>
                                <div class="col-md-8"><p><strong>Lugar de Nacimiento:</strong><br><?php echo htmlspecialchars($privado['ciudad_nacimiento'] ?? 'N/A'); ?>, <?php echo htmlspecialchars($privado['estado_nacimiento'] ?? 'N/A'); ?>, <?php echo htmlspecialchars($privado['pais_nacimiento'] ?? 'N/A'); ?></p></div>
                                <div class="col-md-4"><p><strong>Centro de Reclusión:</strong><br><?php echo htmlspecialchars($privado['nombre_centro'] ?? 'N/A'); ?></p></div>
                                <div class="col-md-4"><p><strong>Fecha de Detención:</strong><br><?php echo !empty($privado['fecha_detencion']) ? date("d/m/Y", strtotime($privado['fecha_detencion'])) : 'N/A'; ?></p></div>
                                <div class="col-md-4"><p><strong>Número de Expediente:</strong><br><?php echo htmlspecialchars($privado['numero_expediente'] ?? 'N/A'); ?></p></div>
                            </div>

                            <form id="formulario_gestion" action="procesar_gestion.php" method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="documento_original" value="<?php echo htmlspecialchars($privado['documento_identidad']); ?>">
                                <input type="hidden" name="numero_expediente_original" value="<?php echo htmlspecialchars($privado['numero_expediente'] ?? ''); ?>">

                                <h5 class="mt-4">Información Personal (Editable)</h5>
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label for="profesion" class="form-label">Profesión</label>
                                        <input type="text" class="form-control" name="profesion" id="profesion" value="<?php echo htmlspecialchars($privado['profesion'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label for="estado_civil" class="form-label">Estado Civil</label>
                                        <select class="form-select" id="estado_civil" name="estado_civil" required>
                                            <option value="Soltero(a)" <?php echo ($privado['estado_civil'] == 'Soltero(a)') ? 'selected' : ''; ?>>Soltero(a)</option>
                                            <option value="Casado(a)" <?php echo ($privado['estado_civil'] == 'Casado(a)') ? 'selected' : ''; ?>>Casado(a)</option>
                                            <option value="Divorciado(a)" <?php echo ($privado['estado_civil'] == 'Divorciado(a)') ? 'selected' : ''; ?>>Divorciado(a)</option>
                                            <option value="Viudo(a)" <?php echo ($privado['estado_civil'] == 'Viudo(a)') ? 'selected' : ''; ?>>Viudo(a)</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label for="foto" class="form-label">Cambiar Foto</label>
                                        <input type="file" class="form-control" name="foto" id="foto">
                                        <input type="hidden" name="foto_actual" value="<?php echo htmlspecialchars($privado['foto'] ?? ''); ?>">
                                        <?php if (!empty($privado['foto'])): ?>
                                            <img src="<?php echo htmlspecialchars($privado['foto']); ?>" alt="Foto Actual" class="img-thumbnail mt-2" style="max-width: 100px;">
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <h5 class="mt-4">Información Judicial (Editable)</h5>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="tribunal" class="form-label">Tribunal</label>
                                        <input type="text" class="form-control" name="tribunal" id="tribunal" value="<?php echo htmlspecialchars($privado['tribunal'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="fechaCondena" class="form-label">Fecha de Condena</label>
                                        <input type="date" class="form-control" id="fechaCondena" name="fechaCondena" value="<?php echo htmlspecialchars($privado['fecha_condena'] ?? ''); ?>">
                                    </div>
                                </div>
                                
                                <p class="form-label">Tiempo de Condena (Editable):</p>
                                <div class="row">
                                    <div class="col-md-4 mb-3"><label for="duracionAnios" class="form-label">Años</label><input type="number" class="form-control" id="duracionAnios" name="duracionAnios" min="0" value="<?php echo $anios; ?>"></div>
                                    <div class="col-md-4 mb-3"><label for="duracionMeses" class="form-label">Meses</label><input type="number" class="form-control" id="duracionMeses" name="duracionMeses" min="0" max="11" value="<?php echo $meses; ?>"></div>
                                    <div class="col-md-4 mb-3"><label for="duracionDias" class="form-label">Días</label><input type="number" class="form-control" id="duracionDias" name="duracionDias" min="0" max="29" value="<?php echo $dias; ?>"></div>
                                </div>
                                
                                <h5 class="mt-3">Personal Judicial Adscrito (Editable)</h5>
                                <div class="row">
                                    <div class="col-md-3 mb-3">
                                        <label for="juez_cedula" class="form-label">Cédula Juez</label>
                                        <input type="text" class="form-control judicial-input" id="juez_cedula" data-tipo="Juez" value="<?php echo htmlspecialchars($privado['juez_cedula'] ?? ''); ?>" placeholder="Cédula (Opcional)">
                                    </div>
                                    <div class="col-md-9 mb-3">
                                        <label for="juez_nombre" class="form-label">Nombre Juez(a)</label>
                                        <input type="text" class="form-control judicial-input" id="juez_nombre" data-tipo="Juez" list="juez-list" value="<?php echo htmlspecialchars($privado['juez_nombre'] ?? ''); ?>" placeholder="Escriba Cédula o Nombre">
                                        <datalist id="juez-list"></datalist>
                                        <input type="hidden" name="juez_id" id="juez_id" value="<?php echo htmlspecialchars($privado['juez_id'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-3 mb-3">
                                        <label for="fiscal_cedula" class="form-label">Cédula Fiscal</label>
                                        <input type="text" class="form-control judicial-input" id="fiscal_cedula" data-tipo="Fiscal" value="<?php echo htmlspecialchars($privado['fiscal_cedula'] ?? ''); ?>" placeholder="Cédula (Opcional)">
                                    </div>
                                    <div class="col-md-9 mb-3">
                                        <label for="fiscal_nombre" class="form-label">Nombre Fiscal</label>
                                        <input type="text" class="form-control judicial-input" id="fiscal_nombre" data-tipo="Fiscal" list="fiscal-list" value="<?php echo htmlspecialchars($privado['fiscal_nombre'] ?? ''); ?>" placeholder="Escriba Cédula o Nombre">
                                        <datalist id="fiscal-list"></datalist>
                                        <input type="hidden" name="fiscal_id" id="fiscal_id" value="<?php echo htmlspecialchars($privado['fiscal_id'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-3 mb-3">
                                        <label for="abogado_cedula" class="form-label">Cédula Abogado</label>
                                        <input type="text" class="form-control judicial-input" id="abogado_cedula" data-tipo="Abogado" value="<?php echo htmlspecialchars($privado['abogado_cedula'] ?? ''); ?>" placeholder="Cédula (Opcional)">
                                    </div>
                                    <div class="col-md-9 mb-3">
                                        <label for="abogado_nombre" class="form-label">Nombre Abogado(a)</label>
                                        <input type="text" class="form-control judicial-input" id="abogado_nombre" data-tipo="Abogado" list="abogado-list" value="<?php echo htmlspecialchars($privado['abogado_nombre'] ?? ''); ?>" placeholder="Escriba Cédula o Nombre">
                                        <datalist id="abogado-list"></datalist>
                                        <input type="hidden" name="abogado_id" id="abogado_id" value="<?php echo htmlspecialchars($privado['abogado_id'] ?? ''); ?>">
                                    </div>
                                </div>

                                <h5 class="mt-4">Asignación (Editable)</h5>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="area_codigo" class="form-label">Área Socioproductiva</label>
                                        <select class="form-select" id="area_codigo" name="area_codigo">
                                            <option value="">Cargando áreas...</option>
                                        </select>
                                        <span id="infoCapacidadArea" class="form-text capacidad-info"></span>
                                    </div>
                                </div>

                                <div class="mt-4">
                                    <button type="submit" class="btn btn-success">Guardar Cambios</button>
                                    <a href="privados_listar.php" class="btn btn-secondary">Volver al Listado</a>
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
    const selectArea = document.getElementById('area_codigo');
    const infoCapacidadArea = document.getElementById('infoCapacidadArea');
    const centroId = '<?php echo htmlspecialchars($privado['centro_codigo'] ?? ''); ?>';
    const areaActual = '<?php echo htmlspecialchars($privado['area_codigo'] ?? ''); ?>';

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

    const form = document.getElementById('formulario_gestion');

    function cargarAreas(centroCodigo, areaPreseleccionada = null) {
        infoCapacidadArea.textContent = ''; 
        selectArea.innerHTML = '<option value="">Cargando áreas...</option>';
        selectArea.disabled = true;
        if (!centroCodigo) {
            selectArea.innerHTML = '<option value="">El privado no tiene un centro asignado</option>';
            return;
        }
        fetch('obtener_areas.php?centro_codigo=' + encodeURIComponent(centroCodigo))
            .then(response => response.json())
            .then(data => {
                selectArea.innerHTML = '<option value="">Sin asignar</option>'; 
                if (data.length > 0) {
                    data.forEach(area => {
                        const option = document.createElement('option');
                        option.value = area.codigo;
                        option.textContent = area.nombre_area;
                        option.setAttribute('data-capacidad', area.capacidad_maxima);
                        option.setAttribute('data-ocupacion', area.ocupacion_actual);
                        
                        if (areaPreseleccionada && area.codigo === areaPreseleccionada) {
                            option.selected = true;
                        }
                        selectArea.appendChild(option);
                    });
                    selectArea.disabled = false;
                    if (areaPreseleccionada) {
                        selectArea.dispatchEvent(new Event('change'));
                    }
                } else {
                    selectArea.innerHTML = '<option value="">No hay áreas asignadas a este centro</option>';
                }
            })
            .catch(error => {
                console.error('Error al cargar las áreas:', error);
                selectArea.innerHTML = '<option value="">Error al cargar áreas</option>';
            });
    }
    
    if(selectArea) {
        selectArea.addEventListener('change', function() {
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
    
    async function getOrCreateJudicial(accion, data) {
        const formData = new FormData();
        formData.append('accion', accion);
        for(const key in data) { formData.append(key, data[key]); }
        try {
            const response = await fetch('buscar_personal_judicial.php', { method: 'POST', body: formData });
            const result = await response.json();
            return result.id || null;
        } catch (error) { console.error('Error en getOrCreateJudicial:', error); return null; }
    }

    function setupJudicialFields(tipo, cedulaInput, nombreInput, hiddenInput, datalist) {
        cedulaInput.addEventListener('change', async function() {
            const cedula = this.value.trim().toUpperCase();
            this.value = cedula;
            if (!cedula) return;
            
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
    }

    setupJudicialFields('Juez', juezCedulaInput, juezNombreInput, juezHiddenId, juezDatalist);
    setupJudicialFields('Fiscal', fiscalCedulaInput, fiscalNombreInput, fiscalHiddenId, fiscalDatalist);
    setupJudicialFields('Abogado', abogadoCedulaInput, abogadoNombreInput, abogadoHiddenId, abogadoDatalist);

    if (centroId) {
        cargarAreas(centroId, areaActual);
    }
    
    form.addEventListener('submit', async function(e) {
        e.preventDefault(); 
        
        const submitButton = form.querySelector('button[type="submit"]');
        submitButton.disabled = true;
        submitButton.textContent = "Guardando...";

        try {
            if (juezNombreInput.value.trim() !== '') {
                juezCedulaInput.value = juezCedulaInput.value.trim().toUpperCase();
                juezNombreInput.value = juezNombreInput.value.trim().toUpperCase();
                const juezId = await getOrCreateJudicial('get_or_create', { nombre: juezNombreInput.value, tipo: 'Juez', cedula: juezCedulaInput.value });
                juezHiddenId.value = juezId;
            } else {
                juezHiddenId.value = '';
            }
            
            if (fiscalNombreInput.value.trim() !== '') {
                fiscalCedulaInput.value = fiscalCedulaInput.value.trim().toUpperCase();
                fiscalNombreInput.value = fiscalNombreInput.value.trim().toUpperCase();
                const fiscalId = await getOrCreateJudicial('get_or_create', { nombre: fiscalNombreInput.value, tipo: 'Fiscal', cedula: fiscalCedulaInput.value });
                fiscalHiddenId.value = fiscalId;
            } else {
                fiscalHiddenId.value = '';
            }

            if (abogadoNombreInput.value.trim() !== '') {
                abogadoCedulaInput.value = abogadoCedulaInput.value.trim().toUpperCase();
                abogadoNombreInput.value = abogadoNombreInput.value.trim().toUpperCase();
                const abogadoId = await getOrCreateJudicial('get_or_create', { nombre: abogadoNombreInput.value, tipo: 'Abogado', cedula: abogadoCedulaInput.value });
                abogadoHiddenId.value = abogadoId;
            } else {
                abogadoHiddenId.value = '';
            }
            
            form.submit();

        } catch (error) {
            alert("Error al procesar los campos antes de guardar: " + error.message);
            submitButton.disabled = false;
            submitButton.textContent = "Guardar Cambios";
        }
    });
});
</script>

<?php 
if (isset($conexion)) {
    pg_close($conexion);
}
require_once 'footer.php'; 
?>