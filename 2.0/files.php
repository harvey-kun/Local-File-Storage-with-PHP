<?php
require "config.php";
require "filetypes.php";
session_start();

/*
    This file handles:
    - Uploading files (one or many at once)
    - Downloading files
    - Deleting files
*/

$isAjax = ($_SERVER["HTTP_X_REQUESTED_WITH"] ?? "") === "XMLHttpRequest";

function finishUpload(int $ok, array $errors): void {
    global $isAjax;

    if ($isAjax) {
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode(["ok" => $ok, "errors" => $errors]);
        exit;
    }

    $_SESSION["flash"] = ["ok" => $ok, "errors" => $errors];
    header("Location: index.php");
    exit;
}

function cleanFileName(string $name): string {
    $name = basename(str_replace("\\", "/", $name));
    $name = preg_replace('/[\x00-\x1F\x7F]/', "", $name);
    $name = trim($name);

    if ($name === "" || $name === "." || $name === "..") {
        $name = "file";
    }

    if (mb_strlen($name, "UTF-8") > 180) {
        $ext  = pathinfo($name, PATHINFO_EXTENSION);
        $base = pathinfo($name, PATHINFO_FILENAME);
        $keep = 180 - ($ext !== "" ? mb_strlen($ext, "UTF-8") + 1 : 0);
        $name = mb_substr($base, 0, max(1, $keep), "UTF-8") . ($ext !== "" ? "." . $ext : "");
    }

    return $name;
}

function uploadErrorText(int $code): string {
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
            return "is larger than the server limit (upload_max_filesize = " . ini_get("upload_max_filesize") . ")";
        case UPLOAD_ERR_FORM_SIZE:
            return "is larger than the allowed size";
        case UPLOAD_ERR_PARTIAL:
            return "was only partially uploaded";
        case UPLOAD_ERR_NO_TMP_DIR:
            return "could not be saved (missing temporary folder)";
        case UPLOAD_ERR_CANT_WRITE:
            return "could not be written to disk";
        case UPLOAD_ERR_EXTENSION:
            return "was stopped by a PHP extension";
        default:
            return "failed to upload";
    }
}

/* UPLOAD */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // The whole request was bigger than post_max_size: PHP throws everything away
    $contentLength = (int)($_SERVER["CONTENT_LENGTH"] ?? 0);
    if (empty($_POST) && empty($_FILES) && $contentLength > 0) {
        finishUpload(0, ["The upload is larger than the server allows (post_max_size = " . ini_get("post_max_size") . ")."]);
    }

    if (($_POST["action"] ?? "") === "upload") {

        if (empty($_FILES["files"])) {
            finishUpload(0, ["No files were received."]);
        }

        // Normalise to arrays so one file and many files use the same code
        $f = $_FILES["files"];
        if (!is_array($f["name"])) {
            foreach (["name", "type", "tmp_name", "error", "size"] as $k) {
                $f[$k] = [$f[$k]];
            }
        }

        $uploadDir = __DIR__ . "/uploads/";

        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
            finishUpload(0, ["Could not create the uploads folder."]);
        }

        // Block direct web access to uploaded files (they are served by files.php / preview.php)
        $htaccess = $uploadDir . ".htaccess";
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess,
                "# Files are served through files.php and preview.php only\n" .
                "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n" .
                "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
        }

        if (!is_writable($uploadDir)) {
            finishUpload(0, ["The uploads folder is not writable."]);
        }

        $stmt = $conn->prepare("INSERT INTO files (filename, filepath, uploaded_at) VALUES (?, ?, NOW())");
        $dbName = "";
        $dbPath = "";
        $stmt->bind_param("ss", $dbName, $dbPath);

        $okCount = 0;
        $errors  = [];

        foreach ($f["name"] as $i => $rawName) {
            $code = (int)$f["error"][$i];

            if ($code === UPLOAD_ERR_NO_FILE) continue;

            $originalName = cleanFileName((string)$rawName);

            if ($code !== UPLOAD_ERR_OK) {
                $errors[] = "\"" . $originalName . "\" " . uploadErrorText($code) . ".";
                continue;
            }

            // Random name with NO extension: an uploaded .php file can never be executed
            $storedName  = bin2hex(random_bytes(16));
            $destination = $uploadDir . $storedName;

            if (!move_uploaded_file($f["tmp_name"][$i], $destination)) {
                $errors[] = "\"" . $originalName . "\" could not be saved on the server.";
                continue;
            }

            $dbName = $originalName;
            $dbPath = "uploads/" . $storedName;

            if (!$stmt->execute()) {
                @unlink($destination);
                $errors[] = "\"" . $originalName . "\" could not be recorded in the database.";
                continue;
            }

            $okCount++;
        }

        finishUpload($okCount, $errors);
    }
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

    sendFile($path, "application/octet-stream", dispositionHeader("attachment", basename($file["filename"])));
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
