<?php
session_start();
require_once 'header.php'; // Incluimos tu cabecera

echo '<main class="app-main"><div class="app-content"><div class="container-fluid">';
echo '<h1>Contenido de la Sesión Actual</h1>';
echo '<pre style="background-color: #eee; padding: 10px; border: 1px solid #ccc;">';

// Esto imprimirá en pantalla TODO lo que está en tu sesión
print_r($_SESSION);

echo '</pre>';
echo '</div></div></main>';
require_once 'footer.php'; // Incluimos tu pie de página
?>