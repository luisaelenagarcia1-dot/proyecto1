<?php
session_start();
require_once 'header.php';
require_once 'conexion.php';

$id_curso = $_GET['id'] ?? '';
$modo_edicion = !empty($id_curso);

// Obtener el siguiente ID autoincrementable de la base de datos para los nuevos registros
$siguiente_id = 1;
$res_max = pg_query($conexion, "SELECT COALESCE(MAX(id), 0) + 1 AS sig_id FROM cursos");
if ($res_max && $row_m = pg_fetch_assoc($res_max)) {
    $siguiente_id = intval($row_m['sig_id']);
}
$siguiente_id_pad = str_pad($siguiente_id, 2, '0', STR_PAD_LEFT);

// Valores por defecto
$sigla        = "FOR";
$nivel        = "BASICO"; // Valor por defecto para el nivel
$codigo_curso = "CURSO-FOR-BAS-" . $siguiente_id_pad;
$nombre_curso = "FORMACIÓN SOCIOPRODUCTIVA BÁSICA";
$descripcion  = "CURSO ORIENTADO AL DESARROLLO DE COMPETENCIAS TÉCNICAS Y PRODUCTIVAS PARA LA INSERCIÓN LABORAL.";

if ($modo_edicion) {
    $res = pg_query_params($conexion, "SELECT * FROM cursos WHERE id = $1", array($id_curso));
    if ($res && pg_num_rows($res) > 0) {
        $curso = pg_fetch_assoc($res);
        $codigo_curso = strtoupper($curso['codigo_curso']);
        $nombre_curso = strtoupper($curso['nombre_curso']);
        $sigla        = strtoupper($curso['sigla'] ?? '');
        $nivel        = strtoupper($curso['nivel'] ?? 'BASICO');
        $descripcion  = strtoupper($curso['descripcion']);
    } else {
        $_SESSION['mensaje_error'] = "❌ El curso seleccionado no existe.";
        header("Location: cursos_listado.php");
        exit();
    }
}
?>

<main class="app-main">
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h3 class="mb-0">
                        <i class="bi bi-journal-text"></i> 
                        <?php echo $modo_edicion ? 'Editar Curso' : 'Registrar Nuevo Curso'; ?>
                    </h3>
                </div>
                <div class="col-sm-6 text-end">
                    <a href="cursos_listado.php" class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i> Volver al Catálogo
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="app-content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-md-8 mx-auto">
                    <div class="card card-primary card-outline">
                        <div class="card-header">
                            <h3 class="card-title"><?php echo $modo_edicion ? 'Modificar datos del Curso' : 'Formulario de Catálogo'; ?></h3>
                        </div>
                        
                        <form action="cursos_procesar.php" method="POST" id="formCurso">
                            <input type="hidden" name="accion" value="<?php echo $modo_edicion ? 'actualizar' : 'registrar'; ?>">
                            <input type="hidden" name="id_curso" value="<?php echo htmlspecialchars($id_curso); ?>">

                            <div class="card-body">
                                <div class="alert alert-danger py-2 mb-3 fw-bold" role="alert">
                                    <i class="bi bi-exclamation-triangle-fill"></i> Todos los campos con * son obligatorios.
                                </div>

                                <!-- 1. Nombre del Curso -->
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Nombre del Curso <span class="text-danger">*</span>:</label>
                                    <input type="text" class="form-control text-uppercase" name="nombre_curso" id="nombre_curso" required 
                                           style="text-transform: uppercase;"
                                           oninput="this.value = this.value.toUpperCase();"
                                           onblur="verificarDuplicado('nombre_curso', this.value)"
                                           value="<?php echo htmlspecialchars($nombre_curso); ?>" placeholder="EJ: CARPINTERÍA">
                                    <div id="error_nombre_curso" class="invalid-feedback fw-bold"></div>
                                </div>

                                <div class="row">
                                    <!-- 2. Sigla Oficial -->
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label fw-bold">Sigla Oficial <span class="text-danger">*</span>:</label>
                                        <input type="text" class="form-control text-uppercase font-monospace fw-bold text-primary" name="sigla" id="sigla" required maxlength="10" 
                                               style="text-transform: uppercase;"
                                               oninput="this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, ''); autogenerarCodigo();"
                                               onblur="verificarDuplicado('sigla', this.value)"
                                               value="<?php echo htmlspecialchars($sigla); ?>" placeholder="EJ: CARP">
                                        <div id="error_sigla" class="invalid-feedback fw-bold"></div>
                                        <small class="text-muted">Única en el sistema.</small>
                                    </div>

                                    <!-- 3. Nivel del Curso -->
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label fw-bold">Nivel <span class="text-danger">*</span>:</label>
                                        <select class="form-select fw-bold text-dark" name="nivel" id="nivel" required onchange="autogenerarCodigo()">
                                            <option value="BASICO" <?php echo ($nivel === 'BASICO') ? 'selected' : ''; ?>>BÁSICO</option>
                                            <option value="MEDIO" <?php echo ($nivel === 'MEDIO') ? 'selected' : ''; ?>>MEDIO</option>
                                            <option value="AVANZADO" <?php echo ($nivel === 'AVANZADO') ? 'selected' : ''; ?>>AVANZADO</option>
                                        </select>
                                    </div>

                                    <!-- 4. Código del Curso -->
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label fw-bold">Código del Curso <span class="text-danger">*</span>:</label>
                                        <input type="text" class="form-control text-uppercase bg-light font-monospace fw-bold text-secondary" name="codigo_curso" id="codigo_curso" required 
                                               style="text-transform: uppercase;"
                                               oninput="this.value = this.value.toUpperCase();"
                                               value="<?php echo htmlspecialchars($codigo_curso); ?>" placeholder="EJ: CURSO-CARP-BAS-01">
                                        <small class="text-muted">Único en el sistema.</small>
                                    </div>
                                </div>

                                <!-- 5. Descripción -->
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Descripción / Contenido Programático:</label>
                                    <textarea class="form-control text-uppercase" name="descripcion" rows="4" 
                                              style="text-transform: uppercase;"
                                              oninput="this.value = this.value.toUpperCase();"
                                              placeholder="BREVE DESCRIPCIÓN DE LOS OBJETIVOS..."><?php echo htmlspecialchars($descripcion); ?></textarea>
                                </div>
                            </div>

                            <div class="card-footer text-end">
                                <a href="cursos_listado.php" class="btn btn-secondary me-2">Cancelar</a>
                                <button type="submit" class="btn btn-primary" id="btnGuardar">
                                    <i class="bi bi-check-circle"></i> <?php echo $modo_edicion ? 'Actualizar Curso' : 'Guardar Curso'; ?>
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
const esModoEdicion = <?php echo $modo_edicion ? 'true' : 'false'; ?>;
const siguienteIdPad = "<?php echo $siguiente_id_pad; ?>";
const idActual = "<?php echo $id_curso; ?>";

function autogenerarCodigo() {
    if (esModoEdicion) return;
    const siglaInput = document.getElementById('sigla').value.toUpperCase().trim();
    const nivelSelect = document.getElementById('nivel').value;
    const codigoInput = document.getElementById('codigo_curso');
    
    // Extraer las primeras 3 letras del nivel seleccionado (BAS, MED, AVA)
    let nivelCorto = nivelSelect.substring(0, 3);

    if (siglaInput.length > 0) {
        codigoInput.value = "CURSO-" + siglaInput + "-" + nivelCorto + "-" + siguienteIdPad;
    } else {
        codigoInput.value = "";
    }
}

// Función AJAX en tiempo real para verificar duplicados al salir del campo (onblur)
function verificarDuplicado(campo, valor) {
    if (!valor.trim()) return;

    fetch(`cursos_verificar.php?campo=${campo}&valor=${encodeURIComponent(valor)}&id_actual=${idActual}`)
        .then(response => response.json())
        .then(data => {
            const inputElem = document.getElementById(campo);
            const errorDiv = document.getElementById('error_' + campo);
            const btnGuardar = document.getElementById('btnGuardar');

            if (data.existe) {
                inputElem.classList.add('is-invalid');
                errorDiv.innerHTML = data.mensaje;
                errorDiv.style.display = 'block';
                btnGuardar.disabled = true; 
            } else {
                inputElem.classList.remove('is-invalid');
                errorDiv.style.display = 'none';
                btnGuardar.disabled = false; 
            }
        })
        .catch(error => console.error('Error en la verificación AJAX:', error));
}
</script>

<?php 
if (isset($conexion)) { pg_close($conexion); }
require_once 'footer.php'; 
?>