<?php
require "config.php";
require "auth.php";
require "aicore.php";

/*
    Admin only: the AI list behind Admin > AI setup. API keys are only ever sent TO the server, never back:
    the browser gets "has_key" and the last 4 characters.

    GET  ?action=list                         all AIs + the default from config.php + presets
    POST action=save    id (0 = new), name, kind, base_url, api_key (empty = keep), model, enabled
    POST action=toggle  id                    switch on / off
    POST action=delete  id
    POST action=move    id, dir=up|down       change the order of use
    POST action=models  id | (kind, base_url, api_key)   "Load models": asks the service which models it has
    POST action=test    id (0 = config.php default) | (kind, base_url, api_key, model)
*/

function out(array $data, int $status = 200): void {
    http_response_code($status);
    header("Content-Type: application/json; charset=utf-8");
    header("Cache-Control: no-store");
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$user = requireLogin($conn, "json");
if (!$user["is_admin"]) out(["ok" => false, "error" => "Only the admin can do that."], 403);
if (!function_exists("curl_init")) out(["ok" => false, "error" => "The PHP cURL extension is turned off on this server."], 503);

$isPost = $_SERVER["REQUEST_METHOD"] === "POST";
$act    = (string)($_POST["action"] ?? $_GET["action"] ?? "");

if ($isPost && !csrfValid()) out(["ok" => false, "error" => "This page is out of date. Reload it and try again."], 403);

function hint(string $key): string {
    return $key === "" ? "" : "••••" . substr($key, -4);
}

function publicRow(array $p): array {
    return [
        "id" => $p["id"], "name" => $p["name"], "kind" => $p["kind"], "base_url" => $p["base_url"], "model" => $p["model"],
        "enabled" => $p["enabled"], "has_key" => $p["key"] !== "", "key_hint" => hint($p["key"]),
        "status" => $p["status"], "last" => $p["last"], "cooling" => $p["cooling"], "cool_s" => $p["cool_s"],
    ];
}

function findRow(mysqli $conn, int $id): ?array {
    foreach (aiRows($conn) as $p) if ($p["id"] === $id) return $p;
    return null;
}

/* The AI a "models" / "test" request is about: a saved one (its stored key is used) or the typed form values */
function targetFromRequest(mysqli $conn): array {
    $id = (int)($_POST["id"] ?? -1);

    if ($id === 0) {
        $d = aiConfigDefault();
        if (!$d) out(["ok" => false, "error" => "No key is set in config.php yet."], 400);
        return $d;
    }

    $saved = $id > 0 ? findRow($conn, $id) : null;
    if ($id > 0 && !$saved) out(["ok" => false, "error" => "That AI no longer exists."], 404);

    $kind  = (string)($_POST["kind"] ?? ($saved["kind"] ?? "openai"));
    $base  = trim((string)($_POST["base_url"] ?? ($saved["base_url"] ?? "")));
    $key   = trim((string)($_POST["api_key"] ?? ""));
    $model = trim((string)($_POST["model"] ?? ($saved["model"] ?? "")));

    if (!isset(aiKinds()[$kind])) out(["ok" => false, "error" => "Unknown type."], 400);
    if ($key === "" && $saved) $key = $saved["key"];
    if ($base === "") $base = aiGuessBase($kind, $key);
    if (!aiValidBase($base)) out(["ok" => false, "error" => "The web address must start with http:// or https://"], 400);

    return ["id" => $saved["id"] ?? -1, "name" => "test", "kind" => $kind, "base_url" => $base, "key" => $key, "model" => $model];
}

/* ---------- LIST ---------- */
if ($act === "list") {
    $def = aiConfigDefault();
    out([
        "ok"      => true,
        "ais"     => array_map("publicRow", aiRows($conn)),
        "default" => $def ? ["set" => true, "kind" => $def["kind"], "model" => $def["model"], "base_url" => $def["base_url"]] : ["set" => false],
        "kinds"   => aiKinds(),
        "presets" => aiPresets(),
        "tries"   => (int)AI_TRIES,
    ]);
}

if (!$isPost) out(["ok" => false, "error" => "Unknown request."], 400);

/* ---------- SAVE (add or change) ---------- */
if ($act === "save") {
    $id    = (int)($_POST["id"] ?? 0);
    $kind  = (string)($_POST["kind"] ?? "");
    $name  = trim((string)($_POST["name"] ?? ""));
    $key   = trim((string)($_POST["api_key"] ?? ""));
    $model = trim((string)($_POST["model"] ?? ""));
    $base  = trim((string)($_POST["base_url"] ?? ""));
    $on    = ($_POST["enabled"] ?? "1") === "0" ? 0 : 1;

    $saved = $id > 0 ? findRow($conn, $id) : null;
    if ($id > 0 && !$saved) out(["ok" => false, "error" => "That AI no longer exists."], 404);

    if (!isset(aiKinds()[$kind]))                  out(["ok" => false, "error" => "Choose the type of AI."], 400);
    if ($key === "" && $saved) $key = $saved["key"];
    if (preg_match('/\s/', $key) || strlen($key) > 500) out(["ok" => false, "error" => "The API key can't contain spaces or line breaks."], 400);
    if ($key === "" && $kind !== "openai")         out(["ok" => false, "error" => "Paste the API key."], 400);
    if ($model === "" || mb_strlen($model) > 120 || preg_match('/\s/', $model)) out(["ok" => false, "error" => "Enter the model name (or press Load models)."], 400);
    if ($base === "") $base = aiGuessBase($kind, $key);
    if (!aiValidBase($base) || strlen($base) > 255) out(["ok" => false, "error" => "The web address must start with http:// or https://"], 400);
    if ($name === "") $name = parse_url($base, PHP_URL_HOST) ?: "AI";
    $name = mb_substr($name, 0, 60, "UTF-8");

    if ($saved) {
        $stmt = $conn->prepare("UPDATE ai_providers SET name = ?, kind = ?, base_url = ?, api_key = ?, model = ?, enabled = ?, cooldown_until = NULL, last_status = '' WHERE id = ?");
        $stmt->bind_param("sssssii", $name, $kind, $base, $key, $model, $on, $id);
        $stmt->execute();
        out(["ok" => true, "id" => $id]);
    }

    $r = $conn->query("SELECT COALESCE(MAX(priority), 0) + 10 FROM ai_providers");
    $prio = (int)($r ? $r->fetch_row()[0] : 10);
    $stmt = $conn->prepare("INSERT INTO ai_providers (name, kind, base_url, api_key, model, priority, enabled, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
    $stmt->bind_param("sssssii", $name, $kind, $base, $key, $model, $prio, $on);
    $stmt->execute();
    out(["ok" => true, "id" => (int)$conn->insert_id]);
}

/* ---------- TOGGLE / DELETE / MOVE ---------- */
if ($act === "toggle") {
    $id = (int)($_POST["id"] ?? 0);
    $stmt = $conn->prepare("UPDATE ai_providers SET enabled = 1 - enabled, cooldown_until = NULL WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    out(["ok" => true]);
}

if ($act === "delete") {
    $id = (int)($_POST["id"] ?? 0);
    $stmt = $conn->prepare("DELETE FROM ai_providers WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    out(["ok" => true]);
}

if ($act === "move") {
    $id  = (int)($_POST["id"] ?? 0);
    $dir = ($_POST["dir"] ?? "") === "up" ? -1 : 1;

    $ids = array_column(aiRows($conn), "id");
    $i   = array_search($id, $ids, true);
    if ($i === false) out(["ok" => false, "error" => "That AI no longer exists."], 404);

    $j = $i + $dir;
    if ($j >= 0 && $j < count($ids)) {
        [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
        $stmt = $conn->prepare("UPDATE ai_providers SET priority = ? WHERE id = ?");
        foreach ($ids as $n => $pid) {
            $prio = ($n + 1) * 10;
            $stmt->bind_param("ii", $prio, $pid);
            $stmt->execute();
        }
    }
    out(["ok" => true]);
}

/* ---------- LOAD MODELS / TEST ---------- */
if ($act === "models") {
    $t = targetFromRequest($conn);
    out(aiListModels($t["kind"], $t["base_url"], $t["key"]));
}

if ($act === "test") {
    $t = targetFromRequest($conn);
    if ($t["model"] === "") out(["ok" => false, "error" => "Enter the model name first."], 400);
    if ($t["key"] === "" && $t["kind"] !== "openai") out(["ok" => false, "error" => "Paste the API key first."], 400);

    $r = aiTest($t);
    if ($t["id"] > 0) aiMark($conn, $t, $r["ok"], $r["ok"] ? "test passed" : $r["error"]);
    out($r);
}

out(["ok" => false, "error" => "Unknown request."], 400);
