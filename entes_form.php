<?php
session_start();
require_once 'header.php';
require_once 'conexion.php';

$rif_url =$_GET['rif'] ?? '';
$modo_edicion = !empty($rif_url);

// Valores por defecto
$nombre_ente      = "Instituto Nacional de Capacitación y Educación Socialista (INCES)";
$sigla            = "INCES"; 
$tipo_rif         = "G";
$numero_rif       = "20001234-5";
$contacto_persona = "Gerencia General de Formación Profesional";
$email            = "formacion@inces.gob.ve";

$prefijo_tel1 = "0212";
$num_tel1     = "5551234";
$prefijo_tel2 = "0424";
$num_tel2     = "";
$prefijo_tel3 = "0212";
$num_tel3     = "";

if ($modo_edicion) {
    $res = pg_query_params($conexion, "SELECT * FROM entes_educativos WHERE rif_nit = $1", array($rif_url));
    if ($res && pg_num_rows($res) > 0) {
        $ente = pg_fetch_assoc($res);
        $nombre_ente      =$ente['nombre_ente'];
        $sigla            =$ente['sigla'] ?? ''; 
        $contacto_persona =$ente['contacto_persona'] ?? $ente['persona_contacto'] ?? '';$email            = $ente['email'] ?? '';$partes_rif = explode('-', $ente['rif_nit']);$tipo_rif   = $partes_rif[0] ?? 'G';$numero_rif = $partes_rif[1] ?? $ente['rif_nit'];

        $telefonos_guardados = explode(' / ',$ente['telefono']);
        if (isset($telefonos_guardados[0]) && !empty($telefonos_guardados[0])) {$partes_t1 = explode('-', $telefonos_guardados[0]);$prefijo_tel1 = $partes_t1[0] ?? '0212';$num_tel1     = $partes_t1[1] ?? $telefonos_guardados[0];
        }
        if (isset($telefonos_guardados[1]) && !empty($telefonos_guardados[1])) {$partes_t2 = explode('-', $telefonos_guardados[1]);$prefijo_tel2 = $partes_t2[0] ?? '0424';$num_tel2     = $partes_t2[1] ?? $telefonos_guardados[1];
        }
        if (isset($telefonos_guardados[2]) && !empty($telefonos_guardados[2])) {
            $partes_t3 = explode('-',$telefonos_guardados[2]);
            $prefijo_tel3 =$partes_t3[0] ?? '0212';
            $num_tel3     =$partes_t3[1] ?? $telefonos_guardados[2]; } } else {$_SESSION['mensaje_error'] = "❌ El ente educativo no existe.";
        header("Location: entes_listado.php");
        exit();
    }
}
?>

<main class="app-main" style="width: 100%; min-height: 100vh;">
    <div class="app-content-header">
        <div class="container-fluid px-4">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h3 class="mb-0">
                        <i class="bi bi-building"></i> 
                        <?php echo $modo_edicion ? 'Editar Ente Educativo' : 'Registrar Nuevo Ente Educativo'; ?>
                    </h3>
                </div>
                <div class="col-sm-6 text-end">
                    <a href="entes_listado.php" class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i> Volver al Listado
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="app-content">
        <div class="container-fluid px-4">
            <div class="row">
                <div class="col-md-8 mx-auto">
                    <div class="card card-primary card-outline shadow-sm">
                        <div class="card-header">
                            <h3 class="card-title"><?php echo $modo_edicion ? 'Modificar datos del Ente' : 'Formulario de Registro'; ?></h3>
                        </div>
                        
                        <form action="entes_procesar.php" method="POST" id="formEnte">
                            <input type="hidden" name="accion" value="<?php echo $modo_edicion ? 'actualizar' : 'registrar'; ?>">
                            <input type="hidden" name="rif_original" value="<?php echo htmlspecialchars($rif_url); ?>">

                            <div class="card-body">
                                <div class="alert alert-danger py-2 mb-3 fw-bold" role="alert">
                                    <i class="bi bi-exclamation-triangle-fill"></i> Los campos marcados con asterisco (<span class="text-danger">*</span>) son obligatorios.
                                </div>

                                <!-- Nombre del Ente y Sigla -->
                                <div class="row">
                                    <div class="col-md-8 mb-3">
                                        <label class="form-label fw-bold">Nombre del Ente <span class="text-danger">*</span>:</label>
                                        <input type="text" class="form-control" name="nombre_ente" required 
                                               value="<?php echo htmlspecialchars($nombre_ente); ?>" placeholder="Nombre de la institución">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label fw-bold">Sigla / Corto <span class="text-danger">*</span>:</label>
                                        <input type="text" class="form-control text-uppercase" name="sigla" required maxlength="20"
                                               value="<?php echo htmlspecialchars($sigla); ?>" placeholder="Ej: INCES">
                                        <small class="text-muted">Para nomenclatura.</small>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label fw-bold">RIF / NIT (Clave Principal) <span class="text-danger">*</span>:</label>
                                    <div class="input-group">
                                        <select class="form-select" name="tipo_rif" id="tipo_rif" style="max-width: 90px;" required onchange="verificarRifDuplicado()">
                                            <option value="G" <?php echo ($tipo_rif == 'G') ? 'selected' : ''; ?>>G</option>
                                            <option value="J" <?php echo ($tipo_rif == 'J') ? 'selected' : ''; ?>>J</option>
                                            <option value="V" <?php echo ($tipo_rif == 'V') ? 'selected' : ''; ?>>V</option>
                                            <option value="E" <?php echo ($tipo_rif == 'E') ? 'selected' : ''; ?>>E</option>
                                        </select>
                                        <input type="text" class="form-control" name="numero_rif" id="numero_rif" required 
                                               value="<?php echo htmlspecialchars($numero_rif); ?>" placeholder="20005556-1" onkeyup="verificarRifDuplicado()">
                                    </div>
                                    <div id="alertaRif" class="form-text text-danger fw-bold mt-1" style="display: none;"></div>
                                    <small class="text-muted">Este número servirá como identificador único de la institución.</small>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-bold">Persona o Coordinación de Contacto <span class="text-danger">*</span>:</label>
                                    <input type="text" class="form-control" name="contacto_persona" required
                                           value="<?php echo htmlspecialchars($contacto_persona); ?>" placeholder="Ej: Coordinación Educativa">
                                </div>

                                <hr class="my-4">
                                <h5 class="text-primary mb-3"><i class="bi bi-telephone"></i> Teléfonos de Contacto (Hasta 3)</h5>

                                <!-- Teléfono 1 -->
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Teléfono Principal (1) <span class="text-danger">*</span>:</label>
                                    <div class="input-group">
                                        <select class="form-select" name="prefijo_tel1" id="prefijo_tel1" style="max-width: 140px;" required onchange="gestionarPrefijo(1)">
                                            <option value="0212" <?php echo ($prefijo_tel1 == '0212') ? 'selected' : ''; ?>>0212 (Caracas)</option>
                                            <option value="0241" <?php echo ($prefijo_tel1 == '0241') ? 'selected' : ''; ?>>0241 (Carabobo)</option>
                                            <option value="0261" <?php echo ($prefijo_tel1 == '0261') ? 'selected' : ''; ?>>0261 (Zulia)</option>
                                            <option value="0276" <?php echo ($prefijo_tel1 == '0276') ? 'selected' : ''; ?>>0276 (Táchira)</option>
                                            <option value="0281" <?php echo ($prefijo_tel1 == '0281') ? 'selected' : ''; ?>>0281 (Anzoátegui)</option>
                                            <option value="0291" <?php echo ($prefijo_tel1 == '0291') ? 'selected' : ''; ?>>0291 (Monagas)</option>
                                            <option value="0424" <?php echo ($prefijo_tel1 == '0424') ? 'selected' : ''; ?>>0424 (Movistar)</option>
                                            <option value="0414" <?php echo ($prefijo_tel1 == '0414') ? 'selected' : ''; ?>>0414 (Movistar)</option>
                                            <option value="0412" <?php echo ($prefijo_tel1 == '0412') ? 'selected' : ''; ?>>0412 (Digitel)</option>
                                            <option value="0426" <?php echo ($prefijo_tel1 == '0426') ? 'selected' : ''; ?>>0426 (Movilnet)</option>
                                            <option value="0416" <?php echo ($prefijo_tel1 == '0416') ? 'selected' : ''; ?>>0416 (Movilnet)</option>
                                            <option value="OTRO" <?php echo (!in_array($prefijo_tel1, ['0212','0241','0261','0276','0281','0291','0424','0414','0412','0426','0416'])) ? 'selected' : ''; ?>>Otro (Manual)</option>
                                        </select>
                                        <input type="text" class="form-control" name="manual_tel1" id="manual_tel1" style="max-width: 90px; display: none;" placeholder="Ej: 0274" value="<?php echo (!in_array($prefijo_tel1, ['0212','0241','0261','0276','0281','0291','0424','0414','0412','0426','0416'])) ? htmlspecialchars($prefijo_tel1) : ''; ?>">
                                        <input type="text" class="form-control" name="num_tel1" required maxlength="8" value="<?php echo htmlspecialchars($num_tel1); ?>" placeholder="5551234">
                                    </div>
                                </div>

                                <!-- Teléfono 2 -->
                                <div class="mb-3">
                                    <label class="form-label">Segundo Teléfono (2) (Opcional):</label>
                                    <div class="input-group">
                                        <select class="form-select" name="prefijo_tel2" id="prefijo_tel2" style="max-width: 140px;" onchange="gestionarPrefijo(2)">
                                            <option value="0212" <?php echo ($prefijo_tel2 == '0212') ? 'selected' : ''; ?>>0212 (Caracas)</option>
                                            <option value="0241" <?php echo ($prefijo_tel2 == '0241') ? 'selected' : ''; ?>>0241 (Carabobo)</option>
                                            <option value="0261" <?php echo ($prefijo_tel2 == '0261') ? 'selected' : ''; ?>>0261 (Zulia)</option>
                                            <option value="0276" <?php echo ($prefijo_tel2 == '0276') ? 'selected' : ''; ?>>0276 (Táchira)</option>
                                            <option value="0281" <?php echo ($prefijo_tel2 == '0281') ? 'selected' : ''; ?>>0281 (Anzoátegui)</option>
                                            <option value="0291" <?php echo ($prefijo_tel2 == '0291') ? 'selected' : ''; ?>>0291 (Monagas)</option>
                                            <option value="0424" <?php echo ($prefijo_tel2 == '0424') ? 'selected' : ''; ?>>0424 (Movistar)</option>
                                            <option value="0414" <?php echo ($prefijo_tel2 == '0414') ? 'selected' : ''; ?>>0414 (Movistar)</option>
                                            <option value="0412" <?php echo ($prefijo_tel2 == '0412') ? 'selected' : ''; ?>>0412 (Digitel)</option>
                                            <option value="0426" <?php echo ($prefijo_tel2 == '0426') ? 'selected' : ''; ?>>0426 (Movilnet)</option>
                                            <option value="0416" <?php echo ($prefijo_tel2 == '0416') ? 'selected' : ''; ?>>0416 (Movilnet)</option>
                                            <option value="OTRO" <?php echo (!empty($num_tel2) && !in_array($prefijo_tel2, ['0212','0241','0261','0276','0281','0291','0424','0414','0412','0426','0416'])) ? 'selected' : ''; ?>>Otro (Manual)</option>
                                        </select>
                                        <input type="text" class="form-control" name="manual_tel2" id="manual_tel2" style="max-width: 90px; display: none;" placeholder="Ej: 0274" value="<?php echo htmlspecialchars($prefijo_tel2); ?>">
                                        <input type="text" class="form-control" name="num_tel2" maxlength="8" value="<?php echo htmlspecialchars($num_tel2); ?>" placeholder="5551234">
                                    </div>
                                </div>

                                <!-- Teléfono 3 -->
                                <div class="mb-3">
                                    <label class="form-label">Tercer Teléfono (3) (Opcional):</label>
                                    <div class="input-group">
                                        <select class="form-select" name="prefijo_tel3" id="prefijo_tel3" style="max-width: 140px;" onchange="gestionarPrefijo(3)">
                                            <option value="0212" <?php echo ($prefijo_tel3 == '0212') ? 'selected' : ''; ?>>0212 (Caracas)</option>
                                            <option value="0241" <?php echo ($prefijo_tel3 == '0241') ? 'selected' : ''; ?>>0241 (Carabobo)</option>
                                            <option value="0261" <?php echo ($prefijo_tel3 == '0261') ? 'selected' : ''; ?>>0261 (Zulia)</option>
                                            <option value="0276" <?php echo ($prefijo_tel3 == '0276') ? 'selected' : ''; ?>>0276 (Táchira)</option>
                                            <option value="0281" <?php echo ($prefijo_tel3 == '0281') ? 'selected' : ''; ?>>0281 (Anzoátegui)</option>
                                            <option value="0291" <?php echo ($prefijo_tel3 == '0291') ? 'selected' : ''; ?>>0291 (Monagas)</option>
                                            <option value="0424" <?php echo ($prefijo_tel3 == '0424') ? 'selected' : ''; ?>>0424 (Movistar)</option>
                                            <option value="0414" <?php echo ($prefijo_tel3 == '0414') ? 'selected' : ''; ?>>0414 (Movistar)</option>
                                            <option value="0412" <?php echo ($prefijo_tel3 == '0412') ? 'selected' : ''; ?>>0412 (Digitel)</option>
                                            <option value="0426" <?php echo ($prefijo_tel3 == '0426') ? 'selected' : ''; ?>>0426 (Movilnet)</option>
                                            <option value="0416" <?php echo ($prefijo_tel3 == '0416') ? 'selected' : ''; ?>>0416 (Movilnet)</option>
                                            <option value="OTRO" <?php echo (!empty($num_tel3) && !in_array($prefijo_tel3, ['0212','0241','0261','0276','0281','0291','0424','0414','0412','0426','0416'])) ? 'selected' : ''; ?>>Otro (Manual)</option>
                                        </select>
                                        <input type="text" class="form-control" name="manual_tel3" id="manual_tel3" style="max-width: 90px; display: none;" placeholder="Ej: 0274" value="<?php echo htmlspecialchars($prefijo_tel3); ?>">
                                        <input type="text" class="form-control" name="num_tel3" maxlength="8" value="<?php echo htmlspecialchars($num_tel3); ?>" placeholder="5551234">
                                    </div>
                                </div>
                                <hr class="my-4">

                                <div class="mb-3">
                                    <label class="form-label fw-bold">Correo Electrónico <span class="text-danger">*</span>:</label>
                                    <!-- Añadido pattern estricto para forzar estructura de correo real en el navegador -->
                                    <input type="email" class="form-control" name="email" required
                                           pattern="[a-zA-Z0-9_]+([.][a-zA-Z0-9_]+)*@[a-zA-Z0-9_]+([.][a-zA-Z0-9_]+)*[.][a-zA-Z]{2,5}"
                                           value="<?php echo htmlspecialchars($email); ?>" placeholder="correo@ejemplo.com">
                                    <small class="text-muted">Ingrese un correo electrónico institucional o personal válido.</small>
                                </div>

                            </div>

                            <div class="card-footer text-end">
                                <a href="entes_listado.php" class="btn btn-secondary me-2">Cancelar</a>
                                <button type="submit" class="btn btn-primary" id="btnGuardar">
                                    <i class="bi bi-check-circle"></i> <?php echo $modo_edicion ? 'Actualizar Ente' : 'Guardar Ente'; ?>
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
function gestionarPrefijo(n) {
    const select = document.getElementById(`prefijo_tel${n}`);
    const inputManual = document.getElementById(`manual_tel${n}`);
    if (select.value === 'OTRO') {
        inputManual.style.display = 'block';
        inputManual.required = true;
        inputManual.focus();
    } else {
        inputManual.style.display = 'none';
        inputManual.required = false;
        inputManual.value = '';
    }
}

function verificarRifDuplicado() {
    const tipo = document.getElementById('tipo_rif').value;
    const num = document.getElementById('numero_rif').value.trim();
    const rifOriginal = "<?php echo htmlspecialchars($rif_url); ?>";
    const alertaDiv = document.getElementById('alertaRif');
    const btnGuardar = document.getElementById('btnGuardar');

    if (num.length === 0) {
        alertaDiv.style.display = 'none';
        btnGuardar.disabled = false;
        return;
    }

    fetch(`verificar_rif.php?tipo=${encodeURIComponent(tipo)}&num=${encodeURIComponent(num)}&rif_original=${encodeURIComponent(rifOriginal)}`)
        .then(response => response.json())
        .then(data => {
            if (data.existe) {
                alertaDiv.innerHTML = data.mensaje;
                alertaDiv.style.display = 'block';
                btnGuardar.disabled = true;
            } else {
                alertaDiv.style.display = 'none';
                btnGuardar.disabled = false;
            }
        })
        .catch(error => console.error('Error:', error));
}

window.onload = function() {
    gestionarPrefijo(1);
    gestionarPrefijo(2);
    gestionarPrefijo(3);
    if(document.getElementById('numero_rif').value.trim() !== '') {
        verificarRifDuplicado();
    }
};
</script>

<?php 
if (isset($conexion)) { pg_close($conexion); }
require_once 'footer.php'; 
?>