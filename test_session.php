<?php
session_start();
echo "Session ID: " . session_id() . "<br>";
echo "Session user: " . (isset($_SESSION['user']) ? $_SESSION['user'] : 'Not set') . "<br>";
print_r($_SESSION);
?>