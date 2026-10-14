<?php
require "config.php";
require "auth.php";

// Only a real "Log out" button press (POST with the page's token) signs out
authStart(true);
if ($_SERVER["REQUEST_METHOD"] === "POST" && csrfValid()) {
    authLogout();
    header("Location: login.php");
    exit;
}

session_write_close();
header("Location: index.php");
exit;
