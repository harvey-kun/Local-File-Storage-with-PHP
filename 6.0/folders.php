<?php
/*
    Folders: every folder is a real directory inside uploads/ (so it shows up in your file manager)
    plus one row in the "folders" table. Used by files.php (and loadFolders() in filetypes.php).

    How sharing works
      - A folder is private / everyone / specific people, exactly like a file.
      - Every file and subfolder inside a folder carries the SAME sharing as the folder. Changing a
        folder's sharing is copied down to everything inside it, so the existing checks for files
        (findAccessibleFile, preview.php, the AI chat...) keep working without any change.
      - Moving something INTO a folder gives it that folder's sharing. Moving it out to the top level
        keeps the sharing it had (it never becomes more public by being moved out).
      - A folder only holds its owner's files and folders. Only the owner (and the admin) can change it.
      - New top-level folders start private ("Only me"); a new subfolder starts with its parent's sharing.
*/

const FOLDER_NAME_MAX  = 60;   // characters shown on the site
const FOLDER_DISK_MAX  = 40;   // characters used for the directory name on the server
const FOLDER_MAX_DEPTH = 6;    // levels of folders inside folders

/* ---------- Records ---------- */

function folderIdList(array $ids): string {
    return implode(",", array_map("intval", $ids));
}

function fetchFolder(mysqli $conn, int $id): ?array {
    if ($id <= 0) return null;
    $sql = "SELECT id, name, parent_id, owner_id, visibility, diskpath FROM folders WHERE id = ?";
    try {
        $stmt = $conn->prepare($sql);
    } catch (mysqli_sql_exception $e) {
        ensureFolderSchema($conn);
        $stmt = $conn->prepare($sql);
    }
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    if (!$r) return null;
    $r["id"]        = (int)$r["id"];
    $r["parent_id"] = $r["parent_id"] === null ? 0 : (int)$r["parent_id"];   // 0 = top level
    $r["owner_id"]  = (int)$r["owner_id"];
    return $r;
}

function folderShareIds(mysqli $conn, int $folderId): array {
    $out  = [];
    $stmt = $conn->prepare("SELECT user_id FROM folder_shares WHERE folder_id = ? ORDER BY user_id");
    $stmt->bind_param("i", $folderId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_row()) $out[] = (int)$r[0];
    return $out;
}

function folderSharedWith(mysqli $conn, int $folderId, int $userId): bool {
    $stmt = $conn->prepare("SELECT 1 FROM folder_shares WHERE folder_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $folderId, $userId);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}

/* The folder row if this user may see it, otherwise null (same answer as "no such folder") */
function findAccessibleFolder(mysqli $conn, int $id, array $user): ?array {
    $f = fetchFolder($conn, $id);
    if (!$f) return null;
    if ($user["is_admin"] || $f["owner_id"] === (int)$user["id"] || $f["visibility"] === "all") return $f;
    if ($f["visibility"] === "custom" && folderSharedWith($conn, $f["id"], (int)$user["id"])) return $f;
    return null;
}

/* Renaming, moving, sharing, deleting, adding files: the owner and the admin only */
function canManageFolder(array $folder, array $user): bool {
    return $user["is_admin"] || (int)$folder["owner_id"] === (int)$user["id"];
}

/* 1 = a top-level folder */
function folderDepth(mysqli $conn, array $folder): int {
    $d   = 1;
    $cur = $folder;
    while ($cur["parent_id"] > 0 && $d < 50) {
        $cur = fetchFolder($conn, $cur["parent_id"]);
        if (!$cur) break;
        $d++;
    }
    return $d;
}

/* The folder's id followed by the ids of every folder below it (parents always before their children) */
function folderTreeIds(mysqli $conn, int $rootId): array {
    $ids   = [$rootId];
    $front = [$rootId];
    for ($i = 0; $i < 30 && $front; $i++) {
        $res   = $conn->query("SELECT id FROM folders WHERE parent_id IN (" . folderIdList($front) . ")");
        $front = [];
        while ($r = $res->fetch_row()) {
            $id = (int)$r[0];
            if (!in_array($id, $ids, true)) { $ids[] = $id; $front[] = $id; }
        }
    }
    return $ids;
}

/* ---------- Sharing (copied down to everything inside) ---------- */

function folderApplySharing(mysqli $conn, int $rootId, string $vis, array $shareIds): bool {
    $ids = folderTreeIds($conn, $rootId);
    $in  = folderIdList($ids);

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("UPDATE folders SET visibility = ? WHERE id IN ($in)");
        $stmt->bind_param("s", $vis);
        $stmt->execute();

        $stmt = $conn->prepare("UPDATE files SET visibility = ? WHERE folder_id IN ($in)");
        $stmt->bind_param("s", $vis);
        $stmt->execute();

        $conn->query("DELETE FROM folder_shares WHERE folder_id IN ($in)");
        $conn->query("DELETE FROM file_shares WHERE file_id IN (SELECT id FROM files WHERE folder_id IN ($in))");

        if ($vis === "custom") {
            foreach ($shareIds as $uid) {
                $uid = (int)$uid;
                $conn->query("INSERT IGNORE INTO folder_shares (folder_id, user_id) SELECT id, $uid FROM folders WHERE id IN ($in)");
                $conn->query("INSERT IGNORE INTO file_shares (file_id, user_id) SELECT id, $uid FROM files WHERE folder_id IN ($in)");
            }
        }
        $conn->commit();
        return true;
    } catch (Throwable $e) {
        $conn->rollback();
        return false;
    }
}

/* ---------- Names ---------- */

/* The name people see: [true, name] or [false, error text] */
function folderCleanName(string $raw): array {
    if (!mb_check_encoding($raw, "UTF-8")) return [false, "The name contains invalid characters."];
    $n = (string)preg_replace('/[\x00-\x1F\x7F]/', "", $raw);
    $n = str_replace(["/", "\\"], "-", $n);
    $n = trim((string)preg_replace('/\s+/u', " ", $n));

    if ($n === "" || $n === "." || $n === "..") return [false, "Enter a folder name."];
    if (mb_strlen($n, "UTF-8") > FOLDER_NAME_MAX) return [false, "Use at most " . FOLDER_NAME_MAX . " characters."];
    return [true, $n];
}

/* Same name already used by this person in the same place? Add " (1)", " (2)"... like a file manager does. */
function folderUniqueName(mysqli $conn, int $ownerId, int $parentId, string $name, int $exceptId = 0): string {
    $stmt = $conn->prepare("SELECT 1 FROM folders WHERE owner_id = ? AND COALESCE(parent_id, 0) = ? AND name = ? AND id <> ? LIMIT 1");
    for ($n = 0; $n < 1000; $n++) {
        $try = $n === 0 ? $name : $name . " (" . $n . ")";
        $stmt->bind_param("iisi", $ownerId, $parentId, $try, $exceptId);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_row()) return $try;
    }
    return $name;
}

/* The directory name on the server: safe on Windows and Linux, never starts with a dot */
function folderDiskName(string $name): string {
    $n = $name;

    if (DIRECTORY_SEPARATOR === "\\" && preg_match('/[^\x20-\x7E]/', $n)) {
        $t = function_exists("iconv") ? @iconv("UTF-8", "ASCII//TRANSLIT//IGNORE", $n) : false;
        $n = ($t !== false && trim($t) !== "") ? $t : $n;
        $n = (string)preg_replace('/[^\x20-\x7E]+/', "_", $n);
    }

    $n = (string)preg_replace('/[<>:"\/\\\\|?*\x00-\x1F\x7F]/', "_", $n);
    $n = rtrim(trim($n), ". ");
    if ($n === "") $n = "folder";
    if ($n[0] === ".") $n = "folder" . $n;

    if (mb_strlen($n, "UTF-8") > FOLDER_DISK_MAX) $n = rtrim(mb_substr($n, 0, FOLDER_DISK_MAX, "UTF-8"), ". ");
    if ($n === "") $n = "folder";

    if (preg_match('/^(con|prn|aux|nul|com[1-9]|lpt[1-9])(\..*)?$/i', $n)) $n = "_" . $n;
    return $n;
}

/* ---------- The directories on the server ---------- */

function folderAbs(string $rel): string {
    return __DIR__ . "/" . $rel;
}

/* Only paths we made ourselves (uploads/...) are ever renamed or removed */
function folderSafeRel(string $rel): bool {
    return strncmp($rel, "uploads/", 8) === 0 && strpos($rel, "..") === false && strpos($rel, "\\") === false;
}

/* uploads/ exists, is writable and cannot be opened straight from the web */
function folderEnsureRoot(): bool {
    $dir = __DIR__ . "/uploads/";
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return false;

    $htaccess = $dir . ".htaccess";
    if (!is_file($htaccess)) {
        @file_put_contents($htaccess,
            "# Files are served through files.php and preview.php only\n" .
            "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n" .
            "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    }
    return is_writable($dir);
}

function folderEnsureDir(string $rel): bool {
    $abs = folderAbs($rel);
    if (is_dir($abs)) return true;
    return @mkdir($abs, 0755, true) || is_dir($abs);
}

/* Creates the directory with the first free name ("Photos", "Photos (1)"...) and returns that name, or "" */
function folderMakeDir(string $parentAbs, string $name): string {
    for ($n = 0; $n < 1000; $n++) {
        $cand = $n === 0 ? $name : $name . " (" . $n . ")";
        if (@mkdir($parentAbs . $cand, 0755)) return $cand;
        if (!file_exists($parentAbs . $cand)) return "";   // it failed for another reason (permissions...)
    }
    return "";
}

/* After a folder was moved or renamed: every folder and file below it gets its new path */
function folderRewritePaths(mysqli $conn, string $old, string $new): void {
    $like = addcslashes($old, "%_\\") . "/%";
    $pre  = $old . "/";
    $n    = strlen($pre);

    foreach (["folders" => ["id", "diskpath"], "files" => ["id", "filepath"]] as $table => [$idCol, $col]) {
        $stmt = $conn->prepare("SELECT `$idCol`, `$col` FROM `$table` WHERE `$col` LIKE ?");
        $stmt->bind_param("s", $like);
        $stmt->execute();
        $res  = $stmt->get_result();
        $rows = [];
        while ($r = $res->fetch_row()) $rows[] = [(int)$r[0], (string)$r[1]];

        $upd = $conn->prepare("UPDATE `$table` SET `$col` = ? WHERE `$idCol` = ?");
        foreach ($rows as [$id, $path]) {
            if (strncmp($path, $pre, $n) !== 0) continue;   // LIKE ignores letter case: make sure it really is below this folder
            $np = $new . "/" . substr($path, $n);
            $upd->bind_param("si", $np, $id);
            $upd->execute();
        }
    }
}

/* ---------- Create / rename / move / delete ---------- */

/* $parent = the folder to create it in (already checked by the caller), or null for the top level. Returns [true, id] or [false, error]. */
function folderCreate(mysqli $conn, array $user, string $rawName, ?array $parent): array {
    [$ok, $name] = folderCleanName($rawName);
    if (!$ok) return [false, $name];

    if ($parent && folderDepth($conn, $parent) >= FOLDER_MAX_DEPTH) {
        return [false, "Folders can only go " . FOLDER_MAX_DEPTH . " levels deep."];
    }
    if (!folderEnsureRoot()) return [false, "Could not create the uploads folder on the server."];

    $ownerId  = $parent ? (int)$parent["owner_id"] : (int)$user["id"];   // a folder always belongs to the owner of the folder it is in
    $parentId = $parent ? (int)$parent["id"] : 0;
    $baseRel  = $parent ? (string)$parent["diskpath"] : "uploads";
    if (!folderSafeRel($baseRel . "/x") || !folderEnsureDir($baseRel)) return [false, "Could not create the folder on the server."];

    $vis    = $parent ? (string)$parent["visibility"] : "private";
    $shares = ($parent && $vis === "custom") ? folderShareIds($conn, $parentId) : [];

    $name = folderUniqueName($conn, $ownerId, $parentId, $name);
    $disk = folderMakeDir(folderAbs($baseRel) . "/", folderDiskName($name));
    if ($disk === "") return [false, "Could not create the folder on the server."];
    $rel = $baseRel . "/" . $disk;

    try {
        $pp   = $parentId > 0 ? $parentId : null;
        $stmt = $conn->prepare("INSERT INTO folders (name, parent_id, owner_id, visibility, diskpath, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("siiss", $name, $pp, $ownerId, $vis, $rel);
        $stmt->execute();
        $id = (int)$conn->insert_id;

        if ($shares) {
            $ins = $conn->prepare("INSERT IGNORE INTO folder_shares (folder_id, user_id) VALUES (?, ?)");
            foreach ($shares as $uid) {
                $uid = (int)$uid;
                $ins->bind_param("ii", $id, $uid);
                $ins->execute();
            }
        }
    } catch (mysqli_sql_exception $e) {
        @rmdir(folderAbs($rel));
        return [false, "Could not save the folder."];
    }

    return [true, $id];
}

/*
    Renames and/or moves a folder (the directory on the server too, and the path of everything inside).
    $newParent null = top level. $adopt = take over the sharing of the new parent folder.
    The caller has checked permissions and made $newName unique. Returns [true, null] or [false, error].
*/
function folderRelocate(mysqli $conn, array $folder, ?array $newParent, string $newName, bool $adopt): array {
    $id          = (int)$folder["id"];
    $newParentId = $newParent ? (int)$newParent["id"] : 0;

    if ($newParent && in_array($newParentId, folderTreeIds($conn, $id), true)) {
        return [false, "A folder can't be moved into itself."];
    }
    if (!folderEnsureRoot()) return [false, "Could not reach the uploads folder on the server."];

    $oldRel  = (string)$folder["diskpath"];
    $baseRel = $newParent ? (string)$newParent["diskpath"] : "uploads";
    if (!folderSafeRel($oldRel) || !folderSafeRel($baseRel . "/x") || !folderEnsureDir($baseRel)) {
        return [false, "Could not reach the folder on the server."];
    }

    $oldAbs = folderAbs($oldRel);
    $wanted = folderDiskName($newName);
    $newRel = $baseRel . "/" . $wanted;

    $same     = strcmp($newRel, $oldRel) === 0;
    $caseOnly = !$same && strcasecmp($newRel, $oldRel) === 0
             && file_exists(folderAbs($newRel)) && realpath(folderAbs($newRel)) === realpath($oldAbs);   // "photos" -> "Photos" on Windows

    if (!$same && !$caseOnly) {
        $n    = 0;
        $cand = $newRel;
        while (file_exists(folderAbs($cand)) && $n < 1000) {
            $n++;
            $cand = $baseRel . "/" . $wanted . " (" . $n . ")";
        }
        if (file_exists(folderAbs($cand))) return [false, "Could not find a free name on the server."];
        $newRel = $cand;
    }

    $moved = false;
    if (!$same) {
        if (is_dir($oldAbs)) {
            if (!@rename($oldAbs, folderAbs($newRel))) return [false, "Could not move the folder on the server."];
            $moved = true;
        } elseif (!@mkdir(folderAbs($newRel), 0755, true)) {
            return [false, "Could not move the folder on the server."];
        }
    }

    $conn->begin_transaction();
    try {
        $pp   = $newParentId > 0 ? $newParentId : null;
        $stmt = $conn->prepare("UPDATE folders SET name = ?, parent_id = ?, diskpath = ? WHERE id = ?");
        $stmt->bind_param("sisi", $newName, $pp, $newRel, $id);
        $stmt->execute();
        if (!$same) folderRewritePaths($conn, $oldRel, $newRel);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        if ($moved) @rename(folderAbs($newRel), $oldAbs);   // put the directory back, nothing changed
        return [false, "Could not save the change."];
    }

    if ($adopt && $newParent) {
        if (!folderApplySharing($conn, $id, (string)$newParent["visibility"], folderShareIds($conn, $newParentId))) {
            return [false, "The folder was moved, but its sharing could not be updated."];
        }
    }
    return [true, null];
}

/* Moves one file into a folder ($dest) or to the top level (null). $adopt = take over the folder's sharing. Returns [true, null] or [false, error]. */
function fileMoveTo(mysqli $conn, array $file, ?array $dest, bool $adopt): array {
    $fid    = (int)$file["id"];
    $destId = $dest ? (int)$dest["id"] : 0;

    $oldRel = (string)$file["filepath"];
    $oldAbs = __DIR__ . "/" . $oldRel;
    if (!is_file($oldAbs)) return [false, "The file is missing from the server."];

    $destRel = $dest ? (string)$dest["diskpath"] : "uploads";
    $newRel  = $oldRel;
    $newAbs  = "";
    $moved   = false;

    if (dirname($oldRel) !== $destRel) {
        if (!folderEnsureRoot() || !folderSafeRel($destRel . "/x") || !folderEnsureDir($destRel)) {
            return [false, "Could not reach the folder on the server."];
        }
        $target = reserveDiskName(folderAbs($destRel) . "/", basename($oldRel));   // adds " (1)" if the name is taken there
        if ($target === "") return [false, "Could not move the file on the server."];

        $newAbs = folderAbs($destRel) . "/" . $target;
        if (!@rename($oldAbs, $newAbs)) {
            @unlink($newAbs);
            return [false, "Could not move the file on the server."];
        }
        $newRel = $destRel . "/" . $target;
        $moved  = true;
    }

    $name = uniqueDisplayName($conn, (int)$file["owner_id"], (string)$file["filename"], $fid, $destId);

    try {
        $fp   = $destId > 0 ? $destId : null;
        $stmt = $conn->prepare("UPDATE files SET filepath = ?, folder_id = ?, filename = ? WHERE id = ?");
        $stmt->bind_param("sisi", $newRel, $fp, $name, $fid);
        $stmt->execute();

        if ($adopt && $dest) {
            $vis  = (string)$dest["visibility"];
            $stmt = $conn->prepare("UPDATE files SET visibility = ? WHERE id = ?");
            $stmt->bind_param("si", $vis, $fid);
            $stmt->execute();
            writeShares($conn, $fid, $vis === "custom" ? folderShareIds($conn, $destId) : []);
        }
    } catch (mysqli_sql_exception $e) {
        if ($moved) @rename($newAbs, $oldAbs);
        return [false, "Could not save the change."];
    }

    return [true, null];
}

/* Deletes every file in the folder and below it, the sub-folders, and the directories on the server */
function folderDeleteTree(mysqli $conn, array $folder): void {
    $ids = folderTreeIds($conn, (int)$folder["id"]);
    $in  = folderIdList($ids);

    $files = [];
    $res = $conn->query("SELECT id, filepath FROM files WHERE folder_id IN ($in)");
    while ($r = $res->fetch_assoc()) $files[] = $r;
    foreach ($files as $f) deleteFile($conn, $f);

    $paths = [];
    foreach (array_reverse($ids) as $fid) {          // deepest folders first, so each directory is empty when it is removed
        $row = fetchFolder($conn, $fid);
        if ($row) $paths[] = (string)$row["diskpath"];
    }

    $conn->query("DELETE FROM folder_shares WHERE folder_id IN ($in)");
    $conn->query("DELETE FROM folders WHERE id IN ($in)");

    foreach ($paths as $p) {
        if (folderSafeRel($p)) @rmdir(folderAbs($p));
    }
}

/*
    $mode "all": delete the folder and everything inside it.
    $mode "up":  first move the files and sub-folders to the folder above (or the top level), keeping their sharing.
    Returns [true, null] or [false, error].
*/
function folderDelete(mysqli $conn, array $folder, string $mode): array {
    $id = (int)$folder["id"];

    if ($mode === "up") {
        $parent   = $folder["parent_id"] > 0 ? fetchFolder($conn, $folder["parent_id"]) : null;
        $parentId = $parent ? (int)$parent["id"] : 0;
        $bad      = 0;

        $ids = [];
        $res = $conn->query("SELECT id FROM files WHERE folder_id = " . $id);
        while ($r = $res->fetch_row()) $ids[] = (int)$r[0];
        foreach ($ids as $fid) {
            $f = fetchFile($conn, $fid);
            if (!$f || !is_file(__DIR__ . "/" . $f["filepath"])) continue;   // already gone from the disk: removed with the folder below
            [$ok] = fileMoveTo($conn, $f, $parent, false);
            if (!$ok) $bad++;
        }

        $subs = [];
        $res  = $conn->query("SELECT id FROM folders WHERE parent_id = " . $id);
        while ($r = $res->fetch_row()) $subs[] = (int)$r[0];
        foreach ($subs as $sid) {
            $c = fetchFolder($conn, $sid);
            if (!$c) continue;
            $name = folderUniqueName($conn, (int)$c["owner_id"], $parentId, (string)$c["name"], $sid);
            [$ok] = folderRelocate($conn, $c, $parent, $name, false);
            if (!$ok) $bad++;
        }

        if ($bad > 0) return [false, "Some items could not be moved out, so the folder was not deleted."];
        $folder = fetchFolder($conn, $id) ?: $folder;
    }

    folderDeleteTree($conn, $folder);
    return [true, null];
}
