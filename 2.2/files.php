<?php
require "config.php";
require "filetypes.php";

/*
    This file handles:
    - Uploading files (one or many at once)
    - Downloading files
    - Deleting files
    - Live file list for auto-refresh   (GET  ?action=list&v=...)
    - Renaming a file                   (POST action=rename)
    - Saving notes on a file            (POST action=notes)
*/

$isAjax = ($_SERVER["HTTP_X_REQUESTED_WITH"] ?? "") === "XMLHttpRequest";

function jsonOut(array $data, int $status = 200): void {
    http_response_code($status);
    header("Content-Type: application/json; charset=utf-8");
    header("Cache-Control: no-store");
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function finishUpload(int $ok, array $errors): void {
    global $isAjax;

    if ($isAjax) {
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode(["ok" => $ok, "errors" => $errors]);
        exit;
    }

    // The session is only needed here (flash message for a classic, non-JavaScript upload)
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
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

/*
    Name used on disk: "<random>_<original name>", so the file keeps its real name and type
    when you look in the uploads folder (Windows Explorer, Linux file manager...).
    - The random part keeps two uploads with the same name from clashing
    - Characters Windows does not allow are replaced
    - Script / server-config files get ".txt" added, so they can never run as code
*/
function storedFileName(string $original): string {
    $name = preg_replace('/[<>:"\/\\\\|?*\x00-\x1F]/u', "_", $original);
    $name = rtrim((string)$name, ". ");
    if ($name === "" || $name[0] === ".") $name = "file" . $name;

    // Keep the stored name short enough for Windows paths
    if (mb_strlen($name, "UTF-8") > 100) {
        $ext  = pathinfo($name, PATHINFO_EXTENSION);
        $base = pathinfo($name, PATHINFO_FILENAME);
        $keep = 100 - ($ext !== "" ? mb_strlen($ext, "UTF-8") + 1 : 0);
        $name = mb_substr($base, 0, max(1, $keep), "UTF-8") . ($ext !== "" ? "." . $ext : "");
    }

    // Windows reserved device names (CON, NUL, COM1...)
    if (preg_match('/^(con|prn|aux|nul|com[1-9]|lpt[1-9])(\..*)?$/i', $name)) $name = "_" . $name;

    // Anything that a web server could execute or treat as config
    $danger = "php php3 php4 php5 php7 php8 phtml phar pht phps inc cgi pl py asp aspx jsp htaccess htpasswd shtml";
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (in_array($ext, explode(" ", $danger), true)) $name .= ".txt";

    return bin2hex(random_bytes(4)) . "_" . $name;
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

/* Returns the database row for a file id, or null */
function findFile(mysqli $conn, int $id): ?array {
    $stmt = $conn->prepare("SELECT id, filename, filepath, notes FROM files WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: null;
}

/* Deletes the physical file and its database record */
function deleteFile(mysqli $conn, int $id): bool {
    $file = findFile($conn, $id);
    if (!$file) return false;

    $path = __DIR__ . "/" . $file["filepath"];
    if (is_file($path)) {
        unlink($path);
    }

    $stmt = $conn->prepare("DELETE FROM files WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    return true;
}

/*
    Renames a file (database name only, the stored file on disk keeps its random name).
    If the new name has no extension, the old extension is kept so the file type and preview still work.
*/
function renameFile(mysqli $conn, int $id, string $newName): array {
    $file = findFile($conn, $id);
    if (!$file) return [false, "File not found."];

    $new = trim(str_replace("\\", "/", $newName));
    $new = rtrim(basename($new), ". ");
    if ($new === "" || $new === "..") return [false, "Enter a file name."];

    $oldExt = pathinfo($file["filename"], PATHINFO_EXTENSION);
    $newExt = pathinfo($new, PATHINFO_EXTENSION);
    if ($oldExt !== "" && $newExt === "") {
        $new .= "." . $oldExt;
    }

    $new = cleanFileName($new);

    $stmt = $conn->prepare("UPDATE files SET filename = ? WHERE id = ?");
    $stmt->bind_param("si", $new, $id);
    if (!$stmt->execute()) return [false, "Could not save the new name."];

    return [true, $new];
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // The whole request was bigger than post_max_size: PHP throws everything away
    $contentLength = (int)($_SERVER["CONTENT_LENGTH"] ?? 0);
    if (empty($_POST) && empty($_FILES) && $contentLength > 0) {
        finishUpload(0, ["The upload is larger than the server allows (post_max_size = " . ini_get("post_max_size") . ")."]);
    }

    /* UPLOAD */
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

        // notes is NOT NULL in the table and has no default, so it must be given a value here
        $stmt = $conn->prepare("INSERT INTO files (filename, filepath, uploaded_at, notes) VALUES (?, ?, NOW(), '')");
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

            // Readable name on disk (random prefix + original name; risky extensions are neutralised)
            $storedName  = storedFileName($originalName);
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

    /* RENAME */
    if (($_POST["action"] ?? "") === "rename") {
        [$ok, $result] = renameFile($conn, (int)($_POST["id"] ?? 0), (string)($_POST["name"] ?? ""));
        if ($ok) jsonOut(["ok" => true, "name" => $result]);
        jsonOut(["ok" => false, "error" => $result], 400);
    }

    /* NOTES */
    if (($_POST["action"] ?? "") === "notes") {
        $id    = (int)($_POST["id"] ?? 0);
        $notes = str_replace(["\r\n", "\r"], "\n", (string)($_POST["notes"] ?? ""));

        if (!mb_check_encoding($notes, "UTF-8")) {
            jsonOut(["ok" => false, "error" => "Notes contain invalid characters."], 400);
        }
        if (!findFile($conn, $id)) {
            jsonOut(["ok" => false, "error" => "File not found."], 404);
        }

        $notes = mb_substr($notes, 0, 900, "UTF-8");   // column is varchar(900)

        $stmt = $conn->prepare("UPDATE files SET notes = ? WHERE id = ?");
        $stmt->bind_param("si", $notes, $id);
        if (!$stmt->execute()) {
            jsonOut(["ok" => false, "error" => "Could not save the notes."], 500);
        }
        jsonOut(["ok" => true, "notes" => $notes]);
    }

    /* DELETE (used by the new interface) */
    if (($_POST["action"] ?? "") === "delete") {
        if (deleteFile($conn, (int)($_POST["id"] ?? 0))) jsonOut(["ok" => true]);
        jsonOut(["ok" => false, "error" => "File not found."], 404);
    }
}


/* LIVE LIST: browsers poll this; they only get the full list back when something changed */
if (isset($_GET["action"]) && $_GET["action"] === "list") {
    $files   = loadFiles($conn);
    $version = filesVersion($files);

    if (($_GET["v"] ?? "") === $version) {
        jsonOut(["v" => $version, "same" => true]);
    }
    jsonOut(["v" => $version, "files" => $files]);
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

/* DELETE (old link style, still works) */
if (isset($_GET["action"]) && $_GET["action"] === "delete") {

    $id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;

    if (!deleteFile($conn, $id)) {
        die("File not found in database.");
    }

    // Go back to the file list
    header("Location: index.php");
    exit;
}

die("Invalid request.");
?>
