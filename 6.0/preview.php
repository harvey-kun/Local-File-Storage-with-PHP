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
    body {
        margin: 0;
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 20px;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
        background: #0f1115;
        color: #8b92a3;
        text-align: center;
    }
</style>
</head>
<body><p><?= htmlspecialchars($message) ?></p></body>
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
