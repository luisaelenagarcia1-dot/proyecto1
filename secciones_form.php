<?php
session_start();
require_once 'header.php';
require_once 'conexion.php';

$id_edit = $_GET['id'] ?? '';
$modo_edicion = !empty($id_edit);

$centro_codigo = '';
$curso_id = '';
$rif_nit = '';
$numero_seccion_actual = '01';
$dias_seleccionados = [];
$hora_desde = '08:00 AM';
$hora_hasta = '12:00 PM';
$fecha_inicio = date('Y-m-d');
$anio = max(intval(date('Y')), 2026);
$capacidad_maxima = 30;
$observaciones = '';

if ($modo_edicion) {
    $res = pg_query_params($conexion, "SELECT * FROM secciones_cursos WHERE id = $1", array($id_edit));
    if ($res && pg_num_rows($res) > 0) {
        $sec = pg_fetch_assoc($res);
        $centro_codigo = $sec['centro_codigo'];
        $curso_id = $sec['curso_id'];
        $rif_nit = $sec['rif_nit'];
        $numero_seccion_actual = str_pad($sec['numero_seccion'], 2, '0', STR_PAD_LEFT);
        $dias_seleccionados = explode(', ', $sec['dias_semana'] ?? '');
        
        $hora_clase_str =$sec['hora_clase'] ?? '';
        if (strpos($hora_clase_str, ' - ') !== false) {$partes_hora = explode(' - ', $hora_clase_str);$hora_desde = trim($partes_hora[0]);$hora_hasta = trim($partes_hora[1]);
        } elseif (!empty($hora_clase_str)) {
            $hora_desde =$hora_clase_str;
        }

        $fecha_inicio =$sec['fecha_inicio'] ?? date('Y-m-d');
        $anio = max(intval($sec['anio']), 2026);
        $capacidad_maxima =$sec['capacidad_maxima'];
        $observaciones =$sec['observaciones'];
    } else {
        $_SESSION['mensaje_error'] = "❌ La sección solicitada no existe.";
        header("Location: secciones_listado.php");
        exit();
    }
}

$centros = pg_query($conexion, "SELECT codigo, nombre, sigla FROM centros ORDER BY nombre ASC");

$cursos = null;
if ($modo_edicion && !empty($centro_codigo)) {
    $cursos = pg_query_params($conexion, "SELECT DISTINCT c.id, c.codigo_curso, c.nombre_curso, c.sigla, c.duracion_horas 
                                           FROM cursos c
                                           INNER JOIN ente_cursos ec ON c.id = ec.curso_id
                                           WHERE ec.centro_codigo = $1
                                           ORDER BY c.nombre_curso ASC", array($centro_codigo));
} else {
    $cursos = pg_query($conexion, "SELECT id, codigo_curso, nombre_curso, sigla, duracion_horas FROM cursos ORDER BY nombre_curso ASC");
}

$entes = null;
if ($modo_edicion && !empty($centro_codigo) && !empty($curso_id)) {
    $entes = pg_query_params($conexion, "SELECT DISTINCT e.rif_nit, e.nombre_ente, e.sigla 
                                           FROM entes_educativos e
                                           INNER JOIN ente_cursos ec ON e.rif_nit = ec.rif_nit
                                           WHERE ec.centro_codigo = $1 AND ec.curso_id = $2
                                           ORDER BY e.nombre_ente ASC", array($centro_codigo,$curso_id));
} else {
    $entes = pg_query($conexion, "SELECT rif_nit, nombre_ente, sigla FROM entes_educativos ORDER BY nombre_ente ASC");
}

$dias_disponibles = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
$horas_opciones = [
    '07:00 AM', '07:30 AM', '08:00 AM', '08:30 AM', '09:00 AM', '09:30 AM', 
    '10:00 AM', '10:30 AM', '11:00 AM', '11:30 AM', '12:00 PM', '12:30 PM', 
    '01:00 PM', '01:30 PM', '02:00 PM', '02:30 PM', '03:00 PM', '03:30 PM', 
    '04:00 PM', '04:30 PM', '05:00 PM', '05:30 PM', '06:00 PM'
];
?>

<main class="app-main" style="width: 100%; min-height: 100vh;">
    <div class="app-content-header">
        <div class="container-fluid px-4">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h3 class="mb-0">
                        <i class="bi bi-journal-plus"></i> 
                        <?php echo $modo_edicion ? 'Modificar Sección' : 'Apertura de Nueva Sección'; ?>
                    </h3>
                </div>
                <div class="col-sm-6 text-end">
                    <a href="secciones_listado.php" class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i> Volver al Listado
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="app-content">
        <div class="container-fluid px-4">
            <div class="row">
                <div class="col-md-9 mx-auto">
                    <div class="card card-primary card-outline shadow-sm">
                        <div class="card-header">
                            <h3 class="card-title">Configuración Operativa y Nomenclatura</h3>
                        </div>
                        
                        <div id="alertaErrores" class="alert alert-danger m-3 d-none" role="alert">
                            <i class="bi bi-exclamation-triangle-fill"></i> <strong>Atención:</strong> Por favor complete los campos marcados en rojo antes de guardar.
                        </div>

                        <form action="secciones_procesar.php" method="POST" id="formSeccion" onsubmit="return validarFormulario(event);">
                            <input type="hidden" name="accion" value="<?php echo $modo_edicion ? 'actualizar' : 'registrar'; ?>">
                            <input type="hidden" name="id" value="<?php echo htmlspecialchars($id_edit); ?>">
                            <input type="hidden" id="seccion_actual_edit" value="<?php echo htmlspecialchars($numero_seccion_actual); ?>">

                            <div class="card-body">
                                <div class="alert alert-secondary py-2 mb-3 fw-bold" role="alert">
                                    <i class="bi bi-info-circle-fill"></i> Nomenclatura oficial con control correlativo y cálculo automático de fecha estimada de finalización.
                                </div>

                                <!-- VISTA PREVIA DE LA NOMENCLATURA -->
                                <div class="card bg-dark text-white mb-4 shadow">
                                    <div class="card-body text-center py-3">
                                        <span class="text-uppercase small text-warning d-block mb-1">Vista Previa del Código de Nomenclatura Oficial</span>
                                        <h2 id="previewNomenclatura" class="font-monospace fw-bold mb-0 text-info">---</h2>
                                        <small class="text-muted">[CENTRO]-[CURSO]-[AÑO]-[SECCIÓN]-[ENTE]</small>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label fw-bold" id="lbl_centro_codigo">Centro Penitenciario <span class="text-danger">*</span>:</label>
                                        <select class="form-select" name="centro_codigo" id="centro_codigo" required onchange="limpiarError(this); cargarCursosPorCentro();">
                                            <option value="">Seleccione un centro...</option>
                                            <?php while ($c = pg_fetch_assoc($centros)):$sigla_centro = !empty($c['sigla']) ? trim($c['sigla']) : 'FALTA_SIGLA';
                                            ?>
                                                <option value="<?php echo $c['codigo']; ?>" 
                                                        data-sigla="<?php echo htmlspecialchars($sigla_centro); ?>"
                                                        <?php echo ($centro_codigo ==$c['codigo']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($c['nombre'] . " (" . $sigla_centro . ")"); ?>
                                                </option>
                                            <?php endwhile; ?>
                                        </select>
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label class="form-label fw-bold" id="lbl_curso_id">Curso Asignado <span class="text-danger">*</span>:</label>
                                        <select class="form-select" name="curso_id" id="curso_id" required onchange="limpiarError(this); cargarEntesPorCurso(); cargarSeccionesDisponibles(); actualizarFechaFin();">
                                            <option value="">Seleccione un curso...</option>
                                            <?php if ($cursos && pg_num_rows($cursos) > 0): ?>
                                                <?php while ($cu = pg_fetch_assoc($cursos)): ?>
                                                    <option value="<?php echo $cu['id']; ?>" 
                                                            data-sigla="<?php echo htmlspecialchars($cu['sigla'] ?? 'CURSO'); ?>"
                                                            data-horas="<?php echo intval($cu['duracion_horas'] ?? 40); ?>"
                                                            <?php echo ($curso_id ==$cu['id']) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($cu['nombre_curso']); ?> (<?php echo htmlspecialchars($cu['sigla'] ?? 'S/N'); ?> - <?php echo intval($cu['duracion_horas'] ?? 40); ?> hrs)
                                                    </option>
                                                <?php endwhile; ?>
                                            <?php endif; ?>
                                        </select>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label fw-bold" id="lbl_rif_nit">Ente Educativo Impartidor <span class="text-danger">*</span>:</label>
                                        <select class="form-select" name="rif_nit" id="rif_nit" required onchange="limpiarError(this); actualizarNomenclatura()">
                                            <option value="">Seleccione el ente...</option>
                                            <?php if ($entes && pg_num_rows($entes) > 0): ?>
                                                <?php while ($e = pg_fetch_assoc($entes)): ?>
                                                    <option value="<?php echo $e['rif_nit']; ?>" 
                                                            data-sigla="<?php echo htmlspecialchars($e['sigla'] ?? 'EXT'); ?>"
                                                            <?php echo ($rif_nit ==$e['rif_nit']) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($e['nombre_ente']); ?> (<?php echo htmlspecialchars($e['sigla'] ?? ''); ?>)
                                                    </option>
                                                <?php endwhile; ?>
                                            <?php endif; ?>
                                        </select>
                                    </div>

                                    <div class="col-md-3 mb-3">
                                        <label class="form-label fw-bold" id="lbl_anio">Año Académico <span class="text-danger">*</span>:</label>
                                        <input type="number" class="form-control" name="anio" id="anio" required min="2026" max="2035" 
                                               value="<?php echo htmlspecialchars($anio); ?>" onkeyup="limpiarError(this); actualizarNomenclatura()" onchange="limpiarError(this); actualizarNomenclatura()">
                                    </div>

                                    <div class="col-md-3 mb-3">
                                        <label class="form-label fw-bold" id="lbl_numero_seccion">N° de Sección <span class="text-danger">*</span>:</label>
                                        <select class="form-select fw-bold text-primary" name="numero_seccion" id="numero_seccion" required onchange="limpiarError(this); actualizarNomenclatura()">
                                            <option value="">Seleccione centro y curso...</option>
                                            <?php if ($modo_edicion && !empty($numero_seccion_actual)): ?>
                                                <option value="<?php echo $numero_seccion_actual; ?>" selected>Sección <?php echo $numero_seccion_actual; ?> (Actual)</option>
                                            <?php endif; ?>
                                        </select>
                                    </div>
                                </div>

                                <hr class="my-4">
                                <h5 class="text-primary mb-3"><i class="bi bi-calendar-check"></i> Programación, Horario y Duración</h5>

                                <div class="mb-3">
                                    <label class="form-label fw-bold" id="lbl_dias">Días de la Semana <span class="text-danger">*</span> (Seleccione al menos uno):</label>
                                    <div class="row g-2 border p-2 rounded bg-light" id="contenedor_dias">
                                        <?php foreach ($dias_disponibles as$dia): ?>
                                            <div class="col-auto">
                                                <div class="form-check p-2">
                                                    <input class="form-check-input checkbox-dia" type="checkbox" name="dias[]" value="<?php echo $dia; ?>" id="dia_<?php echo $dia; ?>" 
                                                        <?php echo in_array($dia,$dias_seleccionados) ? 'checked' : ''; ?> onchange="limpiarErrorDias(); actualizarFechaFin();">
                                                    <label class="form-check-label fw-semibold" for="dia_<?php echo $dia; ?>">
                                                        <?php echo $dia; ?>
                                                    </label>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label fw-bold">Hora Desde <span class="text-danger">*</span>:</label>
                                        <select class="form-select" name="hora_desde" id="hora_desde" required onchange="actualizarFechaFin()">
                                            <?php foreach ($horas_opciones as$hora): ?>
                                                <option value="<?php echo $hora; ?>" <?php echo ($hora_desde ==$hora) ? 'selected' : ''; ?>>
                                                    <?php echo $hora; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="col-md-3 mb-3">
                                        <label class="form-label fw-bold">Hora Hasta <span class="text-danger">*</span>:</label>
                                        <select class="form-select" name="hora_hasta" id="hora_hasta" required onchange="actualizarFechaFin()">
                                            <?php foreach ($horas_opciones as$hora): ?>
                                                <option value="<?php echo $hora; ?>" <?php echo ($hora_hasta ==$hora) ? 'selected' : ''; ?>>
                                                    <?php echo $hora; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="col-md-3 mb-3">
                                        <label class="form-label fw-bold" id="lbl_fecha_inicio">Fecha de Inicio <span class="text-danger">*</span>:</label>
                                        <input type="date" class="form-control" name="fecha_inicio" id="fecha_inicio" required value="<?php echo htmlspecialchars($fecha_inicio); ?>" onchange="limpiarError(this); actualizarFechaFin();">
                                    </div>

                                    <!-- Campo Informativo de Fecha de Finalización Aproximada -->
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label fw-bold text-success"><i class="bi bi-calendar-event"></i> Fecha Fin Aproximada:</label>
                                        <input type="date" class="form-control bg-light fw-bold text-success" name="fecha_fin_aprox" id="fecha_fin_aprox" readonly title="Calculado automáticamente según las horas del curso y días seleccionados">
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label fw-bold" id="lbl_capacidad_maxima">Capacidad Máxima <span class="text-danger">*</span>:</label>
                                        <input type="number" class="form-control" name="capacidad_maxima" id="capacidad_maxima" required min="1" max="100"
                                               value="<?php echo htmlspecialchars($capacidad_maxima); ?>" onkeyup="limpiarError(this)">
                                    </div>

                                    <div class="col-md-8 mb-3">
                                        <label class="form-label fw-bold">Observaciones / Aula:</label>
                                        <input type="text" class="form-control" name="observaciones" 
                                               value="<?php echo htmlspecialchars($observaciones); ?>" placeholder="Detalles adicionales...">
                                    </div>
                                </div>

                            </div>

                            <div class="card-footer text-end">
                                <a href="secciones_listado.php" class="btn btn-secondary me-2">Cancelar</a>
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-check-circle"></i> <?php echo $modo_edicion ? 'Actualizar Sección' : 'Guardar y Aperturar Sección'; ?>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
// Función para calcular automáticamente la fecha de fin aproximada
function actualizarFechaFin() {
    const cursoSelect = document.getElementById('curso_id');
    const fechaInicioInput = document.getElementById('fecha_inicio').value;
    const fechaFinInput = document.getElementById('fecha_fin_aprox');

    if (!cursoSelect.selectedIndex || !fechaInicioInput) {
        fechaFinInput.value = '';
        return;
    }

    const selectedOption = cursoSelect.options[cursoSelect.selectedIndex];
    const duracionHoras = parseInt(selectedOption.getAttribute('data-horas') || '40');

    // Contar cuántos días de la semana están seleccionados
    const diasSeleccionados = document.querySelectorAll('.checkbox-dia:checked').length;
    if (diasSeleccionados === 0) {
        fechaFinInput.value = '';
        return;
    }

    // Calcular horas diarias estimadas (diferencia entre hora desde y hora hasta)
    // Por simplicidad, asumimos un promedio de 4 horas por clase si hay múltiples días, o calculamos el bloque.
    // O si prefieres un cálculo basado en 4 horas por sesión:
    const horasPorSesion = 4; // Puedes ajustar este estimado estándar o calcularlo de los selects de hora
    const sesionesTotales = Math.ceil(duracionHoras / horasPorSesion);

    // Calcular fecha sumando semanas y días hábiles aproximados
    let fechaInicio = new Date(fechaInicioInput + 'T00:00:00');
    let sesionesAcumuladas = 0;
    let diasTotales = 0;

    // Bucle para estimar la fecha sumando días según los días de la semana activos
    while (sesionesAcumuladas < sesionesTotales && diasTotales < 365) {
        fechaInicio.setDate(fechaInicio.getDate() + 1);
        diasTotales++;

        // Nombre del día en español (aproximado por getDay)
        let diaSemanaNum = fechaInicio.getDay(); // 0: Domingo, 1: Lunes, ... 6: Sábado
        let diasMap = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
        let nombreDia = diasMap[diaSemanaNum];

        // Verificar si este día fue seleccionado en el formulario
        let checkboxDia = document.getElementById('dia_' + nombreDia);
        if (checkboxDia && checkboxDia.checked) {
            sesionesAcumuladas++;
        }
    }

    // Formatear a YYYY-MM-DD para el input date
    let anioF = fechaInicio.getFullYear();
    let mesF = String(fechaInicio.getMonth() + 1).padStart(2, '0');
    let diaF = String(fechaInicio.getDate()).padStart(2, '0');

    fechaFinInput.value = `${anioF}-${mesF}-${diaF}`;
}

function limpiarError(elemento) {
    elemento.classList.remove('is-invalid');
    const label = document.getElementById('lbl_' + elemento.id);
    if (label) label.classList.remove('text-danger');
}

function limpiarErrorDias() {
    document.getElementById('contenedor_dias').classList.remove('border-danger', 'bg-danger-subtle');
    document.getElementById('lbl_dias').classList.remove('text-danger');
}

function validarFormulario(event) {
    let camposValidar = ['centro_codigo', 'curso_id', 'rif_nit', 'anio', 'numero_seccion', 'fecha_inicio', 'capacidad_maxima'];
    let formValido = true;
    let primerElementoError = null;

    camposValidar.forEach(id => {
        let campo = document.getElementById(id);
        let label = document.getElementById('lbl_' + id);
        if (!campo || !campo.value.trim()) {
            formValido = false;
            if (campo) {
                campo.classList.add('is-invalid');
                if (!primerElementoError) primerElementoError = campo;
            }
            if (label) label.classList.add('text-danger');
        } else {
            if (campo) campo.classList.remove('is-invalid');
            if (label) label.classList.remove('text-danger');
        }
    });

    let diasSeleccionados = document.querySelectorAll('.checkbox-dia:checked');
    let contenedorDias = document.getElementById('contenedor_dias');
    let lblDias = document.getElementById('lbl_dias');
    if (diasSeleccionados.length === 0) {
        formValido = false;
        contenedorDias.classList.add('border', 'border-danger', 'bg-danger-subtle');
        lblDias.classList.add('text-danger');
        if (!primerElementoError) primerElementoError = contenedorDias;
    } else {
        contenedorDias.classList.remove('border-danger', 'bg-danger-subtle');
        lblDias.classList.remove('text-danger');
    }

    if (!formValido) {
        event.preventDefault();
        document.getElementById('alertaErrores').classList.remove('d-none');
        if (primerElementoError) {
            primerElementoError.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        return false;
    }

    document.getElementById('alertaErrores').classList.add('d-none');
    return true;
}

function cargarCursosPorCentro() {
    const centroCodigo = document.getElementById('centro_codigo').value;
    const selectCurso = document.getElementById('curso_id');
    const selectEnte = document.getElementById('rif_nit');
    
    selectCurso.innerHTML = '<option value="">Cargando cursos...</option>';
    selectCurso.disabled = true;
    selectEnte.innerHTML = '<option value="">Seleccione un curso primero...</option>';
    selectEnte.disabled = true;

    if (!centroCodigo) {
        selectCurso.innerHTML = '<option value="">Primero seleccione un centro...</option>';
        document.getElementById('numero_seccion').innerHTML = '<option value="">Seleccione centro y curso primero...</option>';
        actualizarNomenclatura();
        return;
    }

    fetch(`obtener_cursos_centro.php?centro_codigo=${encodeURIComponent(centroCodigo)}&tipo=cursos`)
        .then(response => response.json())
        .then(data => {
            selectCurso.innerHTML = '<option value="">Seleccione un curso...</option>';
            if (data.length > 0) {
                data.forEach(curso => {
                    const option = document.createElement('option');
                    option.value = curso.id;
                    option.setAttribute('data-sigla', curso.sigla);
                    option.setAttribute('data-horas', curso.duracion_horas);
                    option.textContent = `${curso.nombre_curso} (${curso.sigla} - ${curso.duracion_horas} hrs)`;
                    selectCurso.appendChild(option);
                });
                selectCurso.disabled = false;
            } else {
                selectCurso.innerHTML = '<option value="">No hay cursos asignados a este centro</option>';
            }
            cargarSeccionesDisponibles();
        })
        .catch(error => {
            console.error('Error:', error);
            selectCurso.innerHTML = '<option value="">Error al cargar cursos</option>';
            selectCurso.disabled = false;
        });
}

function cargarEntesPorCurso() {
    const centroCodigo = document.getElementById('centro_codigo').value;
    const cursoId = document.getElementById('curso_id').value;
    const selectEnte = document.getElementById('rif_nit');

    selectEnte.innerHTML = '<option value="">Cargando ente impartidor...</option>';
    selectEnte.disabled = true;

    if (!centroCodigo || !cursoId) {
        selectEnte.innerHTML = '<option value="">Seleccione un curso primero...</option>';
        return;
    }

    fetch(`obtener_cursos_centro.php?centro_codigo=${encodeURIComponent(centroCodigo)}&curso_id=${encodeURIComponent(cursoId)}&tipo=entes`)
        .then(response => response.json())
        .then(data => {
            selectEnte.innerHTML = '<option value="">Seleccione el ente impartidor...</option>';
            if (data.length > 0) {
                data.forEach(ente => {
                    const option = document.createElement('option');
                    option.value = ente.rif_nit;
                    option.setAttribute('data-sigla', ente.sigla);
                    option.textContent = `${ente.nombre_ente} (${ente.sigla})`;
                    selectEnte.appendChild(option);
                });
                selectEnte.disabled = false;
            } else {
                selectEnte.innerHTML = '<option value="">No hay entes registrados para este curso en este centro</option>';
            }
            actualizarNomenclatura();
        })
        .catch(error => {
            console.error('Error al cargar entes:', error);
            selectEnte.innerHTML = '<option value="">Error al cargar entes</option>';
            selectEnte.disabled = false;
        });
}

function cargarSeccionesDisponibles() {
    const centroCodigo = document.getElementById('centro_codigo').value;
    const cursoId = document.getElementById('curso_id').value;
    const selectSeccion = document.getElementById('numero_seccion');
    const seccionActualEdit = document.getElementById('seccion_actual_edit').value;

    if (!centroCodigo || !cursoId) {
        selectSeccion.innerHTML = '<option value="">Seleccione centro y curso primero...</option>';
        actualizarNomenclatura();
        return;
    }

    fetch(`obtener_cursos_centro.php?centro_codigo=${encodeURIComponent(centroCodigo)}&curso_id=${encodeURIComponent(cursoId)}&tipo=secciones_ocupadas`)
        .then(response => response.json())
        .then(data => {
            const ocupadas = data.ocupadas || [];
            const siguientePermitido = data.siguiente_permitido || 1;
            
            let htmlOptions = '<option value="">Seleccione sección...</option>';
            
            for (let n = 1; n <= 20; n++) {
                let valStr = String(n).padStart(2, '0');
                let estaOcupada = ocupadas.includes(n);
                
                if (document.getElementById('formSeccion').querySelector('[name=accion]').value === 'actualizar' && valStr === seccionActualEdit) {
                    htmlOptions += `<option value="${valStr}" selected>Sección ${valStr} (Actual)</option>`;
                } else if (estaOcupada) {
                    htmlOptions += `<option value="${valStr}" disabled class="text-muted">Sección ${valStr} (Ya registrada)</option>`;
                } else if (n === siguientePermitido) {
                    htmlOptions += `<option value="${valStr}" class="fw-bold text-success">Sección ${valStr} (Disponible)</option>`;
                } else {
                    htmlOptions += `<option value="${valStr}" disabled class="text-danger">Sección ${valStr} (Debe crear las anteriores)</option>`;
                }
            }
            selectSeccion.innerHTML = htmlOptions;
            actualizarNomenclatura();
        })
        .catch(error => console.error('Error al verificar secuencia:', error));
}

function actualizarNomenclatura() {
    const selCentro = document.getElementById('centro_codigo');
    const siglaCentro = selCentro.selectedIndex > 0 ? selCentro.options[selCentro.selectedIndex].getAttribute('data-sigla') : 'CENTRO';

    const selCurso = document.getElementById('curso_id');
    const siglaCurso = selCurso.selectedIndex > 0 ? selCurso.options[selCurso.selectedIndex].getAttribute('data-sigla') : 'CURSO';

    const anio = document.getElementById('anio').value.trim() || '2026';
    const seccion = document.getElementById('numero_seccion').value.trim() || '01';

    const selEnte = document.getElementById('rif_nit');
    const siglaEnte = selEnte.selectedIndex > 0 ? selEnte.options[selEnte.selectedIndex].getAttribute('data-sigla') : 'ENTE';

    const nomenclatura = `${siglaCentro}-${siglaCurso}-${anio}-${seccion}-${siglaEnte}`;
    document.getElementById('previewNomenclatura').innerText = nomenclatura;
}

window.onload = function() {
    if (document.getElementById('centro_codigo').value && document.getElementById('curso_id').value) {
        cargarSeccionesDisponibles();
    }
    actualizarNomenclatura();
    actualizarFechaFin();
};
</script>

<?php 
if (isset($conexion)) { pg_close($conexion); }
require_once 'footer.php'; 
?>