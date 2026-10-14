<?php
/*
    Talks to ONE AI service. Three families cover almost every provider on the market:

      gemini     Google Gemini
      openai     OpenAI and every "OpenAI-compatible" API (Groq, OpenRouter, Mistral, DeepSeek, xAI, Ollama ...)
      anthropic  Anthropic Claude

    The rest of the site never builds provider requests itself: it hands over a neutral conversation
    ("ctx") and gets text back. Used by aicore.php (ai.php, ai_admin.php).

    ctx = [
        "system" => "instructions",
        "msgs"   => [ ["role" => "user"|"model", "parts" => [ part, ... ]], ... ],
    ]
    part = ["t"=>"text","text"=>..] | ["t"=>"image","mime"=>..,"b64"=>..] | ["t"=>"pdf","b64"=>..] | ["t"=>"audio","mime"=>..,"b64"=>..]
*/

if (!defined("AI_MAX_TOKENS")) define("AI_MAX_TOKENS", 1500);   // longest answer, in tokens

function aiKinds(): array {
    return [
        "gemini"    => "Google Gemini",
        "openai"    => "OpenAI-compatible",
        "anthropic" => "Anthropic Claude",
    ];
}

function aiDefaultBase(string $kind): string {
    return [
        "gemini"    => "https://generativelanguage.googleapis.com/v1beta",
        "openai"    => "https://api.openai.com/v1",
        "anthropic" => "https://api.anthropic.com/v1",
    ][$kind] ?? "";
}

function aiDefaultModel(string $kind): string {
    return ["gemini" => "gemini-flash-latest", "openai" => "gpt-4o-mini", "anthropic" => "claude-haiku-5-5"][$kind] ?? "";
}

/* Ready-made choices for the admin form: picking one fills in the type and address. [label, kind, base url, suggested model] */
function aiPresets(): array {
    return [
        "gemini"     => ["Google Gemini",        "gemini",    aiDefaultBase("gemini"),    "gemini-flash-latest"],
        "openai"     => ["OpenAI",               "openai",    "https://api.openai.com/v1", "gpt-4o-mini"],
        "anthropic"  => ["Anthropic Claude",     "anthropic", aiDefaultBase("anthropic"), "claude-haiku-5-5"],
        "groq"       => ["Groq",                 "openai",    "https://api.groq.com/openai/v1", "llama-3.3-70b-versatile"],
        "openrouter" => ["OpenRouter",           "openai",    "https://openrouter.ai/api/v1", "openrouter/auto"],
        "mistral"    => ["Mistral",              "openai",    "https://api.mistral.ai/v1", "mistral-small-latest"],
        "deepseek"   => ["DeepSeek",             "openai",    "https://api.deepseek.com/v1", "deepseek-chat"],
        "xai"        => ["xAI Grok",             "openai",    "https://api.x.ai/v1", ""],
        "cerebras"   => ["Cerebras",             "openai",    "https://api.cerebras.ai/v1", ""],
        "together"   => ["Together AI",          "openai",    "https://api.together.xyz/v1", ""],
        "ollama"     => ["Ollama (this computer)", "openai",  "http://localhost:11434/v1", ""],
        "custom"     => ["Other (OpenAI-compatible)", "openai", "", ""],
    ];
}

/* Guess the family from the model name / key, so config.php only needs a key and a model */
function aiGuessKind(string $model, string $key): string {
    if (preg_match('/^(models\/)?(gemini|gemma)/i', $model)) return "gemini";
    if (preg_match('/^claude/i', $model))                    return "anthropic";
    if (strncmp($key, "AIza", 4) === 0)                      return "gemini";
    if (strncmp($key, "sk-ant-", 7) === 0)                   return "anthropic";
    return "openai";
}

/* Web address for a key + family (OpenRouter / Groq / xAI keys have a telltale start) */
function aiGuessBase(string $kind, string $key): string {
    if ($kind === "openai") {
        if (strncmp($key, "sk-or-", 6) === 0) return "https://openrouter.ai/api/v1";
        if (strncmp($key, "gsk_", 4) === 0)   return "https://api.groq.com/openai/v1";
        if (strncmp($key, "xai-", 4) === 0)    return "https://api.x.ai/v1";
    }
    return aiDefaultBase($kind);
}

function aiValidBase(string $url): bool {
    $p = parse_url($url);
    return is_array($p) && in_array(strtolower($p["scheme"] ?? ""), ["http", "https"], true) && !empty($p["host"]) && empty($p["user"]);
}

/* ---------- HTTP ---------- */

function aiHttp(string $method, string $url, array $headers, ?string $body, int $timeout): array {
    $wait = 0;
    $ch = curl_init($url);
    $opts = [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => max(5, $timeout),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_FOLLOWLOCATION => false,     // a provider must never bounce the key somewhere else
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$wait) {
            if (stripos($line, "retry-after:") === 0) $wait = (int)trim(substr($line, 12));
            return strlen($line);
        },
    ];
    if ($body !== null) $opts[CURLOPT_POSTFIELDS] = $body;
    curl_setopt_array($ch, $opts);

    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    return ["code" => $resp === false ? 0 : $code, "body" => $resp === false ? "" : (string)$resp, "err" => $err, "wait" => $wait];
}

function aiErrText(string $body): string {
    $d = json_decode($body, true);
    if (is_array($d)) {
        $m = $d["error"]["message"] ?? ($d["error"] ?? ($d["message"] ?? ""));
        if (is_array($m)) $m = json_encode($m);
        return mb_substr(trim((string)$m), 0, 300, "UTF-8");
    }
    return mb_substr(trim(strip_tags($body)), 0, 200, "UTF-8");
}

/*
    retry = worth trying the SAME service once more (network trouble, rate limit, server overloaded)
    code -1 = the service answered but gave no usable text (blocked / empty): switch service, no retry
*/
function aiFail(int $code, string $msg, int $wait = 0): array {
    $retry = $code === 0 || $code === 408 || $code === 409 || $code === 429 || $code >= 500;
    return ["ok" => false, "code" => $code, "retry" => $retry, "msg" => $msg, "wait" => $wait];
}

function aiHttpFail(array $h): array {
    if ($h["code"] === 0) return aiFail(0, "could not connect" . ($h["err"] !== "" ? " (" . $h["err"] . ")" : ""));
    $t = aiErrText($h["body"]);
    return aiFail($h["code"], "HTTP " . $h["code"] . ($t !== "" ? ": " . $t : ""), $h["wait"]);
}

/* ---------- Building each family's request ---------- */

function aiMergeNote(): string {
    return "[The audio itself could not be given to you by this AI service: you only have the tags above.]";
}

function aiGeminiBody(array $p, array $ctx): array {
    $contents = [];
    foreach ($ctx["msgs"] as $m) {
        $parts = [];
        foreach ($m["parts"] as $x) {
            if ($x["t"] === "text") { if ($x["text"] !== "") $parts[] = ["text" => $x["text"]]; }
            elseif ($x["t"] === "pdf")  $parts[] = ["inline_data" => ["mime_type" => "application/pdf", "data" => $x["b64"]]];
            else                        $parts[] = ["inline_data" => ["mime_type" => $x["mime"], "data" => $x["b64"]]];
        }
        if ($parts) $contents[] = ["role" => $m["role"] === "model" ? "model" : "user", "parts" => $parts];
    }
    $model = preg_replace('#^models/#', "", $p["model"]);
    return [
        aiBase($p) . "/models/" . rawurlencode($model) . ":generateContent",
        ["Content-Type: application/json", "x-goog-api-key: " . $p["key"]],
        [
            "systemInstruction" => ["parts" => [["text" => $ctx["system"]]]],
            "contents"          => $contents,
            "generationConfig"  => ["temperature" => 0.4, "maxOutputTokens" => AI_MAX_TOKENS],
        ],
    ];
}

function aiOpenAiBody(array $p, array $ctx): array {
    $isOpenAi = strcasecmp((string)parse_url($p["base_url"], PHP_URL_HOST), "api.openai.com") === 0;
    $messages = [["role" => "system", "content" => $ctx["system"]]];

    foreach ($ctx["msgs"] as $m) {
        $role = $m["role"] === "model" ? "assistant" : "user";
        if ($role === "assistant") {
            $text = "";
            foreach ($m["parts"] as $x) if ($x["t"] === "text") $text .= $x["text"];
            if ($text !== "") $messages[] = ["role" => "assistant", "content" => $text];
            continue;
        }

        $content = [];
        $dropped = false;
        foreach ($m["parts"] as $x) {
            if ($x["t"] === "text")       { if ($x["text"] !== "") $content[] = ["type" => "text", "text" => $x["text"]]; }
            elseif ($x["t"] === "image")  $content[] = ["type" => "image_url", "image_url" => ["url" => "data:" . $x["mime"] . ";base64," . $x["b64"]]];
            elseif ($x["t"] === "pdf")    $content[] = ["type" => "file", "file" => ["filename" => "document.pdf", "file_data" => "data:application/pdf;base64," . $x["b64"]]];
            else                          $dropped = true;
        }
        if ($dropped) $content[] = ["type" => "text", "text" => aiMergeNote()];
        if (!$content) continue;
        // a message with only text is sent as a plain string (the most widely supported form)
        $plain = count($content) === 1 && $content[0]["type"] === "text";
        $messages[] = ["role" => "user", "content" => $plain ? $content[0]["text"] : $content];
    }

    $body = ["model" => $p["model"], "messages" => $messages];
    $body[$isOpenAi ? "max_completion_tokens" : "max_tokens"] = AI_MAX_TOKENS;
    if (!($isOpenAi && preg_match('/^(o\d|gpt-5)/i', $p["model"]))) $body["temperature"] = 0.4;   // those models only accept the default

    $headers = ["Content-Type: application/json"];
    if ($p["key"] !== "") $headers[] = "Authorization: Bearer " . $p["key"];
    return [aiBase($p) . "/chat/completions", $headers, $body];
}

function aiAnthropicBody(array $p, array $ctx): array {
    $messages = [];
    foreach ($ctx["msgs"] as $m) {
        $role = $m["role"] === "model" ? "assistant" : "user";
        $blocks = [];
        $dropped = false;
        foreach ($m["parts"] as $x) {
            if ($x["t"] === "text")       { if ($x["text"] !== "") $blocks[] = ["type" => "text", "text" => $x["text"]]; }
            elseif ($x["t"] === "image")  $blocks[] = ["type" => "image", "source" => ["type" => "base64", "media_type" => $x["mime"], "data" => $x["b64"]]];
            elseif ($x["t"] === "pdf")    $blocks[] = ["type" => "document", "source" => ["type" => "base64", "media_type" => "application/pdf", "data" => $x["b64"]]];
            else                          $dropped = true;
        }
        if ($dropped) $blocks[] = ["type" => "text", "text" => aiMergeNote()];
        if ($blocks) $messages[] = ["role" => $role, "content" => $blocks];
    }
    return [
        aiBase($p) . "/messages",
        ["Content-Type: application/json", "x-api-key: " . $p["key"], "anthropic-version: 2023-06-01"],
        ["model" => $p["model"], "max_tokens" => AI_MAX_TOKENS, "temperature" => 0.4, "system" => $ctx["system"], "messages" => $messages],
    ];
}

function aiBase(array $p): string {
    return rtrim(trim((string)$p["base_url"]), "/");
}

/* ---------- Reading each family's answer ---------- */

function aiParse(string $kind, array $h): array {
    $d = json_decode($h["body"], true);
    if (!is_array($d)) return aiFail(-1, "the answer was not readable");

    $text = "";
    if ($kind === "gemini") {
        if (!empty($d["promptFeedback"]["blockReason"])) return aiFail(-1, "blocked (" . $d["promptFeedback"]["blockReason"] . ")");
        foreach (($d["candidates"][0]["content"]["parts"] ?? []) as $x) {
            if (isset($x["text"]) && empty($x["thought"])) $text .= $x["text"];
        }
    } elseif ($kind === "anthropic") {
        foreach (($d["content"] ?? []) as $x) {
            if (($x["type"] ?? "") === "text") $text .= (string)($x["text"] ?? "");
        }
    } else {
        $c = $d["choices"][0]["message"]["content"] ?? "";
        if (is_array($c)) {
            foreach ($c as $x) $text .= is_array($x) ? (string)($x["text"] ?? "") : (string)$x;
        } else {
            $text = (string)$c;
        }
    }

    $text = trim($text);
    return $text === "" ? aiFail(-1, "empty reply") : ["ok" => true, "reply" => $text];
}

/* One request to one service. $p = ["kind","base_url","key","model", ...] */
function aiCall(array $p, array $ctx, int $timeout): array {
    if (!aiValidBase((string)$p["base_url"])) return aiFail(-1, "the web address is not valid");
    if ($p["model"] === "")                   return aiFail(-1, "no model is set");

    [$url, $headers, $body] = $p["kind"] === "gemini" ? aiGeminiBody($p, $ctx)
                            : ($p["kind"] === "anthropic" ? aiAnthropicBody($p, $ctx) : aiOpenAiBody($p, $ctx));

    $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) return aiFail(-1, "could not prepare the request");

    $h = aiHttp("POST", $url, $headers, $json, $timeout);
    if ($h["code"] < 200 || $h["code"] >= 300) return aiHttpFail($h);

    return aiParse($p["kind"], $h);
}

/* ---------- Model lists (the admin's "Load models" button) ---------- */

function aiPickModel(array $models): string {
    $skip = '/image|tts|audio|live|embed|vision|robotics|computer|whisper|moderation|dall|realtime|transcribe|guard|safeguard/i';
    $ok = array_values(array_filter($models, fn($m) => !preg_match($skip, $m)));
    if (!$ok) $ok = $models;
    rsort($ok, SORT_NATURAL);   // newer-looking names first

    foreach (['/flash-latest$/', '/-flash$/', '/flash(?!.*lite)/', '/haiku/', '/mini/', '/small/', '/instant/', '/versatile/', '/flash/'] as $re) {
        foreach ($ok as $m) if (preg_match($re, $m)) return $m;
    }
    return $ok[0] ?? "";
}

/* Returns ["ok" => bool, "models" => [...], "suggest" => "...", "error" => "..."] */
function aiListModels(string $kind, string $base, string $key): array {
    $base = rtrim(trim($base), "/");
    if (!aiValidBase($base)) return ["ok" => false, "error" => "Enter a valid web address first (starting with https://)."];

    if ($kind === "gemini") {
        $h = aiHttp("GET", $base . "/models?pageSize=200", ["x-goog-api-key: " . $key], null, 20);
    } elseif ($kind === "anthropic") {
        $h = aiHttp("GET", $base . "/models?limit=100", ["x-api-key: " . $key, "anthropic-version: 2023-06-01"], null, 20);
    } else {
        $h = aiHttp("GET", $base . "/models", $key !== "" ? ["Authorization: Bearer " . $key] : [], null, 20);
    }

    if ($h["code"] < 200 || $h["code"] >= 300) {
        $e = aiHttpFail($h);
        return ["ok" => false, "error" => "Could not load the list: " . $e["msg"]];
    }

    $d = json_decode($h["body"], true);
    $models = [];
    if ($kind === "gemini") {
        foreach (($d["models"] ?? []) as $m) {
            if (in_array("generateContent", $m["supportedGenerationMethods"] ?? [], true)) {
                $models[] = preg_replace('#^models/#', "", (string)($m["name"] ?? ""));
            }
        }
    } else {
        $rows = is_array($d) ? ($d["data"] ?? (array_is_list($d) ? $d : [])) : [];
        foreach ($rows as $m) {
            $id = is_array($m) ? (string)($m["id"] ?? "") : (string)$m;
            if ($id !== "") $models[] = $id;
        }
        if ($kind === "openai" && strcasecmp((string)parse_url($base, PHP_URL_HOST), "api.openai.com") === 0) {
            $models = array_filter($models, fn($m) => !preg_match('/embed|whisper|tts|dall-e|moderation|davinci|babbage|image|audio|realtime|transcribe|search/i', $m));
        }
    }

    $models = array_values(array_unique(array_filter($models)));
    sort($models, SORT_NATURAL | SORT_FLAG_CASE);
    if (!$models) return ["ok" => false, "error" => "The service answered, but listed no chat models."];

    return ["ok" => true, "models" => $models, "suggest" => aiPickModel($models)];
}
