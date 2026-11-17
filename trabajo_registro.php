<?php
session_start();
require_once 'header.php';
require_once 'conexion.php'; 
?>

<main class="app-main">
    <div class="app-content-header">
        <div class="container-fluid">
            <h3 class="mb-0 text-center">Registro de Información Laboral</h3>
        </div>
    </div>
    <div class="app-content">
        <div class="container-fluid">
            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <div class="card card-outline card-primary">
                        <div class="card-body">
                            <form id="formulario_trabajo" action="procesar_trabajo_registro.php" method="post">
                                
                                <p class="text-end text-muted fst-italic">
                                    Los campos marcados con <span class="text-danger">*</span> son obligatorios.
                                </p>

                                <div class="mb-3">
                                    <label for="buscador_privado" class="form-label">Buscar Privado (Cédula o Nombre) <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="buscador_privado" placeholder="Escriba aquí para buscar..." required>
                                    <div id="resultados_busqueda" class="list-group mt-1"></div> 
                                </div>
                                
                                <input type="hidden" id="privado_documento" name="privado_documento">

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="fecha_inicio_labor" class="form-label">Fecha de Inicio Laboral <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" id="fecha_inicio_labor" name="fecha_inicio_labor" required>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Días Laborales <span class="text-danger">*</span></label>
                                        <div id="dias_laborales_group" class="d-flex flex-wrap">
                                            <div class="form-check form-check-inline me-3"><input class="form-check-input" type="checkbox" name="dias_laborales[]" value="L" id="dia_L"><label class="form-check-label" for="dia_L">Lunes</label></div>
                                            <div class="form-check form-check-inline me-3"><input class="form-check-input" type="checkbox" name="dias_laborales[]" value="M" id="dia_M"><label class="form-check-label" for="dia_M">Martes</label></div>
                                            <div class="form-check form-check-inline me-3"><input class="form-check-input" type="checkbox" name="dias_laborales[]" value="X" id="dia_X"><label class="form-check-label" for="dia_X">Miércoles</label></div>
                                            <div class="form-check form-check-inline me-3"><input class="form-check-input" type="checkbox" name="dias_laborales[]" value="J" id="dia_J"><label class="form-check-label" for="dia_J">Jueves</label></div>
                                            <div class="form-check form-check-inline me-3"><input class="form-check-input" type="checkbox" name="dias_laborales[]" value="V" id="dia_V"><label class="form-check-label" for="dia_V">Viernes</label></div>
                                            <div class="form-check form-check-inline me-3"><input class="form-check-input" type="checkbox" name="dias_laborales[]" value="S" id="dia_S"><label class="form-check-label" for="dia_S">Sábado</label></div>
                                            <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="dias_laborales[]" value="D" id="dia_D"><label class="form-check-label" for="dia_D">Domingo</label></div>
                                        </div>
                                        <small id="dias_error" class="text-danger d-none">Por favor, seleccione al menos un día.</small>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="horario_desde" class="form-label">Horario Desde <span class="text-danger">*</span></label>
                                        <input type="time" class="form-control" id="horario_desde" name="horario_desde" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="horario_hasta" class="form-label">Horario Hasta <span class="text-danger">*</span></label>
                                        <input type="time" class="form-control" id="horario_hasta" name="horario_hasta" required>
                                    </div>
                                </div>

                                <button type="submit" class="btn btn-primary">Registrar Trabajo</button>
                                <a href="trabajo_listar.php" class="btn btn-secondary">Cancelar</a>
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
    
    const buscador = document.getElementById('buscador_privado');
    const resultadosDiv = document.getElementById('resultados_busqueda');
    const hiddenInput = document.getElementById('privado_documento');

    buscador.addEventListener('keyup', async function() {
        const term = this.value;
        resultadosDiv.innerHTML = ''; 
        hiddenInput.value = ''; 
        buscador.classList.remove('is-valid', 'is-invalid');

        if (term.length < 2) return; 

        try {
            const response = await fetch(`buscar_privado_para_trabajo.php?term=${term}`);
            const resultados = await response.json();

            if (resultados.length > 0) {
                resultados.forEach(privado => {
                    const item = document.createElement('a');
                    item.href = '#';
                    item.className = 'list-group-item list-group-item-action';
                    item.textContent = privado.texto_visible; 
                    
                    item.addEventListener('click', function(e) {
                        e.preventDefault();
                        buscador.value = privado.texto_visible;
                        hiddenInput.value = privado.documento_identidad;
                        resultadosDiv.innerHTML = '';
                        buscador.classList.add('is-valid');
                    });
                    resultadosDiv.appendChild(item);
                });
            } else {
                resultadosDiv.innerHTML = '<span class="list-group-item text-muted">No se encontraron privados o ya tienen un trabajo activo.</span>';
            }
        } catch (error) {
            console.error("Error en el buscador:", error);
            resultadosDiv.innerHTML = '<span class="list-group-item text-danger">Error al cargar resultados.</span>';
        }
    });

    const form = document.getElementById('formulario_trabajo');
    const diasError = document.getElementById('dias_error');

    form.addEventListener('submit', function(e) {
        let esValido = true;
        
        if (hiddenInput.value === '') {
            e.preventDefault();
            buscador.classList.add('is-invalid');
            esValido = false;
        }

        const diasChecks = document.querySelectorAll('input[name="dias_laborales[]"]:checked');
        if (diasChecks.length === 0) {
            e.preventDefault();
            diasError.classList.remove('d-none');
            esValido = false;
        } else {
            diasError.classList.add('d-none');
        }
    });
});
</script>

<?php 
if (isset($conexion)) { pg_close($conexion); }
require_once 'footer.php';
?>