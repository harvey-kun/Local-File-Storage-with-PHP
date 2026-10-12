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
        if ($err === null && authFindByName($conn, $name)) $err = "That username is already taken.";

        if ($err === null) {
            try {
                $hash = password_hash($pass, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO users (username, password, is_admin) VALUES (?, ?, 0)");
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
<meta name="theme-color" content="#0b0d11">
<title>Log in - File Storage</title>
<style>
:root {
    --bg0: #0b0d11; --bg2: #171b23; --card: #1d222c; --line: #262c38;
    --text: #e7eaf1; --muted: #8b94a8; --accent: #7b96ff; --accent-2: #94abff;
    --accent-ink: #0b1020; --danger: #ff6b78;
}
* { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
html { color-scheme: dark; -webkit-text-size-adjust: 100%; }
body {
    margin: 0; min-height: 100vh; min-height: 100dvh;
    display: flex; align-items: center; justify-content: center;
    padding: calc(20px + env(safe-area-inset-top)) 16px calc(20px + env(safe-area-inset-bottom));
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    background: radial-gradient(circle at 20% 0%, #1a2140 0, transparent 55%), var(--bg0);
    color: var(--text); line-height: 1.45;
}
button, input { font: inherit; color: inherit; }
:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
.card {
    width: 100%; max-width: 400px; padding: 26px 22px 22px;
    background: var(--bg2); border: 1px solid var(--line); border-radius: 18px;
    box-shadow: 0 24px 70px rgba(0, 0, 0, .5);
}
.brand { display: flex; align-items: center; gap: 12px; margin-bottom: 4px; }
.logo { width: 40px; height: 40px; border-radius: 12px; background: var(--accent); color: var(--accent-ink); display: grid; place-items: center; }
.logo svg { width: 22px; height: 22px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
h1 { margin: 0; font-size: 1.3rem; letter-spacing: -.01em; }
.sub { margin: 4px 0 18px; color: var(--muted); font-size: .92rem; }
.tabs { display: grid; grid-template-columns: 1fr 1fr; gap: 4px; padding: 4px; margin-bottom: 18px; background: var(--bg0); border-radius: 12px; }
.tabs button { min-height: 40px; border: 0; border-radius: 9px; background: transparent; color: var(--muted); font-weight: 600; font-size: .92rem; cursor: pointer; transition: background .15s, color .15s; }
.tabs button:hover { color: var(--text); }
.tabs button[aria-selected="true"] { background: var(--card); color: var(--text); }
form { display: flex; flex-direction: column; gap: 14px; }
form[hidden] { display: none; }
label { display: flex; flex-direction: column; gap: 6px; font-size: .85rem; font-weight: 600; color: var(--muted); }
input[type=text], input[type=password] {
    width: 100%; height: 46px; padding: 0 14px; background: var(--bg0);
    border: 1px solid var(--line); border-radius: 11px; outline: none; font-size: 1rem; font-weight: 400; color: var(--text);
}
input:focus { border-color: var(--accent); }
.pw { position: relative; }
.pw input { padding-right: 64px; }
.pw button { position: absolute; right: 4px; top: 4px; height: 38px; padding: 0 10px; border: 0; border-radius: 8px; background: transparent; color: var(--muted); font-size: .8rem; font-weight: 600; cursor: pointer; }
.pw button:hover { color: var(--text); background: var(--card); }
.hint { margin: -6px 0 0; color: var(--muted); font-size: .78rem; font-weight: 400; }
.btn-primary { min-height: 46px; margin-top: 4px; border: 1px solid var(--accent); border-radius: 11px; background: var(--accent); color: var(--accent-ink); font-weight: 700; font-size: 1rem; cursor: pointer; transition: background .15s; }
.btn-primary:hover { background: var(--accent-2); border-color: var(--accent-2); }
.error { margin: 0 0 14px; padding: 10px 12px; border: 1px solid rgba(255, 107, 120, .45); border-radius: 10px; background: rgba(255, 107, 120, .1); color: #ffb4bb; font-size: .88rem; }
</style>
<noscript><style>form[hidden] { display: flex !important; } .tabs { display: none; }</style></noscript>
</head>
<body>

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
</script>
</body>
</html>
