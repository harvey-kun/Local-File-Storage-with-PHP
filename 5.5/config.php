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

/*
    ---- AI assistant: the DEFAULT AI ----
    This is the only thing you have to edit here: paste an API key and a model name. The type of AI
    (Google Gemini / OpenAI-compatible such as OpenRouter, Groq, OpenAI... / Anthropic Claude) and its web
    address are detected from the key and the model name.

    More AIs can be added without touching this file: log in as admin > Admin > "AI setup".
    Those are tried first, in the order you set. This default AI is used when none is added there,
    and it is always the last one tried if the others are busy.

    Never put a key in JavaScript or on GitHub. Instead of pasting it below you can also set the
    environment variable AI_API_KEY on the server.
*/
define("AI_API_KEY", getenv("AI_API_KEY") ?: "PASTE_YOUR_API_KEY_HERE");
define("AI_MODEL",   "thinkingmachines/inkling:free");

/* Optional, only when the automatic detection guesses wrong:
define("AI_PROVIDER", "openai");                        // gemini | openai | anthropic
define("AI_BASE_URL", "https://openrouter.ai/api/v1");  // the service's web address
define("AI_TRIES",    2);                               // tries on one AI before switching to the next
define("AI_BUDGET",   150);                             // most seconds one question may take in total
define("AI_MAX_TOKENS", 1500);                          // longest answer
*/
