<?php
session_start();
require_once 'header.php';
require_once 'conexion.php';
?>

<main class="app-main">
    <div class="app-content-header">
        <div class="container-fluid">
            <h3 class="mb-0">Carga Histórica de Redenciones</h3>
        </div>
    </div>
    <div class="app-content">
        <div class="container-fluid">
            <div class="row justify-content-center">
                <div class="col-md-8">
                    <div class="card card-outline card-primary">
                        <div class="card-header">
                            <h3 class="card-title">Calcular Redención Retroactiva</h3>
                        </div>
                        <div class="card-body">
                            <p class="text-danger">
                                <i class="bi bi-exclamation-triangle-fill"></i> <strong>Atención:</strong> Este módulo es para cargar datos manuales (antiguos). Úselo una sola vez por privado.
                            </p>
                            
                            <form action="procesar_carga_historica.php" method="POST">
                                
                                <div class="mb-3">
                                    <label for="buscador_privado" class="form-label">Buscar Privado (Por Cédula o Nombre)</label>
                                    <input type="text" class="form-control" id="buscador_privado" placeholder="Escriba aquí para buscar...">
                                    
                                    <div id="resultados_busqueda" class="list-group mt-1"></div> 
                                </div>
                                
                                <input type="hidden" id="registro_id" name="registro_id">
                                
                                <fieldset id="formulario_datos" disabled>

                                    <div class="row">
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">Inicio Laboral:</label>
                                            <input type="text" id="fecha_inicio_display" class="form-control" readonly>
                                        </div>
                                        <div class="col-md-8 mb-3">
                                            <label class="form-label">Días Laborales:</label>
                                            <input type="text" id="dias_laborales_display" class="form-control" readonly>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label for="fecha_corte" class="form-label">Fecha de Corte (Fin del Conteo Manual)</label>
                                        <input type="date" class="form-control" id="fecha_corte" name="fecha_corte" required>
                                    </div>
                                    
                                    <hr class="my-4">
                                    <h5 class="text-danger">Total de Excepciones (Datos Manuales)</h5>

                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label for="total_ausente" class="form-label">Total Días Ausente (Injustificado)</label>
                                            <input type="number" class="form-control" id="total_ausente" name="total_ausente" min="0" value="0" required>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label for="total_reposo" class="form-label">Total Días Reposo/Permiso (Justificado)</label>
                                            <input type="number" class="form-control" id="total_reposo" name="total_reposo" min="0" value="0" required>
                                        </div>
                                    </div>

                                    <button type="submit" class="btn btn-primary w-100 mt-3">Calcular y Guardar Carga Histórica</button>
                                
                                </fieldset> </form>
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
    const formularioDatos = document.getElementById('formulario_datos');
    
    // Inputs del formulario
    const registroIdInput = document.getElementById('registro_id');
    const fechaInicioInput = document.getElementById('fecha_inicio_display');
    const diasLaboralesInput = document.getElementById('dias_laborales_display');

    buscador.addEventListener('keyup', async function() {
        const term = this.value;
        resultadosDiv.innerHTML = ''; // Limpiar resultados anteriores
        
        if (term.length < 2) { // No buscar si hay menos de 2 caracteres
            return; 
        }

        // 1. Buscar en el nuevo archivo PHP
        const response = await fetch(`buscar_privado_laboral.php?term=${term}`);
        const resultados = await response.json();

        // 2. Mostrar los resultados
        if (resultados.length > 0) {
            resultados.forEach(privado => {
                const item = document.createElement('a');
                item.href = '#';
                item.className = 'list-group-item list-group-item-action';
                item.textContent = `${privado.nombre_completo} (C.I: ${privado.documento_identidad})`;
                
                // Añadimos un 'listener' a cada resultado
                item.addEventListener('click', function(e) {
                    e.preventDefault(); // Evitar que el enlace recargue la página
                    
                    // 3. Rellenar el formulario al hacer clic
                    buscador.value = `${privado.nombre_completo}`; // Poner el nombre en el buscador
                    registroIdInput.value = privado.registro_id;
                    fechaInicioInput.value = privado.fecha_inicio_labor;
                    diasLaboralesInput.value = privado.dias_laborales;
                    
                    // 4. Habilitar el resto del formulario
                    formularioDatos.disabled = false;
                    
                    // 5. Limpiar y ocultar los resultados
                    resultadosDiv.innerHTML = ''; 
                });
                
                resultadosDiv.appendChild(item);
            });
        } else {
            resultadosDiv.innerHTML = '<span class="list-group-item text-muted">No se encontraron resultados.</span>';
        }
    });
});
</script>

<?php
// No cerramos la conexión porque el script de búsqueda (AJAX) necesita abrirla por separado.
require_once 'footer.php';
?>