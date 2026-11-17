<?php
$servidor = "127.0.0.1"; // Cambiado para forzar la conexión IPv4
$puerto = "5433";
$usuario = "postgres"; 
$contrasena = "Sebas2108"; 
$base_de_datos = "sgr_iactp";

$cadena_conexion = "host=$servidor port=$puerto dbname=$base_de_datos user=$usuario password=$contrasena";

// Quitamos el @ para que PHP nos muestre el error nativo si falla
$conexion = pg_connect($cadena_conexion);

if (!$conexion) {
    die("Error crítico: " . pg_last_error());
}
?>