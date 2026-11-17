<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'header.php';
require_once 'conexion.php';

// ======================================
// OBTENER DATOS DE SESIÓN Y ROL
// ======================================
$usuario_cedula = $_SESSION['nombre_usuario'] ?? $_SESSION['cedula'] ?? '';
$cedula_limpia  = preg_replace('/[^0-9]/', '', $usuario_cedula);
$rolUsuario     = $_SESSION['cargo'] ?? $_SESSION['rol'] ?? '';

$es_administrador = ($rolUsuario === 'Administradora' || $rolUsuario === 'Administrador');
$es_coordinador   = (strcasecmp($rolUsuario, 'Coordinador Regional') === 0);

// Determinar el centro asignado si es Coordinador Regional
$centro_usuario = '';
if ($es_coordinador) {
    $query_c = "SELECT centro_codigo FROM usuario_centros WHERE REPLACE(REPLACE(usuario_cedula, 'V', ''), 'v', '') = $1 LIMIT 1";
    $res_c = pg_query_params($conexion, $query_c, array($cedula_limpia));
    if ($res_c && $row_c = pg_fetch_assoc($res_c)) { 
        $centro_usuario = $row_c['centro_codigo']; 
    }
}

// ======================================
// LÓGICA PHP DE ESTADÍSTICAS (CON FILTRO DE CENTRO PARA COORDINADOR)
// ======================================
$porcentaje_elegibilidad = 0;
$porcentaje_incompletos = 0;
$porcentaje_aprobacion_edu = 0;
$porcentaje_asistencia_edu = 0;

// 1. Conteo de Población Total
if ($es_coordinador) {
    $sql_total = "SELECT COUNT(documento_identidad) AS total FROM privados WHERE centro_codigo = $1";
    $res_total = pg_query_params($conexion, $sql_total, array($centro_usuario));
} else {
    $sql_total = "SELECT COUNT(documento_identidad) AS total FROM privados";
    $res_total = pg_query($conexion, $sql_total);
}
$conteo_privados_total = ($res_total) ? (pg_fetch_assoc($res_total)['total'] ?? 0) : 0;
if ($res_total) pg_free_result($res_total);

// 2. Conteo de Candidatos a Redención (50%+)
if ($es_coordinador) {
    $sql_candidatos = "SELECT COUNT(p.documento_identidad) AS total 
                       FROM privados p
                       LEFT JOIN privados_casos pc ON p.documento_identidad = pc.privado_documento
                       LEFT JOIN casos_judiciales cj ON pc.caso_expediente = cj.numero_expediente
                       WHERE p.centro_codigo = $1
                       AND p.fecha_detencion IS NOT NULL 
                       AND cj.duracion_condena_dias > 0 
                       AND EXTRACT(DAY FROM (NOW() - p.fecha_detencion)) >= (cj.duracion_condena_dias / 2)";
    $res_candidatos = pg_query_params($conexion, $sql_candidatos, array($centro_usuario));
} else {
    $sql_candidatos = "SELECT COUNT(p.documento_identidad) AS total 
                       FROM privados p
                       LEFT JOIN privados_casos pc ON p.documento_identidad = pc.privado_documento
                       LEFT JOIN casos_judiciales cj ON pc.caso_expediente = cj.numero_expediente
                       WHERE p.fecha_detencion IS NOT NULL 
                       AND cj.duracion_condena_dias > 0 
                       AND EXTRACT(DAY FROM (NOW() - p.fecha_detencion)) >= (cj.duracion_condena_dias / 2)";
    $res_candidatos = pg_query($conexion, $sql_candidatos);
}
$conteo_candidatos = ($res_candidatos) ? (pg_fetch_assoc($res_candidatos)['total'] ?? 0) : 0;
if ($res_candidatos) pg_free_result($res_candidatos);

// 3. Conteo de Registros Incompletos
if ($es_coordinador) {
    $sql_incompletos = "SELECT COUNT(p.documento_identidad) AS total 
                        FROM privados p
                        LEFT JOIN privados_casos pc ON p.documento_identidad = pc.privado_documento
                        LEFT JOIN casos_judiciales cj ON pc.caso_expediente = cj.numero_expediente
                        WHERE p.centro_codigo = $1 AND (
                            p.fecha_detencion IS NULL 
                            OR cj.duracion_condena_dias IS NULL 
                            OR cj.duracion_condena_dias = 0 
                            OR cj.numero_expediente IS NULL 
                            OR p.centro_codigo IS NULL
                        )";
    $res_incompletos = pg_query_params($conexion, $sql_incompletos, array($centro_usuario));
} else {
    $sql_incompletos = "SELECT COUNT(p.documento_identidad) AS total 
                        FROM privados p
                        LEFT JOIN privados_casos pc ON p.documento_identidad = pc.privado_documento
                        LEFT JOIN casos_judiciales cj ON pc.caso_expediente = cj.numero_expediente
                        WHERE p.fecha_detencion IS NULL 
                        OR cj.duracion_condena_dias IS NULL 
                        OR cj.duracion_condena_dias = 0 
                        OR cj.numero_expediente IS NULL 
                        OR p.centro_codigo IS NULL";
    $res_incompletos = pg_query($conexion, $sql_incompletos);
}
$conteo_incompletos = ($res_incompletos) ? (pg_fetch_assoc($res_incompletos)['total'] ?? 0) : 0;
if ($res_incompletos) pg_free_result($res_incompletos);

// 4. Conteo de Solicitudes En Proceso (Redenciones)
if ($es_coordinador) {
    $sql_proceso = "SELECT COUNT(sr.id_solicitud) AS total 
                    FROM solicitudes_redencion sr
                    JOIN privados p ON sr.privado_documento = p.documento_identidad
                    WHERE sr.estado = 'En Proceso' AND p.centro_codigo = $1";
    $res_proceso = pg_query_params($conexion, $sql_proceso, array($centro_usuario));
} else {
    $sql_proceso = "SELECT COUNT(id_solicitud) AS total FROM solicitudes_redencion WHERE estado = 'En Proceso'";
    $res_proceso = pg_query($conexion, $sql_proceso);
}
$conteo_en_proceso = ($res_proceso) ? (pg_fetch_assoc($res_proceso)['total'] ?? 0) : 0;
if ($res_proceso) pg_free_result($res_proceso);

// 5. Estadísticas de Gestión Educativa (Inscritos y Secciones por Centro)
if ($es_coordinador) {
    $sql_edu_insc = "SELECT COUNT(ie.id) AS total 
                     FROM inscripciones_educativas ie
                     JOIN secciones_cursos sc ON ie.seccion_id = sc.id
                     WHERE ie.estado_cursante = 'Cursando' AND sc.centro_codigo = $1";
    $res_edu_insc = pg_query_params($conexion, $sql_edu_insc, array($centro_usuario));

    $sql_edu_sec = "SELECT COUNT(id) AS total FROM secciones_cursos WHERE centro_codigo = $1";
    $res_edu_sec = pg_query_params($conexion, $sql_edu_sec, array($centro_usuario));

    $sql_edu_asist = "SELECT COUNT(ae.id) AS total 
                      FROM asistencia_educativa ae
                      JOIN inscripciones_educativas ie ON ae.inscripcion_id = ie.id
                      JOIN secciones_cursos sc ON ie.seccion_id = sc.id
                      WHERE sc.centro_codigo = $1";
    $res_edu_asist = pg_query_params($conexion, $sql_edu_asist, array($centro_usuario));
} else {
    $sql_edu_insc = "SELECT COUNT(id) AS total FROM inscripciones_educativas WHERE estado_cursante = 'Cursando'";
    $res_edu_insc = pg_query($conexion, $sql_edu_insc);

    $sql_edu_sec = "SELECT COUNT(id) AS total FROM secciones_cursos";
    $res_edu_sec = pg_query($conexion, $sql_edu_sec);

    $sql_edu_asist = "SELECT COUNT(id) AS total FROM asistencia_educativa";
    $res_edu_asist = pg_query($conexion, $sql_edu_asist);
}

$conteo_educativo_inscritos = ($res_edu_insc) ? (pg_fetch_assoc($res_edu_insc)['total'] ?? 0) : 0;
if ($res_edu_insc) pg_free_result($res_edu_insc);

$conteo_educativo_secciones = ($res_edu_sec) ? (pg_fetch_assoc($res_edu_sec)['total'] ?? 0) : 0;
if ($res_edu_sec) pg_free_result($res_edu_sec);

$conteo_educativo_asistencias = ($res_edu_asist) ? (pg_fetch_assoc($res_edu_asist)['total'] ?? 0) : 0;
if ($res_edu_asist) pg_free_result($res_edu_asist);

// Análisis educativo (Aprobados y Asistencia General)
if ($es_coordinador) {
    $sql_edu_total_insc = "SELECT COUNT(ie.id) AS total FROM inscripciones_educativas ie JOIN secciones_cursos sc ON ie.seccion_id = sc.id WHERE sc.centro_codigo = $1";
    $res_edu_tot = pg_query_params($conexion, $sql_edu_total_insc, array($centro_usuario));

    $sql_edu_aprobados = "SELECT COUNT(ie.id) AS total FROM inscripciones_educativas ie JOIN secciones_cursos sc ON ie.seccion_id = sc.id WHERE ie.estado_aprobacion = 'APROBADO' AND sc.centro_codigo = $1";
    $res_edu_apr = pg_query_params($conexion, $sql_edu_aprobados, array($centro_usuario));

    $sql_asist_edu_total = "SELECT COUNT(ae.id) AS total FROM asistencia_educativa ae JOIN inscripciones_educativas ie ON ae.inscripcion_id = ie.id JOIN secciones_cursos sc ON ie.seccion_id = sc.id WHERE sc.centro_codigo = $1";
    $res_ae_tot = pg_query_params($conexion, $sql_asist_edu_total, array($centro_usuario));

    $sql_asist_edu_pres = "SELECT COUNT(ae.id) AS total FROM asistencia_educativa ae JOIN inscripciones_educativas ie ON ae.inscripcion_id = ie.id JOIN secciones_cursos sc ON ie.seccion_id = sc.id WHERE ae.asistencia = 'Presente' AND sc.centro_codigo = $1";
    $res_ae_pres = pg_query_params($conexion, $sql_asist_edu_pres, array($centro_usuario));
} else {
    $sql_edu_total_insc = "SELECT COUNT(id) AS total FROM inscripciones_educativas";
    $res_edu_tot = pg_query($conexion, $sql_edu_total_insc);

    $sql_edu_aprobados = "SELECT COUNT(id) AS total FROM inscripciones_educativas WHERE estado_aprobacion = 'APROBADO'";
    $res_edu_apr = pg_query($conexion, $sql_edu_aprobados);

    $sql_asist_edu_total = "SELECT COUNT(id) AS total FROM asistencia_educativa";
    $res_ae_tot = pg_query($conexion, $sql_asist_edu_total);

    $sql_asist_edu_pres = "SELECT COUNT(id) AS total FROM asistencia_educativa WHERE asistencia = 'Presente'";
    $res_ae_pres = pg_query($conexion, $sql_asist_edu_pres);
}

$total_inscripciones_edu = ($res_edu_tot) ? (pg_fetch_assoc($res_edu_tot)['total'] ?? 0) : 0;
if ($res_edu_tot) pg_free_result($res_edu_tot);

$conteo_aprobados_edu = ($res_edu_apr) ? (pg_fetch_assoc($res_edu_apr)['total'] ?? 0) : 0;
if ($res_edu_apr) pg_free_result($res_edu_apr);

$total_asistencias_edu = ($res_ae_tot) ? (pg_fetch_assoc($res_ae_tot)['total'] ?? 0) : 0;
if ($res_ae_tot) pg_free_result($res_ae_tot);

$total_presentes_edu = ($res_ae_pres) ? (pg_fetch_assoc($res_ae_pres)['total'] ?? 0) : 0;
if ($res_ae_pres) pg_free_result($res_ae_pres);


// 6. Lógica para Cupos Disponibles
if ($es_coordinador) {
    $sql5 = "SELECT 
                (SELECT COALESCE(SUM(capacidad_maxima), 0) FROM centros_areas WHERE centro_codigo = $1) AS capacidad_total,
                (SELECT COUNT(documento_identidad) FROM privados WHERE centro_codigo = $1 AND area_codigo IS NOT NULL) AS ocupacion_total";
    $res5 = pg_query_params($conexion, $sql5, array($centro_usuario));
} else {
    $sql5 = "SELECT 
                (SELECT COALESCE(SUM(capacidad_maxima), 0) FROM centros_areas) AS capacidad_total,
                (SELECT COUNT(documento_identidad) FROM privados WHERE centro_codigo IS NOT NULL AND area_codigo IS NOT NULL) AS ocupacion_total";
    $res5 = pg_query($conexion, $sql5);
}
$datos5 = ($res5) ? pg_fetch_assoc($res5) : [];
$capacidad_total = $datos5['capacidad_total'] ?? 0;
$ocupacion_total = $datos5['ocupacion_total'] ?? 0;
$cupos_disponibles = $capacidad_total - $ocupacion_total;
if ($res5) pg_free_result($res5);

// 7. Resumen de Asistencia Laboral del Día
$conteo_presente_hoy = 0;
$conteo_ausente_hoy = 0;
$conteo_programados_hoy = 0;
$fecha_hoy_sql = date('Y-m-d');
$dias_semana_letras = ['Mon'=>'L', 'Tue'=>'M', 'Wed'=>'X', 'Thu'=>'J', 'Fri'=>'V', 'Sat'=>'S', 'Sun'=>'D'];
$letra_dia_hoy = $dias_semana_letras[date('D')];
$param_dia = "%" . $letra_dia_hoy . "%";

if ($es_coordinador) {
    $sql_programados = "SELECT COUNT(rl.id) AS total 
                        FROM registros_laborales rl 
                        JOIN privados p ON rl.privado_documento = p.documento_identidad
                        WHERE p.centro_codigo = $1 
                        AND rl.fecha_fin_labor IS NULL 
                        AND rl.fecha_inicio_labor <= CURRENT_DATE
                        AND rl.dias_laborales LIKE $2";
    $res_prog = pg_query_params($conexion, $sql_programados, array($centro_usuario, $param_dia));
} else {
    $sql_programados = "SELECT COUNT(rl.id) AS total 
                        FROM registros_laborales rl 
                        WHERE rl.fecha_fin_labor IS NULL 
                        AND rl.fecha_inicio_labor <= CURRENT_DATE
                        AND rl.dias_laborales LIKE $1";
    $res_prog = pg_query_params($conexion, $sql_programados, array($param_dia));
}
if ($res_prog) {
    $fila_prog = pg_fetch_assoc($res_prog);
    $conteo_programados_hoy = $fila_prog['total'] ?? 0;
    pg_free_result($res_prog);
}

if ($es_coordinador) {
    $sql_asistencia_hoy = "SELECT a.asistencia, COUNT(a.id) AS total 
                           FROM asistencias a
                           JOIN privados p ON a.privado_documento = p.documento_identidad
                           WHERE p.centro_codigo = $1 AND a.fecha = $2 
                           GROUP BY a.asistencia";
    $res_asist = pg_query_params($conexion, $sql_asistencia_hoy, array($centro_usuario, $fecha_hoy_sql));
} else {
    $sql_asistencia_hoy = "SELECT asistencia, COUNT(id) AS total 
                           FROM asistencias 
                           WHERE fecha = $1 
                           GROUP BY asistencia";
    $res_asist = pg_query_params($conexion, $sql_asistencia_hoy, array($fecha_hoy_sql));
}

if ($res_asist) {
    while ($fila = pg_fetch_assoc($res_asist)) {
        if ($fila['asistencia'] == 'Presente') {
            $conteo_presente_hoy = $fila['total'];
        }
        if (in_array($fila['asistencia'], ['Ausente', 'Reposo', 'Permiso', 'Suspensión'])) {
            $conteo_ausente_hoy += $fila['total'];
        }
    }
    pg_free_result($res_asist);
}

// --- CÁLCULOS DE PORCENTAJES ---
if ($conteo_privados_total > 0) {
    $porcentaje_elegibilidad = round(($conteo_candidatos / $conteo_privados_total) * 100, 1);
    $porcentaje_incompletos = round(($conteo_incompletos / $conteo_privados_total) * 100, 1);
}

if ($total_inscripciones_edu > 0) {
    $porcentaje_aprobacion_edu = round(($conteo_aprobados_edu / $total_inscripciones_edu) * 100, 1);
}

if ($total_asistencias_edu > 0) {
    $porcentaje_asistencia_edu = round(($total_presentes_edu / $total_asistencias_edu) * 100, 1);
}

// Cerramos la conexión al terminar
if (isset($conexion)) {
    pg_close($conexion);
}
// ======================================
?>

<main class="app-main">
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center">
                <h1 class="mb-0 fw-bold"><i class="bi bi-speedometer2 me-2"></i> Tablero Principal de Gestión</h1>
                <?php if ($es_coordinador): ?>
                    <span class="badge bg-info text-dark fs-6 px-3 py-2">Recinto Asignado: <?php echo htmlspecialchars($centro_usuario); ?></span>
                <?php else: ?>
                    <span class="badge bg-secondary fs-6 px-3 py-2">Vista General Administradora</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="app-content">
        <div class="container-fluid">
            
            <!-- ========================================== -->
            <!-- PANEL 1: GESTIÓN DE REDENCIONES Y LABORAL -->
            <!-- ========================================== -->
            <div class="mb-3">
                <h3 class="fw-bold text-secondary border-bottom pb-2"><i class="bi bi-file-earmark-bar-graph-fill me-2"></i> Panel de Gestión de Redenciones y Laboral</h3>
            </div>

            <div class="row mb-4">
                <!-- 1. Asistencia de Hoy -->
                <div class="col-xl-3 col-md-6 mb-3">
                    <div class="small-box shadow-sm h-100" style="background-color: #d1e7dd; color: #0f5132; border: 1px solid #badbcc; border-radius: 8px;">
                        <div class="inner p-3">
                            <h3 class="fw-bold mb-1" style="font-size: 2.3rem; color: #0f5132;"><?php echo $conteo_presente_hoy; ?> <sup style="font-size: 18px">/ <?php echo $conteo_programados_hoy; ?></sup></h3>
                            <p class="fw-bold mb-0" style="font-size: 1.15rem; color: #146c43;">Asistencia Laboral Hoy</p>
                        </div>
                        <div class="icon" style="color: rgba(15, 81, 50, 0.15); font-size: 3.5rem;"><i class="bi bi-calendar-check-fill"></i></div>
                        <a href="asistencia_diaria.php" class="small-box-footer fw-bold py-2" style="background-color: rgba(15, 81, 50, 0.1); color: #0f5132; text-decoration: none; display: block; text-align: center; border-bottom-left-radius: 8px; border-bottom-right-radius: 8px;">Gestionar Asistencia <i class="bi bi-arrow-right-circle"></i></a>
                    </div>
                </div>

                <!-- 2. Solicitudes Pendientes -->
                <div class="col-xl-3 col-md-6 mb-3">
                    <div class="small-box shadow-sm h-100" style="background-color: #cff4fc; color: #055160; border: 1px solid #b6effb; border-radius: 8px;">
                        <div class="inner p-3">
                            <h3 class="fw-bold mb-1" style="font-size: 2.3rem; color: #055160;"><?php echo $conteo_en_proceso; ?></h3>
                            <p class="fw-bold mb-0" style="font-size: 1.15rem; color: #087990;">Solicitudes Pendientes</p>
                        </div>
                        <div class="icon" style="color: rgba(5, 81, 96, 0.15); font-size: 3.5rem;"><i class="bi bi-hourglass-split"></i></div>
                        <a href="redenciones.php" class="small-box-footer fw-bold py-2" style="background-color: rgba(5, 81, 96, 0.1); color: #055160; text-decoration: none; display: block; text-align: center; border-bottom-left-radius: 8px; border-bottom-right-radius: 8px;">Revisar Solicitudes <i class="bi bi-arrow-right-circle"></i></a>
                    </div>
                </div>

                <!-- 3. Registros Incompletos -->
                <div class="col-xl-3 col-md-6 mb-3">
                    <div class="small-box shadow-sm h-100" style="background-color: #f8d7da; color: #842029; border: 1px solid #f5c2c7; border-radius: 8px;">
                        <div class="inner p-3">
                            <h3 class="fw-bold mb-1" style="font-size: 2.3rem; color: #842029;"><?php echo $conteo_incompletos; ?></h3>
                            <p class="fw-bold mb-0" style="font-size: 1.15rem; color: #b02a37;">Registros Incompletos</p>
                        </div>
                        <div class="icon" style="color: rgba(132, 32, 41, 0.15); font-size: 3.5rem;"><i class="bi bi-exclamation-triangle-fill"></i></div>
                        <a href="privados_listar.php?estado=incompleto" class="small-box-footer fw-bold py-2" style="background-color: rgba(132, 32, 41, 0.1); color: #842029; text-decoration: none; display: block; text-align: center; border-bottom-left-radius: 8px; border-bottom-right-radius: 8px;">Corregir Datos <i class="bi bi-arrow-right-circle"></i></a>
                    </div>
                </div>

                <!-- 4. Candidatos Elegibles -->
                <div class="col-xl-3 col-md-6 mb-3">
                    <div class="small-box shadow-sm h-100" style="background-color: #fff3cd; color: #664d03; border: 1px solid #ffeeba; border-radius: 8px;">
                        <div class="inner p-3">
                            <h3 class="fw-bold mb-1" style="font-size: 2.3rem; color: #664d03;"><?php echo $conteo_candidatos; ?></h3>
                            <p class="fw-bold mb-0" style="font-size: 1.15rem; color: #997404;">Candidatos Elegibles</p>
                        </div>
                        <div class="icon" style="color: rgba(102, 77, 3, 0.15); font-size: 3.5rem;"><i class="bi bi-check-circle-fill"></i></div>
                        <a href="privados_listar.php?estado=candidato" class="small-box-footer fw-bold py-2" style="background-color: rgba(102, 77, 3, 0.1); color: #664d03; text-decoration: none; display: block; text-align: center; border-bottom-left-radius: 8px; border-bottom-right-radius: 8px;">Ver Elegibles <i class="bi bi-arrow-right-circle"></i></a>
                    </div>
                </div>
            </div>


            <!-- ========================================== -->
            <!-- PANEL 2: GESTIÓN EDUCATIVA                   -->
            <!-- ========================================== -->
            <div class="mb-3 mt-4">
                <h3 class="fw-bold text-secondary border-bottom pb-2"><i class="bi bi-mortarboard-fill me-2"></i> Panel de Gestión Educativa</h3>
            </div>

            <div class="row mb-4">
                <!-- 1. Inscritos Activos -->
                <div class="col-xl-4 col-md-6 mb-3">
                    <div class="small-box shadow-sm h-100" style="background-color: #e2d9f3; color: #3f2d6b; border: 1px solid #d1c4e9; border-radius: 8px;">
                        <div class="inner p-3">
                            <h3 class="fw-bold mb-1" style="font-size: 2.3rem; color: #3f2d6b;"><?php echo $conteo_educativo_inscritos; ?></h3>
                            <p class="fw-bold mb-0" style="font-size: 1.15rem; color: #512da8;">Cursantes Activos (Inscritos)</p>
                        </div>
                        <div class="icon" style="color: rgba(63, 45, 107, 0.15); font-size: 3.5rem;"><i class="bi bi-people-fill"></i></div>
                        <a href="inscripciones_listado.php" class="small-box-footer fw-bold py-2" style="background-color: rgba(63, 45, 107, 0.1); color: #3f2d6b; text-decoration: none; display: block; text-align: center; border-bottom-left-radius: 8px; border-bottom-right-radius: 8px;">Ver Listado de Inscripciones <i class="bi bi-arrow-right-circle"></i></a>
                    </div>
                </div>

                <!-- 2. Secciones Abiertas -->
                <div class="col-xl-4 col-md-6 mb-3">
                    <div class="small-box shadow-sm h-100" style="background-color: #cfe2ff; color: #084298; border: 1px solid #b6d4fe; border-radius: 8px;">
                        <div class="inner p-3">
                            <h3 class="fw-bold mb-1" style="font-size: 2.3rem; color: #084298;"><?php echo $conteo_educativo_secciones; ?></h3>
                            <p class="fw-bold mb-0" style="font-size: 1.15rem; color: #052c65;">Secciones Académicas Abiertas</p>
                        </div>
                        <div class="icon" style="color: rgba(8, 66, 152, 0.15); font-size: 3.5rem;"><i class="bi bi-door-open-fill"></i></div>
                        <a href="secciones_listado.php" class="small-box-footer fw-bold py-2" style="background-color: rgba(8, 66, 152, 0.1); color: #084298; text-decoration: none; display: block; text-align: center; border-bottom-left-radius: 8px; border-bottom-right-radius: 8px;">Consultar Secciones <i class="bi bi-arrow-right-circle"></i></a>
                    </div>
                </div>

                <!-- 3. Asistencia Educativa -->
                <div class="col-xl-4 col-md-12 mb-3">
                    <div class="small-box shadow-sm h-100" style="background-color: #d1e7dd; color: #0f5132; border: 1px solid #badbcc; border-radius: 8px;">
                        <div class="inner p-3">
                            <h3 class="fw-bold mb-1" style="font-size: 2.3rem; color: #0f5132;"><?php echo $conteo_educativo_asistencias; ?></h3>
                            <p class="fw-bold mb-0" style="font-size: 1.15rem; color: #146c43;">Registros de Asistencia Clases</p>
                        </div>
                        <div class="icon" style="color: rgba(15, 81, 50, 0.15); font-size: 3.5rem;"><i class="bi bi-calendar-check-fill"></i></div>
                        <a href="asistencias_listado.php" class="small-box-footer fw-bold py-2" style="background-color: rgba(15, 81, 50, 0.1); color: #0f5132; text-decoration: none; display: block; text-align: center; border-bottom-left-radius: 8px; border-bottom-right-radius: 8px;">Gestionar Asistencia Educativa <i class="bi bi-arrow-right-circle"></i></a>
                    </div>
                </div>
            </div>


            <!-- ========================================== -->
            <!-- SECCIÓN DE ANÁLISIS Y PORCENTAJES          -->
            <!-- ========================================== -->
            <div class="row">
                <div class="col-lg-6">
                    
                    <h4 class="mb-3 fw-bold">Análisis de Redención y Laboral</h4>

                    <!-- Gráfico 1: Candidatos -->
                    <div class="card mb-4 shadow-sm">
                        <div class="card-header bg-light">
                            <h5 class="card-title fw-bold"><i class="bi bi-file-earmark-bar-graph-fill"></i> Distribución de Candidatos a Redención</h5>
                        </div>
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col-md-5 text-center">
                                    <h1 class="display-3 fw-bold text-secondary"><?php echo $porcentaje_elegibilidad; ?><sup class="fs-5">%</sup></h1>
                                    <p class="text-muted">Población Elegible</p>
                                </div>
                                <div class="col-md-7">
                                    <p class="fw-bold mb-1">Candidatos Elegibles:</p>
                                    <div class="progress mb-3" style="height: 20px;">
                                        <div class="progress-bar bg-warning text-dark fw-bold" style="width: <?php echo $porcentaje_elegibilidad; ?>%;"><?php echo $conteo_candidatos; ?></div>
                                    </div>
                                    <p class="fw-bold mb-1">Población Regular:</p>
                                    <div class="progress mb-3" style="height: 20px;">
                                        <div class="progress-bar bg-primary fw-bold" style="width: <?php echo 100 - $porcentaje_elegibilidad; ?>%;"><?php echo $conteo_privados_total - $conteo_candidatos; ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Gráfico 2: Completitud -->
                    <div class="card mb-4 shadow-sm">
                        <div class="card-header bg-light">
                            <h5 class="card-title fw-bold"><i class="bi bi-check-circle-fill"></i> Calidad y Completitud de Registros</h5>
                        </div>
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col-md-5 text-center">
                                    <h1 class="display-3 fw-bold text-success"><?php echo round(100 - $porcentaje_incompletos, 1); ?><sup class="fs-5">%</sup></h1>
                                    <p class="text-muted">Registros Completos</p>
                                </div>
                                <div class="col-md-7">
                                    <p class="fw-bold mb-1">Registros Completos:</p>
                                    <div class="progress mb-3" style="height: 20px;">
                                        <div class="progress-bar bg-success fw-bold" style="width: <?php echo 100 - $porcentaje_incompletos; ?>%;"><?php echo $conteo_privados_total - $conteo_incompletos; ?></div>
                                    </div>
                                    <p class="fw-bold mb-1 text-danger">Registros Faltantes:</p>
                                    <div class="progress mb-3" style="height: 20px;">
                                        <div class="progress-bar bg-danger fw-bold" style="width: <?php echo $porcentaje_incompletos; ?>%;"><?php echo $conteo_incompletos; ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    
                    <h4 class="mb-3 fw-bold">Análisis de la Parte Educativa</h4>

                    <!-- Gráfico 3: Aprobación Educativa -->
                    <div class="card mb-4 shadow-sm">
                        <div class="card-header bg-light">
                            <h5 class="card-title fw-bold"><i class="bi bi-mortarboard-fill text-indigo"></i> Rendimiento y Aprobación Educativa</h5>
                        </div>
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col-md-5 text-center">
                                    <h1 class="display-3 fw-bold" style="color: #512da8;"><?php echo $porcentaje_aprobacion_edu; ?><sup class="fs-5">%</sup></h1>
                                    <p class="text-muted">Tasa de Aprobación</p>
                                </div>
                                <div class="col-md-7">
                                    <p class="fw-bold mb-1">Estudiantes Aprobados:</p>
                                    <div class="progress mb-3" style="height: 20px;">
                                        <div class="progress-bar fw-bold" style="background-color: #512da8; width: <?php echo $porcentaje_aprobacion_edu; ?>%;"><?php echo $conteo_aprobados_edu; ?></div>
                                    </div>
                                    <p class="fw-bold mb-1 text-muted">En Curso / Otros Estados:</p>
                                    <div class="progress mb-3" style="height: 20px;">
                                        <div class="progress-bar bg-secondary fw-bold" style="width: <?php echo max(0, 100 - $porcentaje_aprobacion_edu); ?>%;"><?php echo max(0, $total_inscripciones_edu - $conteo_aprobados_edu); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Gráfico 4: Asistencia Educativa -->
                    <div class="card mb-4 shadow-sm">
                        <div class="card-header bg-light">
                            <h5 class="card-title fw-bold"><i class="bi bi-calendar-check-fill text-success"></i> Estadísticas de Asistencia a Clases</h5>
                        </div>
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col-md-5 text-center">
                                    <h1 class="display-3 fw-bold text-success"><?php echo $porcentaje_asistencia_edu; ?><sup class="fs-5">%</sup></h1>
                                    <p class="text-muted">Asistencia General</p>
                                </div>
                                <div class="col-md-7">
                                    <p class="fw-bold mb-1 text-success">Asistencias Presentes:</p>
                                    <div class="progress mb-3" style="height: 20px;">
                                        <div class="progress-bar bg-success fw-bold" style="width: <?php echo $porcentaje_asistencia_edu; ?>%;"><?php echo $total_presentes_edu; ?></div>
                                    </div>
                                    <p class="fw-bold mb-1 text-danger">Ausencias / Faltas:</p>
                                    <div class="progress mb-3" style="height: 20px;">
                                        <div class="progress-bar bg-danger fw-bold" style="width: <?php echo max(0, 100 - $porcentaje_asistencia_edu); ?>%;"><?php echo max(0, $total_asistencias_edu - $total_presentes_edu); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            <!-- ========================================== -->
            <!-- SECCIÓN INFERIOR: ACCESOS RÁPIDOS          -->
            <!-- ========================================== -->
            <div class="row">
                <div class="col-lg-12">
                    <div class="card mb-4 shadow-sm">
                        <div class="card-header bg-light">
                            <h5 class="card-title fw-bold"><i class="bi bi-lightning-fill"></i> Accesos Rápidos del Sistema</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <?php if (!$es_coordinador): ?>
                                <div class="col-md-3 mb-2">
                                    <a href="privados_formulario.php" class="btn btn-outline-primary w-100 py-2 fw-bold text-start">
                                        <i class="bi bi-person-plus-fill me-2"></i> Registrar Privado
                                    </a>
                                </div>
                                <?php endif; ?>
                                <div class="col-md-3 mb-2">
                                    <a href="trabajo_registro.php" class="btn btn-outline-info w-100 py-2 fw-bold text-start">
                                        <i class="bi bi-person-workspace me-2"></i> Actividad Laboral
                                    </a>
                                </div>
                                <div class="col-md-3 mb-2">
                                    <a href="inscripcion_form.php" class="btn btn-outline-success w-100 py-2 fw-bold text-start">
                                        <i class="bi bi-mortarboard-fill me-2"></i> Inscribir en Curso
                                    </a>
                                </div>
                                <div class="col-md-3 mb-2">
                                    <a href="evaluaciones_registrar.php" class="btn btn-outline-secondary w-100 py-2 fw-bold text-start">
                                        <i class="bi bi-journal-check me-2"></i> Registrar Evaluaciones
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
        </div>
    </div>
</main>
<?php 
require_once 'footer.php'; 
?>