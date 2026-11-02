<?php
// Resolve the correct project files regardless of whether this page is served from 6.0 or 6.1.
$phpDir = __DIR__;
$configPath = null;
$authPath = null;

foreach ([
    $phpDir . "/config.php",
    $phpDir . "/../6.0/config.php",
    $phpDir . "/../config.php",
] as $candidate) {
    if ($configPath === null && file_exists($candidate)) $configPath = $candidate;
}

foreach ([
    $phpDir . "/auth.php",
    $phpDir . "/../6.0/auth.php",
    $phpDir . "/../auth.php",
] as $candidate) {
    if ($authPath === null && file_exists($candidate)) $authPath = $candidate;
}

if ($configPath === null || $authPath === null) {
    http_response_code(500);
    die("Missing application config or auth files.");
}

require $configPath;
require $authPath;

function resolveRedirectTarget(string $target): string {
    $docRoot = rtrim((string)($_SERVER["DOCUMENT_ROOT"] ?? ""), "/");
    $scriptDir = rtrim(str_replace("\\", "/", dirname($_SERVER["SCRIPT_NAME"] ?? "/")), "/");
    $candidates = [];

    if ($scriptDir !== "" && $scriptDir !== "/") {
        $candidates[] = $scriptDir . "/" . $target;
        $candidates[] = $scriptDir . "/../6.0/" . $target;
    }
    $candidates[] = "/" . ltrim($target, "/");
    $candidates[] = "/6.0/" . ltrim($target, "/");

    foreach ($candidates as $candidate) {
        $file = $docRoot . $candidate;
        if ($docRoot !== "" && file_exists($file)) {
            return $candidate;
        }
    }

    return $target;
}

ensureSchema($conn);
authStart(true);

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "logout") {
    if (csrfValid()) {
        authLogout();
        header("Location: " . resolveRedirectTarget("login.php"));
    } else {
        session_write_close();
        header("Location: " . resolveRedirectTarget("index.php"));
    }
    exit;
}

if (authCurrentUser($conn)) {
    session_write_close();
    header("Location: " . resolveRedirectTarget("index.php"));
    exit;
}

if (empty($_SESSION["csrf"])) $_SESSION["csrf"] = bin2hex(random_bytes(24));

$tab   = "login";
$error = "";
$name  = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $tab  = ($_POST["action"] ?? "") === "register" ? "register" : "login";
    $name = trim((string)($_POST["username"] ?? ""));
    $pass = (string)($_POST["password"] ?? "");

    if (!csrfValid()) {
        $error = "This page was open for too long, or cookies are turned off. Please try again.";
    } elseif (authThrottled($conn)) {
        $error = "Too many attempts. Please wait a few minutes and try again.";
    } elseif ($tab === "login") {
        $user = authFindByName($conn, $name);
        if ($user && authCheckPassword($conn, $user, $pass, true)) {
            authClearAttempts($conn, $user["username"]);
            authLogin($user);
            header("Location: " . resolveRedirectTarget("index.php"));
            exit;
        }
        if (!$user) password_hash($pass, PASSWORD_DEFAULT);
        authRecordAttempt($conn, $name, "failed");
        $error = "Wrong username or password.";
    } else {
        authRecordAttempt($conn, $name, "signup");

        $err = validUsername($name) ?? validPassword($pass);
        if ($err === null && $pass !== (string)($_POST["password2"] ?? "")) $err = "The two passwords don't match.";
        if ($err === null && ($_POST["agree"] ?? "") !== "1") $err = "You need to accept the user agreement to create an account.";
        if ($err === null && authFindByName($conn, $name)) $err = "That username is already taken.";

        if ($err === null) {
            try {
                $hash = password_hash($pass, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO users (username, password, is_admin, agreed_ai_at) VALUES (?, ?, 0, NOW())");
                $stmt->bind_param("ss", $name, $hash);
                $stmt->execute();

                $user = authFindByName($conn, $name);
                if ($user) {
                    authRecordAttempt($conn, $name, "new");
                    authLogin($user);
                    header("Location: " . resolveRedirectTarget("index.php"));
                    exit;
                }
                $err = "Could not create the account.";
            } catch (mysqli_sql_exception $e) {
                $err = $e->getCode() === 1062 ? "That username is already taken." : "Could not create the account.";
            }
        }
        $error = $err;
    }
}

$csrf = csrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#071827">
    <title>Northstar Secure Storage</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #071827;
            --bg-soft: #0d1f2d;
            --panel: rgba(13, 31, 45, 0.82);
            --panel-strong: #10263a;
            --panel-alt: #122c3d;
            --line: rgba(149, 181, 204, 0.24);
            --line-strong: rgba(166, 206, 234, 0.38);
            --text: #edf6ff;
            --muted: #9bb7c9;
            --blue: #64c5ff;
            --cyan: #5de2d1;
            --gold: #f7c96d;
            --gold-strong: #f2b84d;
            --danger: #ff6e7d;
            --danger-soft: rgba(255, 110, 125, 0.15);
            --success: #79f0ae;
            --shadow: rgba(2, 8, 16, 0.55);
        }

        * { box-sizing: border-box; }

        html, body {
            margin: 0;
            min-height: 100%;
            font-family: "Inter", "Segoe UI", sans-serif;
            background:
                radial-gradient(circle at top left, rgba(100, 197, 255, 0.12), transparent 28%),
                radial-gradient(circle at bottom right, rgba(93, 226, 209, 0.12), transparent 30%),
                linear-gradient(135deg, #050d17 0%, var(--bg) 35%, #0a1827 100%);
            color: var(--text);
        }

        body {
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 32px 18px;
        }

        .layout {
            width: min(1160px, 100%);
            display: grid;
            grid-template-columns: 1.08fr 0.92fr;
            gap: 30px;
            align-items: stretch;
        }

        .hero {
            position: relative;
            overflow: hidden;
            border-radius: 26px;
            border: 1px solid var(--line);
            background: linear-gradient(180deg, rgba(10, 26, 38, 0.92), rgba(12, 21, 31, 0.94));
            box-shadow: 0 30px 80px var(--shadow);
            padding: 30px 30px 24px;
        }

        .hero::before {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(120deg, rgba(100, 197, 255, 0.12), transparent 18%, transparent 80%, rgba(93, 226, 209, 0.16));
            pointer-events: none;
        }

        .brand {
            position: relative;
            z-index: 1;
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 38px;
        }

        .brand-mark {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            display: grid;
            place-items: center;
            background: linear-gradient(135deg, var(--gold), var(--gold-strong));
            color: #0b1622;
            font-weight: 900;
            letter-spacing: 0.04em;
            box-shadow: 0 12px 28px rgba(242, 184, 77, 0.35);
        }

        .brand-name {
            display: flex;
            flex-direction: column;
            line-height: 1.05;
        }

        .brand-name .eyebrow {
            font-size: 0.7rem;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: var(--muted);
        }

        .brand-name strong {
            font-size: 1.15rem;
            letter-spacing: -0.03em;
        }

        .hero-copy {
            position: relative;
            z-index: 1;
            max-width: 580px;
        }

        .chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border: 1px solid rgba(100, 197, 255, 0.28);
            border-radius: 999px;
            background: rgba(100, 197, 255, 0.08);
            color: var(--blue);
            font-size: 0.72rem;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            font-weight: 700;
        }

        .chip::before {
            content: "";
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--cyan);
            box-shadow: 0 0 0 4px rgba(93, 226, 209, 0.18);
        }

        h1 {
            margin: 18px 0 14px;
            font-size: clamp(2.4rem, 4vw, 4.2rem);
            line-height: 0.96;
            letter-spacing: -0.07em;
            max-width: 12ch;
        }

        .hero-copy p {
            margin: 0;
            max-width: 42ch;
            font-size: 1.05rem;
            color: var(--muted);
            line-height: 1.65;
        }

        .metrics {
            position: relative;
            z-index: 1;
            list-style: none;
            padding: 0;
            margin: 34px 0 0;
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
        }

        .metrics li {
            padding: 18px 18px 16px;
            border: 1px solid var(--line);
            border-radius: 18px;
            background: rgba(18, 40, 54, 0.68);
            backdrop-filter: blur(6px);
        }

        .metrics strong {
            display: block;
            font-size: 1.6rem;
            letter-spacing: -0.06em;
        }

        .metrics span {
            display: block;
            margin-top: 6px;
            color: var(--muted);
            font-size: 0.82rem;
        }

        .card {
            position: relative;
            width: min(100%, 430px);
            justify-self: end;
            border: 1px solid var(--line-strong);
            border-radius: 24px;
            background: rgba(12, 28, 39, 0.88);
            box-shadow: 0 24px 84px rgba(2, 11, 18, 0.85);
            padding: 24px 22px 20px;
            backdrop-filter: blur(8px);
        }

        .card-shell {
            width: 100%;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .card-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            margin-bottom: 8px;
        }

        .mini-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 700;
        }

        .mini-brand .logo {
            width: 32px;
            height: 32px;
            display: grid;
            place-items: center;
            border-radius: 10px;
            background: linear-gradient(135deg, rgba(247, 201, 109, 0.18), rgba(93, 226, 209, 0.12));
            border: 1px solid rgba(247, 201, 109, 0.38);
            color: var(--gold);
            font-weight: 900;
        }

        .signin-tag {
            font-size: 0.74rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--muted);
        }

        .tabs {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px;
            margin: 4px 0 18px;
            padding: 6px;
            border-radius: 14px;
            background: rgba(11, 19, 26, 0.9);
            border: 1px solid var(--line);
        }

        .tabs button {
            min-height: 42px;
            border: 0;
            border-radius: 10px;
            background: transparent;
            color: var(--muted);
            font-weight: 700;
            cursor: pointer;
            transition: 0.18s ease;
        }

        .tabs button[aria-selected="true"] {
            background: linear-gradient(180deg, rgba(100,197,255,0.18), rgba(93,226,209,0.08));
            color: var(--text);
            box-shadow: inset 0 0 0 1px rgba(100,197,255,0.35);
        }

        form {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        form[hidden] { display: none; }

        .error {
            margin: 0 0 4px;
            padding: 10px 12px;
            border-radius: 12px;
            border: 1px solid rgba(255, 110, 125, 0.5);
            background: var(--danger-soft);
            color: #ffb5bd;
            font-size: 0.88rem;
        }

        label {
            display: flex;
            flex-direction: column;
            gap: 7px;
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.02em;
            color: var(--muted);
        }

        input[type="text"], input[type="password"] {
            width: 100%;
            min-height: 48px;
            padding: 0 14px;
            border-radius: 12px;
            background: rgba(6, 18, 25, 0.9);
            border: 1px solid var(--line);
            color: var(--text);
            font-size: 0.98rem;
            outline: none;
            transition: border-color 0.15s, box-shadow 0.15s;
        }

        input[type="text"]:focus, input[type="password"]:focus {
            border-color: rgba(100, 197, 255, 0.8);
            box-shadow: 0 0 0 3px rgba(100, 197, 255, 0.12);
        }

        .pw {
            position: relative;
        }

        .pw input { padding-right: 68px; }

        .pw button {
            position: absolute;
            right: 6px;
            top: 6px;
            min-height: 36px;
            padding: 0 10px;
            border: 0;
            border-radius: 10px;
            background: rgba(255,255,255,0.04);
            color: var(--muted);
            font-size: 0.72rem;
            font-weight: 700;
            cursor: pointer;
        }

        .hint {
            margin: -2px 0 0;
            color: var(--muted);
            font-size: 0.75rem;
            line-height: 1.5;
        }

        .forgot {
            text-align: center;
            margin-top: 4px;
        }

        .forgot a {
            color: var(--blue);
            text-decoration: none;
        }

        .btn-primary {
            margin-top: 4px;
            min-height: 48px;
            border: 1px solid rgba(247, 201, 109, 0.68);
            border-radius: 12px;
            background: linear-gradient(135deg, var(--gold), var(--gold-strong));
            color: #09161f;
            font-weight: 900;
            font-size: 1rem;
            letter-spacing: 0.02em;
            cursor: pointer;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
            box-shadow: 0 14px 24px rgba(247, 201, 109, 0.2);
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 16px 28px rgba(247, 201, 109, 0.28);
        }

        .eula {
            border-radius: 12px;
            border: 1px solid var(--line);
            background: rgba(11, 19, 26, 0.8);
            overflow: hidden;
        }

        .eula summary {
            padding: 12px 14px;
            list-style: none;
            cursor: pointer;
            color: var(--text);
            font-size: 0.84rem;
            font-weight: 700;
        }

        .eula summary::-webkit-details-marker { display: none; }

        .eula-body {
            padding: 0 14px 14px;
            font-size: 0.8rem;
            color: var(--muted);
            line-height: 1.6;
        }

        .eula-body h2 {
            margin: 0 0 8px;
            color: var(--text);
            font-size: 0.86rem;
        }

        .eula-body ol {
            margin: 0;
            padding-left: 18px;
        }

        label.agree {
            flex-direction: row;
            align-items: flex-start;
            gap: 10px;
            color: var(--muted);
            font-weight: 500;
            line-height: 1.5;
            cursor: pointer;
        }

        label.agree input {
            width: 18px;
            height: 18px;
            margin-top: 2px;
            accent-color: var(--gold-strong);
        }

        .theme-toggle {
            position: fixed;
            top: 18px;
            right: 18px;
            width: 44px;
            height: 44px;
            border-radius: 12px;
            border: 1px solid var(--line);
            background: rgba(13, 31, 45, 0.9);
            color: var(--text);
            display: grid;
            place-items: center;
            cursor: pointer;
            z-index: 2;
        }

        .theme-toggle svg {
            width: 18px;
            height: 18px;
            fill: none;
            stroke: currentColor;
            stroke-width: 1.8;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        @media (max-width: 960px) {
            .layout {
                grid-template-columns: 1fr;
                max-width: 520px;
            }

            .card {
                justify-self: stretch;
                width: 100%;
            }

            .hero {
                min-height: 0;
            }
        }

        @media (max-width: 520px) {
            .metrics {
                grid-template-columns: 1fr;
            }

            .card {
                padding-left: 18px;
                padding-right: 18px;
            }
        }
    </style>
</head>
<body>
    <button type="button" class="theme-toggle" id="themeBtn" aria-label="Switch theme" title="Switch theme">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path id="themeIcon" d="M12 2.5v2.2M12 19.3v2.2M2.5 12h2.2M19.3 12h2.2M5.3 5.3l1.6 1.6M17.1 17.1l1.6 1.6M5.3 18.7l1.6-1.6M17.1 6.9l1.6-1.6M12 8a4 4 0 1 1 0 8 4 4 0 0 1 0-8z"/></svg>
    </button>

    <div class="layout">
        <aside class="hero" aria-label="Northstar Secure Storage overview">
            <div class="brand">
                <div class="brand-mark">N</div>
                <div class="brand-name">
                    <span class="eyebrow">Northstar</span>
                    <strong>Secure Storage</strong>
                </div>
            </div>

            <div class="hero-copy">
                <div class="chip">Private cloud operations</div>
                <h1>Store every file in a secure vault.</h1>
                <p>Northstar Secure Storage gives your team a modern, compliant home for documents, media, and shared project files—without sacrificing speed or privacy.</p>
            </div>

            <ul class="metrics">
                <li>
                    <strong>24/7</strong>
                    <span>Secure access</span>
                </li>
                <li>
                    <strong>1-click</strong>
                    <span>Shared links</span>
                </li>
                <li>
                    <strong>AI</strong>
                    <span>Smart review</span>
                </li>
            </ul>
        </aside>

        <main class="card">
            <div class="card-shell">
                <div class="card-top">
                    <div class="mini-brand">
                        <span class="logo">N</span>
                        <span>Northstar</span>
                    </div>
                    <span class="signin-tag">Secure sign in</span>
                </div>

                <div class="tabs" role="tablist">
                    <button type="button" role="tab" id="tabLogin" aria-selected="<?= $tab === "login" ? "true" : "false" ?>">Log in</button>
                    <button type="button" role="tab" id="tabRegister" aria-selected="<?= $tab === "register" ? "true" : "false" ?>">Create account</button>
                </div>

                <?php if ($error !== ""): ?>
                    <p class="error" role="alert"><?= htmlspecialchars($error) ?></p>
                <?php endif; ?>

                <form method="post" id="formLogin" <?= $tab === "login" ? "" : "hidden" ?>>
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="login">

                    <label>
                        Username
                        <input type="text" name="username" autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="50" required value="<?= $tab === "login" ? htmlspecialchars($name) : "" ?>">
                    </label>

                    <label>
                        Password
                        <span class="pw">
                            <input type="password" name="password" autocomplete="current-password" maxlength="200" required>
                            <button type="button" data-show>Show</button>
                        </span>
                    </label>

                    <button type="submit" class="btn-primary">Log in</button>
                    <p class="hint forgot">Need access? <a href="#">Contact your administrator</a>.</p>
                </form>

                <form method="post" id="formRegister" <?= $tab === "register" ? "" : "hidden" ?>>
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="register">

                    <label>
                        Choose a username
                        <input type="text" name="username" autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="32" required value="<?= $tab === "register" ? htmlspecialchars($name) : "" ?>">
                    </label>
                    <p class="hint">3 to 32 letters, numbers, dots, dashes or underscores.</p>

                    <label>
                        Password
                        <span class="pw">
                            <input type="password" name="password" autocomplete="new-password" minlength="6" maxlength="72" required>
                            <button type="button" data-show>Show</button>
                        </span>
                    </label>

                    <label>
                        Confirm password
                        <span class="pw">
                            <input type="password" name="password2" autocomplete="new-password" minlength="6" maxlength="72" required>
                            <button type="button" data-show>Show</button>
                        </span>
                    </label>

                    <details class="eula">
                        <summary>User agreement</summary>
                        <div class="eula-body">
                            <h2>AI features and your files</h2>
                            <ol>
                                <li>Your files remain private unless you choose to share them.</li>
                                <li>This platform includes AI tools for selected files and direct file previews.</li>
                                <li>AI processing may use the file content and your messages for service operation and improvement.</li>
                                <li>Only use the AI on files you are authorized to process.</li>
                                <li>You can delete your account and associated content at any time.</li>
                            </ol>
                        </div>
                    </details>

                    <label class="agree">
                        <input type="checkbox" name="agree" value="1" required>
                        <span>I have read the agreement and consent to secure AI analysis for the files I select.</span>
                    </label>

                    <button type="submit" class="btn-primary">Create account</button>
                </form>
            </div>
        </main>
    </div>

    <script>
        const tabs = {
            login: [document.getElementById("tabLogin"), document.getElementById("formLogin")],
            register: [document.getElementById("tabRegister"), document.getElementById("formRegister")]
        };

        function show(which) {
            for (const key in tabs) {
                tabs[key][0].setAttribute("aria-selected", key === which ? "true" : "false");
                tabs[key][1].hidden = key !== which;
            }
            const first = tabs[which][1].querySelector("input[type=text]");
            if (first) first.focus();
            const err = document.querySelector(".error");
            if (err) err.remove();
        }

        tabs.login[0].addEventListener("click", () => show("login"));
        tabs.register[0].addEventListener("click", () => show("register"));
        if (location.hash === "#register") show("register");

        document.querySelectorAll("[data-show]").forEach(function (button) {
            button.addEventListener("click", function () {
                const input = button.parentElement.querySelector("input");
                const reveal = input.type === "password";
                input.type = reveal ? "text" : "password";
                button.textContent = reveal ? "Hide" : "Show";
            });
        });

        document.getElementById("formRegister").addEventListener("submit", function (event) {
            const form = event.target;
            if (form.password.value !== form.password2.value) {
                event.preventDefault();
                form.password2.setCustomValidity("The two passwords don't match.");
                form.password2.reportValidity();
            }
        });

        document.querySelector('#formRegister [name=password2]').addEventListener("input", function (event) {
            event.target.setCustomValidity("");
        });

        const savedTheme = localStorage.getItem("northstar-theme");
        const prefersLight = window.matchMedia("(prefers-color-scheme: light)").matches;
        const currentTheme = savedTheme || (prefersLight ? "light" : "dark");

        function applyTheme(theme) {
            const root = document.documentElement;
            const icon = document.getElementById("themeIcon");
            if (theme === "light") {
                root.style.setProperty("--bg", "#edf5fb");
                root.style.setProperty("--bg-soft", "#dfeaf5");
                root.style.setProperty("--panel", "rgba(255,255,255,0.9)");
                root.style.setProperty("--panel-strong", "#eaf3fa");
                root.style.setProperty("--panel-alt", "#ffffff");
                root.style.setProperty("--line", "rgba(58, 92, 120, 0.18)");
                root.style.setProperty("--line-strong", "rgba(58, 92, 120, 0.25)");
                root.style.setProperty("--text", "#0d2339");
                root.style.setProperty("--muted", "#55708a");
                root.style.setProperty("--danger-soft", "rgba(255, 110, 125, 0.08)");
                root.style.setProperty("--panel", "rgba(255,255,255,0.82)");
                icon.setAttribute("d", "M20.5 14.2A8.5 8.5 0 0 1 9.8 3.5a8.5 8.5 0 1 0 10.7 10.7z");
                document.querySelector("meta[name=theme-color]").setAttribute("content", "#edf5fb");
            } else {
                root.style.setProperty("--bg", "#071827");
                root.style.setProperty("--bg-soft", "#0d1f2d");
                root.style.setProperty("--panel", "rgba(13, 31, 45, 0.82)");
                root.style.setProperty("--panel-strong", "#10263a");
                root.style.setProperty("--panel-alt", "#122c3d");
                root.style.setProperty("--line", "rgba(149, 181, 204, 0.24)");
                root.style.setProperty("--line-strong", "rgba(166, 206, 234, 0.38)");
                root.style.setProperty("--text", "#edf6ff");
                root.style.setProperty("--muted", "#9bb7c9");
                root.style.setProperty("--danger-soft", "rgba(255, 110, 125, 0.15)");
                icon.setAttribute("d", "M12 2.5v2.2M12 19.3v2.2M2.5 12h2.2M19.3 12h2.2M5.3 5.3l1.6 1.6M17.1 17.1l1.6 1.6M5.3 18.7l1.6-1.6M17.1 6.9l1.6-1.6M12 8a4 4 0 1 1 0 8 4 4 0 0 1 0-8z");
                document.querySelector("meta[name=theme-color]").setAttribute("content", "#071827");
            }
        }

        document.getElementById("themeBtn").addEventListener("click", function () {
            const next = getComputedStyle(document.documentElement).getPropertyValue("--bg").trim() === "#edf5fb" ? "dark" : "light";
            localStorage.setItem("northstar-theme", next);
            applyTheme(next);
        });

        applyTheme(currentTheme);
    </script>
</body>
</html>
