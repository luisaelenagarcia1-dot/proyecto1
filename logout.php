<?php
session_start();
session_unset(); // Elimina todas las variables de la sesión
session_destroy(); // Destruye la sesión por completo
header('Location: login.php'); // Redirige al login
exit;
?>