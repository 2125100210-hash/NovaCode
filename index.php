<?php
require_once "conexion.php";

if (isset($_SESSION['usuario'])) {
    header("Location: equipos.php");
} else {
    header("Location: login.php");
}
exit;