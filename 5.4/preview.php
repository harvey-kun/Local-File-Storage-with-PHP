<?php
require "config.php";
require "filetypes.php";
require "auth.php";

function fail($message, $code = 404) {
    http_response_code($code);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Preview unavailable</title>
<style>
    :root { --bg: #081b2f; --grid: rgba(120,175,230,.07); --text: #e6f0f8; --muted: #86a7c2; --line: #3a6a94; --accent: #ffd23f; color-scheme: dark; }
    @media (prefers-color-scheme: light) {
        :root { --bg: #eef4f9; --grid: rgba(10,37,64,.055); --text: #0a2540; --muted: #4a6883; --line: #8aa6bd; --accent: #0b4a8f; color-scheme: light; }
    }
    * { box-sizing: border-box; }
    body {
        margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px;
        font-family: "Archivo", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
        background-color: var(--bg);
        background-image: linear-gradient(var(--grid) 1px, transparent 1px), linear-gradient(90deg, var(--grid) 1px, transparent 1px);
        background-size: 28px 28px;
        color: var(--muted); text-align: center; line-height: 1.5;
    }
    .box { max-width: 320px; animation: rise .4s ease-out both; }
    .mark { width: 84px; height: 84px; padding: 22px; margin: 0 auto 16px; display: block; border: 1.5px dashed var(--line); color: var(--accent); fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
    h1 { margin: 0 0 6px; font-size: 1.1rem; font-weight: 800; font-stretch: 125%; color: var(--text); }
    p { margin: 0; overflow-wrap: anywhere; }
    @keyframes rise { from { opacity: 0; transform: translateY(10px); } }
    @media (prefers-reduced-motion: reduce) { .box { animation: none; } }
</style>
</head>
<body>
<div class="box">
    <svg class="mark" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h8l4 4v14H6zM14 3v4h4M9.5 13.5l5 5M14.5 13.5l-5 5"/></svg>
    <h1>Can&rsquo;t show this one</h1>
    <p><?= htmlspecialchars($message) ?></p>
</div>
</body>
</html>
    <?php
    exit;
}

// Signed-in users only
$user = requireLogin($conn, "plain");

$id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;

// Same answer for "no such file" and "this file was not shared with you"
$file = findAccessibleFile($conn, $id, $user);

if (!$file) {
    fail("File not found.");
}

$path = __DIR__ . "/" . $file["filepath"];

if (!is_file($path)) {
    fail("The file is missing from the server.");
}

$ext  = ftExt($file["filename"]);
$info = ftPreview($ext);

if ($info === null) {
    fail("This file type cannot be previewed.", 415);
}

[$kind, $mime] = $info;

// Audio extras for the player: ?meta=1 (title / artist / album as JSON) and ?cover=1 (the album cover image)
if (isset($_GET["meta"]) || isset($_GET["cover"])) {
    if ($kind !== "audio") {
        http_response_code(404);
        exit;
    }

    require __DIR__ . "/audiometa.php";
    $tags = audioMeta($path);

    if (isset($_GET["cover"])) {
        if (!$tags["cover"]) {
            http_response_code(404);
            exit;
        }
        header("Content-Type: " . $tags["cover"]["mime"]);
        header("X-Content-Type-Options: nosniff");
        header("Cache-Control: private, max-age=3600");
        header("Content-Length: " . strlen($tags["cover"]["data"]));
        echo $tags["cover"]["data"];
        exit;
    }

    header("Content-Type: application/json; charset=utf-8");
    header("Cache-Control: private, max-age=300");
    echo json_encode([
        "title"  => $tags["title"],
        "artist" => $tags["artist"],
        "album"  => $tags["album"],
        "cover"  => $tags["cover"] !== null,
    ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$headers = [
    "X-Content-Type-Options: nosniff",
    "Cache-Control: private, max-age=300",
];

// SVG can contain scripts: lock it down if someone opens the URL directly
if ($ext === "svg") {
    $headers[] = "Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox";
}

sendFile($path, $mime, dispositionHeader("inline", $file["filename"]), $headers);
