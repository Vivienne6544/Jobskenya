<?php
$host = "mysql-1d6ac083-jobskenya-db.b.aivencloud.com";
$port = 24231;
$user = "avnadmin";
$pass = "AVNS_RgeCK3USx0P4j4zPQV5";
$dbname = "jobskedb";

$conn = mysqli_init();
mysqli_ssl_set($conn, NULL, NULL, NULL, NULL, NULL);

$success = mysqli_real_connect($conn, $host, $user, $pass, $dbname, $port, NULL, MYSQLI_CLIENT_SSL);

if (!$success) {
    die("Connection failed: " . mysqli_connect_error());
}