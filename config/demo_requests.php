<?php
function demo_requests_install(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS demo_requests (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,contact_name VARCHAR(120) NOT NULL,school_name VARCHAR(200) NOT NULL,email VARCHAR(150) NOT NULL,phone VARCHAR(30) NOT NULL,student_count INT UNSIGNED NOT NULL,plan VARCHAR(20) NOT NULL DEFAULT '',requirements TEXT NOT NULL,status ENUM('New','Contacted','Closed') NOT NULL DEFAULT 'New',consented_at DATETIME NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,INDEX(status,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS demo_request_limits (ip_hash CHAR(64) PRIMARY KEY,window_start DATETIME NOT NULL,request_count INT NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
