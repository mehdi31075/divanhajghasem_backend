<?php
// Copy to variables.php and use the existing hosting database credentials.
$host = 'localhost';
$user = 'YOUR_DATABASE_USER';
$pass = 'YOUR_DATABASE_PASSWORD';
$database = 'YOUR_DATABASE_NAME';
$connect = new mysqli($host, $user, $pass, $database);
?>
