<?php
/*
    The AI "switchboard": keeps the list of AI services and always answers, even when one is busy.

    Order of use:
      1. the AIs added in Admin > AI setup (top of the list first, switched-off ones skipped)
      2. the default AI from config.php (AI_API_KEY + AI_MODEL), always the last resort

    For every question:
      - an AI that says "busy" (rate limit / overloaded / network) gets AI_TRIES tries, then the next AI is used
      - an AI that refuses (wrong key, wrong model) is skipped at once and rested for a few minutes
      - the SAME prompt (file + chat history + question) is handed to the next AI
      - if no AI could look at the picture / PDF / audio, the last round is asked again without it (text only)

    Used by ai.php. Providers themselves are in aiprov.php.
*/
require_once __DIR__ . "/aiprov.php";

if (!defined("AI_TRIES"))   define("AI_TRIES", 2);      // tries on one AI before moving to the next
if (!defined("AI_BUDGET"))  define("AI_BUDGET", 150);   // most seconds one question may take in total
if (!defined("AI_TIMEOUT")) define("AI_TIMEOUT", 90);   // most seconds one single request may take

/* ---------- Storage ---------- */

function aiEnsureTable(mysqli $conn): void {
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS ai_providers (
            id INT NOT NULL AUTO_INCREMENT,
            name VARCHAR(60) NOT NULL,
            kind VARCHAR(12) NOT NULL DEFAULT 'openai',
            base_url VARCHAR(255) NOT NULL DEFAULT '',
            api_key VARCHAR(500) NOT NULL DEFAULT '',
            model VARCHAR(120) NOT NULL DEFAULT '',
            priority INT NOT NULL DEFAULT 10,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            cooldown_until DATETIME NULL DEFAULT NULL,
            last_status VARCHAR(255) NOT NULL DEFAULT '',
            last_used_at DATETIME NULL DEFAULT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (mysqli_sql_exception $e) { /* created by a parallel request */ }
}

/* Every AI added in the admin panel, in order of use. Keys are included: never send this to the browser. */
function aiRows(mysqli $conn): array {
    $sql = "SELECT id, name, kind, base_url, api_key, model, priority, enabled, last_status, last_used_at,
                   (cooldown_until IS NOT NULL AND cooldown_until > NOW()) AS cooling,
                   GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), cooldown_until)) AS cool_s
              FROM ai_providers ORDER BY priority, id";
    try {
        $res = $conn->query($sql);
    } catch (mysqli_sql_exception $e) {
        aiEnsureTable($conn);
        $res = $conn->query($sql);
    }

    $out = [];
    while ($r = $res->fetch_assoc()) {
        $out[] = [
            "id"       => (int)$r["id"],
            "name"     => (string)$r["name"],
            "kind"     => (string)$r["kind"],
            "base_url" => (string)$r["base_url"],
            "key"      => (string)$r["api_key"],
            "model"    => (string)$r["model"],
            "priority" => (int)$r["priority"],
            "enabled"  => (int)$r["enabled"] === 1,
            "status"   => (string)$r["last_status"],
            "last"     => $r["last_used_at"] !== null ? (string)$r["last_used_at"] : "",
            "cooling"  => (int)$r["cooling"] === 1,
            "cool_s"   => (int)$r["cool_s"],
        ];
    }
    return $out;
}

/* The default AI from config.php, or null when no real key was pasted there */
function aiConfigDefault(): ?array {
    $key   = defined("AI_API_KEY") ? trim((string)AI_API_KEY) : "";
    $model = defined("AI_MODEL")   ? trim((string)AI_MODEL)   : "";
    if ($key === "" || preg_match('/paste|your[_ -]?api|^x{6,}$/i', $key)) return null;

    $kind = defined("AI_PROVIDER") && isset(aiKinds()[strtolower((string)AI_PROVIDER)])
          ? strtolower((string)AI_PROVIDER) : aiGuessKind($model, $key);
    $base = defined("AI_BASE_URL") && trim((string)AI_BASE_URL) !== "" ? trim((string)AI_BASE_URL) : aiGuessBase($kind, $key);
    if ($model === "") $model = aiDefaultModel($kind);

    return ["id" => 0, "name" => "Default (config.php)", "kind" => $kind, "base_url" => $base, "key" => $key,
            "model" => $model, "priority" => PHP_INT_MAX, "enabled" => true, "status" => "", "last" => "", "cooling" => false, "cool_s" => 0];
}

/* Switched-on AIs that are complete, then the default AI (unless it is the same one as an added AI) */
function aiChain(mysqli $conn): array {
    $chain = [];
    foreach (aiRows($conn) as $p) {
        if (!$p["enabled"] || $p["model"] === "" || !aiValidBase($p["base_url"])) continue;
        if ($p["key"] === "" && $p["kind"] !== "openai") continue;   // only OpenAI-compatible servers (e.g. Ollama) may work without a key
        $chain[] = $p;
    }

    $def = aiConfigDefault();
    if ($def) {
        $dup = false;
        foreach ($chain as $p) if ($p["key"] === $def["key"] && $p["model"] === $def["model"]) $dup = true;
        if (!$dup) $chain[] = $def;
    }
    return $chain;
}

function aiMark(mysqli $conn, array $p, bool $ok, string $status, int $cool = 0): void {
    if ($p["id"] <= 0) return;   // the config.php default has no row
    try {
        $status = mb_strtoupper(mb_substr($status, 0, 1, "UTF-8"), "UTF-8") . mb_substr($status, 1, null, "UTF-8");
        $status = mb_substr(($ok ? "OK: " : "Failed: ") . $status, 0, 250, "UTF-8");
        $id = (int)$p["id"];
        if ($ok) {
            $stmt = $conn->prepare("UPDATE ai_providers SET last_status = ?, last_used_at = NOW(), cooldown_until = NULL WHERE id = ?");
            $stmt->bind_param("si", $status, $id);
        } elseif ($cool > 0) {
            $stmt = $conn->prepare("UPDATE ai_providers SET last_status = ?, last_used_at = NOW(), cooldown_until = NOW() + INTERVAL ? SECOND WHERE id = ?");
            $stmt->bind_param("sii", $status, $cool, $id);
        } else {
            $stmt = $conn->prepare("UPDATE ai_providers SET last_status = ?, last_used_at = NOW() WHERE id = ?");
            $stmt->bind_param("si", $status, $id);
        }
        $stmt->execute();
    } catch (mysqli_sql_exception $e) { /* bookkeeping only, never break an answer */ }
}

/* ---------- Helpers ---------- */

function aiKey(array $p): string {
    return $p["id"] > 0 ? "p" . $p["id"] : "cfg";
}

/* What the conversation contains that not every AI can read */
function aiNeeds(array $ctx): array {
    $n = ["pdf" => false, "audio" => false, "media" => false];
    foreach ($ctx["msgs"] as $m) {
        foreach ($m["parts"] as $x) {
            if ($x["t"] === "pdf")   $n["pdf"] = true;
            if ($x["t"] === "audio") $n["audio"] = true;
            if ($x["t"] !== "text")  $n["media"] = true;
        }
    }
    return $n;
}

/* Can this AI really read that kind of content? (audio: Gemini only; PDF: Gemini, Claude, OpenAI, OpenRouter) */
function aiCanRead(array $p, array $needs): bool {
    $host = strtolower((string)parse_url($p["base_url"], PHP_URL_HOST));
    if ($needs["audio"] && $p["kind"] !== "gemini") return false;
    if ($needs["pdf"]) {
        if ($p["kind"] === "openai" && $host !== "api.openai.com" && $host !== "openrouter.ai") return false;
    }
    return true;
}

/* The same conversation with pictures / PDFs / audio replaced by a short notice (the "text only" last round) */
function aiStrip(array $ctx): array {
    foreach ($ctx["msgs"] as &$m) {
        $parts = [];
        $note  = false;
        foreach ($m["parts"] as $x) {
            if ($x["t"] === "text") $parts[] = $x;
            else $note = true;
        }
        if ($note) $parts[] = ["t" => "text", "text" => "[The file's own content (picture, PDF or audio) could not be shown to you, so answer only from what is written here and say so.]"];
        $m["parts"] = $parts;
    }
    unset($m);
    return $ctx;
}

/* Alternating user / model turns, starting and ending with the user (some services refuse anything else) */
function aiNormalize(array $msgs): array {
    $out = [];
    foreach ($msgs as $m) {
        $role = $m["role"] === "model" ? "model" : "user";
        if (!$m["parts"]) continue;
        if ($out && $out[count($out) - 1]["role"] === $role) {
            $out[count($out) - 1]["parts"] = array_merge($out[count($out) - 1]["parts"], $m["parts"]);
        } else {
            $out[] = ["role" => $role, "parts" => $m["parts"]];
        }
    }
    while ($out && $out[0]["role"] !== "user") array_shift($out);
    return $out;
}

/* How long to rest an AI after a failure (seconds). 0 = no rest (a 400 can be about this one file, not about the AI) */
function aiRest(array $r): int {
    $c = $r["code"];
    if ($c === 401 || $c === 403) return 900;
    if ($c === 404) return 600;
    if ($c === 429) return max(60, min(300, (int)$r["wait"]));
    if ($c === 0 || $c === 408 || $c === 409 || $c >= 500) return 45;
    return 0;
}

/* ---------- One round over the chain ---------- */

function aiRound(mysqli $conn, array $chain, array $ctx, float $deadline, array &$notes, array &$skip): array {
    $needs = aiNeeds($ctx);

    // AIs that can read this content come first, AIs that are resting come last (and get a single try)
    $ready = [];
    $maybe = [];
    $rest  = [];
    foreach ($chain as $p) {
        if (isset($skip[aiKey($p)])) continue;
        if ($p["cooling"])                  $rest[]  = $p;
        elseif (!aiCanRead($p, $needs))     $maybe[] = $p;
        else                                $ready[] = $p;
    }

    $allEmpty = true;
    $tried    = 0;

    foreach ([[array_merge($ready, $maybe), max(1, (int)AI_TRIES)], [$rest, 1]] as [$group, $tries]) {
        foreach ($group as $p) {
            for ($t = 1; $t <= $tries; $t++) {
                $left = (int)floor($deadline - microtime(true));
                if ($left < 4) {
                    $notes[] = "Ran out of time.";
                    break 3;
                }

                $r = aiCall($p, $ctx, min((int)AI_TIMEOUT, $left));
                $tried++;

                if ($r["ok"]) {
                    aiMark($conn, $p, true, "answered");
                    return ["ok" => true, "reply" => $r["reply"], "provider" => $p["name"], "model" => $p["model"]];
                }

                $notes[] = $p["name"] . " (" . $p["model"] . ")" . ($tries > 1 ? ", try " . $t : "") . ": " . $r["msg"];
                if ($r["code"] !== -1) $allEmpty = false;

                // worth another go on the same AI? (and not if it asks us to wait a long time)
                if (!$r["retry"] || $t >= $tries || $r["wait"] > 5) break;
                usleep((int)min(2500000, max(1000000, $r["wait"] * 1000000)));
            }

            $rest_s = aiRest($r ?? ["code" => 0, "wait" => 0]);
            aiMark($conn, $p, false, $r["msg"] ?? "no answer", $rest_s);
            if ($rest_s > 0) $skip[aiKey($p)] = true;   // don't come back to it in this question
        }
    }

    return ["ok" => false, "empty" => $tried > 0 && $allEmpty];
}

/*
    Asks the AIs. $ctx = see aiprov.php.
    Returns ["ok"=>true, "reply"=>..., "provider"=>..., "model"=>...]
         or ["ok"=>false, "setup"=>true]                no AI is set up at all
         or ["ok"=>false, "empty"=>bool, "notes"=>[...]] every AI failed (notes say why, for the admin)
*/
function aiGenerate(mysqli $conn, array $ctx): array {
    $chain = aiChain($conn);
    if (!$chain) return ["ok" => false, "setup" => true, "notes" => []];

    @set_time_limit((int)AI_BUDGET + 30);
    $deadline = microtime(true) + (int)AI_BUDGET;
    $notes = [];
    $skip  = [];

    $res = aiRound($conn, $chain, $ctx, $deadline, $notes, $skip);

    if (!$res["ok"] && aiNeeds($ctx)["media"]) {
        $notes[] = "Trying again without the picture / PDF / audio.";
        $res = aiRound($conn, $chain, aiStrip($ctx), $deadline, $notes, $skip);
        if ($res["ok"]) $res["text_only"] = true;
    }

    $res["notes"] = $notes;
    return $res;
}

/* One quick test request for the admin's "Test" button (no retries, no switching) */
function aiTest(array $p): array {
    $ctx = ["system" => "You are a connection test. Reply with the single word OK.",
            "msgs"   => [["role" => "user", "parts" => [["t" => "text", "text" => "Say OK."]]]]];
    $t0 = microtime(true);
    $r  = aiCall($p, $ctx, 40);
    $ms = (int)round((microtime(true) - $t0) * 1000);

    if ($r["ok"]) return ["ok" => true, "ms" => $ms, "reply" => mb_substr($r["reply"], 0, 80, "UTF-8")];
    return ["ok" => false, "ms" => $ms, "error" => $r["msg"]];
}
