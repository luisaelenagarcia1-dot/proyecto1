<?php
// Inicia la sesión de PHP solo si no está ya iniciada.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Comprobamos si el usuario ha iniciado sesión. Si no, lo redirigimos al login.
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: login.php');
    exit;
}

// Obtenemos el nombre completo (Nombre y Apellido)
$nombreCompleto = ($_SESSION['nombre'] ?? '') . ' ' . ($_SESSION['apellido'] ?? '');
$nombreUsuario = trim($nombreCompleto) ? $nombreCompleto : 'Usuario';

// Rol / Cargo
$rolUsuario = $_SESSION['cargo'] ?? 'Rol';

// Obtenemos el nombre de usuario de login (ej: V12345678)
$nombreLogin = $_SESSION['nombre_usuario'] ?? 'N/D'; 

// Esta variable es esencial para la foto.
$fotoUsuario = $_SESSION['foto_perfil'] ?? 'assets/img/user2-160x160.jpg';

if (!empty($fotoUsuario) && $fotoUsuario[0] !== '/') {
    $fotoUsuario = '/' . $fotoUsuario;
}

// Validaciones de roles limpios
$es_administrador = ($rolUsuario === 'Administradora' || $rolUsuario === 'Administrador'); 
$es_coordinador   = (strcasecmp($rolUsuario, 'Coordinador Regional') === 0);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <title>Sistema de Gestion Integral</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.11.0/styles/overlayscrollbars.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="./css/adminlte.css">
    
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    
    <style>
        .sidebar-brand .brand-link {
            display: block !important;
            text-align: center;
            padding: 15px 8px;
            height: auto; 
        }

        .sidebar-brand .brand-image-new {
            max-height: 55px; 
            width: 55px;
            border-radius: 50%; 
            object-fit: cover;
            display: inline-block;
            vertical-align: middle;
            float: none !important;
        }

        .sidebar-brand .brand-text {
            margin-left: 0 !important;
            width: 100%;
        }

        .sidebar-collapsed .system-name-full {
            display: none !important;
        }

        .dropdown-menu .user-header {
            min-height: 80px; 
            padding: 15px;
            text-align: center;
            display: block; 
        }
        .dropdown-menu .user-header p {
            margin-top: 0;
            font-size: 1.1rem;
            line-height: 1.2;
        }

        /* --- SOLUCIÓN PARA LA BARRA LATERAL (ALTURA Y SCROLL FLUIDO) --- */
        .app-sidebar {
            height: 100vh;
            position: fixed;
            top: 0;
            bottom: 0;
            left: 0;
            z-index: 1038;
        }
        .sidebar-wrapper {
            height: calc(100vh - 70px);
            overflow-y: auto;
            overflow-x: hidden;
        }
    </style>
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
    <div class="app-wrapper">
        <nav class="app-header navbar navbar-expand bg-body">
            <div class="container-fluid">
                <ul class="navbar-nav">
                    <li class="nav-item"><a class="nav-link" data-lte-toggle="sidebar" href="#" role="button"><i class="bi bi-list"></i></a></li>
                    <li class="nav-item d-none d-md-block"><a href="index.php" class="nav-link">Inicio</a></li>
                </ul>
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item dropdown user-menu">
                        <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
                            <span class="d-none d-md-inline"><?php echo htmlspecialchars($nombreUsuario); ?></span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-lg dropdown-menu-end">
                            <li class="user-header text-bg-primary">
                                <p style="margin-top: 0; font-size: 1.1rem; text-align: center; line-height: 1.3;">
                                    <span class="d-block fw-bold"><?php echo htmlspecialchars($nombreUsuario); ?></span>
                                    <small class="d-block" style="font-size: 0.85em; opacity: 0.9;">Usuario: <?php echo htmlspecialchars($nombreLogin); ?></small>
                                    <small class="d-block" style="font-size: 0.85em; opacity: 0.9;">Rol: <?php echo htmlspecialchars($rolUsuario); ?></small>
                                </p>
                            </li>
                            <li class="user-footer">
                                <a href="configuracion.php" class="btn btn-default btn-flat">configuración</a>
                                <a href="logout.php" class="btn btn-default btn-flat float-end">cerrar sesion</a>
                            </li>
                        </ul>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#" data-lte-toggle="fullscreen">
                            <i data-lte-icon="maximize" class="bi bi-arrows-fullscreen"></i>
                            <i data-lte-icon="minimize" class="bi bi-fullscreen-exit" style="display: none;"></i>
                        </a>
                    </li>
                </ul>
            </div>
        </nav>
        
        <aside class="app-sidebar bg-body-secondary shadow" data-bs-theme="dark">
            <div class="sidebar-brand">
                <a href="./index.php" class="brand-link text-center">
                    <img src="assets/img/logo.jpg" alt="logo" class="brand-image-new opacity-75 shadow mb-1">
                    <span class="brand-text d-block system-name-full" style="font-size: 0.75rem; opacity: 0.8; line-height: 1;">SISTEMA DE GESTIÓN INTEGRAL</span>
                </a>
            </div>
            <div class="sidebar-wrapper" data-overlayscrollbars="host">
                <nav class="mt-2">
                    <ul class="nav sidebar-menu flex-column" data-lte-toggle="treeview" role="navigation" aria-label="Main navigation" data-accordion="false" id="navigation">
                        
                        <!-- GESTIÓN DE PRIVADOS -->
                        <li class="nav-item">
                            <a href="#" class="nav-link">
                                <i class="nav-icon bi bi-person-badge-fill"></i><p>Gestión de Privados<i class="nav-arrow bi bi-chevron-right"></i></p>
                            </a>
                            <ul class="nav nav-treeview">
                                <li class="nav-item">
                                    <a href="./privados_listar.php" class="nav-link"><i class="nav-icon bi bi-search"></i><p>Consulta</p></a>
                                </li>
                                <?php if (!$es_coordinador): ?>
                                <li class="nav-item">
                                    <a href="./privados_formulario.php" class="nav-link"><i class="nav-icon bi bi-journal-plus"></i><p>Registro</p></a>
                                </li>
                                <?php endif; ?>
                            </ul>
                        </li>

                        <!-- GESTIÓN LABORAL -->
                        <li class="nav-item">
                            <a href="#" class="nav-link">
                                <i class="nav-icon bi bi-person-workspace"></i><p>Gestión Laboral<i class="nav-arrow bi bi-chevron-right"></i></p>
                            </a>
                            <ul class="nav nav-treeview">
                                <li class="nav-item">
                                    <a href="./trabajo_listar.php" class="nav-link"><i class="nav-icon bi bi-list-columns-reverse"></i><p>Consultar</p></a>
                                </li>
                                <?php if (!$es_coordinador): ?>
                                <li class="nav-item">
                                    <a href="./trabajo_registro.php" class="nav-link"><i class="nav-icon bi bi-r-circle"></i><p>Registro de Actividad</p></a>
                                </li>
                                <?php endif; ?>
                                <li class="nav-item">
                                    <a href="./asistencia_diaria.php" class="nav-link">
                                        <i class="nav-icon bi bi-calendar-check-fill text-success"></i>
                                        <p>Asistencia Diaria</p>
                                    </a>
                                </li>
                                <?php if ($es_administrador): ?>
                                <li class="nav-item">
                                    <a href="carga_historica.php" class="nav-link">
                                        <i class="nav-icon bi bi-upload text-warning"></i>
                                        <p class="text-warning">Carga Histórica (Manual)</p>
                                    </a>
                                </li>
                                <?php endif; ?>
                            </ul>
                        </li>
                        
                        <!-- ÁREAS SOCIOPRODUCTIVAS -->
                        <li class="nav-item">
                            <a href="#" class="nav-link">
                                <i class="nav-icon bi bi-diagram-3-fill"></i><p>Áreas Socioproductivas<i class="nav-arrow bi bi-chevron-right"></i></p>
                            </a>
                            <ul class="nav nav-treeview">
                                <li class="nav-item">
                                    <a href="./areas_listado.php" class="nav-link"><i class="nav-icon bi bi-circle-fill"></i><p>Consultas</p></a>
                                </li>
                                <?php if ($es_administrador): ?>
                                <li class="nav-item">
                                    <a href="./areas_formulario.php" class="nav-link"><i class="nav-icon bi bi-app-indicator"></i><p>Asignar Capacidad</p></a>
                                </li>
                                <li class="nav-item">
                                    <a href="./registro_area_base.php" class="nav-link"><i class="nav-icon bi bi-plus-circle"></i><p>Registrar Área Base</p></a>
                                </li>
                                <?php endif; ?>
                            </ul>
                        </li>

                        <!-- GESTIÓN DE REDENCIONES (Oculto para el Coordinador) -->
                        <?php if (!$es_coordinador): ?>
                        <li class="nav-item">
                            <a href="#" class="nav-link">
                                <i class="nav-icon bi bi-file-earmark-bar-graph-fill"></i><p>Gestión de Redenciones<i class="nav-arrow bi bi-chevron-right"></i></p>
                            </a>
                            <ul class="nav nav-treeview">
                                <li class="nav-item">
                                    <a href="./redenciones.php" class="nav-link"><i class="nav-icon bi bi-arrow-down-up"></i><p>Solicitudes</p></a>
                                </li>
                                <li class="nav-item">
                                    <a href="./historico_redenciones.php" class="nav-link"><i class="nav-icon bi bi-clock-history"></i><p>Histórico de Redenciones</p></a>
                                </li>
                            </ul>
                        </li>
                        <?php endif; ?>

                        <!-- GESTIÓN EDUCATIVA -->
                        <li class="nav-item">
                            <a href="#" class="nav-link">
                                <i class="nav-icon bi bi-mortarboard-fill"></i>
                                <p>
                                    Gestión Educativa
                                    <i class="nav-arrow bi bi-chevron-right"></i>
                                </p>
                            </a>
                            <ul class="nav nav-treeview">
                                <!-- 1. Inscripciones -->
                                <li class="nav-item">
                                    <a href="#" class="nav-link">
                                        <i class="nav-icon bi bi-people"></i>
                                        <p>Inscripción<i class="nav-arrow bi bi-chevron-right"></i></p>
                                    </a>
                                    <ul class="nav nav-treeview">
                                        <li class="nav-item">
                                            <a href="inscripciones_listado.php" class="nav-link">
                                                <i class="nav-icon bi bi-search"></i>
                                                <p>Consulta</p>
                                            </a>
                                        </li>
                                        <?php if (!$es_coordinador): ?>
                                        <li class="nav-item">
                                            <a href="inscripcion_form.php" class="nav-link">
                                                <i class="nav-icon bi bi-journal-plus"></i>
                                                <p>Registro</p>
                                            </a>
                                        </li>
                                        <?php endif; ?>
                                    </ul>
                                </li>
                                <li class="nav-item">
                                <!-- Menú Desplegable de Asistencias y Redención Educativa -->
                                <li class="nav-item has-treeview">
                                    <a href="#" class="nav-link">
                                        <i class="nav-icon bi bi-calendar-check-fill"></i>
                                        <p>
                                            Asistencias / Educación
                                            <i class="nav-arrow bi bi-chevron-right"></i>
                                        </p>
                                    </a>
                                    <ul class="nav nav-treeview" style="padding-left: 20px;">
                                        <li class="nav-item">
                                            <a href="asistencias_listado.php" class="nav-link">
                                                <i class="nav-icon bi bi-search"></i>
                                                <p>Consultar Asistencias</p>
                                            </a>
                                        </li>
                                        <li class="nav-item">
                                            <a href="asistencia_registrar.php" class="nav-link">
                                                <i class="nav-icon bi bi-plus-square"></i>
                                                <p>Registrar Asistencia</p>
                                            </a>
                                        </li>
                                        <li class="nav-item">
                                            <a href="horas_historicas_registrar.php" class="nav-link">
                                                <i class="nav-icon bi bi-clock-history"></i>
                                                <p>Carga de Horas Históricas</p>
                                            </a>
                                        </li>
                                    </ul>
                                </li>
                                <li class="nav-item">
                                    <a href="#" class="nav-link">
                                        <i class="nav-icon bi bi-journal-check"></i>
                                        <p>
                                            Evaluaciones
                                            <i class="nav-arrow bi bi-chevron-right"></i>
                                        </p>
                                    </a>
                                    <ul class="nav nav-treeview" style="padding-left: 20px;">
                                        <li class="nav-item">
                                            <a href="evaluaciones_registrar.php" class="nav-link">
                                                <i class="nav-icon bi bi-check2-square text-success"></i>
                                                <p>Registrar Evaluaciones</p>
                                            </a>
                                        </li>
                                        <li class="nav-item">
                                            <a href="evaluaciones_consultar.php" class="nav-link">
                                                <i class="nav-icon bi bi-search text-info"></i>
                                                <p>Consultar Calificaciones</p>
                                            </a>
                                        </li>
                                    </ul>
                                </li>
                                <!-- 2. Entes Educativos (Visible solo para Admins, oculto al Coordinador) -->
                                <?php if (!$es_coordinador): ?>
                                <li class="nav-item">
                                    <a href="#" class="nav-link">
                                        <i class="nav-icon bi bi-building"></i>
                                        <p>Entes Educativos<i class="nav-arrow bi bi-chevron-right"></i></p>
                                    </a>
                                    <ul class="nav nav-treeview">
                                        <li class="nav-item">
                                            <a href="./entes_listado.php" class="nav-link">
                                                <i class="nav-icon bi bi-search"></i>
                                                <p>Consulta</p>
                                            </a>
                                        </li>
                                        <li class="nav-item">
                                            <a href="./entes_form.php" class="nav-link">
                                                <i class="nav-icon bi bi-journal-plus"></i>
                                                <p>Registro</p>
                                            </a>
                                        </li>
                                    </ul>
                                </li>
                                <?php endif; ?>

                                <!-- 3. Gestión de Cursos (Solo Asignación de Curso con Consulta para el Coordinador) -->
                                <li class="nav-item">
                                    <a href="#" class="nav-link">
                                        <i class="nav-icon bi bi-journal-text"></i>
                                        <p>Gestión de Cursos<i class="nav-arrow bi bi-chevron-right"></i></p>
                                    </a>
                                    <ul class="nav nav-treeview">
                                        <?php if (!$es_coordinador): ?>
                                        <li class="nav-item">
                                            <a href="./cursos_listado.php" class="nav-link">
                                                <i class="nav-icon bi bi-search"></i>
                                                <p>Consulta</p>
                                            </a>
                                        </li>
                                        <li class="nav-item">
                                            <a href="./cursos_form.php" class="nav-link">
                                                <i class="nav-icon bi bi-journal-plus"></i>
                                                <p>Registro</p>
                                            </a>
                                        </li>
                                        <?php endif; ?>
                                        
                                        <li class="nav-item">
                                            <a href="#" class="nav-link">
                                                <i class="nav-icon bi bi-diagram-3"></i>
                                                <p>Asignación de Curso<i class="nav-arrow bi bi-chevron-right"></i></p>
                                            </a>
                                            <ul class="nav nav-treeview">
                                                <li class="nav-item">
                                                    <a href="./cursos_entes_gestion.php" class="nav-link">
                                                        <i class="nav-icon bi bi-search"></i>
                                                        <p>Consulta</p>
                                                    </a>
                                                </li>
                                                <?php if (!$es_coordinador): ?>
                                                <li class="nav-item">
                                                    <a href="./cursos_entes_form.php" class="nav-link">
                                                        <i class="nav-icon bi bi-journal-plus"></i>
                                                        <p>Registro</p>
                                                    </a>
                                                </li>
                                                <?php endif; ?>
                                            </ul>
                                        </li>
                                    </ul>
                                </li>

                                <!-- 4. Apertura de Secciones (Solo Consulta) -->
                                <li class="nav-item">
                                    <a href="#" class="nav-link">
                                        <i class="nav-icon bi bi-door-open"></i>
                                        <p>Apertura de Secciones<i class="nav-arrow bi bi-chevron-right"></i></p>
                                    </a>
                                    <ul class="nav nav-treeview">
                                        <li class="nav-item">
                                            <a href="secciones_listado.php" class="nav-link">
                                                <i class="nav-icon bi bi-search"></i>
                                                <p>Consulta</p>
                                            </a>
                                        </li>
                                        <?php if (!$es_coordinador): ?>
                                        <li class="nav-item">
                                            <a href="secciones_form.php" class="nav-link">
                                                <i class="nav-icon bi bi-journal-plus"></i>
                                                <p>Registro</p>
                                            </a>
                                        </li>
                                        <?php endif; ?>
                                    </ul>
                                </li>
                            </ul>
                        </li>

                        <?php
                        // Bloque exclusivo de Administración (El Coordinador no lo ve)
                        if ($es_administrador):
                            echo '
                            <li class="nav-header">ADMINISTRACIÓN</li>
                            <li class="nav-item">
                                <a href="registrar_usuario.php" class="nav-link">
                                    <i class="nav-icon bi bi-person-plus-fill text-success"></i>
                                    <p class="text-success">Registrar Personal</p>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="./usuarios_listar.php" class="nav-link"><i class="nav-icon bi bi-clock-history"></i><p>Consulta de Usuarios</p></a>
                            </li>
                            <li class="nav-item">
                                <a href="auditoria_general.php" class="nav-link">
                                    <i class="nav-icon bi bi-shield-lock-fill text-info"></i>
                                    <p class="text-info">Auditoría General</p>
                                </a>
                            </li>';
                        endif;
                        ?>
                        
                    </ul>
                </nav>
            </div>
        </aside>