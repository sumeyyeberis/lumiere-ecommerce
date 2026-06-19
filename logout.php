<?php
/**
 * logout.php — Session Destruction
 * -----------------------------------------------------------------------
 * WHAT THIS SCRIPT DOES:
 *   1. Starts the session (required to access it).
 *   2. Clears all session variables.
 *   3. Destroys the session itself (removes server-side data + cookie).
 *   4. Redirects the user to the home page.
 *
 * NOTE: This is a pure PHP script with no HTML output. It exists solely
 * to perform the three steps above and redirect. Keeping it as a separate
 * file means any page can add a "Logout" link pointing to logout.php.
 *
 * KEY CONCEPTS:
 *   - session_unset() vs session_destroy():
 *       session_unset()  → empties the $_SESSION array (data gone)
 *       session_destroy() → removes the session file on the server
 *                           AND invalidates the session cookie
 *     Both together = complete, secure logout.
 *   - The setcookie() call explicitly expires the PHPSESSID cookie in
 *     the user's browser. Without this, the cookie lingers even though
 *     the server-side session is gone (harmless but untidy).
 * -----------------------------------------------------------------------
 */

// Step 1: Initialize the session so PHP can access it
session_start();

// Step 2: Clear all variables stored in $_SESSION
// This includes user_id, first_name, role, cart, flash messages, etc.
session_unset();

// Step 3a: Delete the session cookie from the browser.
// We use session_get_cookie_params() to retrieve the exact settings PHP
// used when setting the cookie, so we delete the right one.
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),   // Usually "PHPSESSID"
        '',               // Empty value
        time() - 42000,   // Expiry in the past → browser deletes it
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Step 3b: Destroy the server-side session data file
session_destroy();

// Step 4: Send the user back to the home page with a goodbye message.
// We start a NEW session just to store the flash message — this is fine
// because session_destroy() ended the old one.
session_start();
$_SESSION['flash_message'] = "You have been logged out successfully.";
$_SESSION['flash_type']    = 'success';

header("Location: index.php");
exit; // Always call exit after header("Location: ...") to stop script execution
?>
