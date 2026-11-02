<?php
require "config.php";
require "auth.php";

ensureSchema($conn);
authStart(true);

// Log out: only a real "Log out" button press (POST with the page's token) signs out
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "logout") {
    if (csrfValid()) {
        authLogout();
        header("Location: login.php");
    } else {
        session_write_close();
        header("Location: index.php");
    }
    exit;
}

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
            authClearAttempts($conn, $user["username"]);
            authLogin($user);
            header("Location: index.php");
            exit;
        }
        if (!$user) password_hash($pass, PASSWORD_DEFAULT);   // same waiting time whether or not the name exists
        authRecordAttempt($conn, $name, "failed");
        $error = "Wrong username or password.";

    } else {

        authRecordAttempt($conn, $name, "signup");   // creating accounts is rate limited too

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
<title>Northstar Secure Storage | Secure Access</title>
<script>
try { var t = localStorage.getItem("fs-theme"); if (t === "light" || t === "dark") document.documentElement.dataset.theme = t; } catch (e) {}
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@62..125,100..900&display=swap">
<script>
document.documentElement.classList.add("intro");
setTimeout(function () { document.documentElement.classList.remove("intro"); }, 2000);
</script>
<style>
:root {
    --bg0: #061423;
    --bg1: #081b2f;
    --bg2: #0c2540;
    --card: #0f2d4c;
    --card-2: #163b61;
    --line: #1c4366;
    --line-2: #3a6a94;
    --text: #e6f0f8;
    --muted: #86a7c2;
    --accent: #ffd23f;
    --accent-2: #ffe27a;
    --accent-ink: #061423;
    --accent-text: #ffd23f;
    --focus: #ffd23f;
    --edge: #ffd23f;
    --stamp: #a8851a;
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
    --viewer: #04101c;
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
.hint.forgot { margin: 12px 0 0; text-align: center; }
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
:root { --ease: cubic-bezier(.2,.8,.2,1); --spring: cubic-bezier(.34,1.56,.64,1); }
@keyframes stampDown { 0% { opacity: 0; transform: translate(-6px,-6px) scale(1.5) rotate(-10deg); box-shadow: 9px 9px 0 var(--stamp); }
                       55% { opacity: 1; }
                       100% { transform: none; box-shadow: 0 0 0 var(--stamp); } }
@keyframes cardIn { from { opacity: 0; transform: translateY(18px) scale(.985); } to { opacity: 1; transform: none; } }
@keyframes riseIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: none; } }
@keyframes tabIn  { from { opacity: 0; transform: translateX(10px); } to { opacity: 1; transform: none; } }
@keyframes shake  { 0%, 100% { transform: none; } 20% { transform: translateX(-7px); } 40% { transform: translateX(6px); } 60% { transform: translateX(-4px); } 80% { transform: translateX(2px); } }
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

body { animation: fadeIn .6s ease-out; }
.card { animation: cardIn .6s var(--ease) backwards; }
.logo { animation: stampDown .65s var(--ease) .3s backwards; }
html.intro .card > :not(.error) { animation: riseIn .5s var(--ease) backwards; animation-delay: calc(var(--k, 4) * 70ms + 250ms); }
html.intro .card > :nth-child(1) { --k: 0; }
html.intro .card > :nth-child(2) { --k: 1; }
html.intro .card > :nth-child(3) { --k: 2; }
html.intro .card > :nth-child(4) { --k: 3; }

form:not([hidden]) { animation: tabIn .3s var(--ease); }
.tabs button { transition: background .15s, color .15s, box-shadow .2s; }
.error { animation: shake .45s var(--ease) .5s; }
input[type=text], input[type=password] { transition: border-color .15s, box-shadow .15s; }
input[type=text]:focus, input[type=password]:focus { box-shadow: 0 0 0 3px color-mix(in srgb, var(--focus) 22%, transparent); }
.pw button, .theme { transition: color .15s, background .15s, border-color .15s, transform .12s var(--spring); }
.pw button:active, .theme:active { transform: scale(.92); }
.theme:hover svg { transform: rotate(25deg); }
.theme svg { transition: transform .35s var(--spring); }

@keyframes grid    { to { background-position: 28px 28px, 28px 28px; } }
@keyframes bob     { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-3px); } }
@keyframes scan    { 0% { transform: translateY(0); opacity: 0; } 10% { opacity: .9; } 100% { transform: translateY(var(--h, 420px)); opacity: 0; } }
@keyframes barber  { to { background-position: 14.14px 0; } }
@keyframes tick    { 0% { transform: scale(.3); opacity: 0; } 100% { transform: scale(1); opacity: 1; } }
@keyframes spinIn  { from { opacity: 0; transform: rotate(-90deg) scale(.5); } to { opacity: 1; transform: none; } }
body { animation: fadeIn .6s ease-out, grid 50s linear infinite; }
.card { overflow: hidden; }
.card::before { content: ""; position: absolute; left: 0; right: 0; top: 0; height: 2px; pointer-events: none; opacity: 0;
                background: linear-gradient(90deg, transparent, var(--accent), transparent); animation: scan 1.1s var(--ease) .5s; }
.logo { transition: transform .3s var(--spring); }
@media (hover: hover) { .logo:hover { transform: rotate(-8deg) scale(1.08); } }
label { transition: color .15s; }
label:focus-within { color: var(--text); }
.hint { transition: color .15s; }
.eula[open] .eula-body { animation: riseIn .3s var(--ease); }
.eula summary { transition: color .15s; }
.eula summary:hover { color: var(--text); }
label.agree input { transition: transform .2s var(--spring); }
label.agree input:checked { transform: scale(1.18); }
.theme:active svg { transform: rotate(180deg); }
.theme svg { animation: spinIn .6s var(--spring) .6s backwards; }
.hint.forgot a, .card a { transition: color .15s; }
.btn-primary { position: relative; overflow: hidden; }
.btn-primary.busy { pointer-events: none; background-image: repeating-linear-gradient(-45deg, rgba(6,20,35,.18) 0 6px, transparent 6px 12px); background-size: 16.97px 100%; animation: barber .7s linear infinite; }
.ripple { position: absolute; border-radius: 50%; background: currentColor; pointer-events: none; animation: ripple .6s var(--ease) forwards; }
@keyframes ripple { from { transform: scale(0); opacity: .35; } to { transform: scale(1); opacity: 0; } }
@keyframes sRow  { from { opacity: 0; transform: translateY(14px) rotate(-.6deg); } to { opacity: 1; transform: none; } }
@keyframes mpick { 0%, 22% { background: var(--card); box-shadow: inset 0 0 0 1px var(--line); } 27%, 100% { background: transparent; box-shadow: inset 0 0 0 1px transparent; } }
@keyframes mbar  { 0%, 22% { opacity: 1; width: 6px; } 27%, 100% { opacity: .45; width: 4px; } }
@keyframes prog  { 0% { transform: scaleX(0); background-color: var(--accent); } 70% { transform: scaleX(1); background-color: var(--accent); } 74%, 100% { transform: scaleX(1); background-color: var(--ok); } }
@keyframes stA   { 0%, 70% { opacity: 1; } 74%, 100% { opacity: 0; } }
@keyframes stB   { 0%, 70% { opacity: 0; transform: translateY(4px); } 76%, 100% { opacity: 1; transform: none; } }
@keyframes bubU  { 0%, 8% { opacity: 0; transform: translateY(10px) scale(.95); } 13%, 86% { opacity: 1; transform: none; } 94%, 100% { opacity: 0; transform: translateY(-4px); } }
@keyframes bubA  { 0%, 24% { opacity: 0; transform: translateY(10px) scale(.95); } 30%, 86% { opacity: 1; transform: none; } 94%, 100% { opacity: 0; transform: translateY(-4px); } }
.wall { position: fixed; inset: 0; z-index: 0; pointer-events: none; }
.wall-scrim { position: fixed; inset: 0; z-index: 0; pointer-events: none; background: var(--bg0); opacity: .38; }
.shell, .theme { position: relative; z-index: 1; }
.theme { position: fixed; z-index: 2; }
.shell { width: 100%; max-width: 1080px; display: grid; grid-template-columns: minmax(0, 1fr) 400px; column-gap: 64px; align-items: center; }
.shell > .card { grid-column: 2; grid-row: 1; justify-self: end; }
.stage { grid-column: 1; grid-row: 1; display: flex; flex-direction: column; gap: 34px; min-width: 0; }
.strip { display: none; }
.stage-copy h2 { margin: 0 0 12px; max-width: 15ch; font-size: clamp(2rem, 3.8vw, 3.1rem); font-weight: 800; font-stretch: 100%; line-height: 1.04; letter-spacing: -.015em; }
.stage-copy p { margin: 0; max-width: 42ch; color: var(--muted); font-size: 1.02rem; line-height: 1.55; }
.sheet { position: relative; max-width: 470px; padding: 26px 18px 18px; background: var(--bg2); border: 1px solid var(--line); border-radius: 6px; box-shadow: 0 24px 70px var(--shadow); }
.sheet::before { content: ""; position: absolute; inset: -9px; pointer-events: none; --t: var(--line-2); --m: 16px;
    background:
        linear-gradient(var(--t), var(--t)) left top / var(--m) 2px no-repeat, linear-gradient(var(--t), var(--t)) left top / 2px var(--m) no-repeat,
        linear-gradient(var(--t), var(--t)) right top / var(--m) 2px no-repeat, linear-gradient(var(--t), var(--t)) right top / 2px var(--m) no-repeat,
        linear-gradient(var(--t), var(--t)) left bottom / var(--m) 2px no-repeat, linear-gradient(var(--t), var(--t)) left bottom / 2px var(--m) no-repeat,
        linear-gradient(var(--t), var(--t)) right bottom / var(--m) 2px no-repeat, linear-gradient(var(--t), var(--t)) right bottom / 2px var(--m) no-repeat; }
.dim { position: absolute; left: 18px; right: 210px; top: 7px; display: flex; align-items: center; gap: 8px; color: var(--muted); font-size: .68rem; }
.dim i { flex: 1; height: 7px; border-left: 1.5px solid var(--line-2); border-right: 1.5px solid var(--line-2); position: relative; transform-origin: center; }
.dim i::after { content: ""; position: absolute; left: 0; right: 0; top: 50%; height: 1.5px; background: var(--line-2); }
.dim b { font-weight: 600; white-space: nowrap; }
.chip { position: absolute; display: inline-flex; align-items: center; gap: 8px; padding: 6px 12px 6px 7px; border: 1px solid var(--line); border-radius: 99px; background: var(--card); color: var(--text); font-size: .74rem; font-weight: 600; box-shadow: 0 8px 22px var(--shadow); white-space: nowrap; }
.chip.share { right: -14px; top: -20px; animation: bob 5s ease-in-out infinite; }
.avs { display: inline-flex; }
.avs u { width: 22px; height: 22px; margin-left: -6px; display: grid; place-items: center; border: 2px solid var(--card); border-radius: 50%; background: var(--accent); color: var(--accent-ink); font-size: .62rem; font-weight: 800; text-decoration: none; }
.avs u:first-child { margin-left: 0; }
.avs u:nth-child(2) { background: #42d6a4; }
.avs u:nth-child(3) { background: #b58cff; }
.mock { display: flex; flex-direction: column; gap: 2px; }
.mrow { --d: calc(var(--i) * 2s + 1.8s); position: relative; display: grid; grid-template-columns: 30px minmax(0, 1fr) auto; align-items: center; gap: 12px; padding: 9px 12px 9px 18px; border-radius: 3px; border-bottom: 1px solid var(--row-line, var(--line));
    animation: sRow .6s var(--ease) backwards, mpick 8s linear infinite; animation-delay: calc(var(--i) * 110ms + .8s), var(--d); }
.mrow::before { content: ""; position: absolute; left: 4px; top: 9px; bottom: 9px; width: 4px; background: var(--c); opacity: .45; animation: mbar 8s linear infinite; animation-delay: var(--d); }
.mi { width: 28px; height: 36px; color: var(--c); }
.mt { min-width: 0; display: flex; flex-direction: column; }
.mt b { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: .88rem; }
.mt small { color: var(--muted); font-size: .74rem; }
.mrow em { font-style: normal; font-size: .64rem; font-weight: 800; letter-spacing: .04em; color: var(--c); }
:root[data-theme="light"] .mrow em { filter: brightness(.7); }
.mup { margin-top: 12px; padding: 10px 12px; border: 1px solid var(--line); border-radius: 4px; background: var(--card); animation: sRow .6s var(--ease) 1.4s backwards; }
.mup-top { display: flex; justify-content: space-between; gap: 10px; font-size: .8rem; font-weight: 600; }
.mup-st { position: relative; display: inline-grid; font-size: .74rem; font-weight: 600; color: var(--muted); }
.mup-st b { grid-area: 1 / 1; text-align: right; font-weight: 600; animation: stA 5.5s linear infinite; }
.mup-st b.ok { color: var(--ok); animation-name: stB; }
.mup-bar { height: 4px; margin-top: 8px; border-radius: 4px; background: var(--line); overflow: hidden; }
.mup-bar i { display: block; height: 100%; transform-origin: left center; border-radius: 4px; animation: prog 5.5s var(--ease) infinite;
    background-image: repeating-linear-gradient(-45deg, rgba(6,20,35,.22) 0 5px, transparent 5px 10px); background-size: 14.14px 14.14px; }
.bub { position: absolute; right: -22px; max-width: 235px; padding: 8px 12px; border-radius: 4px; font-size: .78rem; line-height: 1.4; box-shadow: 0 10px 26px var(--shadow); }
.bub.u { bottom: 68px; background: var(--accent); color: var(--accent-ink); font-weight: 600; animation: bubU 12s ease-in-out 2.2s infinite backwards; }
.bub.a { bottom: 12px; background: var(--card); border: 1px solid var(--line); animation: bubA 12s ease-in-out 2.2s infinite backwards; }
.points { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 22px; max-width: 520px; }
.points li { display: grid; grid-template-columns: 22px 1fr; column-gap: 8px; row-gap: 3px; align-items: center; animation: riseIn .5s var(--ease) backwards; }
.points li:nth-child(1) { animation-delay: 1.5s; } .points li:nth-child(2) { animation-delay: 1.6s; } .points li:nth-child(3) { animation-delay: 1.7s; }
.points svg { width: 20px; height: 20px; fill: none; stroke: var(--accent-text); stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
.points b { font-size: .92rem; }
.points span { grid-column: 1 / -1; color: var(--muted); font-size: .8rem; line-height: 1.45; }
.stage-copy { animation: riseIn .6s var(--ease) .1s backwards; }
.sheet { animation: cardIn .7s var(--ease) .35s backwards; }
.dim i { animation: drawBar 1s var(--ease) .9s backwards; }
@keyframes drawBar { from { transform: scaleX(0); } to { transform: scaleX(1); } }
@media (max-width: 940px) {
    .shell { grid-template-columns: 1fr; max-width: 400px; }
    .shell > .card { grid-column: 1; justify-self: stretch; }
    .stage { display: none; }
    .strip { display: block; grid-column: 1; margin: 18px 0 0; text-align: center; color: var(--muted); font-size: .8rem; line-height: 1.6; animation: fadeIn .6s ease-out 1s backwards; }
}
@media (max-height: 720px) and (min-width: 941px) { .points { display: none; } .stage { gap: 26px; } }
@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after { animation: none !important; transition: none !important; }
}
</style>
<noscript><style>form[hidden] { display: flex !important; } .tabs { display: none; }</style></noscript>
</head>
<body>

<div class="wall-scrim" aria-hidden="true"></div>
<div class="wall" id="wall" aria-hidden="true"></div>
<button type="button" class="theme" id="themeBtn" aria-label="Switch theme" title="Switch theme"><svg viewBox="0 0 24 24" aria-hidden="true"><path id="themeIcon" d=""/></svg></button>

<svg width="0" height="0" style="position:absolute" aria-hidden="true"><symbol id="lf-doc" viewBox="0 0 44 56"><path d="M6 2h22l12 12v38a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z" fill="currentColor"/></symbol></svg>
<div class="shell">
<main class="card">
    <div class="brand">
        <span class="logo"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg></span>
        <h1>Northstar Secure Storage</h1>
    </div>
    <p class="sub">Secure access to your company files and shared workspace.</p>

    <div class="tabs" role="tablist">
        <button type="button" role="tab" id="tabLogin" aria-selected="<?= $tab === "login" ? "true" : "false" ?>">Sign in</button>
        <button type="button" role="tab" id="tabRegister" aria-selected="<?= $tab === "register" ? "true" : "false" ?>">Create account</button>
    </div>

    <?php if ($error !== ""): ?>
        <p class="error" role="alert"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <form method="post" id="formLogin" <?= $tab === "login" ? "" : "hidden" ?>>
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="login">

        <label>Username or work email
            <input type="text" name="username" autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="50" required
                   value="<?= $tab === "login" ? htmlspecialchars($name) : "" ?>">
        </label>
        <label>Password
            <span class="pw">
                <input type="password" name="password" autocomplete="current-password" maxlength="200" required>
                <button type="button" data-show>Show</button>
            </span>
        </label>
        <button type="submit" class="btn-primary">Sign in</button>
        <p class="hint forgot">Need help? Contact your company administrator.</p>
    </form>

    <form method="post" id="formRegister" <?= $tab === "register" ? "" : "hidden" ?>>
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="register">

        <label>Choose your company username
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
            <summary>Company user agreement</summary>
            <div class="eula-body">
                <h2>Secure access and file handling</h2>
                <ol>
                    <li>Your files remain private unless you choose to share them with your team.</li>
                    <li>This platform enables secure AI review for one selected file at a time after it is opened for preview.</li>
                    <li>When you use the AI tool, the file content and your questions are sent to the approved AI provider configured by your organization.</li>
                    <li>You agree that the content of files selected for AI review and your chat messages may be used to improve AI performance.</li>
                    <li>Only use the AI on files you are authorized to access and that you are comfortable sharing in this secure environment.</li>
                    <li>You may delete your account at any time and choose whether your stored files are removed with it.</li>
                </ol>
            </div>
        </details>
        <label class="agree">
            <input type="checkbox" name="agree" value="1" required>
            <span>I have read the user agreement and accept that my selected files and messages may be processed by our approved AI tools.</span>
        </label>
        <button type="submit" class="btn-primary">Create account</button>
    </form>
</main>

<aside class="stage">
    <div class="stage-copy">
        <h2>Control your files with enterprise-grade security.</h2>
        <p>Store, review, and share business documents from one secure platform built for modern teams.</p>
    </div>

    <div class="sheet" aria-hidden="true">
        <div class="dim"><i></i><b>secured in your workspace</b><i></i></div>
        <div class="chip share"><span class="avs"><u>M</u><u>J</u><u>A</u></span>Shared with 3 team members</div>

        <div class="mock">
            <div class="mrow" style="--i:0;--c:#42d6a4"><svg class="mi" viewBox="0 0 44 56"><use href="#lf-doc"/></svg><div class="mt"><b>quarterly-report.pdf</b><small>4.2 MB · visible to everyone</small></div><em>PUBLIC</em></div>
            <div class="mrow" style="--i:1;--c:#ff4d4d"><svg class="mi" viewBox="0 0 44 56"><use href="#lf-doc"/></svg><div class="mt"><b>client-contract.pdf</b><small>1.1 MB · shared with 2 people</small></div><em>TEAM</em></div>
            <div class="mrow" style="--i:2;--c:#b58cff"><svg class="mi" viewBox="0 0 44 56"><use href="#lf-doc"/></svg><div class="mt"><b>demo-reel.mp3</b><small>7.8 MB · only you</small></div><em>PRIVATE</em></div>
            <div class="mrow" style="--i:3;--c:#f472b6"><svg class="mi" viewBox="0 0 44 56"><use href="#lf-doc"/></svg><div class="mt"><b>brand-guidelines.mp4</b><small>58 MB · shared with Mia</small></div><em>TEAM</em></div>
        </div>

        <div class="mup">
            <div class="mup-top"><span>proposal-final.docx</span><span class="mup-st"><b>uploading…</b><b class="ok">uploaded</b></span></div>
            <div class="mup-bar"><i></i></div>
        </div>

        <div class="bub u">Summarise this contract</div>
        <div class="bub a">A 12-month agreement from 1 March. Renewal terms are reviewed monthly.</div>
    </div>

    <ul class="points">
        <li><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="2.8"/></svg><b>Preview</b><span>Open files instantly in the browser.</span></li>
        <li><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="6" r="2.5"/><circle cx="18" cy="18" r="2.5"/><path d="M8.2 10.8l7.6-3.6M8.2 13.2l7.6 3.6"/></svg><b>Share</b><span>Choose exactly who can access each document.</span></li>
        <li><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v11H9l-5 4z"/><path d="M8 9.5h8M8 12.5h5"/></svg><b>Ask AI</b><span>Get summaries and answers for the files you select.</span></li>
    </ul>
</aside>

    <p class="strip">Secure previews · Team sharing · AI-ready documents</p>
</div>

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

document.getElementById("formRegister").addEventListener("submit", e => {
    const f = e.target;
    if (f.password.value !== f.password2.value) {
        e.preventDefault();
        f.password2.setCustomValidity("The two passwords don't match.");
        f.password2.reportValidity();
    }
});
document.querySelector('#formRegister [name=password2]').addEventListener("input", e => e.target.setCustomValidity(""));

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
<script src="gradientwaves.js"></script>
<script>
try {
    new GridMotion(document.getElementById("wall"), {
        source: "loginwall.php?list=1",
        rows: 4, aspectRatio: 1, gap: 14, radius: 12, angle: -12, speed: 22,
        parallax: 0.5, spotlight: 0.6, dim: 0.6, fade: 0.5
    });
} catch (e) {}
</script>
<script>
(function () {
    document.querySelectorAll("form").forEach(function (f) {
        f.addEventListener("submit", function (e) {
            setTimeout(function () {
                if (e.defaultPrevented) return;
                var b = f.querySelector(".btn-primary");
                if (b) b.classList.add("busy");
            }, 0);
        });
    });
    if (matchMedia("(prefers-reduced-motion: reduce)").matches) return;
    document.addEventListener("pointerdown", function (e) {
        var b = e.target.closest && e.target.closest(".btn-primary");
        if (!b) return;
        var r = b.getBoundingClientRect(), d = Math.max(r.width, r.height) * 2.2;
        var s = document.createElement("span");
        s.className = "ripple";
        s.style.cssText = "width:" + d + "px;height:" + d + "px;left:" + (e.clientX - r.left - d / 2) + "px;top:" + (e.clientY - r.top - d / 2) + "px";
        b.appendChild(s);
        setTimeout(function () { s.remove(); }, 650);
    }, { passive: true });
})();
</script>
</body>
</html>
