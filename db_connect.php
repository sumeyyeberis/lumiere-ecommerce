<?php
/**
 * db_connect.php
 * -----------------------------------------------------------------------
 * PURPOSE: Establish a single, reusable, secure connection to the MySQL
 *          database using PHP's MySQLi extension (object-oriented style).
 *
 * HOW TO USE: Simply include/require this file at the top of any PHP page
 *             that needs database access:
 *             require_once 'db_connect.php';
 *             Then use the $conn object to run queries.
 *
 * WHY MySQLi (not PDO)?
 *   MySQLi is MySQL-specific and slightly simpler for beginners. It fully
 *   supports prepared statements, which protect us from SQL Injection.
 *   PDO is more portable but adds complexity we don't need here.
 * -----------------------------------------------------------------------
 */

// --- Database Credentials ---
// Define these as constants so they cannot be accidentally overwritten
// anywhere else in the code.
define('DB_HOST', 'localhost');   // XAMPP always runs MySQL on localhost
define('DB_USER', 'root');        // Default XAMPP MySQL username
define('DB_PASS', '');            // Default XAMPP MySQL password (empty)
define('DB_NAME', 'ecommerce_db');// The database name from our SQL script

// --- Create the Connection ---
// new mysqli() attempts to connect. If it fails, we catch it below.
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// --- Error Handling ---
// connect_error is NULL on success, or a string describing the error.
// We use die() to stop execution completely if the DB is unreachable,
// because nothing else can work without it.
if ($conn->connect_error) {
    // In a real production app you would LOG this error privately and show
    // a generic "Service unavailable" message to the user. For development
    // we show the real error so we can debug quickly.
    die("<h2 style='font-family:sans-serif;color:#c0392b;'>
            Database Connection Failed<br>
            <small>" . htmlspecialchars($conn->connect_error) . "</small>
         </h2>");
}

// --- Set Character Encoding ---
// Force UTF-8 so that special characters (ü, ö, ç, emojis, etc.)
// are stored and retrieved correctly. This prevents "mojibake" (garbled text).
$conn->set_charset("utf8mb4");

// $conn is now a live MySQLi connection object available to any file
// that require_once'd this script.
?>
