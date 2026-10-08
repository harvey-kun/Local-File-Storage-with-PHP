<?php
require "config.php";

$id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;

$stmt = $conn->prepare("SELECT filename, filepath FROM files WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("File not found.");
}

$file = $result->fetch_assoc();
$path = __DIR__ . "/" . $file["filepath"];

if (!is_file($path)) {
    die("The file is missing from the server.");
}

$ext = strtolower(pathinfo($file["filename"], PATHINFO_EXTENSION));

$mimeTypes = [
    "jpg" => "image/jpeg",
    "jpeg" => "image/jpeg",
    "png" => "image/png",
    "gif" => "image/gif",
    "webp" => "image/webp",
    "pdf" => "application/pdf",
    "txt" => "text/plain"
];

if (!isset($mimeTypes[$ext])) {
    die("This file type cannot be previewed.");
}

header("Content-Type: " . $mimeTypes[$ext]);
header("Content-Length: " . filesize($path));

readfile($path);
exit;
?>
