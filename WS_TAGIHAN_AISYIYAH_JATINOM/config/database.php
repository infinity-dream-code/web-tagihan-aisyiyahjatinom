<?php
class Database {
    private $pdo;

    public function __construct() {
        $host = "10.99.23.26";
        $port = "3306";
        $dbname = "blitar_sd_aisyiyah_jatinom"; 
        $username = "root";
        $password = "Smartpay1ct";

        $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8";
        $this->pdo = new PDO($dsn, $username, $password);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function getConnection() {
        return $this->pdo;
    }
}
