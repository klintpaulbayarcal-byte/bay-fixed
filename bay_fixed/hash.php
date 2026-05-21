<?php
// Simple password-hash generator. Remove this file after use.
// Usage: http://localhost/<path>/web_system/hash.php?pw=YourPassword
$pw = isset($_GET['pw']) ? $_GET['pw'] : '';
if ($pw === '') {
    echo "<h3>Provide a password using ?pw=YourPassword</h3>";
    exit;
}
echo "<p>Password: <strong>" . htmlspecialchars($pw) . "</strong></p>";
echo "<p>Bcrypt hash (copy this into your `users.password` field):</p>";
echo '<pre>' . password_hash($pw, PASSWORD_DEFAULT) . '</pre>';
?>
