<?php
/**
 * db_connect.php
 * -----------------------------------------------------------------------
 * Purpose: Securely connect to the database using environment variables
 * to support both local development and cloud deployment (Railway).
 */

// --- Database Credentials ---
// We use getenv() to grab values from the server environment.
// If the server doesn't provide them (like on your laptop), we use the defaults.
$host = getenv('DB_HOST') ?: 'localhost'; 
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';
$db   = getenv('DB_NAME') ?: 'ecommerce_db';

// --- Create the Connection ---
$conn = new mysqli($host, $user, $pass, $db);

// --- Error Handling ---
if ($conn->connect_error) {
    die("<h2 style='font-family:sans-serif;color:#c0392b;'>
            Database Connection Failed<br>
            <small>" . htmlspecialchars($conn->connect_error) . "</small>
         </h2>");
}

// --- Set Character Encoding ---
$conn->set_charset("utf8mb4");

// $conn is now ready to use across your project.
?>
