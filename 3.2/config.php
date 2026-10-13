<?php
$host = "localhost";
$db   = "Storage_test";
$user = "root";
$pass = "";

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

// ---- AI assistant (Gemini) ----
// IMPORTANT: this key was shared in a chat, so create a NEW key in Google AI Studio and paste it here.
// Never put it in JavaScript or commit it to GitHub. You can also set it as an environment variable GEMINI_API_KEY.
define("AI_API_KEY", getenv("GEMINI_API_KEY") ?: "PASTE_YOUR_API_KEY_HEREEEEE");
define("AI_MODEL", "gemini-flash-latest"); 



