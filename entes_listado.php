<?php
session_start();
require_once 'header.php';
require_once 'conexion.php';

// Filtro de búsqueda opcional
$busqueda = trim($_GET['busqueda'] ?? '');

$sql = "SELECT * FROM entes_educativos WHERE 1=1";
$params = [];

if (!empty($busqueda)) {
    $sql .= " AND (nombre_ente ILIKE $1 OR sigla ILIKE $1 OR rif_nit ILIKE $1 OR contacto_persona ILIKE $1)";
    $params[] = "%" . $busqueda . "%";
}

$sql .= " ORDER BY nombre_ente ASC";
$resultado = ($params) ? pg_query_params($conexion, $sql, $params) : pg_query($conexion, $sql);
$total_filas = ($resultado) ? pg_num_rows($resultado) : 0;
?>

<main class="app-main" style="width: 100%; min-height: 100vh;">
    <div class="app-content-header">
        <div class="container-fluid px-4">
            <div class="row align-items-center mb-2">
                <div class="col-sm-6">
                    <h3 class="mb-0">
                        <i class="bi bi-building"></i> Consulta de Entes Educativos
                    </h3>
                </div>
                <div class="col-sm-6 text-end">
                    <a href="entes_pdf.php?busqueda=<?php echo urlencode($busqueda); ?>" target="_blank" class="btn btn-danger me-2">
                        <i class="bi bi-file-earmark-pdf"></i> Descargar PDF
                    </a>
                    <a href="entes_form.php" class="btn btn-primary">
                        <i class="bi bi-plus-circle"></i> Nuevo Ente Educativo
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="app-content">
        <div class="container-fluid px-4">

            <?php if (isset($_SESSION['mensaje_exito'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?php echo $_SESSION['mensaje_exito']; unset($_SESSION['mensaje_exito']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['mensaje_error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php echo $_SESSION['mensaje_error']; unset($_SESSION['mensaje_error']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- BARRA DE BÚSQUEDA -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card shadow-sm">
                        <div class="card-body">
                            <form action="entes_listado.php" method="GET" class="row g-3">
                                <div class="col-md-8">
                                    <input type="text" class="form-control" name="busqueda" value="<?php echo htmlspecialchars($busqueda); ?>" placeholder="Buscar por nombre, sigla, RIF o persona de contacto...">
                                </div>
                                <div class="col-md-4 d-flex">
                                    <button type="submit" class="btn btn-secondary me-2"><i class="bi bi-search"></i> Buscar</button>
                                    <a href="entes_listado.php" class="btn btn-outline-secondary"><i class="bi bi-x-lg"></i> Todos</a>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TABLA DE ENTES EDUCATIVOS -->
            <div class="row">
                <div class="col-12">
                    <div class="card card-primary card-outline shadow-sm">
                        <div class="card-header">
                            <h3 class="card-title">Listado de Instituciones Registradas</h3>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-striped table-hover align-middle mb-0">
                                    <thead class="table-dark">
                                        <tr>
                                            <th class="text-center" style="width: 50px;">N°</th>
                                            <th>Sigla</th>
                                            <th>Nombre del Ente / RIF</th>
                                            <th>Contacto / Teléfono</th>
                                            <th>Correo Electrónico</th>
                                            <th class="text-center" style="width: 150px;">Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($resultado && $total_filas > 0): ?>
                                            <?php 
                                            $i = 1;
                                            while ($row = pg_fetch_assoc($resultado)): 
                                                // Suponiendo que el identificador principal del ente en tu BD es 'id' o 'rif_nit'. 
                                                // Usamos 'id' si existe, o 'rif_nit' si manejas el RIF como clave en la relación.
                                                $ente_id = $row['id'] ?? $row['rif_nit'];
                                                $sigla_mostrada = !empty($row['sigla']) ? htmlspecialchars($row['sigla']) : '<span class="text-muted fst-italic">S/N</span>';
                                            ?>
                                                <tr>
                                                    <td class="text-center fw-bold"><?php echo $i++; ?></td>
                                                    <td>
                                                        <span class="badge bg-info text-dark fs-6"><?php echo $sigla_mostrada; ?></span>
                                                    </td>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($row['nombre_ente']); ?></strong><br>
                                                        <small class="text-muted">RIF/NIT: <strong><?php echo htmlspecialchars($row['rif_nit']); ?></strong></small>
                                                    </td>
                                                    <td>
                                                        <?php echo htmlspecialchars($row['contacto_persona'] ?? $row['persona_contacto'] ?? 'N/D'); ?><br>
                                                        <small class="text-muted"><i class="bi bi-telephone"></i> <?php echo htmlspecialchars($row['telefono'] ?? 'N/D'); ?></small>
                                                    </td>
                                                    <td>
                                                        <a href="mailto:<?php echo htmlspecialchars($row['email'] ?? ''); ?>">
                                                            <?php echo htmlspecialchars($row['email'] ?? 'N/D'); ?>
                                                        </a>
                                                    </td>
                                                    <td class="text-center">
                                                        <div class="btn-group" role="group">
                                                            <!-- Botón para Asignar Cursos (Color Azul / Info) -->
                                                            <a href="cursos_entes_form.php?ente_id=<?php echo urlencode($ente_id); ?>" class="btn btn-sm btn-info text-white" title="Asignar Cursos">
                                                                <i class="bi bi-journal-plus"></i>
                                                            </a>
                                                            <!-- Botón Editar -->
                                                            <a href="entes_form.php?rif=<?php echo urlencode($row['rif_nit']); ?>" class="btn btn-sm btn-warning" title="Editar Ente">
                                                                <i class="bi bi-pencil-square"></i>
                                                            </a>
                                                            <!-- Botón Eliminar -->
                                                            <a href="entes_procesar.php?accion=eliminar&rif=<?php echo urlencode($row['rif_nit']); ?>" 
                                                               class="btn btn-sm btn-danger" 
                                                               title="Eliminar Ente"
                                                               onclick="return confirm('¿Está seguro de eliminar este ente educativo?');">
                                                                <i class="bi bi-trash"></i>
                                                            </a>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endwhile; ?>
                                            <?php pg_free_result($resultado); ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="6" class="text-center py-4 text-muted">No se encontraron entes educativos registrados.</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</main>

<?php 
if (isset($conexion)) { pg_close($conexion); }
require_once 'footer.php'; 
?>