<?php
/**
 * Logout - IndieZone
 */
session_start();
session_unset();
session_destroy();

// Redireciona para o index oficial na raiz da public
header("Location: ../index.php");
exit();
