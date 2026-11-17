<?php
$contrasena = '12345678';
$contrasena_hash = password_hash($contrasena, PASSWORD_DEFAULT);
echo $contrasena_hash;
?>