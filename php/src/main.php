<?php
$host = '127.0.0.1';
$dbname = 'mydatabase';
$username = 'myuser';
$password = 'mypassword';
echo getenv('pg-dir');

// try {
//     $pdo = new PDO('pgsql:host=/run/postgresql;dbname=mydb', $user, $pass);
//     $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
//     echo "connection successful";
// } catch (PDOException $e) {
//     echo "connection error: " . $e->getMessage();
// }



?>