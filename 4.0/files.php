<?php
require "config.php";
require "filetypes.php";
require "auth.php";

/*
    This file handles (every request needs a signed-in user):
    - Uploading files (one or many at once) together with who may see them
    - Downloading files (every download is logged: who and when)
    - Deleting / renaming / notes / sharing: the uploader and the admin only
    - Live file list for auto-refresh      (GET  ?action=list&v=...)
    - People for the share menu            (GET  ?action=users)
    - Download log of one file             (GET  ?action=downloads&id=...)
    - Change username / change password    (POST action=change_username / change_password)
    - Delete my account                    (POST action=delete_account)
    - Admin: list / remove accounts        (GET ?action=admin_users, POST action=admin_delete_user)
    - Admin: activity log                  (GET ?action=admin_log&type=ai|downloads|logins)
*/

$isAjax = ($_SERVER["HTTP_X_REQUESTED_WITH"] ?? "") === "XMLHttpRequest";
$isPost = $_SERVER["REQUEST_METHOD"] === "POST";
$act    = (string)($_POST["action"] ?? $_GET["action"] ?? "");

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
    authStart(true);
    $_SESSION["flash"] = ["ok" => $ok, "errors" => $errors];
    header("Location: index.php");
    exit;
}

/*
    Everything below needs a signed-in user. The session is opened read-only (it never blocks the live
    refresh or media streaming) except when the password changes, because that renews the session fingerprint.
*/
$user = requireLogin($conn, $isAjax ? "json" : ($isPost ? "page" : "plain"), $act === "change_password");

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
    Name used on disk = the file's real name: test.txt is saved as test.txt, music.flac as music.flac.
    The uploads folder stays readable in Windows Explorer / Linux file managers and every file keeps its type.
    - Characters Windows does not allow are replaced with "_"
    - Script / server-config files are saved as "name_php.txt" so they can never run as code
    (a name that is already taken is handled by reserveDiskName)
*/
function diskFileName(string $original): string {
    $name = $original;

    // PHP on Windows uses the ANSI code page for file names, so plain letters are the safe choice there
    if (DIRECTORY_SEPARATOR === "\\" && preg_match('/[^\x20-\x7E]/', $name)) {
        $t = function_exists("iconv") ? @iconv("UTF-8", "ASCII//TRANSLIT//IGNORE", $name) : false;
        $name = ($t !== false && trim($t) !== "") ? $t : $name;
        $name = preg_replace('/[^\x20-\x7E]+/', "_", $name);
    }

    // Characters Windows file names can't contain (the pattern only matches ASCII, so UTF-8 text is untouched)
    $name = preg_replace('/[<>:"\/\\\\|?*\x00-\x1F\x7F]/', "_", $name);
    $name = rtrim((string)$name, ". ");
    if ($name === "" || $name[0] === ".") $name = "file" . $name;

    // Keep the name short enough for Windows paths
    if (mb_strlen($name, "UTF-8") > 100) {
        $ext  = pathinfo($name, PATHINFO_EXTENSION);
        $base = pathinfo($name, PATHINFO_FILENAME);
        $keep = 100 - ($ext !== "" ? mb_strlen($ext, "UTF-8") + 1 : 0);
        $name = mb_substr($base, 0, max(1, $keep), "UTF-8") . ($ext !== "" ? "." . $ext : "");
    }

    // Windows reserved device names (CON, NUL, COM1...)
    if (preg_match('/^(con|prn|aux|nul|com[1-9]|lpt[1-9])(\..*)?$/i', $name)) $name = "_" . $name;

    // Anything that a web server could execute or treat as config: "shell.php" -> "shell_php.txt"
    $danger = "php php3 php4 php5 php7 php8 phtml phar pht phps inc cgi pl py asp aspx jsp htaccess htpasswd shtml";
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (in_array($ext, explode(" ", $danger), true)) {
        $name = substr($name, 0, -(strlen($ext) + 1)) . "_" . $ext . ".txt";
    }

    return $name;
}

/*
    Claims a free file name inside $dir by creating an empty file ("x" mode fails if the name exists,
    so two uploads can never take the same name). If "test.txt" is taken it tries "test (1).txt",
    "test (2).txt"... like Windows does. Returns the claimed name, or "" if nothing could be created.
*/
function reserveDiskName(string $dir, string $name): string {
    $ext  = pathinfo($name, PATHINFO_EXTENSION);
    $dot  = $ext !== "" ? "." . $ext : "";
    $base = $ext !== "" ? substr($name, 0, -strlen($dot)) : $name;

    for ($n = 0; $n < 1000; $n++) {
        $cand = $n === 0 ? $name : $base . " (" . $n . ")" . $dot;
        $h = @fopen($dir . $cand, "x");
        if ($h) { fclose($h); return $cand; }
    }

    $cand = $base . "-" . bin2hex(random_bytes(4)) . $dot;
    $h = @fopen($dir . $cand, "x");
    if ($h) { fclose($h); return $cand; }

    return "";
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

/* The file, but only when this person may change it (uploader or admin). Otherwise answers and stops. */
function manageableFile(mysqli $conn, array $user, int $id): array {
    $f = findAccessibleFile($conn, $id, $user);
    if (!$f) jsonOut(["ok" => false, "error" => "File not found."], 404);
    if (!canManageFile($f, $user)) {
        jsonOut(["ok" => false, "error" => "Only the person who uploaded this file (or the admin) can do that."], 403);
    }
    return $f;
}

/* Deletes the physical file, its sharing and download records, and the database record */
function deleteFile(mysqli $conn, array $file): void {
    $path = __DIR__ . "/" . $file["filepath"];
    if (is_file($path)) {
        @unlink($path);
    }

    $id = (int)$file["id"];
    foreach (["file_shares" => "file_id", "file_downloads" => "file_id", "files" => "id"] as $table => $col) {
        $stmt = $conn->prepare("DELETE FROM `$table` WHERE `$col` = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
    }
}

/*
    Removes an account.
    $deleteFiles = true  -> the files it uploaded are deleted too
    $deleteFiles = false -> the files stay on the site and are handed to the admin
    Download history rows stay (they show as "deleted account").
*/
function removeAccount(mysqli $conn, array $target, bool $deleteFiles): void {
    $tid = (int)$target["id"];

    if ($deleteFiles) {
        $stmt = $conn->prepare("SELECT id, filepath FROM files WHERE owner_id = ?");
        $stmt->bind_param("i", $tid);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) deleteFile($conn, $row);
    } else {
        $r   = $conn->query("SELECT id FROM users WHERE is_admin = 1 ORDER BY id LIMIT 1");
        $adm = (int)($r ? ($r->fetch_row()[0] ?? 0) : 0);
        if ($adm > 0) {
            $stmt = $conn->prepare("UPDATE files SET owner_id = ? WHERE owner_id = ?");
            $stmt->bind_param("ii", $adm, $tid);
            $stmt->execute();
        }
    }

    $stmt = $conn->prepare("DELETE FROM file_shares WHERE user_id = ?");
    $stmt->bind_param("i", $tid);
    $stmt->execute();

    $stmt = $conn->prepare("DELETE FROM ai_log WHERE user_id = ?");
    $stmt->bind_param("i", $tid);
    $stmt->execute();

    $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
    $stmt->bind_param("i", $tid);
    $stmt->execute();
}

/*
    Renames a file: the name shown on the site AND the real file in the uploads folder.
    If the new name has no extension, the old extension is kept so the file type and preview still work.
*/
function renameFile(mysqli $conn, array $file, string $newName): array {
    $id = (int)$file["id"];

    $new = trim(str_replace("\\", "/", $newName));
    $new = rtrim(basename($new), ". ");
    if ($new === "" || $new === "..") return [false, "Enter a file name."];

    $oldExt = pathinfo($file["filename"], PATHINFO_EXTENSION);
    $newExt = pathinfo($new, PATHINFO_EXTENSION);
    if ($oldExt !== "" && $newExt === "") {
        $new .= "." . $oldExt;
    }

    $new = cleanFileName($new);

    $dir      = __DIR__ . "/uploads/";
    $oldPath  = __DIR__ . "/" . $file["filepath"];
    $newRel   = $file["filepath"];     // new location (relative), changes only if the file on disk is renamed
    $newPath  = $oldPath;
    $moved    = false;                 // the physical file was renamed
    $reserved = false;                 // we created an empty placeholder that must be cleaned up on failure

    if (is_file($oldPath)) {
        $wanted  = diskFileName($new);
        $oldBase = basename($file["filepath"]);

        if ($wanted !== $oldBase) {
            if (file_exists($dir . $wanted) && realpath($dir . $wanted) === realpath($oldPath)) {
                $target = $wanted;                          // same file, only the letter case changes (Windows)
            } else {
                $target = reserveDiskName($dir, $wanted);   // adds " (1)" if another file already has this name
                $reserved = $target !== "";
            }
            if ($target === "") return [false, "Could not rename the file on the server."];

            $newPath = $dir . $target;
            if (!@rename($oldPath, $newPath)) {
                if ($reserved) @unlink($newPath);
                return [false, "Could not rename the file on the server."];
            }
            $moved  = true;
            $newRel = "uploads/" . $target;
        }
    }

    $stmt = $conn->prepare("UPDATE files SET filename = ?, filepath = ? WHERE id = ?");
    $stmt->bind_param("ssi", $new, $newRel, $id);
    if (!$stmt->execute()) {
        if ($moved) @rename($newPath, $oldPath);            // put the file back, nothing changed
        return [false, "Could not save the new name."];
    }

    return [true, $new];
}

if ($isPost) {

    // The whole request was bigger than post_max_size: PHP throws everything away
    $contentLength = (int)($_SERVER["CONTENT_LENGTH"] ?? 0);
    if (empty($_POST) && empty($_FILES) && $contentLength > 0) {
        finishUpload(0, ["The upload is larger than the server allows (post_max_size = " . ini_get("post_max_size") . ")."]);
    }

    // Every change needs the page's secret token (stops other websites from sending requests for you)
    if (!csrfValid()) {
        if ($act === "upload" && !$isAjax) finishUpload(0, ["This page was open for too long. Reload it and try again."]);
        jsonOut(["ok" => false, "error" => "This page is out of date. Reload it and try again."], 403);
    }

    /* UPLOAD */
    if ($act === "upload") {

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

        // Who may see the new files: nothing chosen = everyone (the default)
        $ownerId = (int)$user["id"];
        [$vis, $shareIds] = normalizeSharing($conn, $ownerId, (string)($_POST["visibility"] ?? ""), parseIdList($_POST["shares"] ?? ""));

        // notes is NOT NULL in the table and has no default, so it must be given a value here
        $stmt = $conn->prepare("INSERT INTO files (filename, filepath, uploaded_at, notes, owner_id, visibility) VALUES (?, ?, NOW(), '', ?, ?)");
        $dbName = "";
        $dbPath = "";
        $stmt->bind_param("ssis", $dbName, $dbPath, $ownerId, $vis);

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

            // The file keeps its real name on disk (test.txt stays test.txt). If that name is already
            // taken, " (1)", " (2)"... is added. Script files are saved as name_php.txt so they never run.
            $storedName = reserveDiskName($uploadDir, diskFileName($originalName));
            if ($storedName === "") {
                $errors[] = "\"" . $originalName . "\" could not be saved on the server.";
                continue;
            }
            $destination = $uploadDir . $storedName;

            // (this replaces the empty placeholder that reserveDiskName just created)
            if (!move_uploaded_file($f["tmp_name"][$i], $destination)) {
                @unlink($destination);
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

            if ($vis === "custom") writeShares($conn, (int)$conn->insert_id, $shareIds);

            $okCount++;
        }

        finishUpload($okCount, $errors);
    }

    /* RENAME */
    if ($act === "rename") {
        $file = manageableFile($conn, $user, (int)($_POST["id"] ?? 0));
        [$ok, $result] = renameFile($conn, $file, (string)($_POST["name"] ?? ""));
        if ($ok) jsonOut(["ok" => true, "name" => $result]);
        jsonOut(["ok" => false, "error" => $result], 400);
    }

    /* NOTES */
    if ($act === "notes") {
        $file  = manageableFile($conn, $user, (int)($_POST["id"] ?? 0));
        $notes = str_replace(["\r\n", "\r"], "\n", (string)($_POST["notes"] ?? ""));

        if (!mb_check_encoding($notes, "UTF-8")) {
            jsonOut(["ok" => false, "error" => "Notes contain invalid characters."], 400);
        }

        $notes = mb_substr($notes, 0, 900, "UTF-8");   // column is varchar(900)
        $id    = (int)$file["id"];

        $stmt = $conn->prepare("UPDATE files SET notes = ? WHERE id = ?");
        $stmt->bind_param("si", $notes, $id);
        if (!$stmt->execute()) {
            jsonOut(["ok" => false, "error" => "Could not save the notes."], 500);
        }
        jsonOut(["ok" => true, "notes" => $notes]);
    }

    /* SHARE: everyone / only me / specific people */
    if ($act === "share") {
        $file = manageableFile($conn, $user, (int)($_POST["id"] ?? 0));
        $id   = (int)$file["id"];

        $wanted = (string)($_POST["visibility"] ?? "private");
        [$vis, $shareIds] = normalizeSharing($conn, (int)$file["owner_id"], $wanted === "" ? "private" : $wanted, parseIdList($_POST["shares"] ?? ""));

        $stmt = $conn->prepare("UPDATE files SET visibility = ? WHERE id = ?");
        $stmt->bind_param("si", $vis, $id);
        if (!$stmt->execute()) {
            jsonOut(["ok" => false, "error" => "Could not save the sharing settings."], 500);
        }
        writeShares($conn, $id, $vis === "custom" ? $shareIds : []);

        jsonOut(["ok" => true, "visibility" => $vis, "shared" => $shareIds]);
    }

    /* DELETE */
    if ($act === "delete") {
        $file = manageableFile($conn, $user, (int)($_POST["id"] ?? 0));
        deleteFile($conn, $file);
        jsonOut(["ok" => true]);
    }

    /* CHANGE USERNAME (needs the current password) */
    if ($act === "change_username") {
        if (authThrottled($conn)) {
            jsonOut(["ok" => false, "error" => "Too many attempts. Please wait a few minutes and try again."], 429);
        }
        if (!authCheckPassword($conn, $user, (string)($_POST["password"] ?? ""), false)) {
            authRecordAttempt($conn);
            jsonOut(["ok" => false, "error" => "Your password is wrong."], 400);
        }

        $new = trim((string)($_POST["username"] ?? ""));
        $err = authChangeUsername($conn, $user, $new);
        if ($err !== null) jsonOut(["ok" => false, "error" => $err], 400);

        jsonOut(["ok" => true, "name" => $new]);
    }

    /* CHANGE PASSWORD (needs the current password; every other device is signed out) */
    if ($act === "change_password") {
        if (authThrottled($conn)) {
            jsonOut(["ok" => false, "error" => "Too many attempts. Please wait a few minutes and try again."], 429);
        }

        $new = (string)($_POST["password"] ?? "");
        if ($new !== (string)($_POST["password2"] ?? "")) {
            jsonOut(["ok" => false, "error" => "The two new passwords don't match."], 400);
        }

        $err = authChangePassword($conn, $user, (string)($_POST["current"] ?? ""), $new);
        if ($err !== null) {
            if ($err === "Your current password is wrong.") authRecordAttempt($conn);
            jsonOut(["ok" => false, "error" => $err], 400);
        }

        jsonOut(["ok" => true]);
    }

    /* DELETE MY ACCOUNT (needs the password; the admin account can't be deleted) */
    if ($act === "delete_account") {
        if ($user["is_admin"]) {
            jsonOut(["ok" => false, "error" => "The admin account can't be deleted."], 403);
        }
        if (authThrottled($conn)) {
            jsonOut(["ok" => false, "error" => "Too many attempts. Please wait a few minutes and try again."], 429);
        }
        if (!authCheckPassword($conn, $user, (string)($_POST["password"] ?? ""), false)) {
            authRecordAttempt($conn);
            jsonOut(["ok" => false, "error" => "Your password is wrong."], 400);
        }

        removeAccount($conn, $user, ($_POST["delete_files"] ?? "") === "1");
        authLogout();
        jsonOut(["ok" => true]);
    }

    /* ADMIN: remove somebody else's account */
    if ($act === "admin_delete_user") {
        if (!$user["is_admin"]) jsonOut(["ok" => false, "error" => "Only the admin can do that."], 403);

        $target = authUserById($conn, (int)($_POST["id"] ?? 0));
        if (!$target) jsonOut(["ok" => false, "error" => "Account not found."], 404);
        if ($target["is_admin"]) jsonOut(["ok" => false, "error" => "Admin accounts can't be removed here."], 400);

        removeAccount($conn, $target, ($_POST["delete_files"] ?? "") === "1");
        jsonOut(["ok" => true]);
    }

    jsonOut(["ok" => false, "error" => "Unknown request."], 400);
}


/* LIVE LIST: browsers poll this; they only get the full list back when something changed */
if ($act === "list") {
    $files   = loadFiles($conn, $user);
    $version = filesVersion($files);

    if (($_GET["v"] ?? "") === $version) {
        jsonOut(["v" => $version, "same" => true]);
    }
    jsonOut(["v" => $version, "files" => $files]);
}

/* PEOPLE FOR THE SHARE MENU (everybody except me; the admin sees every file anyway) */
if ($act === "users") {
    $others = array_values(array_filter(shareableUsers($conn), fn($u) => $u["id"] !== (int)$user["id"]));
    jsonOut(["users" => $others]);
}

/* ADMIN: all accounts with their file counts */
if ($act === "admin_users") {
    if (!$user["is_admin"]) jsonOut(["ok" => false, "error" => "Only the admin can do that."], 403);

    $res = $conn->query("SELECT u.id, u.username, u.is_admin, u.agreed_ai_at,
                                (SELECT COUNT(*) FROM files f WHERE f.owner_id = u.id) AS n
                           FROM users u ORDER BY u.is_admin DESC, u.username");
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $rows[] = ["id" => (int)$r["id"], "name" => (string)$r["username"], "admin" => (int)$r["is_admin"] === 1, "files" => (int)$r["n"]];
    }
    jsonOut(["ok" => true, "users" => $rows]);
}

/* ADMIN: activity log. type=ai -> last AI uses (from ai_log, no question text), type=downloads -> last downloads (from file_downloads) */
if ($act === "admin_log") {
    if (!$user["is_admin"]) jsonOut(["ok" => false, "error" => "Only the admin can do that."], 403);

    $type = (string)($_GET["type"] ?? "ai");
    $rows = [];

    if ($type === "downloads") {
        $res = $conn->query("SELECT d.downloaded_at AS at, u.username, f.filename
                               FROM file_downloads d
                               LEFT JOIN users u ON u.id = d.user_id
                               LEFT JOIN files f ON f.id = d.file_id
                              ORDER BY d.id DESC LIMIT 100");
        while ($r = $res->fetch_assoc()) {
            $rows[] = ["at" => (string)$r["at"],
                       "user" => $r["username"] !== null ? (string)$r["username"] : "deleted account",
                       "file" => $r["filename"] !== null ? (string)$r["filename"] : "deleted file"];
        }
    } elseif ($type === "logins") {
        // Failed log-in / sign-up attempts per IP address (login_attempts). A successful log-in clears that IP's rows.
        $res = $conn->query("SELECT ip, attempted_at AS at FROM login_attempts ORDER BY attempted_at DESC LIMIT 100");
        while ($r = $res->fetch_assoc()) {
            $rows[] = ["at" => (string)$r["at"], "user" => (string)$r["ip"], "file" => "Failed log-in or sign-up attempt"];
        }
    } else {
        $res = $conn->query("SELECT l.asked_at AS at, u.username, f.filename
                               FROM ai_log l
                               LEFT JOIN users u ON u.id = l.user_id
                               LEFT JOIN files f ON f.id = l.file_id
                              ORDER BY l.asked_at DESC LIMIT 100");
        while ($r = $res->fetch_assoc()) {
            $rows[] = ["at" => (string)$r["at"],
                       "user" => $r["username"] !== null ? (string)$r["username"] : "deleted account",
                       "file" => $r["filename"] !== null ? (string)$r["filename"] : "unknown file"];
        }
    }
    jsonOut(["ok" => true, "type" => $type, "rows" => $rows]);
}

/* WHO DOWNLOADED A FILE (uploader and admin only) */
if ($act === "downloads") {
    $file = findAccessibleFile($conn, (int)($_GET["id"] ?? 0), $user);
    if (!$file) jsonOut(["ok" => false, "error" => "File not found."], 404);
    if (!canManageFile($file, $user)) jsonOut(["ok" => false, "error" => "Not allowed."], 403);

    $id = (int)$file["id"];
    $stmt = $conn->prepare("SELECT u.username, d.downloaded_at FROM file_downloads d LEFT JOIN users u ON u.id = d.user_id WHERE d.file_id = ? ORDER BY d.id DESC LIMIT 200");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $rows[] = ["user" => $r["username"] !== null ? (string)$r["username"] : "deleted account", "at" => $r["downloaded_at"]];
    }

    $stmt = $conn->prepare("SELECT COUNT(*) FROM file_downloads WHERE file_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $total = (int)($stmt->get_result()->fetch_row()[0] ?? 0);

    jsonOut(["ok" => true, "total" => $total, "downloads" => $rows]);
}

/* DOWNLOAD */
if ($act === "download") {

    $id   = isset($_GET["id"]) ? (int)$_GET["id"] : 0;
    $file = findAccessibleFile($conn, $id, $user);   // same answer for "no such file" and "not yours to see"

    if (!$file) {
        http_response_code(404);
        die("File not found.");
    }

    $path = __DIR__ . "/" . $file["filepath"];

    if (!is_file($path)) {
        http_response_code(404);
        die("The file is missing from the server.");
    }

    // Remember who downloaded it (a resumed download that starts in the middle is not counted again)
    $range = trim((string)($_SERVER["HTTP_RANGE"] ?? ""));
    if ($range === "" || preg_match('/^bytes=0-/', $range)) {
        $uid = (int)$user["id"];
        $fid = (int)$file["id"];
        $stmt = $conn->prepare("INSERT INTO file_downloads (file_id, user_id, downloaded_at) VALUES (?, ?, NOW())");
        $stmt->bind_param("ii", $fid, $uid);
        $stmt->execute();
    }

    sendFile($path, "application/octet-stream", dispositionHeader("attachment", basename($file["filename"])));
}

http_response_code(400);
die("Invalid request.");
?>
