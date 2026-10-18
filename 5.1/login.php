<?php
require "config.php";
require "auth.php";

ensureSchema($conn);
authStart(true);

// Already signed in on this device
if (authCurrentUser($conn)) {
    session_write_close();
    header("Location: index.php");
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
            authClearAttempts($conn);
            authLogin($user);
            header("Location: index.php");
            exit;
        }
        if (!$user) password_hash($pass, PASSWORD_DEFAULT);   // same waiting time whether or not the name exists
        authRecordAttempt($conn);
        $error = "Wrong username or password.";

    } else {

        authRecordAttempt($conn);   // creating accounts is rate limited too

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
                    authLogin($user);
                    header("Location: index.php");
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
<meta name="theme-color" content="#061423">
<title>Log in - File Storage</title>
<script>
try { var t = localStorage.getItem("fs-theme"); if (t === "light" || t === "dark") document.documentElement.dataset.theme = t; } catch (e) {}
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@62..125,100..900&display=swap">
<style>
:root {
    --bg0: #061423;      /* sidebar */
    --bg1: #081b2f;      /* file list */
    --bg2: #0c2540;      /* details */
    --card: #0f2d4c;
    --card-2: #163b61;
    --line: #1c4366;
    --line-2: #3a6a94;
    --text: #e6f0f8;
    --muted: #86a7c2;
    --accent: #ffd23f;       /* highlighter yellow: buttons, avatar, the one loud colour */
    --accent-2: #ffe27a;
    --accent-ink: #061423;
    --accent-text: #ffd23f;  /* accent used as text / icon colour */
    --focus: #ffd23f;
    --edge: #ffd23f;         /* outline of yellow buttons */
    --stamp: #a8851a;        /* hard offset shadow under yellow buttons */
    --danger: #ff7f6e;
    --danger-text: #ffb4a8;
    --on-danger: #1a0508;
    --ok: #5fe3bb;
    --warn: #ffc44d;
    --hover: rgba(140,190,240,.07);
    --row-line: rgba(140,190,240,.08);
    --tint: rgba(255,210,63,.13);
    --tint-2: rgba(255,210,63,.3);
    --scrim: rgba(2,8,16,.72);
    --glass: rgba(8,27,47,.9);
    --shadow: rgba(0,0,0,.5);
    --viewer: #04101c;       /* behind a previewed image */
    --code-bg: #061423;
    --code-text: #d8e6f2;
    --grid: rgba(120,175,230,.07);
    color-scheme: dark;
}
:root[data-theme="light"] {
    --bg0: #dde8f1;
    --bg1: #eef4f9;
    --bg2: #f9fbfd;
    --card: #ffffff;
    --card-2: #e4edf5;
    --line: #c3d3e1;
    --line-2: #8aa6bd;
    --text: #0a2540;
    --muted: #4a6883;
    --accent: #ffd23f;
    --accent-2: #ffc400;
    --accent-ink: #0a2540;
    --accent-text: #0b4a8f;
    --focus: #0b4a8f;
    --edge: #0a2540;
    --stamp: #0a2540;
    --danger: #c43a26;
    --danger-text: #a32d1b;
    --on-danger: #ffffff;
    --ok: #0b8a62;
    --warn: #a96a00;
    --hover: rgba(10,37,64,.05);
    --row-line: rgba(10,37,64,.08);
    --tint: rgba(11,74,143,.09);
    --tint-2: rgba(255,210,63,.55);
    --scrim: rgba(10,37,64,.5);
    --glass: rgba(255,255,255,.93);
    --shadow: rgba(10,37,64,.22);
    --viewer: #d4e1ec;
    --code-bg: #ffffff;
    --code-text: #12304f;
    --grid: rgba(10,37,64,.055);
    color-scheme: light;
}
@media (prefers-color-scheme: light) {
    :root:not([data-theme]) {
        --bg0: #dde8f1;
        --bg1: #eef4f9;
        --bg2: #f9fbfd;
        --card: #ffffff;
        --card-2: #e4edf5;
        --line: #c3d3e1;
        --line-2: #8aa6bd;
        --text: #0a2540;
        --muted: #4a6883;
        --accent: #ffd23f;
        --accent-2: #ffc400;
        --accent-ink: #0a2540;
        --accent-text: #0b4a8f;
        --focus: #0b4a8f;
        --edge: #0a2540;
        --stamp: #0a2540;
        --danger: #c43a26;
        --danger-text: #a32d1b;
        --on-danger: #ffffff;
        --ok: #0b8a62;
        --warn: #a96a00;
        --hover: rgba(10,37,64,.05);
        --row-line: rgba(10,37,64,.08);
        --tint: rgba(11,74,143,.09);
        --tint-2: rgba(255,210,63,.55);
        --scrim: rgba(10,37,64,.5);
        --glass: rgba(255,255,255,.93);
        --shadow: rgba(10,37,64,.22);
        --viewer: #d4e1ec;
        --code-bg: #ffffff;
        --code-text: #12304f;
        --grid: rgba(10,37,64,.055);
        color-scheme: light;
    }
}
* { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
html { -webkit-text-size-adjust: 100%; }
body {
    margin: 0; min-height: 100vh; min-height: 100dvh;
    display: flex; align-items: center; justify-content: center;
    padding: calc(20px + env(safe-area-inset-top)) 16px calc(20px + env(safe-area-inset-bottom));
    font-family: "Archivo", "Segoe UI", system-ui, -apple-system, Roboto, Helvetica, Arial, sans-serif;
    background-color: var(--bg0);
    background-image: linear-gradient(var(--grid) 1px, transparent 1px), linear-gradient(90deg, var(--grid) 1px, transparent 1px);
    background-size: 28px 28px;
    color: var(--text); line-height: 1.45;
}
button, input { font: inherit; color: inherit; }
:focus-visible { outline: 2px solid var(--focus); outline-offset: 2px; }
.card {
    width: 100%; max-width: 400px; padding: 26px 22px 22px;
    background: var(--bg2); border: 1px solid var(--line); border-radius: 8px;
    box-shadow: 0 24px 70px var(--shadow);
}
.brand { display: flex; align-items: center; gap: 12px; margin-bottom: 4px; }
.logo { width: 40px; height: 40px; border-radius: 4px; background: var(--accent); color: var(--accent-ink); display: grid; place-items: center; }
.logo svg { width: 22px; height: 22px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
h1 { margin: 0; font-size: 1.3rem; letter-spacing: -.01em; }
.sub { margin: 4px 0 18px; color: var(--muted); font-size: .92rem; }
.tabs { display: grid; grid-template-columns: 1fr 1fr; gap: 4px; padding: 4px; margin-bottom: 18px; background: var(--bg0); border-radius: 4px; }
.tabs button { min-height: 40px; border: 0; border-radius: 4px; background: transparent; color: var(--muted); font-weight: 600; font-size: .92rem; cursor: pointer; transition: background .15s, color .15s; }
.tabs button:hover { color: var(--text); }
.tabs button[aria-selected="true"] { background: var(--card); color: var(--text); }
form { display: flex; flex-direction: column; gap: 14px; }
form[hidden] { display: none; }
label { display: flex; flex-direction: column; gap: 6px; font-size: .85rem; font-weight: 600; color: var(--muted); }
input[type=text], input[type=password] {
    width: 100%; height: 46px; padding: 0 14px; background: var(--bg0);
    border: 1px solid var(--line); border-radius: 4px; outline: none; font-size: 1rem; font-weight: 400; color: var(--text);
}
input:focus { border-color: var(--focus); }
.pw { position: relative; }
.pw input { padding-right: 64px; }
.pw button { position: absolute; right: 4px; top: 4px; height: 38px; padding: 0 10px; border: 0; border-radius: 4px; background: transparent; color: var(--muted); font-size: .8rem; font-weight: 600; cursor: pointer; }
.pw button:hover { color: var(--text); background: var(--card); }
.hint { margin: -6px 0 0; color: var(--muted); font-size: .78rem; font-weight: 400; }
.btn-primary { min-height: 46px; margin-top: 4px; border: 1px solid var(--accent); border-radius: 4px; background: var(--accent); color: var(--accent-ink); font-weight: 700; font-size: 1rem; cursor: pointer; transition: background .15s; }
.btn-primary:hover { background: var(--accent-2); border-color: var(--accent-2); }
.eula { border: 1px solid var(--line); border-radius: 4px; background: var(--bg0); font-size: .82rem; color: var(--muted); }
.eula summary { cursor: pointer; padding: 10px 12px; font-weight: 600; }
.eula-body { padding: 0 14px 12px; max-height: 190px; overflow-y: auto; line-height: 1.5; }
.eula-body h2 { margin: 0 0 6px; font-size: .88rem; color: var(--text); }
.eula-body ol { margin: 0; padding-left: 18px; }
.eula-body li { margin-bottom: 6px; }
label.agree { flex-direction: row; align-items: flex-start; gap: 10px; font-weight: 400; font-size: .84rem; line-height: 1.4; cursor: pointer; }
label.agree input { flex: none; width: 18px; height: 18px; margin: 1px 0 0; accent-color: var(--accent-text); }
.error { margin: 0 0 14px; padding: 10px 12px; border: 1px solid rgba(255, 107, 120, .45); border-radius: 4px; background: rgba(255, 107, 120, .1); color: var(--danger-text); font-size: .88rem; }

/* look: cyanotype (same as the file page) */
h1 { font-stretch: 100%; font-weight: 700; font-size: 1.3rem; letter-spacing: .01em; }
.card { position: relative; }
.logo { border-radius: 6px; }
.tabs { gap: 3px; padding: 0; background: transparent; border-bottom: 1px solid var(--line); border-radius: 0; }
.tabs button { border: 1px solid transparent; border-bottom: 0; border-radius: 5px 5px 0 0; margin-bottom: -1px; }
.tabs button[aria-selected="true"] { background: var(--bg2); border-color: var(--line); box-shadow: inset 0 2px 0 var(--focus); }
.btn-primary { border-color: var(--edge); box-shadow: 3px 3px 0 var(--stamp); transition: transform .08s, box-shadow .08s, background .15s; }
.btn-primary:hover { border-color: var(--edge); transform: translate(-1px, -1px); box-shadow: 4px 4px 0 var(--stamp); }
.btn-primary:active { transform: translate(3px, 3px); box-shadow: 0 0 0 var(--stamp); }
.theme { position: fixed; top: calc(12px + env(safe-area-inset-top)); right: 12px; width: 40px; height: 40px; display: grid; place-items: center; padding: 0; border: 1px solid var(--line); border-radius: 4px; background: var(--card); color: var(--muted); cursor: pointer; }
.theme:hover { color: var(--text); border-color: var(--line-2); }
.theme svg { width: 18px; height: 18px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
</style>
<noscript><style>form[hidden] { display: flex !important; } .tabs { display: none; }</style></noscript>
</head>
<body>

<button type="button" class="theme" id="themeBtn" aria-label="Switch theme" title="Switch theme"><svg viewBox="0 0 24 24" aria-hidden="true"><path id="themeIcon" d=""/></svg></button>

<main class="card">
    <div class="brand">
        <span class="logo"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg></span>
        <h1>File Storage</h1>
    </div>
    <p class="sub">Log in to see and share your files.</p>

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

        <label>Username
            <input type="text" name="username" autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="50" required
                   value="<?= $tab === "login" ? htmlspecialchars($name) : "" ?>">
        </label>
        <label>Password
            <span class="pw">
                <input type="password" name="password" autocomplete="current-password" maxlength="200" required>
                <button type="button" data-show>Show</button>
            </span>
        </label>
        <button type="submit" class="btn-primary">Log in</button>
    </form>

    <form method="post" id="formRegister" <?= $tab === "register" ? "" : "hidden" ?>>
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="register">

        <label>Choose a username
            <input type="text" name="username" autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="32" required
                   value="<?= $tab === "register" ? htmlspecialchars($name) : "" ?>">
        </label>
        <p class="hint">3 to 32 letters, numbers, dots, dashes or underscores.</p>
        <label>Password
            <span class="pw">
                <input type="password" name="password" autocomplete="new-password" minlength="6" maxlength="72" required>
                <button type="button" data-show>Show</button>
            </span>
        </label>
        <label>Confirm password
            <span class="pw">
                <input type="password" name="password2" autocomplete="new-password" minlength="6" maxlength="72" required>
                <button type="button" data-show>Show</button>
            </span>
        </label>

        <details class="eula">
            <summary>User agreement (please read)</summary>
            <div class="eula-body">
                <h2>AI features and your files</h2>
                <ol>
                    <li>Your files are private unless you choose to share them.</li>
                    <li>This site has an AI assistant. It only works on <b>one file you select</b>: it can read that file only after you open its preview and start the AI chat. It never reads files you have not selected.</li>
                    <li>When you use the AI chat, the content of that file and your messages are sent to an AI provider chosen by the site admin (for example Google Gemini, OpenAI, Anthropic or OpenRouter). If that provider is busy, the same request may be sent to another one so you still get an answer.</li>
                    <li>You agree that the content of files you select for the AI, and your chat messages, may be used to train and improve AI models.</li>
                    <li>Only use the AI on files you have the right to use, and do not use it on anything you are not comfortable having processed this way.</li>
                    <li>You can delete your account at any time and choose whether your uploaded files are deleted with it.</li>
                </ol>
            </div>
        </details>
        <label class="agree">
            <input type="checkbox" name="agree" value="1" required>
            <span>I have read the user agreement and I agree that the AI may view the files I select and use that data to train AI models.</span>
        </label>
        <button type="submit" class="btn-primary">Create account</button>
    </form>
</main>

<script>
const tabs = { login: [document.getElementById("tabLogin"), document.getElementById("formLogin")],
               register: [document.getElementById("tabRegister"), document.getElementById("formRegister")] };

function show(which) {
    for (const k in tabs) {
        tabs[k][0].setAttribute("aria-selected", k === which ? "true" : "false");
        tabs[k][1].hidden = k !== which;
    }
    const first = tabs[which][1].querySelector("input[type=text]");
    if (first) first.focus();
    const err = document.querySelector(".error");
    if (err) err.remove();
}
tabs.login[0].addEventListener("click", () => show("login"));
tabs.register[0].addEventListener("click", () => show("register"));
if (location.hash === "#register") show("register");

document.querySelectorAll("[data-show]").forEach(b => b.addEventListener("click", () => {
    const input = b.parentElement.querySelector("input");
    const reveal = input.type === "password";
    input.type = reveal ? "text" : "password";
    b.textContent = reveal ? "Hide" : "Show";
}));

// Register form: check the two passwords match before sending
document.getElementById("formRegister").addEventListener("submit", e => {
    const f = e.target;
    if (f.password.value !== f.password2.value) {
        e.preventDefault();
        f.password2.setCustomValidity("The two passwords don't match.");
        f.password2.reportValidity();
    }
});
document.querySelector('#formRegister [name=password2]').addEventListener("input", e => e.target.setCustomValidity(""));

// Light / dark: follows the device until the button is used, then the choice is remembered in this browser
const SUN = "M12 8a4 4 0 100 8 4 4 0 000-8zM12 2.5v2.2M12 19.3v2.2M2.5 12h2.2M19.3 12h2.2M5.3 5.3l1.6 1.6M17.1 17.1l1.6 1.6M5.3 18.7l1.6-1.6M17.1 6.9l1.6-1.6";
const MOON = "M20.5 14.2A8.5 8.5 0 019.8 3.5a8.5 8.5 0 1010.7 10.7z";
function themeNow() { return document.documentElement.dataset.theme || (matchMedia("(prefers-color-scheme: light)").matches ? "light" : "dark"); }
function themeSync() {
    const t = themeNow(), btn = document.getElementById("themeBtn");
    document.getElementById("themeIcon").setAttribute("d", t === "light" ? MOON : SUN);
    const label = t === "light" ? "Switch to dark theme" : "Switch to light theme";
    btn.title = label; btn.setAttribute("aria-label", label);
    const m = document.querySelector('meta[name="theme-color"]');
    if (m) m.content = t === "light" ? "#dde8f1" : "#061423";
}
document.getElementById("themeBtn").addEventListener("click", () => {
    const next = themeNow() === "light" ? "dark" : "light";
    document.documentElement.dataset.theme = next;
    try { localStorage.setItem("fs-theme", next); } catch (e) {}
    themeSync();
});
matchMedia("(prefers-color-scheme: light)").addEventListener("change", themeSync);
themeSync();
</script>
</body>
</html>
