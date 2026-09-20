<?php
// Puente hacia la configuración central de base de datos.
// Se deja este archivo (mismo nombre de siempre) porque otros archivos
// del panel HQ ya hacen require_once 'conexion.php'; así no hay que
// tocarlos y todos quedan usando la misma fuente de credenciales.
require_once __DIR__ . '/../../config.php';
