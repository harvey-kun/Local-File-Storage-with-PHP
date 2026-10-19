<?php
/*
    Accounts, login sessions, permissions and the database tables behind them.
    Used by index.php, login.php, logout.php, files.php and preview.php
*/

const AUTH_DAYS     = 30;                                          // a device stays signed in this long
const AUTH_RESERVED = ["admin", "administrator", "root", "system"]; // names only the admin account may use

/* ---------- Sessions ---------- */

/*
    Read-only sessions (the default) don't lock, so the live refresh and media streaming never wait for
    each other. Pass $write = true only when the session has to be changed (login, logout, password change).
*/
function authStart(bool $write = false): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;

    ini_set("session.gc_maxlifetime", (string)(AUTH_DAYS * 86400));
    ini_set("session.use_strict_mode", "1");
    ini_set("session.use_only_cookies", "1");
    session_name("fs_session");
    session_set_cookie_params([
        "lifetime" => AUTH_DAYS * 86400,
        "path"     => "/",
        "secure"   => !empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off",
        "httponly" => true,
        "samesite" => "Lax",
    ]);

    session_start($write ? [] : ["read_and_close" => true]);
}

function csrfToken(): string {
    return (string)($_SESSION["csrf"] ?? "");
}

/* The token comes in a header (JavaScript requests) or a hidden "csrf" field (plain forms) */
function csrfValid(): bool {
    $sent = $_SERVER["HTTP_X_CSRF_TOKEN"] ?? ($_POST["csrf"] ?? "");
    $have = csrfToken();
    return $have !== "" && is_string($sent) && hash_equals($have, $sent);
}

/* ---------- Database tables ---------- */

function authDb(mysqli $conn, string $sql): void {
    try { $conn->query($sql); } catch (mysqli_sql_exception $e) { /* already done by a parallel request */ }
}

function authHasColumn(mysqli $conn, string $table, string $col): bool {
    $t = $conn->real_escape_string($table);
    $c = $conn->real_escape_string($col);
    $r = $conn->query("SHOW COLUMNS FROM `$t` LIKE '$c'");
    return $r && $r->num_rows > 0;
}

/*
    Creates / upgrades everything the login and sharing features need. Safe to run any number of times.
    Called by login.php, so the first visit to the site sets the database up (setup.sql does the same by hand).
*/
function ensureSchema(mysqli $conn): void {
    authDb($conn, "CREATE TABLE IF NOT EXISTS users (
        id INT NOT NULL AUTO_INCREMENT,
        username VARCHAR(50) NOT NULL,
        password VARCHAR(255) NOT NULL,
        is_admin TINYINT(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (username),
        UNIQUE KEY id (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    // The users table you made only has username + password: add a stable id and the admin flag
    if (!authHasColumn($conn, "users", "id"))       authDb($conn, "ALTER TABLE users ADD COLUMN id INT NOT NULL AUTO_INCREMENT UNIQUE FIRST");
    if (!authHasColumn($conn, "users", "is_admin")) authDb($conn, "ALTER TABLE users ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0");

    // When the person accepted the user agreement (AI can read a selected file and learn from it). NULL = not yet.
    if (!authHasColumn($conn, "users", "agreed_ai_at")) authDb($conn, "ALTER TABLE users ADD COLUMN agreed_ai_at DATETIME NULL DEFAULT NULL");

    // Who owns each file and who may see it
    if (!authHasColumn($conn, "files", "owner_id"))   authDb($conn, "ALTER TABLE files ADD COLUMN owner_id INT NULL DEFAULT NULL");
    if (!authHasColumn($conn, "files", "visibility")) authDb($conn, "ALTER TABLE files ADD COLUMN visibility VARCHAR(10) NOT NULL DEFAULT 'all'");

    authDb($conn, "CREATE TABLE IF NOT EXISTS file_shares (
        file_id INT NOT NULL,
        user_id INT NOT NULL,
        PRIMARY KEY (file_id, user_id),
        KEY user_id (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    authDb($conn, "CREATE TABLE IF NOT EXISTS file_downloads (
        id INT NOT NULL AUTO_INCREMENT,
        file_id INT NOT NULL,
        user_id INT NOT NULL,
        downloaded_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY file_id (file_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    authDb($conn, "CREATE TABLE IF NOT EXISTS ai_log (
        user_id INT NOT NULL,
        asked_at DATETIME NOT NULL,
        KEY user_time (user_id, asked_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Which file the AI was used on (so the admin can see who used the AI and when). The questions are NOT stored.
    if (!authHasColumn($conn, "ai_log", "file_id")) authDb($conn, "ALTER TABLE ai_log ADD COLUMN file_id INT NULL DEFAULT NULL");

    authDb($conn, "CREATE TABLE IF NOT EXISTS login_attempts (
        ip VARCHAR(45) NOT NULL,
        attempted_at DATETIME NOT NULL,
        KEY ip_time (ip, attempted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Who tried, and how it went (failed / signup attempt / ok = signed in / new = account created), for the admin's sign-in log
    if (!authHasColumn($conn, "login_attempts", "username")) authDb($conn, "ALTER TABLE login_attempts ADD COLUMN username VARCHAR(64) NULL DEFAULT NULL");
    if (!authHasColumn($conn, "login_attempts", "result"))   authDb($conn, "ALTER TABLE login_attempts ADD COLUMN result VARCHAR(8) NOT NULL DEFAULT 'failed'");

    // The built-in admin account (user "admin", password "admin") if no admin exists yet
    $r = $conn->query("SELECT id FROM users WHERE is_admin = 1 LIMIT 1");
    if (!$r || $r->num_rows === 0) {
        $e = $conn->query("SELECT id FROM users WHERE username = 'admin' LIMIT 1");
        if ($e && ($row = $e->fetch_assoc())) {
            authDb($conn, "UPDATE users SET is_admin = 1 WHERE id = " . (int)$row["id"]);
        } else {
            try {
                $hash = password_hash("admin", PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO users (username, password, is_admin) VALUES ('admin', ?, 1)");
                $stmt->bind_param("s", $hash);
                $stmt->execute();
            } catch (mysqli_sql_exception $ex) { /* created by a parallel request */ }
        }
    }

    // Files uploaded before accounts existed belong to the admin
    authDb($conn, "UPDATE files SET owner_id = (SELECT id FROM users WHERE is_admin = 1 ORDER BY id LIMIT 1) WHERE owner_id IS NULL");
}

/* ---------- Users ---------- */

function authRow(?array $row): ?array {
    if (!$row) return null;
    $row["id"]       = (int)$row["id"];
    $row["is_admin"] = (int)$row["is_admin"] === 1;
    return $row;
}

function authUserById(mysqli $conn, int $id): ?array {
    $stmt = $conn->prepare("SELECT id, username, password, is_admin, agreed_ai_at FROM users WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    return authRow($stmt->get_result()->fetch_assoc() ?: null);
}

function authFindByName(mysqli $conn, string $name): ?array {
    $stmt = $conn->prepare("SELECT id, username, password, is_admin, agreed_ai_at FROM users WHERE username = ?");
    $stmt->bind_param("s", $name);
    $stmt->execute();
    return authRow($stmt->get_result()->fetch_assoc() ?: null);
}

/* Changing the password changes this fingerprint, which signs out every other device */
function pwFingerprint(string $hash): string {
    return substr(hash("sha256", $hash), 0, 20);
}

/* The signed-in user of the current session (session must already be started), or null */
function authCurrentUser(mysqli $conn): ?array {
    $uid = (int)($_SESSION["uid"] ?? 0);
    if ($uid <= 0) return null;

    try {
        $user = authUserById($conn, $uid);
    } catch (mysqli_sql_exception $e) {
        ensureSchema($conn);
        $user = authUserById($conn, $uid);
    }
    if (!$user) return null;
    if (!hash_equals((string)($_SESSION["pwf"] ?? ""), pwFingerprint((string)$user["password"]))) return null;
    return $user;
}

/*
    Every page / endpoint calls this first.
    $mode: "page"  -> send the browser to login.php
           "json"  -> 401 + JSON (used by JavaScript requests)
           "plain" -> 401 + short text (images, audio, video, iframes)
*/
function requireLogin(mysqli $conn, string $mode = "page", bool $write = false): array {
    authStart($write);
    $user = authCurrentUser($conn);
    if ($user) return $user;

    if ($mode === "json") {
        http_response_code(401);
        header("Content-Type: application/json; charset=utf-8");
        header("Cache-Control: no-store");
        echo json_encode(["ok" => false, "auth" => false, "error" => "Please log in again."]);
        exit;
    }
    if ($mode === "plain") {
        http_response_code(401);
        header("Content-Type: text/plain; charset=utf-8");
        echo "Please log in.";
        exit;
    }
    header("Location: login.php");
    exit;
}

/* Needs a writable session */
function authLogin(array $user): void {
    session_regenerate_id(true);
    $_SESSION = [];
    $_SESSION["uid"]  = (int)$user["id"];
    $_SESSION["pwf"]  = pwFingerprint((string)$user["password"]);
    $_SESSION["csrf"] = bin2hex(random_bytes(24));
}

function authLogout(): void {
    authStart(true);
    $_SESSION = [];
    $p = session_get_cookie_params();
    setcookie(session_name(), "", [
        "expires"  => time() - 3600,
        "path"     => $p["path"],
        "secure"   => $p["secure"],
        "httponly" => true,
        "samesite" => "Lax",
    ]);
    session_destroy();
}

/*
    Checks a typed password. Hashes made by password_hash() are verified normally. A plain-text password
    typed straight into the table (e.g. in phpMyAdmin) is also accepted once and replaced by a hash when
    $upgrade is true (login), so nothing stays in clear text.
*/
function authCheckPassword(mysqli $conn, array &$user, string $pass, bool $upgrade = false): bool {
    $stored = (string)$user["password"];
    $isHash = (bool)password_get_info($stored)["algo"];

    if ($isHash) {
        if (!password_verify($pass, $stored)) return false;
        if ($upgrade && password_needs_rehash($stored, PASSWORD_DEFAULT)) authSetPasswordHash($conn, $user, password_hash($pass, PASSWORD_DEFAULT));
        return true;
    }

    if ($stored !== "" && hash_equals($stored, $pass)) {
        if ($upgrade) authSetPasswordHash($conn, $user, password_hash($pass, PASSWORD_DEFAULT));
        return true;
    }
    return false;
}

function authSetPasswordHash(mysqli $conn, array &$user, string $hash): void {
    $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
    $stmt->bind_param("si", $hash, $user["id"]);
    $stmt->execute();
    $user["password"] = $hash;
}

function validUsername(string $name, bool $isAdmin = false): ?string {
    if (!preg_match('/^[A-Za-z0-9._-]{3,32}\z/', $name)) {
        return "Use 3 to 32 letters, numbers, dots, dashes or underscores (no spaces).";
    }
    if (!$isAdmin && in_array(strtolower($name), AUTH_RESERVED, true)) {
        return "That username is reserved.";
    }
    return null;
}

function validPassword(string $pw): ?string {
    if (strlen($pw) < 6)  return "Use at least 6 characters for the password.";
    if (strlen($pw) > 72) return "The password can be at most 72 characters.";
    return null;
}

/* ---------- Brute-force protection (per IP address) ---------- */

function authIp(): string {
    return substr((string)($_SERVER["REMOTE_ADDR"] ?? ""), 0, 45);
}

function authThrottled(mysqli $conn): bool {
    // Failed tries and sign-up tries from this address in the last 10 minutes, counted from its last successful log-in
    $ip = authIp();
    $stmt = $conn->prepare("SELECT COUNT(*) FROM login_attempts
                             WHERE ip = ? AND result IN ('failed', 'signup') AND attempted_at > (NOW() - INTERVAL 10 MINUTE)
                               AND attempted_at > COALESCE((SELECT MAX(o.attempted_at) FROM login_attempts o WHERE o.ip = ? AND o.result = 'ok'), '1970-01-01')");
    $stmt->bind_param("ss", $ip, $ip);
    $stmt->execute();
    $n = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
    return $n >= 8;
}

/* Writes one line to the sign-in log. $result: failed | signup | ok | new */
function authRecordAttempt(mysqli $conn, ?string $username = null, string $result = "failed"): void {
    $ip = authIp();
    $name = $username === null ? null : mb_substr(trim($username), 0, 64, "UTF-8");
    if ($name === "") $name = null;
    $stmt = $conn->prepare("INSERT INTO login_attempts (ip, attempted_at, username, result) VALUES (?, NOW(), ?, ?)");
    $stmt->bind_param("sss", $ip, $name, $result);
    $stmt->execute();
    $conn->query("DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 30 DAY)");   // kept for the admin's sign-in log
}

/* A successful log-in: logged for the admin, and it lifts any temporary block on this address */
function authClearAttempts(mysqli $conn, ?string $username = null): void {
    authRecordAttempt($conn, $username, "ok");
}

/* ---------- Files: who may see / change what ---------- */

/* Accounts that can be picked in the share menu (the admin sees everything anyway) */
function shareableUsers(mysqli $conn): array {
    $out = [];
    $res = $conn->query("SELECT id, username FROM users WHERE is_admin = 0 ORDER BY username");
    while ($r = $res->fetch_assoc()) {
        $out[] = ["id" => (int)$r["id"], "name" => $r["username"]];
    }
    return $out;
}

function fetchFile(mysqli $conn, int $id): ?array {
    $stmt = $conn->prepare("SELECT id, filename, filepath, notes, owner_id, visibility FROM files WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) return null;
    $row["id"]       = (int)$row["id"];
    $row["owner_id"] = (int)$row["owner_id"];
    return $row;
}

function fileSharedWith(mysqli $conn, int $fileId, int $userId): bool {
    $stmt = $conn->prepare("SELECT 1 FROM file_shares WHERE file_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $fileId, $userId);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}

/* The file row if this user may see it, otherwise null (same answer as "no such file") */
function findAccessibleFile(mysqli $conn, int $id, array $user): ?array {
    $f = fetchFile($conn, $id);
    if (!$f) return null;
    if ($user["is_admin"] || $f["owner_id"] === $user["id"] || $f["visibility"] === "all") return $f;
    if ($f["visibility"] === "custom" && fileSharedWith($conn, $f["id"], $user["id"])) return $f;
    return null;
}

/* Renaming, notes, sharing and deleting: the uploader and the admin only */
function canManageFile(array $file, array $user): bool {
    return $user["is_admin"] || (int)$file["owner_id"] === (int)$user["id"];
}

function parseIdList($raw): array {
    $parts = is_array($raw) ? $raw : explode(",", (string)$raw);
    $ids = [];
    foreach ($parts as $p) {
        $n = (int)$p;
        if ($n > 0) $ids[$n] = $n;
        if (count($ids) >= 500) break;
    }
    return array_values($ids);
}

/*
    Cleans up a sharing choice. Returns [visibility, [user ids]].
    - nothing sent -> everyone; anything unknown -> private (never accidentally public)
    - only real, non-admin accounts that are not the owner can be picked
    - "specific people" with nobody picked means private
*/
function normalizeSharing(mysqli $conn, int $ownerId, string $vis, array $ids): array {
    if ($vis === "") $vis = "all";
    elseif (!in_array($vis, ["all", "private", "custom"], true)) $vis = "private";

    $keep = [];
    if ($vis === "custom" && $ids) {
        $in  = implode(",", array_map("intval", $ids));
        $res = $conn->query("SELECT id FROM users WHERE is_admin = 0 AND id <> " . $ownerId . " AND id IN (" . $in . ")");
        while ($r = $res->fetch_assoc()) $keep[] = (int)$r["id"];
    }
    if ($vis === "custom" && !$keep) $vis = "private";

    return [$vis, $keep];
}

function writeShares(mysqli $conn, int $fileId, array $ids): void {
    $del = $conn->prepare("DELETE FROM file_shares WHERE file_id = ?");
    $del->bind_param("i", $fileId);
    $del->execute();

    if (!$ids) return;
    $ins = $conn->prepare("INSERT IGNORE INTO file_shares (file_id, user_id) VALUES (?, ?)");
    foreach ($ids as $uid) {
        $uid = (int)$uid;
        $ins->bind_param("ii", $fileId, $uid);
        $ins->execute();
    }
}

/* ---------- Changing username / password ---------- */

/* Returns an error text, or null when the username was changed */
function authChangeUsername(mysqli $conn, array $user, string $new): ?string {
    $err = validUsername($new, $user["is_admin"]);
    if ($err !== null) return $err;
    if ($new === $user["username"]) return "That is already your username.";

    try {
        $stmt = $conn->prepare("UPDATE users SET username = ? WHERE id = ?");
        $stmt->bind_param("si", $new, $user["id"]);
        $stmt->execute();
    } catch (mysqli_sql_exception $e) {
        return $e->getCode() === 1062 ? "That username is already taken." : "Could not change the username.";
    }
    return null;
}

/*
    Returns an error text, or null when the password was changed.
    The session must be writable: this device stays signed in (its fingerprint is renewed),
    every other device is signed out because their fingerprint no longer matches.
*/
function authChangePassword(mysqli $conn, array &$user, string $current, string $new): ?string {
    if (!authCheckPassword($conn, $user, $current, false)) return "Your current password is wrong.";

    $err = validPassword($new);
    if ($err !== null) return $err;

    authSetPasswordHash($conn, $user, password_hash($new, PASSWORD_DEFAULT));
    $_SESSION["pwf"] = pwFingerprint((string)$user["password"]);
    return null;
}
