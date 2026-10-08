<?php
require "config.php";

/*
    This file handles BOTH:
    - Uploading files
    - Downloading files
*/

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["action"]) && $_POST["action"] === "upload") {

    if (!isset($_FILES["file"]) || $_FILES["file"]["error"] !== UPLOAD_ERR_OK) {
        die("Upload failed.");
    }

    $file = $_FILES["file"];
    $originalName = basename($file["name"]);
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    $allowed = [
        "jpg", "jpeg", "png", "gif", "webp",
        "pdf", "txt",
        "doc", "docx",
        "xls", "xlsx",
        "zip"
    ];

    if (!in_array($extension, $allowed, true)) {
        die("This file type is not allowed.");
    }

    $uploadDir = __DIR__ . "/uploads/";

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $storedName = bin2hex(random_bytes(16)) . ($extension ? "." . $extension : "");
    $destination = $uploadDir . $storedName;

   if (!move_uploaded_file($file["tmp_name"], $destination)) {
    die("Could not save the file.<br>
    Temporary file: " . $file["tmp_name"] . "<br>
    Destination: " . $destination);
   }

    $relativePath = "uploads/" . $storedName;

    $stmt = $conn->prepare(
        "INSERT INTO files (filename, filepath, uploaded_at) VALUES (?, ?, NOW())"
    );
    $stmt->bind_param("ss", $originalName, $relativePath);

    if (!$stmt->execute()) {
        @unlink($destination);
        die("Could not save file information.");
    }

    header("Location: index.php");
    exit;
}


/* DOWNLOAD */
if (isset($_GET["action"]) && $_GET["action"] === "download") {

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

    $filename = basename($file["filename"]);

    header("Content-Type: application/octet-stream");
    header("Content-Disposition: attachment; filename=\"" . addslashes($filename) . "\"");
    header("Content-Length: " . filesize($path));

    readfile($path);
    exit;
}

/* DELETE */
if (isset($_GET["action"]) && $_GET["action"] === "delete") {

    $id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;

    // Get the file path first
    $stmt = $conn->prepare("SELECT filepath FROM files WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();

    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        die("File not found in database.");
    }

    $file = $result->fetch_assoc();
    $path = __DIR__ . "/" . $file["filepath"];

    // Delete the physical file if it still exists
    if (is_file($path)) {
        unlink($path);
    }

    // Delete the database record
    $stmt = $conn->prepare("DELETE FROM files WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();

    // Go back to the file list
    header("Location: index.php");
    exit;
}

die("Invalid request.");
?>
