<?php
session_start();
require_once 'header.php';
require_once 'conexion.php';

// 1. Validar que se recibió un ID
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error_message'] = "No se especificó un registro de trabajo.";
    header('Location: trabajo_listar.php');
    exit();
}
$registro_id = $_GET['id'];
$mes_actual = isset($_GET['mes']) ? $_GET['mes'] : date('Y-m');
$fecha_inicio_mes = date('Y-m-01', strtotime($mes_actual));
$fecha_fin_mes = date('Y-m-t', strtotime($mes_actual));

// --- Lógica para deshabilitar botón "Mes Siguiente" ---
$es_mes_futuro = date('Y-m', strtotime('+1 month', strtotime($mes_actual))) > date('Y-m');

// 2. Consulta para obtener los datos del registro laboral (PostgreSQL)
$sql_registro = "SELECT 
                     rl.id,
                     p.documento_identidad,
                     (p.nombre || ' ' || p.apellido) AS nombre_completo,
                     rl.fecha_inicio_labor,
                     rl.dias_laborales,
                     rl.fecha_fin_labor
                  FROM registros_laborales rl
                  JOIN privados p ON rl.privado_documento = p.documento_identidad
                  WHERE rl.id = $1";

$res_registro = pg_query_params($conexion, $sql_registro, array($registro_id));

if (!$res_registro || pg_num_rows($res_registro) === 0) {
    if ($res_registro) pg_free_result($res_registro);
    $_SESSION['error_message'] = "Registro de trabajo no encontrado.";
    header('Location: trabajo_listar.php');
    exit();
}
$registro = pg_fetch_assoc($res_registro);
pg_free_result($res_registro);
$dias_laborales_array = explode(',', $registro['dias_laborales']);

// 3. Consulta para obtener las asistencias (PostgreSQL)
$sql_asistencias = "SELECT fecha, asistencia FROM asistencias WHERE registro_laboral_id = $1 AND fecha BETWEEN $2 AND $3";
$res_asistencias = pg_query_params($conexion, $sql_asistencias, array($registro_id, $fecha_inicio_mes, $fecha_fin_mes));

$asistencias = [];
if ($res_asistencias) {
    while ($asistencia = pg_fetch_assoc($res_asistencias)) {
        $asistencias[$asistencia['fecha']] = $asistencia['asistencia'];
    }
    pg_free_result($res_asistencias);
}

// Arrays de traducción
$dias_semana_es = ['Mon'=>'Lunes', 'Tue'=>'Martes', 'Wed'=>'Miércoles', 'Thu'=>'Jueves', 'Fri'=>'Viernes', 'Sat'=>'Sábado', 'Sun'=>'Domingo'];
$dias_semana_letras = ['Mon'=>'L', 'Tue'=>'M', 'Wed'=>'X', 'Thu'=>'J', 'Fri'=>'V', 'Sat'=>'S', 'Sun'=>'D'];

// Mapa de colores para los <select>
$select_color_map = [
    'Presente' => 'text-bg-success',
    'Ausente'  => 'text-bg-danger',
    'Reposo'   => 'text-bg-warning',
    'Permiso'  => 'text-bg-info'
];
?>

<main class="app-main">
    <div class="app-content-header">
        <div class="container-fluid">
            <h3 class="mb-0">Gestión de Asistencia</h3>
        </div>
    </div>
    <div class="app-content">
        <div class="container-fluid">
            <div class="row justify-content-center">
                <div class="col-lg-10">
                    <div class="card card-outline card-primary">
                        <div class="card-body">
                            <h4 class="card-title">Asistencia para: <strong><?php echo htmlspecialchars($registro['nombre_completo']); ?></strong></h4>
                            <p class="card-subtitle text-muted">Registro #<?php echo htmlspecialchars($registro_id); ?> | Inicio: <?php echo date('d/m/Y', strtotime($registro['fecha_inicio_labor'])); ?></p>
                            
                            <!-- Insignias (badges) de Días Laborales -->
                            <p class="mt-2">
                                <strong>Días Laborales:</strong>
                                <?php foreach ($dias_laborales_array as $dia_letra): ?>
                                    <span class="badge text-bg-primary">
                                        <?php echo str_replace(['L','M','X','J','V','S','D'], ['Lun','Mar','Mié','Jue','Vie','Sáb','Dom'], $dia_letra); ?>
                                    </span>
                                <?php endforeach; ?>
                            </p>
                            <hr>

                            <form action="procesar_asistencia.php" method="POST">
                                <input type="hidden" name="registro_id" value="<?php echo htmlspecialchars($registro_id); ?>">

                                <!-- Botón de guardar arriba -->
                                <div class="d-flex justify-content-end mb-3">
                                    <button type="submit" class="btn btn-success">
                                        <i class="bi bi-check-lg me-1"></i> Guardar Cambios
                                    </button>
                                </div>
                                
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <a href="asistencia_gestionar.php?id=<?php echo $registro_id; ?>&mes=<?php echo date('Y-m', strtotime('-1 month', strtotime($mes_actual))); ?>" class="btn btn-secondary">&larr; Mes Anterior</a>
                                    <h4><?php echo date('F Y', strtotime($mes_actual)); ?></h4>
                                    <!-- Botón "Mes Siguiente" deshabilitado si es futuro -->
                                    <a href="asistencia_gestionar.php?id=<?php echo $registro_id; ?>&mes=<?php echo date('Y-m', strtotime('+1 month', strtotime($mes_actual))); ?>" 
                                       class="btn btn-secondary <?php if ($es_mes_futuro) echo 'disabled'; ?>">
                                        Mes Siguiente &rarr;
                                    </a>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-bordered text-center">
                                        <thead class="table-dark">
                                            <tr>
                                                <th>Fecha</th>
                                                <th>Día</th>
                                                <th style="width: 200px;">Asistencia</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $fecha_actual = new DateTime($fecha_inicio_mes);
                                            $hoy = new DateTime();
                                            
                                            while ($fecha_actual <= new DateTime($fecha_fin_mes)):
                                                $dia_semana_letra_en = $fecha_actual->format('D');
                                                $letra_dia_db = $dias_semana_letras[$dia_semana_letra_en];
                                                $es_laboral = in_array($letra_dia_db, $dias_laborales_array);
                                                $es_despues_de_inicio = $fecha_actual >= new DateTime($registro['fecha_inicio_labor']);
                                                $es_despues_de_fin = !empty($registro['fecha_fin_labor']) && $fecha_actual > new DateTime($registro['fecha_fin_labor']);

                                                if ($es_despues_de_fin) {
                                                    $fecha_actual->modify('+1 day');
                                                    continue;
                                                }

                                                $dia_actual = $fecha_actual->format('Y-m-d');
                                                $estado_actual = $asistencias[$dia_actual] ?? 'Ausente';
                                                $is_read_only = !$es_laboral || !$es_despues_de_inicio || $fecha_actual > $hoy;
                                                $row_class = !$es_laboral ? 'table-light text-muted' : '';
                                            ?>
                                            <tr class="<?php echo $row_class; ?>">
                                                <td><?php echo date('d/m', strtotime($dia_actual)); ?></td>
                                                <td><?php echo $dias_semana_es[$dia_semana_letra_en]; ?></td>
                                                <td>
                                                    <?php if (!$is_read_only): ?>
                                                        <?php
                                                        $color_clase = $select_color_map[$estado_actual] ?? 'text-bg-danger';
                                                        ?>
                                                        <select class="form-select <?php echo $color_clase; ?>" name="asistencia[<?php echo $dia_actual; ?>]">
                                                            <option value="Presente" <?php echo $estado_actual === 'Presente' ? 'selected' : ''; ?>>Presente</option>
                                                            <option value="Ausente"  <?php echo $estado_actual === 'Ausente' ? 'selected' : ''; ?>>Ausente</option>
                                                            <option value="Reposo"   <?php echo $estado_actual === 'Reposo' ? 'selected' : ''; ?>>Reposo</option>
                                                            <option value="Permiso"  <?php echo $estado_actual === 'Permiso' ? 'selected' : ''; ?>>Permiso</option>
                                                        </select>
                                                    <?php else: ?>
                                                        <?php 
                                                        if ($fecha_actual > $hoy) {
                                                            echo '<span class="badge bg-secondary">Día Futuro</span>';
                                                        } else if (!$es_laboral) {
                                                            echo '<span class="badge bg-secondary">No Laboral</span>';
                                                        } else {
                                                            echo htmlspecialchars($estado_actual);
                                                        }
                                                        ?>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php $fecha_actual->modify('+1 day'); endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="d-flex justify-content-end mt-3">
                                    <button type="submit" class="btn btn-success me-2">Guardar Cambios</button>
                                    <a href="trabajo_listar.php" class="btn btn-secondary">Volver al Listado</a>
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
    const colorClasses = {
        'Presente': 'text-bg-success',
        'Ausente': 'text-bg-danger',
        'Reposo': 'text-bg-warning',
        'Permiso': 'text-bg-info'
    };

    function updateSelectColor(select) {
        Object.values(colorClasses).forEach(cls => select.classList.remove(cls));
        const newClass = colorClasses[select.value];
        if (newClass) {
            select.classList.add(newClass);
        }
    }

    const selects = document.querySelectorAll('select[name^="asistencia["]');
    selects.forEach(select => {
        select.addEventListener('change', function() {
            updateSelectColor(this);
        });
    });
});
</script>

<?php
if (isset($conexion)) {
    pg_close($conexion);
}
require_once 'footer.php';
?>