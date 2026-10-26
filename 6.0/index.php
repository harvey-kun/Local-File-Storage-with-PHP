<?php
require "config.php";
require "filetypes.php";
require "auth.php";

// Signed-in users only (the session is writable here so the one-time upload message can be removed)
$user = requireLogin($conn, "page", true);

// One-time message after a classic (non-JavaScript) upload
$flash = $_SESSION["flash"] ?? null;
unset($_SESSION["flash"]);
$csrf = csrfToken();
session_write_close();

// Accounts that can be picked in the share menu (everybody except me; the admin sees every file anyway)
$others = array_values(array_filter(shareableUsers($conn), fn($u) => $u["id"] !== $user["id"]));
$me     = ["id" => $user["id"], "name" => $user["username"], "admin" => $user["is_admin"], "ai" => !empty($user["agreed_ai_at"]), "av" => avatarVersion((int)$user["id"])];

// Files this person may see (records of files that vanished from disk are cleaned up inside loadFiles)
$files   = loadFiles($conn, $user);
$folders = loadFolders($conn, $user, $files);
$version = filesVersion($files, $folders);

// Server limits (a single file can't exceed the smaller of the two)
$limits  = array_filter([iniBytes(ini_get("upload_max_filesize")), iniBytes(ini_get("post_max_size"))]);
$maxFile = $limits ? min($limits) : 0;

$typeColors = [];
foreach (ftTypes() as $key => $def) $typeColors[$key] = $def[0];

// Safe to print inside <script> (file names and notes are user text)
$J = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#061423">
<title>Local File Storage</title>
<script>
/* Use the saved light/dark choice before the page paints (no flash). Without one the device setting decides. */
try { var t = localStorage.getItem("fs-theme"); if (t === "light" || t === "dark") document.documentElement.dataset.theme = t; } catch (e) {}
/* Left panel: remember whether it was folded into the icon rail (desktop only, no flash) */
document.documentElement.classList.add("side-c");   // desktop: the left panel starts as a slim rail and opens over the list
/* page-load animations only run while this class is on (the first 2.4 seconds), so live refreshes never replay them */
document.documentElement.classList.add("intro");
setTimeout(function () { document.documentElement.classList.remove("intro"); }, 2400);
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
* { box-sizing: border-box; -webkit-tap-highlight-color: transparent; scrollbar-width: thin; scrollbar-color: var(--line-2) transparent; }
html, body { height: 100%; }
html { -webkit-text-size-adjust: 100%; }
body {
    margin: 0;
    font-family: "Archivo", "Segoe UI", system-ui, -apple-system, Roboto, Helvetica, Arial, sans-serif;
    background: var(--bg1);
    color: var(--text);
    line-height: 1.45;
    overflow: hidden;
}
button, input, select, textarea { font: inherit; color: inherit; }
:focus-visible { outline: 2px solid var(--focus); outline-offset: 2px; }
.hidden { display: none !important; }
.i { width: 18px; height: 18px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; flex: none; }
.i-lg { width: 24px; height: 24px; }

/* ---------- Buttons ---------- */
.btn {
    display: inline-flex; align-items: center; justify-content: center; gap: 7px;
    min-height: 40px; padding: 8px 14px;
    font-size: .9rem; font-weight: 600; text-decoration: none;
    background: var(--card); border: 1px solid var(--line); border-radius: 4px; color: var(--text);
    cursor: pointer; transition: background .15s, border-color .15s;
}
.btn:hover { background: var(--card-2); }
.btn:disabled { opacity: .5; cursor: not-allowed; }
.btn-primary { background: var(--accent); border-color: var(--accent); color: var(--accent-ink); }
.btn-primary:hover { background: var(--accent-2); border-color: var(--accent-2); }
.btn-danger { color: var(--danger); }
.btn-danger:hover { background: rgba(255,107,120,.12); border-color: var(--danger); }
.btn-primary.danger { background: var(--danger); border-color: var(--danger); color: var(--on-danger); }
.btn-sm { min-height: 34px; padding: 5px 11px; font-size: .84rem; }
.icon-btn {
    display: inline-flex; align-items: center; justify-content: center;
    width: 34px; height: 34px; padding: 0; flex: none;
    background: transparent; border: 0; border-radius: 8px;
    color: var(--muted); cursor: pointer; text-decoration: none;
    transition: background .15s, color .15s;
}
.icon-btn:hover { background: var(--card-2); color: var(--text); }
.icon-btn.danger:hover { background: rgba(255,107,120,.14); color: var(--danger); }

/* ---------- Layout ---------- */
.app { display: grid; grid-template-columns: 270px minmax(0, 1fr) 360px; grid-template-rows: minmax(0, 1fr); height: 100vh; height: 100dvh; }

.side {
    background: var(--bg0); border-right: 1px solid var(--line);
    display: flex; flex-direction: column; gap: 22px;
    padding: 18px 16px calc(18px + env(safe-area-inset-bottom)); overflow-y: auto;
}
.brand { display: flex; align-items: center; gap: 10px; }
.brand .logo { width: 34px; height: 34px; border-radius: 4px; background: var(--accent); color: var(--accent-ink); display: grid; place-items: center; }
.brand h1 { margin: 0; font-size: 1.05rem; letter-spacing: -.01em; line-height: 1.2; }
.live { margin-left: auto; display: inline-flex; align-items: center; gap: 6px; font-size: .76rem; color: var(--muted); }
.live i { width: 8px; height: 8px; border-radius: 50%; background: var(--ok); box-shadow: 0 0 0 3px rgba(66,214,164,.16); }
.live.off i { background: var(--warn); box-shadow: 0 0 0 3px rgba(255,196,77,.16); }
.side-close { display: none; }

.side h2 { margin: 0 0 8px; font-size: .82rem; font-weight: 600; color: var(--muted); }

/* Upload */
.dropzone {
    display: flex; flex-direction: column; align-items: center; gap: 4px; text-align: center;
    padding: 20px 12px; border: 1.5px dashed var(--line); border-radius: 4px; background: var(--bg1);
    cursor: pointer; transition: border-color .15s, background .15s;
}
.dropzone:hover { --t: var(--focus); }
.dropzone .i { width: 28px; height: 28px; color: var(--accent-text); }
.dropzone strong { font-size: .95rem; }
.dropzone span, .dropzone small { color: var(--muted); font-size: .8rem; }
.dropzone input { display: none; }
.upload .btn-primary { width: 100%; margin-top: 10px; }
.queue { margin-top: 10px; display: flex; flex-direction: column; gap: 6px; max-height: 260px; overflow-y: auto; }
.qitem { display: flex; align-items: center; gap: 10px; padding: 8px 10px; background: var(--card); border: 1px solid var(--line); border-radius: 4px; }
.qitem .ficon { width: 24px; height: 31px; flex: 0 0 24px; }
.qinfo { flex: 1; min-width: 0; }
.qname { font-size: .85rem; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.qmeta { font-size: .74rem; color: var(--muted); }
.qitem.done .qmeta { color: var(--ok); }
.qitem.error .qmeta { color: var(--danger); }
.bar { height: 3px; background: var(--line); border-radius: 3px; margin-top: 5px; overflow: hidden; display: none; }
.qitem.uploading .bar, .qitem.done .bar { display: block; }
.bar i { display: block; height: 100%; width: 0; background: var(--accent); transition: width .1s linear; }
.qitem.done .bar i { background: var(--ok); width: 100% !important; }
.qx { width: 28px; height: 28px; }
.qbar { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-top: 8px; font-size: .8rem; color: var(--muted); }

/* Library nav + storage */
.groups { display: flex; flex-direction: column; gap: 2px; }
.g {
    display: flex; align-items: center; gap: 10px; width: 100%;
    padding: 8px 10px; border: 0; border-radius: 4px; background: transparent;
    color: var(--muted); text-align: left; cursor: pointer; font-size: .9rem;
    transition: background .15s, color .15s;
}
.g i { width: 9px; height: 9px; border-radius: 3px; flex: none; }
.g span { flex: 1; }
.g b { font-weight: 500; font-size: .8rem; }
.g:hover { background: var(--card); color: var(--text); }
.g.on { background: var(--card); color: var(--text); }
.g.on b { color: var(--accent-text); }
.usage .stack { display: flex; height: 8px; border-radius: 6px; overflow: hidden; background: var(--card); gap: 2px; }
.usage .stack i { display: block; height: 100%; min-width: 3px; }
.usage p { margin: 8px 0 0; font-size: .8rem; color: var(--muted); }

/* ---------- List panel ---------- */
.list-panel { background: var(--bg1); display: flex; flex-direction: column; min-width: 0; min-height: 0; }
.topbar { display: flex; align-items: center; gap: 10px; padding: 14px 16px 10px; }
.menu-btn { display: none; }
.search { position: relative; flex: 1; min-width: 0; }
.search .i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--muted); pointer-events: none; }
.search input {
    width: 100%; height: 42px; padding: 0 40px 0 38px;
    background: var(--card); border: 1px solid var(--line); border-radius: 4px; outline: none;
}
.search input:focus { border-color: var(--focus); }
.search input::-webkit-search-cancel-button { -webkit-appearance: none; display: none; }
.search input { -webkit-appearance: none; appearance: none; }
.search input::placeholder { color: var(--muted); }
.search .icon-btn { position: absolute; right: 4px; top: 4px; }
.sort { height: 42px; padding: 0 10px; background: var(--card); border: 1px solid var(--line); border-radius: 4px; cursor: pointer; }
.sort option { background: var(--card); }

.chips { display: none; gap: 8px; padding: 0 16px 8px; overflow-x: auto; scrollbar-width: none; }
.chips::-webkit-scrollbar { display: none; }
.chips .g { width: auto; flex: none; padding: 6px 12px; border: 1px solid var(--line); border-radius: 999px; gap: 7px; }
.chips .g.on { border-color: var(--focus); }

.resultbar { display: flex; align-items: center; gap: 8px; padding: 6px 16px 10px; border-bottom: 1px solid var(--line); }
.resultbar .sum { flex: 1; min-width: 0; font-size: .85rem; color: var(--muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

.rows { flex: 1; overflow-y: auto; padding: 8px 10px calc(24px + env(safe-area-inset-bottom)); }
.row {
    position: relative; display: flex; align-items: center; gap: 12px;
    padding: 10px 10px 10px 16px; border-radius: 4px; cursor: pointer;
    border-bottom: 1px solid var(--row-line);
}
.row::before { content: ""; position: absolute; left: 4px; top: 10px; bottom: 10px; width: 3px; border-radius: 3px; background: var(--c); opacity: .45; }
.row:hover { background: var(--hover); }
.row.sel { background: var(--card); }
.row.sel::before { opacity: 1; }
.row .ficon { width: 32px; height: 41px; flex: 0 0 32px; }
.rinfo { flex: 1; min-width: 0; }
.rname { display: flex; align-items: center; gap: 2px; min-width: 0; }
.rtext { font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; }
.rname .icon-btn { width: 28px; height: 28px; opacity: .55; }
.rname .icon-btn .i { width: 15px; height: 15px; }
.row:hover .rname .icon-btn, .rname .icon-btn:focus-visible { opacity: 1; }
.rmeta { color: var(--muted); font-size: .8rem; }
.rnote { color: var(--muted); font-size: .8rem; margin-top: 2px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-style: italic; }
.ractions { display: flex; gap: 2px; flex: none; }
.row.fresh { animation: fresh 2.4s ease-out; }
@keyframes fresh { from { background: var(--tint-2); } to { background: transparent; } }

/* ---------- Select mode (pick files to print) ---------- */
.selbar { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; padding: 8px 16px; background: var(--tint); border-bottom: 1px solid var(--line); }
.selbar b { flex: 1; min-width: 110px; font-size: .9rem; }
.pk { display: none; place-items: center; flex: 0 0 22px; width: 22px; height: 22px; border: 1.5px solid var(--line-2); border-radius: 7px; color: transparent; transition: background .12s, border-color .12s, color .12s; }
.pk .i { width: 14px; height: 14px; stroke-width: 2.8; }
.pickmode .pk { display: grid; }
.pickmode .row { cursor: pointer; user-select: none; }
.pickmode .ractions, .pickmode .rname .icon-btn { display: none; }
.pickmode .row.sel:not(.picked) { background: transparent; }
.row.picked { background: var(--tint); }
.row.picked .pk { background: var(--accent); border-color: var(--accent); color: var(--accent-ink); }
.row.picked::before { opacity: 1; }

.empty { text-align: center; color: var(--muted); padding: 56px 20px; }
.empty .i { width: 40px; height: 40px; opacity: .6; margin-bottom: 8px; }
.empty p { margin: 0 0 12px; }

/* ---------- Detail panel ---------- */
.detail { background: var(--bg2); border-left: 1px solid var(--line); overflow-y: auto; display: flex; flex-direction: column; }
.d-top { display: none; align-items: center; gap: 6px; padding: calc(8px + env(safe-area-inset-top)) 10px 8px; border-bottom: 1px solid var(--line); font-weight: 600; position: sticky; top: 0; background: var(--bg2); z-index: 2; }
.d-body { padding: 18px; display: flex; flex-direction: column; gap: 16px; padding-bottom: calc(24px + env(safe-area-inset-bottom)); }
.d-empty { margin: auto; padding: 30px; text-align: center; color: var(--muted); max-width: 260px; }
.d-empty .i { width: 40px; height: 40px; opacity: .5; margin-bottom: 8px; }
.d-stage {
    position: relative; height: 230px; border-radius: 4px; background: var(--bg0);
    border: 1px solid var(--line); display: grid; place-items: center; overflow: hidden;
}
.d-stage.can { cursor: pointer; }
.d-stage .ficon { width: 78px; height: 100px; }
.d-stage img { max-width: 100%; max-height: 100%; object-fit: contain; display: block; }
.d-stage .badge { position: absolute; right: 12px; bottom: 12px; width: 44px; height: 44px; border-radius: 8px; background: var(--accent); color: var(--accent-ink); display: grid; place-items: center; box-shadow: 0 4px 14px rgba(0,0,0,.45); transition: transform .15s; }
.d-stage.can:hover .badge { transform: scale(1.08); }
.d-name { display: flex; align-items: flex-start; gap: 4px; }
.d-name h2 { margin: 0; flex: 1; min-width: 0; font-size: 1.1rem; line-height: 1.3; word-break: break-word; }
.d-info { margin: 0; display: grid; grid-template-columns: auto 1fr; gap: 6px 16px; font-size: .88rem; }
.d-info dt { color: var(--muted); }
.d-info dd { margin: 0; text-align: right; }
.d-notes label { display: block; margin-bottom: 6px; font-weight: 600; font-size: .9rem; }
.d-notes textarea {
    width: 100%; min-height: 120px; resize: vertical; padding: 10px 12px;
    background: var(--bg0); border: 1px solid var(--line); border-radius: 4px; outline: none; line-height: 1.5;
}
.d-notes textarea:focus { border-color: var(--focus); }
.d-nstat { display: flex; justify-content: space-between; margin-top: 4px; font-size: .78rem; color: var(--muted); min-height: 1.2em; }
.d-nstat .err { color: var(--danger); }
.d-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.d-actions .wide { grid-column: 1 / -1; }

/* ---------- Dialog, toast, drop overlay ---------- */
.dlg-wrap { position: fixed; inset: 0; z-index: 300; display: none; align-items: center; justify-content: center; padding: 20px; background: var(--scrim); backdrop-filter: blur(3px); }
.dlg-wrap.open { display: flex; }
.dlg { width: 100%; max-width: 420px; background: var(--bg2); border: 1px solid var(--line); border-radius: 8px; padding: 20px; box-shadow: 0 20px 60px var(--shadow); }
.dlg h3 { margin: 0 0 6px; font-size: 1.05rem; }
.dlg p { margin: 0 0 12px; color: var(--muted); font-size: .9rem; word-break: break-word; }
.dlg input { width: 100%; height: 42px; padding: 0 12px; margin: 6px 0 4px; background: var(--bg0); border: 1px solid var(--line); border-radius: 4px; outline: none; }
.dlg input:focus { border-color: var(--focus); }
.dlg .dlg-err { color: var(--danger); font-size: .84rem; min-height: 1.2em; margin: 4px 0 8px; }
.dlg-btns { display: flex; justify-content: flex-end; gap: 8px; margin-top: 8px; }

.toast { position: fixed; left: 50%; bottom: calc(22px + env(safe-area-inset-bottom)); transform: translate(-50%, 20px); z-index: 400; max-width: calc(100vw - 32px); padding: 10px 16px; background: var(--card-2); border: 1px solid var(--line); border-radius: 4px; font-size: .88rem; box-shadow: 0 10px 30px var(--shadow); opacity: 0; pointer-events: none; transition: opacity .2s, transform .2s; }
.toast.on { opacity: 1; transform: translate(-50%, 0); }

.drop-overlay { position: fixed; inset: 0; z-index: 250; display: none; align-items: center; justify-content: center; background: rgba(11,13,17,.82); border: 3px dashed var(--accent); font-size: 1.2rem; font-weight: 600; pointer-events: none; }
.drop-overlay.on { display: flex; }
.scrim { position: fixed; inset: 0; z-index: 55; background: rgba(5,7,10,.6); display: none; }

/* ---------- Preview modal ---------- */
.modal { position: fixed; inset: 0; z-index: 200; display: none; align-items: center; justify-content: center; padding: 20px; background: rgba(5,7,10,.82); backdrop-filter: blur(4px); }
.modal.open { display: flex; }
.pv-box { width: 100%; max-width: 1040px; height: 100%; max-height: 90vh; max-height: 90dvh; display: flex; flex-direction: column; background: var(--bg2); border: 1px solid var(--line); border-radius: 8px; overflow: hidden; }
.pv-box.is-audio { max-width: 460px; height: auto; max-height: 94dvh; border: 0; background: #12141b; }   /* the player is always dark: no light outline or backing around it, in either theme */
.pv-box.is-audio .pv-body { background: #12141b; }
.pv-box.is-audio .pv-bar { display: none; }      /* the audio player has no title bar, only a small close button */
.au-x { position: absolute; top: 10px; right: 10px; z-index: 3; width: 36px; height: 36px; display: grid; place-items: center; border: 0; border-radius: 50%; background: rgba(0,0,0,.35); color: #fff; cursor: pointer; transition: background .15s; }
.au-x:hover { background: rgba(0,0,0,.6); }
.pv-bar { display: flex; align-items: center; gap: 4px; padding: 6px 8px 6px 16px; border-bottom: 1px solid var(--line); }
.pv-title { flex: 1; min-width: 0; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pv-body { flex: 1; min-height: 0; display: flex; align-items: center; justify-content: center; background: var(--viewer); position: relative; }
.pv-body iframe { width: 100%; height: 100%; border: 0; background: #fff; }
.pv-img { max-width: 100%; max-height: 100%; object-fit: contain; }
/* Zoom (images: pinch / wheel / drag; text: bigger or smaller letters) */
.zv { position: absolute; inset: 0; overflow: hidden; touch-action: none; user-select: none; -webkit-user-select: none; }
.zv.zoomed { cursor: grab; }
.zv.zoomed:active { cursor: grabbing; }
.zv-img { position: absolute; left: 0; top: 0; max-width: none; max-height: none; transform-origin: 0 0; will-change: transform; -webkit-user-drag: none; }
.pv-text:not(.dim) { font-size: var(--tz, 13px); }
.pv-zoom { position: absolute; left: 50%; bottom: 14px; transform: translateX(-50%); z-index: 5; display: flex; align-items: center; gap: 2px; padding: 4px;
           background: var(--glass); border: 1px solid var(--line); border-radius: 999px; backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px); box-shadow: 0 6px 20px rgba(0,0,0,.45); }
.pv-zoom button { min-width: 40px; height: 40px; padding: 0 10px; border: 0; border-radius: 999px; background: transparent; color: var(--text); font: inherit; font-size: 1.15rem; line-height: 1; cursor: pointer; }
.pv-zoom button:hover { background: var(--hover); }
.pv-zoom button.pct { min-width: 62px; font-size: .8rem; color: var(--muted); font-variant-numeric: tabular-nums; }
@property --bg0 { syntax: "<color>"; inherits: true; initial-value: #000; }
@property --bg1 { syntax: "<color>"; inherits: true; initial-value: #000; }
@property --bg2 { syntax: "<color>"; inherits: true; initial-value: #000; }
@property --card { syntax: "<color>"; inherits: true; initial-value: #000; }
@property --card-2 { syntax: "<color>"; inherits: true; initial-value: #000; }
@property --line { syntax: "<color>"; inherits: true; initial-value: #000; }
@property --line-2 { syntax: "<color>"; inherits: true; initial-value: #000; }
@property --muted { syntax: "<color>"; inherits: true; initial-value: #000; }
@property --accent { syntax: "<color>"; inherits: true; initial-value: #000; }
@property --accent-2 { syntax: "<color>"; inherits: true; initial-value: #000; }
@property --accent-ink { syntax: "<color>"; inherits: true; initial-value: #000; }
@property --accent-text { syntax: "<color>"; inherits: true; initial-value: #000; }
@property --focus { syntax: "<color>"; inherits: true; initial-value: #000; }
@property --edge { syntax: "<color>"; inherits: true; initial-value: #000; }
@property --stamp { syntax: "<color>"; inherits: true; initial-value: #000; }
@property --tint { syntax: "<color>"; inherits: true; initial-value: #000; }
@property --tint-2 { syntax: "<color>"; inherits: true; initial-value: #000; }
@property --glass { syntax: "<color>"; inherits: true; initial-value: #000; }
:root.th-anim { transition: --bg0 1.8s ease, --bg1 1.8s ease, --bg2 1.8s ease, --card 1.8s ease, --card-2 1.8s ease, --line 1.8s ease, --line-2 1.8s ease, --muted 1.8s ease, --accent 1.8s ease, --accent-2 1.8s ease, --accent-ink 1.8s ease, --accent-text 1.8s ease, --focus 1.8s ease, --edge 1.8s ease, --stamp 1.8s ease, --tint 1.8s ease, --tint-2 1.8s ease, --glass 1.8s ease; }
/* Mini player (music keeps playing while the big player is minimized) */
.au-min { right: 54px; }
.mini { position: fixed; left: 50%; bottom: calc(14px + env(safe-area-inset-bottom)); z-index: 150; width: min(440px, calc(100vw - 24px));
        display: flex; align-items: center; gap: 8px; padding: 10px 8px 8px 10px; background: var(--card); border: 1px solid var(--line); border-radius: 8px;
        box-shadow: 0 12px 34px var(--shadow); transform: translate(-50%, 160%); opacity: 0; visibility: hidden;
        transition: transform .38s cubic-bezier(.2,.8,.2,1), opacity .25s, visibility 0s .38s; }
.mini.on { transform: translate(-50%, 0); opacity: 1; visibility: visible; transition: transform .38s cubic-bezier(.2,.8,.2,1), opacity .25s; }
.mini { overflow: hidden; }
.mini-viz { position: absolute; inset: 0; width: 100%; height: 100%; z-index: 0; pointer-events: none; }
.mini > :not(.mini-viz):not(.mini-bar) { position: relative; z-index: 1; }
.mini-bar { position: absolute; z-index: 2; left: 0; right: 0; top: 0; height: 3px; background: var(--line); border-radius: 8px 8px 0 0; overflow: hidden; }
.mini-bar i { display: block; height: 100%; width: var(--p, 0%); background: var(--accent); }
.mini-art { flex: none; width: 44px; height: 44px; padding: 0; border: 1px solid var(--line); border-radius: 6px; background: var(--tint-2) center / cover no-repeat; cursor: pointer; }
.mini-txt { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 1px; padding: 0; border: 0; background: none; text-align: left; cursor: pointer; color: var(--text); }
.mini-txt b { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: .9rem; }
.mini-txt small { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--muted); font-size: .76rem; }
.mini-play { flex: none; width: 40px; height: 40px; display: grid; place-items: center; padding: 0; border: 0; border-radius: 50%; background: var(--accent); color: var(--accent-ink); cursor: pointer; }
.mini-play .i { width: 20px; height: 20px; }
body.has-mini .list-panel { padding-bottom: 84px; }
@media (prefers-reduced-motion: reduce) { .mini, .mini.on { transition: none; } :root.th-anim { transition: none; } }
.pv-msg { color: var(--muted); padding: 24px; text-align: center; }

/* Text / code viewer: dark like the rest of the page (a plain iframe would be white with white text) */
.pv-text { align-self: stretch; width: 100%; height: 100%; margin: 0; padding: 18px 22px 24px; overflow: auto; background: var(--code-bg); color: var(--code-text);
           font: 13px/1.65 ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace; tab-size: 4;
           white-space: pre-wrap; overflow-wrap: anywhere; user-select: text; }
.pv-text.dim { color: var(--muted); font-family: inherit; font-size: .95rem; white-space: normal; text-align: center; padding-top: 48px; }
.pv-text .more { display: block; margin-top: 16px; padding-top: 12px; border-top: 1px solid var(--line); color: var(--warn); font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; white-space: normal; }

/* Player controls (shared) */
.pbtn { display: inline-flex; align-items: center; justify-content: center; min-width: 40px; height: 40px; padding: 0 6px; border: 0; border-radius: 50%; background: transparent; color: #fff; cursor: pointer; transition: background .15s, transform .1s; }
.pbtn:hover { background: rgba(255,255,255,.14); }
.pbtn:active { transform: scale(.94); }
.pbtn.txt { border-radius: 8px; font-size: .82rem; font-weight: 700; min-width: 46px; }
.pbtn.big { width: 66px; height: 66px; background: #fff; color: #10131a; }
.pbtn.big:hover { background: #fff; transform: scale(1.06); }
.pbtn.big .i { width: 28px; height: 28px; }
.i-pause { display: none; }
.playing .i-play { display: none; }
.playing .i-pause { display: block; }
.i-vol-off { display: none; }
.muted .i-vol-on { display: none; }
.muted .i-vol-off { display: block; }
.t { font-size: .78rem; font-variant-numeric: tabular-nums; color: rgba(255,255,255,.75); min-width: 38px; }

.rng { -webkit-appearance: none; appearance: none; width: 100%; height: 20px; margin: 0; background: transparent; cursor: pointer; --p: 0%; }
.rng::-webkit-slider-runnable-track { height: 5px; border-radius: 5px; background: linear-gradient(to right, #fff var(--p), rgba(255,255,255,.22) var(--p)); }
.rng::-moz-range-track { height: 5px; border-radius: 5px; background: rgba(255,255,255,.22); }
.rng::-moz-range-progress { height: 5px; border-radius: 5px; background: #fff; }
.rng::-webkit-slider-thumb { -webkit-appearance: none; width: 14px; height: 14px; margin-top: -4.5px; border-radius: 50%; background: #fff; box-shadow: 0 1px 5px rgba(0,0,0,.5); }
.rng::-moz-range-thumb { width: 14px; height: 14px; border: 0; border-radius: 50%; background: #fff; }
.rng.vol { width: 80px; }

/* Audio player */
.au { position: relative; width: 100%; overflow: hidden; background: #12141b; }
.au-bg { position: absolute; inset: -40px; background: radial-gradient(circle at 30% 10%, rgba(181,140,255,.4), transparent 60%), radial-gradient(circle at 80% 90%, rgba(123,150,255,.25), transparent 55%); background-size: cover; background-position: center; }
.au-bg.cover { filter: blur(44px) saturate(1.4) brightness(.5); transform: scale(1.15); }
.au-card { position: relative; display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 26px 24px 22px; text-align: center; color: #fff; }
.au-art { width: min(68vw, 290px); aspect-ratio: 1; border-radius: 8px; display: grid; place-items: center; overflow: hidden; background: rgba(0,0,0,.28); box-shadow: 0 18px 44px rgba(0,0,0,.5); transform: scale(.93); transition: transform .35s ease; margin-bottom: 14px; }
.playing .au-art { transform: scale(1); }
.au-art img { width: 100%; height: 100%; object-fit: cover; display: block; }
.au-art .ficon { width: 96px; height: 123px; }
.au-title { font-size: 1.2rem; font-weight: 700; line-height: 1.25; max-width: 100%; word-break: break-word; }
.au-artist { font-size: .95rem; color: rgba(255,255,255,.85); }
.au-album { font-size: .84rem; color: rgba(255,255,255,.6); }
.au-seek { display: flex; align-items: center; gap: 10px; width: 100%; margin-top: 16px; }
.au-ctl { display: flex; align-items: center; justify-content: center; gap: 6px; margin-top: 8px; width: 100%; }
.au-ctl .vol-g { display: flex; align-items: center; }
.au-err { color: #ffb4bb; font-size: .85rem; margin-top: 10px; }

/* Video player */
.vd { position: relative; width: 100%; height: 100%; background: #000; display: flex; align-items: center; justify-content: center; user-select: none; }
.vd video { width: 100%; height: 100%; object-fit: contain; background: #000; }
.vd-big { position: absolute; left: 50%; top: 50%; transform: translate(-50%, -50%); width: 78px; height: 78px; border: 0; border-radius: 50%; background: rgba(255,255,255,.92); color: #10131a; display: grid; place-items: center; cursor: pointer; box-shadow: 0 8px 30px rgba(0,0,0,.5); transition: transform .15s; }
.vd-big:hover { transform: translate(-50%, -50%) scale(1.07); }
.vd-big .i { width: 34px; height: 34px; }
.vd.playing .vd-big { display: none; }
.vd-ctl { position: absolute; left: 0; right: 0; bottom: 0; padding: 30px 14px 10px; background: linear-gradient(to top, rgba(0,0,0,.78), transparent); opacity: 0; transition: opacity .2s; }
.vd.show .vd-ctl, .vd:not(.playing) .vd-ctl { opacity: 1; }
.vd.playing:not(.show) { cursor: none; }
.vd-row { display: flex; align-items: center; gap: 2px; margin-top: 2px; }
.vd-row .sp { flex: 1; }
.vd-row .vol-g { display: flex; align-items: center; }
.vd-err { pointer-events: none; position: absolute; inset: 0; display: grid; place-items: center; color: #ffb4bb; padding: 20px; text-align: center; background: rgba(0,0,0,.6); }

/* ---------- Account, sharing, download log ---------- */
.acct { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 10px; padding: 10px; background: var(--card); border: 1px solid var(--line); border-radius: 4px; }
.acct .av { flex: none; width: 34px; height: 34px; border-radius: 6px; background: var(--accent); color: var(--accent-ink); display: grid; place-items: center; font-weight: 700; text-transform: uppercase; }
.acct .who { flex: 1 1 calc(100% - 50px); min-width: 0; display: flex; flex-direction: column; line-height: 1.25; }
.acct .who b { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: .92rem; }
.acct .who small { color: var(--muted); font-size: .74rem; white-space: nowrap; }
/* the buttons sit on their own row under the name, so the name never gets squeezed */
.acct > .icon-btn, .acct > form { flex: 1 1 0; min-width: 0; }
.acct > form .icon-btn { width: 100%; }
.acct > .icon-btn { width: auto; }
.acct form { margin: 0; display: inline-flex; }

.share-up { margin-top: 12px; }
.share-up > label { display: block; margin-bottom: 6px; font-size: .8rem; font-weight: 600; color: var(--muted); }
.vis-sel { width: 100%; height: 40px; padding: 0 10px; background: var(--card); border: 1px solid var(--line); border-radius: 4px; cursor: pointer; }
.vis-sel option { background: var(--card); }
.plist { margin-top: 8px; max-height: 190px; overflow-y: auto; background: var(--bg0); border: 1px solid var(--line); border-radius: 4px; padding: 6px; display: flex; flex-direction: column; gap: 2px; }
.prow { display: flex; align-items: center; gap: 12px; padding: 9px 12px; border-radius: 8px; cursor: pointer; font-size: .92rem; font-weight: 500; color: var(--text); line-height: 1.3; }
.prow span { flex: 1; min-width: 0; overflow-wrap: anywhere; }
.prow:hover { background: var(--card); }
.prow input, .dlg .prow input, .dlg .opt input { flex: none; width: 18px; height: 18px; min-width: 18px; margin: 0; padding: 0; accent-color: var(--accent-text); cursor: pointer; }
.pk-empty { margin: 0; padding: 10px; color: var(--muted); font-size: .82rem; }
.hint-s { margin: 6px 0 0; font-size: .78rem; color: var(--warn); }
.opt { display: flex; align-items: center; gap: 12px; padding: 10px 12px; margin-bottom: 6px; border: 1px solid var(--line); border-radius: 4px; cursor: pointer; font-size: .92rem; }
.opt:hover { background: var(--card); }
.vbadge { display: inline-flex; flex: none; margin-left: 6px; color: var(--muted); }
.vbadge .i { width: 14px; height: 14px; }
.d-sec h3 { margin: 0 0 8px; font-size: .9rem; }
.dl-list { display: flex; flex-direction: column; gap: 3px; max-height: 220px; overflow-y: auto; }
.dl-row { display: flex; justify-content: space-between; gap: 10px; padding: 7px 10px; background: var(--bg0); border-radius: 8px; font-size: .84rem; }
.dl-row span:first-child { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.dl-row span:last-child { color: var(--muted); white-space: nowrap; }
.dl-empty { color: var(--muted); font-size: .84rem; }
.dlg-scroll { max-height: calc(100vh - 40px); max-height: calc(100dvh - 40px); overflow-y: auto; }
.dlg h4 { margin: 14px 0 6px; font-size: .92rem; }
.dlg form + form { margin-top: 18px; padding-top: 6px; border-top: 1px solid var(--line); }
.dlg label.fl { display: block; margin-top: 8px; font-size: .8rem; font-weight: 600; color: var(--muted); }
textarea[readonly] { opacity: .85; cursor: default; }

/* ---------- Admin panel, delete account ---------- */
.dlg .danger-zone { margin-top: 18px; padding-top: 6px; border-top: 1px solid var(--line); }
.dlg .danger-zone p { margin: 0 0 8px; }
.adm-row { display: flex; align-items: center; gap: 10px; padding: 10px 12px; margin-bottom: 6px; border: 1px solid var(--line); border-radius: 4px; background: var(--card); }
.adm-row .nm { flex: 1; min-width: 0; display: flex; flex-direction: column; line-height: 1.3; }
.adm-row .nm b { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: .92rem; }
.adm-row .nm small { color: var(--muted); font-size: .78rem; }
.adm-row .tag { flex: none; padding: 2px 8px; border-radius: 99px; background: var(--bg0); color: var(--muted); font-size: .72rem; font-weight: 600; }
#logTools { display: flex; justify-content: flex-end; margin: 0 0 10px; }
#logTools.hidden { display: none; }
.adm-empty { color: var(--muted); font-size: .88rem; padding: 8px 2px; }

/* ---------- AI assistant (inside the preview) ---------- */
.ai-fab { position: fixed; right: 20px; bottom: calc(20px + env(safe-area-inset-bottom)); z-index: 215; width: 54px; height: 54px; display: none; place-items: center; border: 0; border-radius: 10px; background: var(--accent); color: var(--accent-ink); cursor: pointer; box-shadow: 0 8px 26px rgba(0,0,0,.5); transition: transform .15s, background .15s; }
.ai-fab:hover { background: var(--accent-2, var(--accent)); transform: scale(1.06); }
.ai-fab.on { display: grid; }
.ai-fab svg, .ai-send svg { width: 24px; height: 24px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
.ai-panel { position: fixed; right: 20px; bottom: calc(86px + env(safe-area-inset-bottom)); z-index: 215; width: 370px; max-width: calc(100vw - 24px); height: 520px; max-height: calc(100dvh - 120px); display: none; flex-direction: column; background: var(--bg2); border: 1px solid var(--line); border-radius: 8px; box-shadow: 0 20px 60px var(--shadow); overflow: hidden; }
.ai-panel.open { display: flex; }
.ai-head { display: flex; align-items: center; gap: 8px; padding: 8px 8px 8px 14px; border-bottom: 1px solid var(--line); }
.ai-head b { font-size: .95rem; }
.ai-head span { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--muted); font-size: .78rem; }
.ai-msgs { flex: 1; min-height: 0; overflow-y: auto; padding: 12px; display: flex; flex-direction: column; gap: 8px; }
.ai-m { max-width: 88%; padding: 8px 12px; border-radius: 4px; font-size: .9rem; line-height: 1.45; overflow-wrap: anywhere; user-select: text; }
.ai-m.user { align-self: flex-end; background: var(--accent); color: var(--accent-ink); border-bottom-right-radius: 4px; }
.ai-m.ai { align-self: flex-start; background: var(--card); border-bottom-left-radius: 4px; }
.ai-m.ai code { padding: 1px 5px; border-radius: 5px; background: var(--bg0); font: .85em ui-monospace, Menlo, Consolas, monospace; }
.ai-m.err { align-self: flex-start; background: rgba(255,107,120,.1); border: 1px solid rgba(255,107,120,.4); color: var(--danger-text); }
.ai-m.dim { color: var(--muted); font-style: italic; }
.ai-consent { padding: 12px; border: 1px solid var(--line); border-radius: 4px; background: var(--card); font-size: .86rem; line-height: 1.45; }
.ai-consent p { margin: 0 0 10px; }
.ai-sugg { display: flex; flex-wrap: wrap; gap: 6px; padding: 0 12px 10px; }
.ai-sugg:empty { display: none; }
.ai-sugg button { padding: 6px 11px; border: 1px solid var(--line); border-radius: 99px; background: var(--card); color: var(--text); font-size: .8rem; cursor: pointer; transition: border-color .15s, background .15s; }
.ai-sugg button:hover { border-color: var(--focus); background: var(--bg0); }
.ai-form { display: flex; gap: 8px; padding: 10px 12px 6px; border-top: 1px solid var(--line); }
.ai-form input { flex: 1; min-width: 0; height: 40px; padding: 0 12px; background: var(--bg0); border: 1px solid var(--line); border-radius: 4px; outline: none; font-size: .92rem; }
.ai-form input:focus { border-color: var(--focus); }
.ai-send { flex: none; width: 40px; height: 40px; display: grid; place-items: center; border: 0; border-radius: 4px; background: var(--accent); color: var(--accent-ink); cursor: pointer; }
.ai-send:disabled { opacity: .5; cursor: default; }
.ai-note { margin: 0; padding: 0 14px 10px; color: var(--muted); font-size: .72rem; }
@media (max-width: 640px) {
    .ai-panel { right: 12px; left: 12px; width: auto; max-width: none; height: min(70dvh, 560px); }
    .ai-fab { right: 14px; }
}

/* ---------- Mobile account button + menu sheet, admin log ---------- */
.acct-top { display: none; flex: none; width: 42px; height: 42px; padding: 0; border: 0; border-radius: 6px; background: var(--accent); color: var(--accent-ink); font-weight: 700; font-size: 1rem; text-transform: uppercase; cursor: pointer; place-items: center; }
.menu-head { display: flex; align-items: center; gap: 12px; margin-bottom: 14px; }
.menu-head .av { flex: none; width: 42px; height: 42px; border-radius: 8px; background: var(--accent); color: var(--accent-ink); display: grid; place-items: center; font-weight: 700; text-transform: uppercase; }
.menu-head .who { display: flex; flex-direction: column; min-width: 0; line-height: 1.3; }
.menu-head .who b { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.menu-head .who small { color: var(--muted); font-size: .78rem; }
.menu-item { display: flex; align-items: center; gap: 12px; width: 100%; min-height: 48px; padding: 0 14px; margin: 0 0 6px; border: 1px solid var(--line); border-radius: 4px; background: var(--card); color: var(--text); font-size: .95rem; text-align: left; cursor: pointer; }
.menu-item:hover { border-color: var(--focus); }
.menu-item.out { color: var(--danger); }
#menuDlg form { margin: 0; }
#adminDlg .dlg { max-width: 560px; }
.adm-tabs { display: grid; grid-auto-flow: column; grid-auto-columns: minmax(max-content, 1fr); gap: 4px; padding: 4px; margin-bottom: 12px; background: var(--bg0); border-radius: 4px; overflow-x: auto; scrollbar-width: none; }
.adm-tabs::-webkit-scrollbar { display: none; }
.adm-tabs button { min-height: 36px; padding: 0 12px; white-space: nowrap; border: 0; border-radius: 7px; background: transparent; color: var(--muted); font-size: .86rem; font-weight: 600; cursor: pointer; }
.adm-tabs button[aria-selected="true"] { background: var(--card); color: var(--text); }
.log-row { padding: 10px 12px; margin-bottom: 6px; border: 1px solid var(--line); border-radius: 4px; background: var(--card); font-size: .86rem; }
.log-row.bad { border-left: 3px solid #e5604d; }
.log-row .top { display: flex; justify-content: space-between; gap: 10px; color: var(--muted); font-size: .76rem; }
.log-row .top b { color: var(--text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.log-row .top span { flex: none; }
.log-row .fl { margin: 3px 0 0; color: var(--muted); font-size: .76rem; overflow-wrap: anywhere; }
.log-row .tx { margin: 4px 0 0; color: var(--text); white-space: pre-wrap; overflow-wrap: anywhere; }

/* Admin > Report (database overview) */
#adminDlg .dlg.wide { max-width: 980px; }
.dbr-bar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 10px; }
.dbr-bar label { color: var(--muted); font-size: .8rem; font-weight: 600; }
.dbr-bar select { height: 38px; padding: 0 10px; background: var(--bg0); color: var(--text); border: 1px solid var(--line); border-radius: 4px; }
.dbr-bar .grow { flex: 1; }
.dbr-sum { margin: 0 0 12px; color: var(--muted); font-size: .84rem; }
.dbr-sum b { color: var(--text); }
.dbr-scroll { overflow-x: auto; margin: 6px 0 4px; border: 1px solid var(--line); border-radius: 8px; }
.dbr-tab { width: 100%; border-collapse: collapse; font-size: .8rem; }
.dbr-tab th, .dbr-tab td { padding: 6px 9px; border-bottom: 1px solid var(--line); text-align: left; vertical-align: top; white-space: nowrap; max-width: 280px; overflow: hidden; text-overflow: ellipsis; }
.dbr-tab th { position: sticky; top: 0; background: var(--bg0); color: var(--muted); font-weight: 600; }
.dbr-tab td.num, .dbr-tab th.num { text-align: right; }
.dbr-tab td.nul { color: var(--muted); font-style: italic; }
.dbr-group { margin: 16px 0 6px; font-size: .78rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); }
.dbr-t { margin-bottom: 6px; border: 1px solid var(--line); border-radius: 4px; background: var(--card); }
.dbr-t > summary { display: flex; align-items: center; gap: 10px; padding: 10px 12px; cursor: pointer; list-style: none; }
.dbr-t > summary::-webkit-details-marker { display: none; }
.dbr-t > summary b { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: .92rem; }
.dbr-t > summary span { flex: none; color: var(--muted); font-size: .78rem; }
.dbr-in { padding: 0 12px 12px; }
.dbr-in h5 { margin: 10px 0 2px; font-size: .78rem; color: var(--muted); font-weight: 600; }
.dbr-note { margin: 4px 0 0; color: var(--muted); font-size: .76rem; }

/* Admin > export / import (accounts and AI setup) */
.xfer { margin: 0 0 12px; }
.xfer-row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.xfer-keys { display: flex; align-items: center; gap: 8px; margin: 10px 0 0; color: var(--muted); font-size: .82rem; cursor: pointer; }
.xfer-keys input { width: 18px; height: 18px; flex: none; accent-color: var(--accent); }
.xfer-box { margin-top: 10px; padding: 12px; border: 1px solid var(--line); border-radius: 4px; background: var(--bg0); }
.xfer-box p { margin: 0 0 4px; font-size: .88rem; overflow-wrap: anywhere; }
.xfer-box select { width: 100%; height: 42px; margin: 4px 0 12px; padding: 0 10px; background: var(--bg2); color: var(--text); border: 1px solid var(--line); border-radius: 4px; outline: none; }
.xfer-box select:focus { border-color: var(--accent); }
.xfer-box .xfer-row { justify-content: flex-end; }
.xfer-res { margin-top: 10px; padding: 10px 12px; border: 1px solid var(--line); border-radius: 4px; background: var(--card); font-size: .86rem; overflow-wrap: anywhere; }
.xfer-res.ok { border-color: var(--ok); }
.xfer-res.bad { border-color: var(--danger); }
.xfer-res ul { margin: 6px 0 0; padding-left: 18px; color: var(--muted); font-size: .8rem; }
.xfer-more { margin: 8px 2px 0; }
.xfer-more summary { cursor: pointer; color: var(--muted); font-size: .78rem; font-weight: 600; list-style: none; width: fit-content; }
.xfer-more summary::-webkit-details-marker { display: none; }
.xfer-more summary:hover { color: var(--text, inherit); text-decoration: underline; }
.xfer-more[open] summary::after { content: " (hide)"; font-weight: 400; }
.xfer-note { margin: 6px 0 0; color: var(--muted); font-size: .78rem; line-height: 1.5; }

/* Admin > AI setup */
.aia-row { padding: 10px 12px; margin-bottom: 6px; border: 1px solid var(--line); border-radius: 4px; background: var(--card); }
.aia-row.off { opacity: .6; }
.aia-top { display: flex; align-items: center; gap: 8px; min-width: 0; }
.aia-top b { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: .92rem; }
.aia-sub { margin: 2px 0 0; color: var(--muted); font-size: .78rem; overflow-wrap: anywhere; }
.aia-st { margin: 4px 0 0; font-size: .76rem; color: var(--muted); overflow-wrap: anywhere; }
.aia-st.ok { color: var(--ok); }
.aia-st.bad { color: var(--danger); }
.aia-btns { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
.aia-btns .btn { min-height: 34px; padding: 0 11px; font-size: .8rem; }
.aia-form { margin-top: 10px; padding: 12px; border: 1px solid var(--line); border-radius: 4px; background: var(--bg0); }
.aia-form .dlg-err { min-height: 0; }
.aia-form select, .aia-form input[type=text], .aia-form input[type=password] { width: 100%; height: 42px; padding: 0 12px; margin: 4px 0 2px; background: var(--bg2); color: var(--text); border: 1px solid var(--line); border-radius: 4px; outline: none; }
.aia-form select:focus, .aia-form input:focus { border-color: var(--focus); }
.aia-line { display: flex; gap: 8px; align-items: center; }
.aia-line input { flex: 1; min-width: 0; }
.aia-line .btn { flex: none; min-height: 42px; margin-top: 2px; }
.aia-msg { margin: 6px 0 0; font-size: .8rem; color: var(--muted); }
.aia-msg.ok { color: var(--ok); }
.aia-msg.bad { color: var(--danger); }
.aia-note { margin: 12px 2px 0; color: var(--muted); font-size: .78rem; line-height: 1.5; }

/* ---------- Print ---------- */
#printArea { display: none; }
@media print {
    @page { margin: 14mm; }
    body { overflow: visible; background: #fff; color: #000; height: auto; }
    body > *:not(#printArea) { display: none !important; }
    #printArea { display: block; font-family: Arial, Helvetica, sans-serif; color: #000; }
    #printArea h1 { font-size: 18pt; margin: 0 0 4pt; }
    #printArea p { margin: 0 0 10pt; font-size: 9.5pt; color: #444; }
    #printArea table { width: 100%; border-collapse: collapse; table-layout: fixed; font-size: 9pt; }
    #printArea thead { display: table-header-group; }
    #printArea tr { break-inside: avoid; }
    #printArea th, #printArea td { border: 1px solid #999; padding: 4pt 6pt; text-align: left; vertical-align: top; word-break: break-word; }
    #printArea th { background: #eee; }
    #printArea td.notes { white-space: pre-wrap; }
    #printArea h2 { font-size: 14pt; margin: 18pt 0 6pt; padding-bottom: 3pt; border-bottom: 2pt solid #000; break-after: avoid; }
    #printArea h3 { font-size: 11pt; margin: 14pt 0 2pt; break-after: avoid; }
    #printArea h4 { font-size: 9pt; margin: 8pt 0 3pt; color: #444; break-after: avoid; }
    #printArea .pbr { break-before: page; }
    #printArea table.dbt { font-size: 7.5pt; margin-bottom: 4pt; }
    #printArea table.dbt th, #printArea table.dbt td { padding: 2pt 4pt; }
    #printArea table.dbt td.nul { color: #777; font-style: italic; }
    #printArea table.dbt td.num { text-align: right; }
}

/* ---------- Responsive ---------- */
@media (min-width: 1000px) and (max-width: 1239px) {
    .app { grid-template-columns: 240px minmax(0, 1fr) 330px; }
}
@media (min-width: 1000px) {
    .app { transition: grid-template-columns .2s ease; }
    .app.no-detail { grid-template-columns: 270px minmax(0, 1fr) 0; }   /* details panel folded away until a file is selected */
    .app.no-detail .detail { visibility: hidden; overflow: hidden; border-left-width: 0; }
}
@media (min-width: 1000px) and (max-width: 1239px) {
    .app.no-detail { grid-template-columns: 240px minmax(0, 1fr) 0; }
}
@media (max-width: 999px) {
    .app { grid-template-columns: 1fr; }

    .side {
        position: fixed; inset: 0 auto 0 0; z-index: 60; width: min(340px, 90vw);
        transform: translateX(-102%); visibility: hidden;
        transition: transform .22s ease, visibility 0s .22s;
        padding-top: calc(18px + env(safe-area-inset-top));
    }
    .side.open { transform: none; visibility: visible; transition: transform .22s ease; }
    .side-close { display: inline-flex; }
    .scrim.on { display: block; }
    .side .groups, .side .lib-box, .side .acct { display: none; }   /* phones: the avatar button already opens the account menu, and the chips replace the library list */

    .menu-btn { display: inline-flex; }
    .acct-top { display: inline-grid; }
    .topbar { padding-top: calc(12px + env(safe-area-inset-top)); }
    .chips { display: flex; }

    .detail {
        position: fixed; inset: 0; z-index: 50; border-left: 0;
        transform: translateY(100%); visibility: hidden;
        transition: transform .24s ease, visibility 0s .24s;
    }
    .detail.open { transform: none; visibility: visible; transition: transform .24s ease; }
    .d-top { display: flex; }
}
@media (max-width: 640px) {
    .topbar { flex-wrap: wrap; }
    .menu-btn { order: 1; flex: 1; min-height: 42px; }
    .sort { order: 2; }
    .acct-top { order: 2; }
    .search { order: 3; flex: 1 1 100%; }
    .dlg-wrap.sheet { align-items: flex-end; padding: 0; }
    .dlg-wrap.sheet .dlg { max-width: none; border-radius: 8px 8px 0 0; padding-bottom: calc(20px + env(safe-area-inset-bottom)); }
    .ractions .hide-s { display: none; }
    .resultbar { flex-wrap: wrap; }
    .modal { padding: 0; }
    .pv-box { max-height: 100dvh; border-radius: 0; border: 0; }
    .pv-box.is-audio { max-width: none; height: 100dvh; justify-content: flex-start; }
    .pv-box.is-audio .pv-body { align-items: stretch; }
    .pv-box.is-audio .au { flex: 1; display: flex; }
    .pv-box.is-audio .au-card { margin: auto; }
    .rng.vol { display: none; }
    .vd-big { width: 66px; height: 66px; }
}
/* ---------- Look: cyanotype (blueprint paper, ink blue, one highlighter) ---------- */
.brand h1 { font-stretch: 100%; font-weight: 700; font-size: 1.1rem; letter-spacing: .01em; }
.dlg h3, .d-name h2, .menu-head b { font-stretch: 100%; font-weight: 700; letter-spacing: .01em; }
.btn { font-weight: 600; }
.btn-primary { border-color: var(--edge); box-shadow: 3px 3px 0 var(--stamp); transition: transform .08s, box-shadow .08s, background .15s; }
.btn-primary:hover { border-color: var(--edge); transform: translate(-1px, -1px); box-shadow: 4px 4px 0 var(--stamp); }
.btn-primary:active { transform: translate(3px, 3px); box-shadow: 0 0 0 var(--stamp); }
.btn-primary:disabled { box-shadow: none; transform: none; }
.btn-primary.danger { border-color: var(--danger); box-shadow: 3px 3px 0 rgba(0,0,0,.35); }

/* the drop area is marked with crop marks, like a print sheet */
.dropzone {
    --t: var(--line-2);
    border: 0; border-radius: 0; padding: 24px 14px;
    background:
        linear-gradient(var(--t), var(--t)) left top / 18px 2px no-repeat,
        linear-gradient(var(--t), var(--t)) left top / 2px 18px no-repeat,
        linear-gradient(var(--t), var(--t)) right top / 18px 2px no-repeat,
        linear-gradient(var(--t), var(--t)) right top / 2px 18px no-repeat,
        linear-gradient(var(--t), var(--t)) left bottom / 18px 2px no-repeat,
        linear-gradient(var(--t), var(--t)) left bottom / 2px 18px no-repeat,
        linear-gradient(var(--t), var(--t)) right bottom / 18px 2px no-repeat,
        linear-gradient(var(--t), var(--t)) right bottom / 2px 18px no-repeat,
        var(--bg1);
    transition: none;
}

/* the file list sits on faint drafting grid */
.rows {
    background-image: linear-gradient(var(--grid) 1px, transparent 1px), linear-gradient(90deg, var(--grid) 1px, transparent 1px);
    background-size: 28px 28px; background-position: -1px -1px;
}
.row { border-radius: 3px; }
.row.sel { background: var(--card); box-shadow: inset 0 0 0 1px var(--line); }
.row::before { width: 4px; border-radius: 0; }

/* type filters are index tabs */
.chips { gap: 3px; padding-bottom: 0; margin-bottom: 8px; }
.chips .g { border-radius: 5px 5px 0 0; border: 1px solid var(--line); border-bottom: 0; background: transparent; padding: 7px 12px 6px; }
.chips .g.on { background: var(--card); border-color: var(--line); box-shadow: inset 0 2px 0 var(--focus); }
.g.on { box-shadow: inset 2px 0 0 var(--focus); }
.chips .g.on { box-shadow: inset 0 2px 0 var(--focus); }
.g i { border-radius: 1px; }

.acct .av, .menu-head .av, .acct-top { font-stretch: 100%; font-weight: 700; }
.d-stage { background-color: var(--bg0); }
.ficon { filter: var(--ficon, none); }
:root[data-theme="light"] { --ficon: brightness(.78) saturate(1.2); }
@media (prefers-color-scheme: light) { :root:not([data-theme]) { --ficon: brightness(.78) saturate(1.2); } }

/* ---------- Motion ----------
   One orchestrated moment: the logo is stamped down, then the sidebar and the file list settle in (page load only).
   Everything after that answers something the person did. Only transform / opacity are animated, and
   prefers-reduced-motion switches all of it off (rule at the very end). */
@property --m { syntax: "<length>"; inherits: true; initial-value: 18px; }
:root { --ease: cubic-bezier(.2,.8,.2,1); --spring: cubic-bezier(.34,1.56,.64,1); }

@keyframes stampDown { 0% { opacity: 0; transform: translate(-6px,-6px) scale(1.4) rotate(-9deg); box-shadow: 8px 8px 0 var(--stamp); }
                       55% { opacity: 1; }
                       100% { transform: none; box-shadow: 0 0 0 var(--stamp); } }
@keyframes riseIn  { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: none; } }
@keyframes rowIn   { from { opacity: 0; transform: translateX(-12px); } to { opacity: 1; transform: none; } }
@keyframes fadeIn  { from { opacity: 0; } to { opacity: 1; } }
@keyframes popIn   { from { opacity: 0; transform: translateY(10px) scale(.96); } to { opacity: 1; transform: none; } }
@keyframes sheetUp { from { transform: translateY(100%); } to { transform: none; } }
@keyframes drawBar { from { transform: scaleX(0); } to { transform: scaleX(1); } }
@keyframes beat    { 0% { box-shadow: 0 0 0 0 color-mix(in srgb, var(--ok) 55%, transparent); } 70%, 100% { box-shadow: 0 0 0 8px color-mix(in srgb, var(--ok) 0%, transparent); } }
@keyframes barber  { to { background-position: 14.14px 0; } }
@keyframes tick    { 0% { transform: scale(.3); opacity: 0; } 100% { transform: scale(1); opacity: 1; } }
@keyframes bob     { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-5px); } }

/* page load (the "intro" class is on <html> for the first 2.4 seconds only, so live refreshes never replay it) */
.brand .logo { animation: stampDown .6s var(--ease) .05s backwards; }
html.intro .side > * { animation: riseIn .55s var(--ease) backwards; animation-delay: calc(var(--k, 5) * 70ms + 150ms); }
html.intro .side > :nth-child(1) { --k: 0; }
html.intro .side > :nth-child(2) { --k: 1; }
html.intro .side > :nth-child(3) { --k: 2; }
html.intro .side > :nth-child(4) { --k: 3; }
html.intro .side > :nth-child(5) { --k: 4; }
html.intro .topbar, html.intro .chips, html.intro .resultbar { animation: fadeIn .5s ease-out .2s backwards; }
html.intro .row { --k: 14; animation: rowIn .45s var(--ease) backwards; animation-delay: calc(var(--k) * 34ms + 300ms); }
html.intro .row:nth-child(1)  { --k: 0; }  html.intro .row:nth-child(2)  { --k: 1; }
html.intro .row:nth-child(3)  { --k: 2; }  html.intro .row:nth-child(4)  { --k: 3; }
html.intro .row:nth-child(5)  { --k: 4; }  html.intro .row:nth-child(6)  { --k: 5; }
html.intro .row:nth-child(7)  { --k: 6; }  html.intro .row:nth-child(8)  { --k: 7; }
html.intro .row:nth-child(9)  { --k: 8; }  html.intro .row:nth-child(10) { --k: 9; }
html.intro .row:nth-child(11) { --k: 10; } html.intro .row:nth-child(12) { --k: 11; }
html.intro .row:nth-child(13) { --k: 12; } html.intro .row:nth-child(14) { --k: 13; }
html.intro .usage .stack i { transform-origin: left center; animation: drawBar .8s var(--ease) .7s backwards; }

/* the green dot breathes while the page is receiving live updates */
.live:not(.off) i { animation: beat 2.6s ease-out infinite; }

/* file rows: the type colour bar grows, the icon lifts, a new upload slides in and flashes */
.row { transition: background .15s; }
.row::before { transition: opacity .15s, width .2s var(--ease); }
.row .ficon { transition: transform .3s var(--spring); }
.row.sel::before, .row.picked::before { width: 6px; }
@media (hover: hover) {
    .row:hover::before { width: 6px; opacity: .9; }
    .row:hover .ficon { transform: translateY(-2px) rotate(-3deg); }
}
.row.fresh { animation: fresh 2.4s ease-out, rowIn .5s var(--ease); }
.row.picked .pk .i { animation: tick .28s var(--spring); }

/* sidebar: the little type squares turn into diamonds */
.g i { transition: transform .25s var(--spring); }
.g:hover i, .g.on i { transform: scale(1.35) rotate(45deg); }

/* drop area: the crop marks open up when you reach for it (and when a file is being dragged over the page) */
.dropzone {
    --m: 18px;
    transition: --m .3s var(--ease);
    background:
        linear-gradient(var(--t), var(--t)) left top / var(--m) 2px no-repeat,
        linear-gradient(var(--t), var(--t)) left top / 2px var(--m) no-repeat,
        linear-gradient(var(--t), var(--t)) right top / var(--m) 2px no-repeat,
        linear-gradient(var(--t), var(--t)) right top / 2px var(--m) no-repeat,
        linear-gradient(var(--t), var(--t)) left bottom / var(--m) 2px no-repeat,
        linear-gradient(var(--t), var(--t)) left bottom / 2px var(--m) no-repeat,
        linear-gradient(var(--t), var(--t)) right bottom / var(--m) 2px no-repeat,
        linear-gradient(var(--t), var(--t)) right bottom / 2px var(--m) no-repeat,
        var(--bg1);
}
.dropzone:hover, .dropzone:focus-within, body:has(.drop-overlay.on) .dropzone { --m: 32px; }
body:has(.drop-overlay.on) .dropzone { --t: var(--focus); }
.dropzone .i { transition: transform .35s var(--spring); }
.dropzone:hover .i, body:has(.drop-overlay.on) .dropzone .i { transform: translateY(-4px); }
.drop-overlay.on { animation: fadeIn .15s ease-out; }

/* upload queue */
.qitem { animation: riseIn .3s var(--ease) backwards; }
.qitem.uploading .bar i {
    background-image: repeating-linear-gradient(-45deg, rgba(6,20,35,.22) 0 5px, transparent 5px 10px);
    background-size: 14.14px 14.14px; animation: barber .7s linear infinite;
}
.qitem.done .qmeta { animation: riseIn .3s var(--ease); }

/* details panel: the parts settle in one after another when a file is opened */
.d-body > * { animation: riseIn .35s var(--ease) backwards; }
.d-body > :nth-child(2) { animation-delay: 50ms; }
.d-body > :nth-child(3) { animation-delay: 90ms; }
.d-body > :nth-child(4) { animation-delay: 130ms; }
.d-body > :nth-child(n+5) { animation-delay: 170ms; }
.d-stage img { animation: fadeIn .35s ease-out; }
.d-empty .i, .empty .i { animation: bob 3.6s ease-in-out infinite; }

/* dialogs, menus, preview and toast */
.dlg-wrap.open { animation: fadeIn .18s ease-out; }
.dlg-wrap.open .dlg { animation: popIn .28s var(--ease); }
.modal.open { animation: fadeIn .2s ease-out; }
.modal.open .pv-box { animation: popIn .32s var(--ease); }
.toast { transition: opacity .2s, transform .4s var(--spring); }
@media (max-width: 640px) { .dlg-wrap.sheet.open .dlg { animation: sheetUp .3s var(--ease); } }

/* press feedback */
.icon-btn { transition: background .15s, color .15s, transform .12s var(--spring); }
.icon-btn:active { transform: scale(.88); }
.btn:not(.btn-primary):active { transform: translateY(1px); }
.sort, .search input { transition: border-color .15s, box-shadow .15s; }
.search input:focus, .sort:focus-visible { box-shadow: 0 0 0 3px color-mix(in srgb, var(--focus) 22%, transparent); }

/* ---------- Motion, round two: players, AI, menus, details, feedback ---------- */
@keyframes shake   { 0%, 100% { transform: none; } 20% { transform: translateX(-6px); } 40% { transform: translateX(5px); } 60% { transform: translateX(-3px); } 80% { transform: translateX(2px); } }
@keyframes ring    { 0% { transform: scale(1); opacity: .8; } 100% { transform: scale(1.55); opacity: 0; } }
@keyframes spin    { to { transform: rotate(360deg); } }
@keyframes drift   { from { transform: translate3d(-2%, -1%, 0) scale(1.02); } to { transform: translate3d(3%, 2%, 0) scale(1.12); } }
@keyframes driftC  { from { transform: translate3d(-2%, -1%, 0) scale(1.15); } to { transform: translate3d(3%, 2%, 0) scale(1.28); } }
@keyframes artGlow { 0%, 100% { box-shadow: 0 18px 44px rgba(0,0,0,.5), 0 0 0 0 rgba(181,140,255,.0); } 50% { box-shadow: 0 18px 44px rgba(0,0,0,.5), 0 0 46px 6px rgba(181,140,255,.38); } }
@keyframes zoomIn  { from { opacity: 0; transform: translate(-50%, 12px); } to { opacity: 1; transform: translate(-50%, 0); } }
@keyframes panelIn { from { opacity: 0; transform: translateY(18px) scale(.9); } to { opacity: 1; transform: none; } }
@keyframes fabIn   { from { opacity: 0; transform: scale(.4) rotate(-20deg); } to { opacity: 1; transform: none; } }
@keyframes msgIn   { from { opacity: 0; transform: translate(var(--dx, 0), 8px) scale(.96); } to { opacity: 1; transform: none; } }
@keyframes breathe { 0%, 100% { opacity: .5; } 50% { opacity: 1; } }
@keyframes nudge   { from { transform: translateX(-5px); } to { transform: none; } }
@keyframes scan    { 0% { transform: translateY(0); opacity: 0; } 12% { opacity: .95; } 100% { transform: translateY(228px); opacity: 0; } }
@keyframes glow    { 0%, 100% { box-shadow: inset 0 0 0 0 transparent; } 50% { box-shadow: inset 0 0 90px 0 color-mix(in srgb, var(--accent) 24%, transparent); } }
@keyframes ripple  { from { transform: scale(0); opacity: .35; } to { transform: scale(1); opacity: 0; } }
@keyframes spinIn  { from { opacity: 0; transform: rotate(-90deg) scale(.5); } to { opacity: 1; transform: none; } }

/* click ripple (added by the small script at the end of the page) */
.btn, .ai-send, .mini-play, .menu-item, .ai-sugg button { position: relative; overflow: hidden; }
.ripple { position: absolute; border-radius: 50%; background: currentColor; pointer-events: none; animation: ripple .6s var(--ease) forwards; }

/* buttons and fields */
.btn { transition: background .15s, border-color .15s, transform .15s var(--ease); }
@media (hover: hover) { .btn:not(.btn-primary):not(:disabled):hover { transform: translateY(-1px); } }
.search .i { transition: color .15s, transform .3s var(--spring); }
.search:focus-within .i { color: var(--accent-text); transform: translateY(-50%) scale(1.18) rotate(-8deg); }
.search .icon-btn:not(.hidden) { animation: tick .22s var(--spring); }
.d-notes textarea, .dlg input, .ai-form input, .aia-form input, .aia-form select, .xfer-box select, .vis-sel { transition: border-color .15s, box-shadow .15s; }
.d-notes textarea:focus, .dlg input:focus, .ai-form input:focus, .aia-form input:focus, .aia-form select:focus { box-shadow: 0 0 0 3px color-mix(in srgb, var(--focus) 22%, transparent); }
.dlg .dlg-err:not(:empty), .aia-msg.bad, .xfer-res.bad { animation: shake .4s var(--ease); }
.aia-msg.ok, .xfer-res.ok, .aia-st.ok { animation: riseIn .3s var(--ease); }

/* list text and sidebar nav */
.row .rtext { transition: transform .2s var(--ease); }
.g { transition: background .15s, color .15s, transform .2s var(--ease); }
.g.on { animation: nudge .3s var(--ease); }
@media (hover: hover) {
    .row:hover .rtext { transform: translateX(2px); }
    .groups .g:hover { transform: translateX(3px); }
}
.pickmode .row:hover .pk { border-color: var(--focus); }
.row.picked .pk { animation: tick .25s var(--spring); }

/* a soft light follows the pointer across the file list */
.row::after { content: ""; position: absolute; inset: 0; border-radius: inherit; pointer-events: none; opacity: 0; transition: opacity .2s;
              background: radial-gradient(190px circle at var(--mx, 50%) var(--my, 50%), color-mix(in srgb, var(--accent) 13%, transparent), transparent 70%); }
@media (hover: hover) { .row:hover::after { opacity: 1; } }

/* details panel: a scan line sweeps the preview, pictures zoom a touch on hover */
.d-stage::after { content: ""; position: absolute; left: 0; right: 0; top: 0; height: 2px; pointer-events: none; opacity: 0;
                  background: linear-gradient(90deg, transparent, var(--accent), transparent); animation: scan .95s var(--ease) .18s; }
.d-stage img { transition: transform .5s var(--ease); }
.d-stage .ficon { animation: bob 4s ease-in-out infinite; }
@media (hover: hover) { .d-stage.can:hover img { transform: scale(1.05); } }
.d-info dd, .d-info dt { animation: fadeIn .4s ease-out backwards; animation-delay: .25s; }

/* sidebar scrim, drag overlay */
.scrim.on { animation: fadeIn .2s ease-out; }
.drop-overlay.on { animation: fadeIn .15s ease-out, glow 1.8s ease-in-out .15s infinite; }

/* menus, admin lists, download log: rows rise in one by one */
:is(.adm-row, .aia-row, .log-row, .dl-row, .menu-item, .menu-head, .prow, .opt, .xfer-box, .aia-form) { animation: riseIn .32s var(--ease) backwards; }
:is(.adm-row, .aia-row, .log-row, .dl-row, .menu-item, .prow, .opt):nth-child(2) { animation-delay: 40ms; }
:is(.adm-row, .aia-row, .log-row, .dl-row, .menu-item, .prow, .opt):nth-child(3) { animation-delay: 80ms; }
:is(.adm-row, .aia-row, .log-row, .dl-row, .menu-item, .prow, .opt):nth-child(4) { animation-delay: 120ms; }
:is(.adm-row, .aia-row, .log-row, .dl-row, .menu-item, .prow, .opt):nth-child(5) { animation-delay: 160ms; }
:is(.adm-row, .aia-row, .log-row, .dl-row, .menu-item, .prow, .opt):nth-child(6) { animation-delay: 200ms; }
:is(.adm-row, .aia-row, .log-row, .dl-row, .menu-item, .prow, .opt):nth-child(n+7) { animation-delay: 240ms; }
.adm-row, .aia-row, .log-row, .opt, .prow, .menu-item { transition: border-color .15s, background .15s, transform .2s var(--ease); }
@media (hover: hover) {
    .adm-row:hover, .aia-row:hover, .log-row:hover { border-color: var(--line-2); }
    .menu-item:hover, .opt:hover { transform: translateX(3px); }
}
.adm-tabs button { transition: background .15s, color .15s, box-shadow .2s; }
.adm-tabs button[aria-selected="true"] { box-shadow: inset 0 -2px 0 var(--focus); }
.dbr-t[open] .dbr-in { animation: riseIn .3s var(--ease); }
.dbr-t { transition: border-color .15s; }
.dbr-t[open] { border-color: var(--line-2); }
.menu-head .av, .acct .av, .acct-top { transition: transform .3s var(--spring); }
@media (hover: hover) { .acct:hover .av, .acct-top:hover { transform: rotate(-6deg) scale(1.08); } }

/* mini player: the cover turns into a record while music plays */
.mini-art { transition: border-radius .45s var(--ease); }
.mini.playing .mini-art { border-radius: 50%; animation: spin 9s linear infinite; }
.mini-play { transition: transform .15s var(--spring); }
.mini-play:hover { transform: scale(1.08); }
.mini-play:active { transform: scale(.92); }
.mini-bar i { transition: width .25s linear; box-shadow: 0 0 10px var(--accent); }

/* audio player */
.au-bg:not(.cover) { animation: drift 16s ease-in-out infinite alternate; animation-play-state: paused; }
.au-bg.cover { animation: driftC 18s ease-in-out infinite alternate; animation-play-state: paused; }
.playing .au-bg { animation-play-state: running; }
.playing .au-art { animation: artGlow 2.6s ease-in-out infinite; }
.au-card > :not(.au-art) { animation: riseIn .5s var(--ease) backwards; }
.au-card > :nth-child(2) { animation-delay: 70ms; }
.au-card > :nth-child(3) { animation-delay: 120ms; }
.au-card > :nth-child(4) { animation-delay: 170ms; }
.au-card > :nth-child(5) { animation-delay: 220ms; }
.au-card > :nth-child(n+6) { animation-delay: 270ms; }
.au-art { animation: fadeIn .6s ease-out backwards; }   /* opacity only: the cover keeps its own scale(.93) / scale(1) */
.au-art img { animation: fadeIn .5s ease-out; }
.i-play, .i-pause { animation: tick .26s var(--spring); }
.pbtn.big { transition: background .15s, transform .15s var(--spring); }
.pbtn.big:active { transform: scale(.94); }
.rng::-webkit-slider-thumb { transition: transform .15s var(--spring); }
.rng:hover::-webkit-slider-thumb, .rng:active::-webkit-slider-thumb { transform: scale(1.45); }
.rng::-moz-range-thumb { transition: transform .15s var(--spring); }
.rng:hover::-moz-range-thumb, .rng:active::-moz-range-thumb { transform: scale(1.45); }

/* video player: the big play button pulses while paused, the controls slide up with the fade */
.vd-big::before { content: ""; position: absolute; inset: 0; border-radius: 50%; border: 2px solid rgba(255,255,255,.8); pointer-events: none; animation: ring 2.2s ease-out infinite; }
.vd-ctl { transform: translateY(10px); transition: opacity .2s, transform .28s var(--ease); }
.vd.show .vd-ctl, .vd:not(.playing) .vd-ctl { transform: none; }
.vd-err { animation: fadeIn .3s ease-out; }

/* preview content */
.pv-zoom { animation: zoomIn .35s var(--spring) .15s backwards; }
.pv-text:not(.dim), .pv-img, .zv-img { animation: fadeIn .35s ease-out; }
.pv-bar { animation: fadeIn .3s ease-out .1s backwards; }
.au-x { transition: background .15s, transform .25s var(--spring); }
.au-x:hover { transform: rotate(90deg); }

/* AI assistant */
.ai-fab.on { animation: fabIn .5s var(--spring); }
.ai-fab::after { content: ""; position: absolute; inset: 0; border-radius: inherit; border: 2px solid var(--accent); pointer-events: none; opacity: 0; animation: ring 1.8s ease-out .7s 3 both; }
.ai-panel.open { animation: panelIn .36s var(--spring); transform-origin: bottom right; }
.ai-m { animation: msgIn .3s var(--ease) backwards; }
.ai-m.user { --dx: 14px; }
.ai-m.ai, .ai-m.err { --dx: -14px; }
.ai-m.dim { animation: msgIn .3s var(--ease) backwards, breathe 1.5s ease-in-out .3s infinite; }
.ai-consent { animation: riseIn .4s var(--ease) backwards; }
.ai-sugg button { animation: riseIn .35s var(--ease) backwards; }
.ai-sugg button:nth-child(2) { animation-delay: 50ms; }
.ai-sugg button:nth-child(3) { animation-delay: 100ms; }
.ai-sugg button:nth-child(4) { animation-delay: 150ms; }
.ai-sugg button:nth-child(n+5) { animation-delay: 200ms; }
@media (hover: hover) { .ai-sugg button:hover { transform: translateY(-2px); } }
.ai-sugg button { transition: border-color .15s, background .15s, transform .2s var(--spring); }
.ai-send svg { transition: transform .25s var(--spring); }
.ai-send:not(:disabled):hover svg { transform: translateX(2px) rotate(-10deg); }
.ai-send:disabled svg { animation: breathe 1.2s ease-in-out infinite; }

/* ============================================================
   Left panel as a toggle: full panel <-> icon rail (desktop). Phones keep the slide-in drawer.
   Middle panel: title header, pill filters, card rows, list/grid layout.
   ============================================================ */
.side { overflow-x: hidden; }
.side-toggle, .rail-up { display: none; }
@media (min-width: 1000px) {
    .side-toggle { display: inline-flex; margin-left: 2px; }
    .live { margin-left: auto; }

    html.side-c .app { grid-template-columns: 72px minmax(0, 1fr) 360px; }
    html.side-c .app.no-detail { grid-template-columns: 72px minmax(0, 1fr) 0; }

    html.side-c .side { padding: 16px 0 calc(16px + env(safe-area-inset-bottom)); gap: 16px; align-items: center; }
    html.side-c .brand { flex-direction: column; gap: 10px; }
    html.side-c .brand h1 { display: none; }
    html.side-c .side-toggle { margin: 0; }
    html.side-c .side-toggle .i { transform: scaleX(-1); }

    html.side-c .rail-up {
        order: 1; display: grid; place-items: center; width: 44px; height: 44px; flex: none; padding: 0;
        background: var(--accent); color: var(--accent-ink); border: 1px solid var(--edge); border-radius: 4px;
        box-shadow: 3px 3px 0 var(--stamp); cursor: pointer; transition: transform .08s, box-shadow .08s;
    }
    html.side-c .rail-up:hover { transform: translate(-1px, -1px); box-shadow: 4px 4px 0 var(--stamp); }
    html.side-c .rail-up:active { transform: translate(3px, 3px); box-shadow: 0 0 0 var(--stamp); }
    html.side-c .upload, html.side-c .usage, html.side-c .lib-box h2 { display: none; }

    html.side-c .lib-box { order: 2; }
    html.side-c .groups { gap: 6px; align-items: center; }
    html.side-c .groups .g { width: 44px; height: 40px; padding: 0; justify-content: center; }
    html.side-c .groups .g:hover { transform: none; }
    html.side-c .groups .g span, html.side-c .groups .g b { display: none; }
    html.side-c .groups .g i { width: 12px; height: 12px; }
    html.side-c .groups .g.on { box-shadow: inset 0 0 0 1.5px var(--focus); }

    html.side-c .acct { order: 9; margin-top: auto; width: 100%; flex-direction: column; flex-wrap: nowrap; gap: 6px; padding: 10px 0 0; background: none; border: 0; border-top: 1px solid var(--line); border-radius: 0; }
    html.side-c .acct .who { display: none; }
    html.side-c .acct > .icon-btn, html.side-c .acct > form { flex: none; width: auto; }
    html.side-c .acct .icon-btn, html.side-c .acct > form .icon-btn { width: 40px; height: 40px; }

    /* keyboard hint only where there is a keyboard */
    .kbd { display: grid; }
}

/* brand row: the "Live" status is a dot on the logo so the name and fold button have room */
.brand { position: relative; gap: 10px; }
.brand .logo { position: relative; flex: none; }
.brand .live { position: absolute; left: 25px; top: -3px; margin: 0; width: 14px; height: 14px; display: grid; place-items: center; background: var(--bg0); border-radius: 50%; }
.brand .live #liveText { display: none; }
.brand h1 { flex: 1; min-width: 0; font-size: 1rem; line-height: 1.15; overflow-wrap: normal; }   /* wraps onto two lines when the panel is narrow, never cut off */

/* ---------- Middle panel ---------- */
.list-panel { --pad: 20px; container: lp / inline-size; }

.resultbar { display: flex; align-items: flex-end; justify-content: space-between; gap: 10px 16px; flex-wrap: wrap; padding: 20px var(--pad) 6px; border-bottom: 0; }
.lp-title { flex: 1 1 200px; min-width: 0; }
.lp-title h2 { margin: 0; font-size: 1.65rem; line-height: 1.15; font-weight: 800; letter-spacing: -.015em; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.resultbar .sum { display: block; flex: none; margin-top: 3px; font-size: .84rem; }
.lp-tools { display: flex; align-items: center; gap: 6px; flex: none; flex-wrap: wrap; }

.seg { display: inline-flex; gap: 2px; padding: 2px; background: var(--card); border: 1px solid var(--line); border-radius: 6px; }
.seg button { display: grid; place-items: center; width: 34px; height: 30px; padding: 0; border: 0; border-radius: 4px; background: transparent; color: var(--muted); cursor: pointer; transition: background .15s, color .15s; }
.seg button:hover { background: var(--hover); color: var(--text); }
.seg button[aria-pressed="true"] { background: var(--accent); color: var(--accent-ink); }

@container lp (max-width: 620px) {
    .lp-tools .lbl { display: none; }
    .lp-tools .btn { padding: 5px 9px; }
}

.topbar { padding: 10px var(--pad) 12px; }
.search input { height: 44px; border-radius: 6px; }
.sort { height: 44px; border-radius: 6px; padding: 0 12px; }
.kbd { display: none; position: absolute; right: 12px; top: 50%; transform: translateY(-50%); min-width: 22px; height: 22px; padding: 0 6px; place-items: center;
       border: 1px solid var(--line-2); border-bottom-width: 2px; border-radius: 4px; font: 600 .72rem ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; color: var(--muted); pointer-events: none; }
.search input:focus ~ .kbd, .search input:not(:placeholder-shown) ~ .kbd { display: none; }
@media (hover: none) { .kbd { display: none !important; } }

/* file-type filters: pills (shown here on phones and while the left panel is folded; the open panel has its own list) */
@media (min-width: 1000px) { html.side-c .chips { display: flex; } }
.chips { gap: 6px; padding: 0 var(--pad) 12px; margin-bottom: 0; }
.chips .g { gap: 10px; min-height: 36px; padding: 4px 6px 4px 12px; border: 1px solid var(--line); border-radius: 999px; background: transparent; box-shadow: none; font-size: .86rem; }
.chips .g:hover { background: var(--card); }
.chips .g b { min-width: 24px; padding: 1px 7px; border-radius: 999px; background: var(--card); color: var(--muted); font-size: .74rem; font-weight: 600; text-align: center; }
.chips .g.on { background: var(--accent); border-color: var(--edge); color: var(--accent-ink); box-shadow: none; }
.chips .g.on b { background: rgba(6,20,35,.16); color: var(--accent-ink); }
.chips .g.on i { box-shadow: 0 0 0 2px var(--accent-ink); }

.selbar { margin: 4px var(--pad) 8px; border: 1px solid var(--line); border-radius: 6px; }

/* rows become cards */
.rows { display: flex; flex-direction: column; gap: 8px; padding: 4px var(--pad) calc(28px + env(safe-area-inset-bottom)); background-image: none; align-content: start; }
.row { min-height: 68px; gap: 14px; padding: 10px 12px 10px 20px; background: var(--card); border: 1px solid var(--line); border-radius: 6px;
       transition: background .15s, border-color .15s, box-shadow .15s, transform .15s var(--ease); }
.row::before { left: -1px; top: -1px; bottom: -1px; width: 4px; border-radius: 6px 0 0 6px; opacity: .85; }
.row:hover { background: var(--card); border-color: var(--line-2); }
.row.sel { background: color-mix(in srgb, var(--accent) 8%, var(--card)); border-color: var(--focus); box-shadow: 3px 3px 0 color-mix(in srgb, var(--stamp) 60%, transparent); transform: translate(-1px, -1px); }
.row.picked { background: color-mix(in srgb, var(--accent) 8%, var(--card)); border-color: var(--focus); }
.row .ficon { width: 36px; height: 46px; flex: 0 0 36px; }
.rtext { font-size: .98rem; }
.rmeta { display: flex; flex-wrap: wrap; align-items: center; margin-top: 1px; }
.rmeta span + span::before { content: ""; display: inline-block; width: 3px; height: 3px; margin: 0 8px; border-radius: 50%; background: currentColor; opacity: .6; vertical-align: middle; }
.rmeta .by { color: var(--accent-text); }
.pk { flex: 0 0 24px; width: 24px; height: 24px; }

/* grid layout */
.rows.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(152px, 1fr)); grid-auto-rows: max-content; gap: 12px; }
.rows.grid .row { flex-direction: column; align-items: stretch; gap: 8px; min-height: auto; padding: 18px 12px 8px; }
.rows.grid .row::before, .rows.grid .row.sel::before, .rows.grid .row.picked::before, .rows.grid .row:hover::before { left: -1px; right: -1px; top: -1px; bottom: auto; width: auto; height: 4px; border-radius: 6px 6px 0 0; }
.rows.grid .row .ficon { width: 62px; height: 80px; flex: none; align-self: center; margin: 4px 0 2px; }
.rows.grid .rinfo { flex: none; }
.rows.grid .rmeta { font-size: .78rem; }
.rows.grid .rnote { display: none; }
.rows.grid .rmeta .by { flex-basis: 100%; }
.rows.grid .rmeta .by::before { display: none; }
.rows.grid .ractions { justify-content: center; flex-wrap: wrap; border-top: 1px solid var(--row-line); padding-top: 4px; margin: 0 -4px; }
.rows.grid .pk { position: absolute; top: 14px; left: 10px; }
.rows.grid .row:hover .rtext { transform: none; }

/* section headings in the list: Folders / Your Files / Shared With You */
.sec { grid-column: 1 / -1; display: flex; align-items: center; gap: 8px; margin: 14px 0 0; font-size: .75rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: var(--muted); }
.sec:first-child { margin-top: 2px; }
.sec .i { width: 15px; height: 15px; }
.sec b { padding: 1px 7px; border-radius: 999px; background: var(--card); border: 1px solid var(--line); font-size: .72rem; font-weight: 600; letter-spacing: 0; }
.sec::after { content: ""; flex: 1; height: 1px; background: var(--line-2); opacity: .7; }

/* empty and no-result states */
.empty { grid-column: 1 / -1; display: flex; flex-direction: column; align-items: center; margin: 12px 0; padding: 44px 24px; border: 1.5px dashed var(--line-2); border-radius: 8px; background: color-mix(in srgb, var(--card) 55%, transparent); }
.empty h3 { margin: 6px 0 4px; font-size: 1.08rem; color: var(--text); }
.empty p { max-width: 36ch; margin: 0 0 16px; }

@media (max-width: 999px) {
    .list-panel { padding-top: env(safe-area-inset-top); }
    .topbar { padding-top: 10px; }
}
@media (max-width: 640px) {
    .list-panel { --pad: 14px; }
    .resultbar { padding-top: 16px; }
    .lp-title h2 { font-size: 1.4rem; }
}

@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after { animation: none !important; transition: none !important; }
}

/* ---------- Animated waves behind the file list ---------- */
.list-panel { position: relative; isolation: isolate; }
.gradient-waves-container { position: absolute; inset: 0; z-index: -1; overflow: hidden; pointer-events: none; }
.gradient-waves-canvas { position: absolute; inset: 0; display: block; width: 100%; height: 100%; }

/* ---------- Desktop: the left and right panels float OVER the middle panel instead of pushing it ---------- */
@media (min-width: 1000px) {
    html .app, html .app.no-detail, html.side-c .app, html.side-c .app.no-detail {
        grid-template-columns: 72px minmax(0, 1fr);   /* the middle panel never changes size */
        position: relative; transition: none;
    }
    /* left panel: a 72px icon rail that opens over the middle panel */
    .side { grid-column: 1; grid-row: 1; position: relative; z-index: 40; width: 270px; transition: width .2s ease, box-shadow .2s ease; }
    html.side-c .side { width: 72px; }
    html:not(.side-c) .side { box-shadow: 10px 0 34px var(--shadow); }
    /* middle panel */
    .list-panel { grid-column: 2; grid-row: 1; }
    /* right panel: slides in over the right edge of the middle panel */
    .detail, .app.no-detail .detail {
        grid-column: 2; grid-row: 1; justify-self: end; width: 360px; max-width: 100%; position: relative; z-index: 30;
        box-shadow: -10px 0 34px var(--shadow); border-left-width: 1px;
        transition: transform .22s ease, visibility 0s;
    }
    .app.no-detail .detail { transform: translateX(105%); visibility: hidden; box-shadow: none; transition: transform .22s ease, visibility 0s .22s; }
}
@media (min-width: 1000px) and (max-width: 1239px) {
    .side { width: 240px; }
    html.side-c .side { width: 72px; }
    .detail, .app.no-detail .detail { width: 330px; }
}

/* ---------- Account dialog: wider, with the hanging profile card on the right ---------- */
.acct-card { display: none; }
@media (min-width: 860px) {
    #acctDlg .dlg { max-width: 940px; display: grid; grid-template-columns: minmax(0, 400px) minmax(0, 1fr); gap: 22px; }
    .acct-card { display: block; position: sticky; top: 0; align-self: start; height: min(660px, calc(100dvh - 82px)); min-height: 420px;
                 border-radius: 8px; border: 1px solid var(--line); background: var(--bg0); overflow: hidden; }
    .acct-card .lanyard-canvas { display: block; width: 100%; height: 100%; }
    .acct-card .lw-note { margin: 0; padding: 24px; font-size: .85rem; color: var(--muted); text-align: center; }
}

/* ---------- Profile picture ---------- */
.avsec { display: flex; gap: 14px; align-items: flex-start; margin: 4px 0 14px; padding-bottom: 14px; border-bottom: 1px solid var(--line); }
.avbig { flex: none; width: 72px; height: 72px; border-radius: 50%; display: grid; place-items: center; background: var(--accent) center / cover no-repeat; color: var(--accent-ink); font-size: 1.7rem; font-weight: 700; text-transform: uppercase; overflow: hidden; }
.avtxt { min-width: 0; flex: 1; }
.avtxt h4 { margin: 0 0 2px; }
.avtxt p { margin: 0 0 8px; font-size: .8rem; color: var(--muted); }
.avbtns { display: flex; gap: 8px; flex-wrap: wrap; }

/* ---------- Folders ---------- */
.crumbs { display: flex; align-items: center; flex-wrap: wrap; gap: 2px 2px; padding: 2px var(--pad, 16px) 8px; font-size: .9rem; color: var(--muted); }
.crumbs button { display: inline-flex; align-items: center; gap: 6px; padding: 5px 9px; border: 0; border-radius: 6px; background: none; color: var(--accent-text); font: inherit; font-weight: 600; cursor: pointer; }
.crumbs button:hover { background: var(--hover); }
.crumbs b { padding: 5px 9px; color: var(--text); font-weight: 700; }
.crumbs .sep { opacity: .55; }
.crumbs .i { width: 15px; height: 15px; }
.row.dropon, .crumbs button.dropon { outline: 2px dashed var(--focus); outline-offset: -2px; background: var(--tint); }
.row[draggable="true"] { -webkit-user-drag: element; }
.frow .rtext { font-weight: 700; }
.mvlist { max-height: 260px; }
.mvlist .prow.cur { opacity: .55; }
.dlg .dlg-note { margin: 8px 0 0; font-size: .8rem; color: var(--muted); }
.dlg .opt + .opt { margin-top: 0; }

/* ThoughtLine: "Thinking… 3.2s" -> "Thought for 3.2s" (port of the React Bits component, no React needed) */
.thought-line {
    --tl-ease-out: cubic-bezier(0.23, 1, 0.32, 1);
    --tl-ease-in-out: cubic-bezier(0.77, 0, 0.175, 1);
    --tl-font: 16px;
    --tl-color: currentColor;
    --tl-glyph: currentColor;
    --tl-settle: 350ms;
    --tl-blur: 2px;
    --tl-shimmer: 1.8s;
    --tl-breath: 1.6s;
    --tl-trough: 0.55;
    --tl-done: 0.75;
    --tl-timer: 0.55;

    display: inline-flex;
    flex-direction: column;
    align-items: flex-start;
    color: var(--tl-color);
    font-family: inherit;
    font-size: var(--tl-font);
    font-weight: 500;
    line-height: 1.2;
    font-style: normal;
}

.thought-line__head {
    position: relative;
    display: inline-flex;
    align-items: center;
    gap: 0.3em;
    margin: 0;
    padding: 0;
    border: 0;
    background: none;
    color: inherit;
    font: inherit;
    text-align: left;
    white-space: nowrap;
    outline: none;
    cursor: default;
}
.thought-line__head[data-toggle] { cursor: pointer; -webkit-tap-highlight-color: transparent; }
.thought-line__head[data-toggle]:focus-visible { outline: 2px solid var(--focus, currentColor); outline-offset: 3px; border-radius: 4px; }

/* the sparkle: breathes while working, dims when done */
.thought-line__glyph {
    display: inline-flex;
    flex: none;
    width: 1.1em;
    height: 1.1em;
    margin-right: 0.2em;
    color: var(--tl-glyph);
    opacity: 0.55;
    transition: opacity var(--tl-settle) var(--tl-ease-out);
}
.thought-line[data-working] .thought-line__glyph {
    animation: thought-line-breathe var(--tl-breath) var(--tl-ease-in-out) infinite;
}
.thought-line__glyph svg {
    display: block;
    width: 100%;
    height: 100%;
    fill: none;
    stroke: currentColor;
    stroke-width: 1.8;
    stroke-linecap: round;
    stroke-linejoin: round;
}
.thought-line__dot { width: 0.5em; height: 0.5em; margin: auto; border-radius: 50%; background: currentColor; }

@keyframes thought-line-breathe {
    0%, 100% { opacity: var(--tl-trough); }
    50%      { opacity: 1; }
}

/* the two lines ("Thinking…" and "Thought for") sit on top of each other and crossfade */
.thought-line__label { display: inline-grid; }
.thought-line__text {
    grid-area: 1 / 1;
    width: max-content;
    opacity: 0;
    filter: blur(var(--tl-blur));
    transition: opacity var(--tl-settle) var(--tl-ease-out), filter var(--tl-settle) var(--tl-ease-out);
}
.thought-line__text[data-active] { opacity: 1; filter: blur(0); }
.thought-line__text--done[data-active] { opacity: var(--tl-done); }

.thought-line__breath { display: inline-block; }
/* no shimmer: the label breathes a little behind the glyph */
.thought-line[data-working] .thought-line__breath:not([data-shimmer]) {
    animation: thought-line-breathe var(--tl-breath) var(--tl-ease-in-out) 0.14s infinite;
}
.thought-line[data-working] .thought-line__breath[data-shimmer] {
    background: linear-gradient(
        100deg,
        color-mix(in srgb, var(--tl-color) 50%, transparent) 30%,
        var(--tl-color) 50%,
        color-mix(in srgb, var(--tl-color) 50%, transparent) 70%
    );
    background-size: 250% 100%;
    background-position: 125% 0;
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
    -webkit-text-fill-color: transparent;
    animation: thought-line-shimmer var(--tl-shimmer) linear infinite;
}
@keyframes thought-line-shimmer { to { background-position: -125% 0; } }

.thought-line__timer {
    font-variant-numeric: tabular-nums;
    opacity: var(--tl-timer);
    transition: opacity var(--tl-settle) ease, transform var(--tl-settle) var(--tl-ease-in-out);
}
.thought-line__timer[data-done] { opacity: var(--tl-done); }

.thought-line__chevron {
    display: inline-flex;
    width: 1em;
    height: 1em;
    margin-left: 0.1em;
    opacity: 0;
    transition: opacity 200ms ease, transform 200ms var(--tl-ease-out);
}
.thought-line__chevron svg { width: 100%; height: 100%; fill: none; stroke: currentColor; stroke-width: 2.2; stroke-linecap: round; stroke-linejoin: round; }
.thought-line__chevron[data-on] { opacity: 0.55; }
.thought-line[data-open] .thought-line__chevron { transform: rotate(180deg); }

.thought-line__sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }

/* the trace: folds open and closed */
.thought-line__trace {
    display: grid;
    grid-template-rows: 0fr;
    width: 0;
    min-width: 100%;
    transition: grid-template-rows var(--tl-settle) var(--tl-ease-out);
}
.thought-line__trace[data-open] { grid-template-rows: 1fr; }
.thought-line__fold { min-height: 0; overflow-x: visible; overflow-y: clip; }
.thought-line__steps {
    display: flex;
    flex-direction: column;
    gap: 0.45em;
    width: max-content;
    padding: 0.6em 0 0.2em 1.6em;
    font-size: 0.875em;
    font-weight: 400;
    white-space: nowrap;
}
.thought-line__step {
    display: flex;
    align-items: center;
    gap: 0.5em;
    opacity: 1;
    transform: translateY(0);
    transition: opacity 200ms var(--tl-ease-out), transform 200ms var(--tl-ease-out);
}
@starting-style {
    .thought-line__step { opacity: 0; transform: translateY(-4px); }
}
.thought-line__mark { display: inline-grid; flex: none; place-items: center; width: 1em; height: 1em; opacity: 0.55; }
.thought-line__mark svg { width: 100%; height: 100%; fill: none; stroke: currentColor; stroke-width: 2.5; stroke-linecap: round; stroke-linejoin: round; }
.thought-line__pulse {
    display: block;
    width: 0.4em;
    height: 0.4em;
    border-radius: 50%;
    background: currentColor;
    animation: thought-line-pulse 1.6s var(--tl-ease-in-out) infinite;
}
@keyframes thought-line-pulse { 0%, 100% { opacity: 0.55; } 50% { opacity: 1; } }
.thought-line__step-text { transition: opacity var(--tl-settle) ease; }
.thought-line__step[data-done] .thought-line__step-text { opacity: 0.55; }

@media (prefers-reduced-motion: reduce) {
    .thought-line__text { filter: none !important; transition: opacity var(--tl-settle) ease; }
    .thought-line__timer { transition: opacity var(--tl-settle) ease; }
    .thought-line__step { transform: none !important; transition: opacity 200ms ease; }
    .thought-line__pulse { animation-duration: 2.4s; }
    .thought-line[data-working] .thought-line__breath[data-shimmer] { animation: none; background: none; color: inherit; -webkit-text-fill-color: currentColor; }
    .thought-line { --tl-trough: 0.8; --tl-breath: 2.4s; }
}

/* ---- inside the AI chat ---- */
.ai-m.think { --dx: -14px; align-self: flex-start; max-width: 100%; padding: 4px 4px; background: none; }
</style>
</head>
<body>

<!-- Icon sprite -->
<svg width="0" height="0" style="position:absolute" aria-hidden="true">
    <symbol id="i-folder-plus" viewBox="0 0 24 24"><path d="M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><path d="M12 10.5v5M9.5 13h5"/></symbol>
    <symbol id="i-move" viewBox="0 0 24 24"><path d="M4 12h11m0 0l-4-4m4 4l-4 4"/><path d="M20 5v14"/></symbol>
    <symbol id="i-folder" viewBox="0 0 24 24"><path d="M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></symbol>
    <symbol id="i-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/></symbol>
    <symbol id="i-pencil" viewBox="0 0 24 24"><path d="M4 20h4L19 9l-4-4L4 16z"/><path d="M13.5 6.5l4 4"/></symbol>
    <symbol id="i-download" viewBox="0 0 24 24"><path d="M12 4v11m0 0l-4-4m4 4l4-4"/><path d="M5 19h14"/></symbol>
    <symbol id="i-trash" viewBox="0 0 24 24"><path d="M5 7h14M10 7V5h4v2M7 7l1 12h8l1-12M10 11v5M14 11v5"/></symbol>
    <symbol id="i-eye" viewBox="0 0 24 24"><path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="2.8"/></symbol>
    <symbol id="i-check" viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5"/></symbol>
    <symbol id="i-select" viewBox="0 0 24 24"><rect x="4" y="4" width="16" height="16" rx="3.5"/><path d="M8.5 12.5l2.5 2.5 4.5-5"/></symbol>
    <symbol id="i-print" viewBox="0 0 24 24"><path d="M7 9V4h10v5M7 17H5a1 1 0 01-1-1v-5a2 2 0 012-2h12a2 2 0 012 2v5a1 1 0 01-1 1h-2"/><rect x="7" y="14" width="10" height="6"/></symbol>
    <symbol id="i-upload" viewBox="0 0 24 24"><path d="M12 16V4m0 0L8 8m4-4l4 4"/><path d="M4 16v3a1 1 0 001 1h14a1 1 0 001-1v-3"/></symbol>
    <symbol id="i-close" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></symbol>
    <symbol id="i-back" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></symbol>
    <symbol id="i-play" viewBox="0 0 24 24"><path d="M8 5.5v13l11-6.5z" fill="currentColor" stroke="none"/></symbol>
    <symbol id="i-pause" viewBox="0 0 24 24"><path d="M7 5h3.5v14H7zM13.5 5H17v14h-3.5z" fill="currentColor" stroke="none"/></symbol>
    <symbol id="i-back10" viewBox="0 0 24 24"><path d="M4.5 12.5a7.5 7.5 0 107.5-7.5H9"/><path d="M11.5 2.5L8.5 5l3 2.5"/><text x="12" y="16" text-anchor="middle" font-size="7.5" font-weight="700" fill="currentColor" stroke="none" font-family="Arial,sans-serif">10</text></symbol>
    <symbol id="i-fwd10" viewBox="0 0 24 24"><g transform="translate(24 0) scale(-1 1)"><path d="M4.5 12.5a7.5 7.5 0 107.5-7.5H9"/><path d="M11.5 2.5L8.5 5l3 2.5"/></g><text x="12" y="16" text-anchor="middle" font-size="7.5" font-weight="700" fill="currentColor" stroke="none" font-family="Arial,sans-serif">10</text></symbol>
    <symbol id="i-vol-on" viewBox="0 0 24 24"><path d="M4 9.5v5h3.5L12 18.5v-13L7.5 9.5z" fill="currentColor"/><path d="M15.5 9a4 4 0 010 6M18 6.5a8 8 0 010 11"/></symbol>
    <symbol id="i-vol-off" viewBox="0 0 24 24"><path d="M4 9.5v5h3.5L12 18.5v-13L7.5 9.5z" fill="currentColor"/><path d="M16 9.5l5 5M21 9.5l-5 5"/></symbol>
    <symbol id="i-full" viewBox="0 0 24 24"><path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/></symbol>
    <symbol id="i-lock" viewBox="0 0 24 24"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 018 0v3"/></symbol>
    <symbol id="i-users" viewBox="0 0 24 24"><circle cx="9" cy="8" r="3.2"/><path d="M3 19c0-3.3 2.7-5.5 6-5.5s6 2.2 6 5.5"/><path d="M16 5.2a3.2 3.2 0 010 5.6M18 14c2 .6 3 2.3 3 5"/></symbol>
    <symbol id="i-globe" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.7 3.6 5.7 3.6 9S14.5 18.3 12 21c-2.5-2.7-3.6-5.7-3.6-9S9.5 5.7 12 3z"/></symbol>
    <symbol id="i-share" viewBox="0 0 24 24"><circle cx="6" cy="12" r="2.6"/><circle cx="18" cy="6" r="2.6"/><circle cx="18" cy="18" r="2.6"/><path d="M8.3 10.8l7.4-3.6M8.3 13.2l7.4 3.6"/></symbol>
    <symbol id="i-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2.5v2.2M12 19.3v2.2M2.5 12h2.2M19.3 12h2.2M5.3 5.3l1.6 1.6M17.1 17.1l1.6 1.6M5.3 18.7l1.6-1.6M17.1 6.9l1.6-1.6"/></symbol>
    <symbol id="i-moon" viewBox="0 0 24 24"><path d="M20.5 14.2A8.5 8.5 0 019.8 3.5a8.5 8.5 0 1010.7 10.7z"/></symbol>
    <symbol id="i-gear" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-2 2 2 2 0 01-2-2v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06a1.65 1.65 0 00.33-1.82 1.65 1.65 0 00-1.51-1H3a2 2 0 01-2-2 2 2 0 012-2h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 010-2.83 2 2 0 012.83 0l.06.06a1.65 1.65 0 001.82.33H9a1.65 1.65 0 001-1.51V3a2 2 0 012-2 2 2 0 012 2v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 0 2 2 0 010 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9a1.65 1.65 0 001.51 1H21a2 2 0 012 2 2 2 0 01-2 2h-.09a1.65 1.65 0 00-1.51 1z"/></symbol>
    <symbol id="i-min" viewBox="0 0 24 24"><path d="M6 9.5l6 6 6-6"/></symbol>
    <symbol id="i-expand" viewBox="0 0 24 24"><path d="M6 14.5l6-6 6 6"/></symbol>
    <symbol id="i-user" viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.6"/><path d="M5 20c0-3.6 3-6 7-6s7 2.4 7 6"/></symbol>
    <symbol id="i-users" viewBox="0 0 24 24"><circle cx="9" cy="8" r="3.2"/><path d="M3 19c0-3.2 2.7-5.2 6-5.2s6 2 6 5.2M16 5.2a3.2 3.2 0 010 5.6M18 14.2c1.8.7 3 2.3 3 4.8"/></symbol>
    <symbol id="i-panel" viewBox="0 0 24 24"><rect x="3.5" y="5" width="17" height="14" rx="2.5"/><path d="M9.5 5v14"/></symbol>
    <symbol id="i-list" viewBox="0 0 24 24"><path d="M8.5 7H20M8.5 12H20M8.5 17H20M4 7h.01M4 12h.01M4 17h.01"/></symbol>
    <symbol id="i-grid" viewBox="0 0 24 24"><rect x="4" y="4" width="6.5" height="6.5" rx="1.5"/><rect x="13.5" y="4" width="6.5" height="6.5" rx="1.5"/><rect x="4" y="13.5" width="6.5" height="6.5" rx="1.5"/><rect x="13.5" y="13.5" width="6.5" height="6.5" rx="1.5"/></symbol>
    <symbol id="i-logout" viewBox="0 0 24 24"><path d="M10 4H6a1 1 0 00-1 1v14a1 1 0 001 1h4M15 8l4 4-4 4M19 12H9"/></symbol>
</svg>

<div class="app no-detail" id="app">

    <!-- ============ Left panel: upload + library ============ -->
    <aside class="side" id="side">
        <div class="brand">
            <span class="logo"><svg class="i"><use href="#i-folder"/></svg></span>
            <h1>Local File Storage</h1>
            <span class="live" id="live" title="Updates automatically on all devices"><i></i><span id="liveText">Live</span></span>
            <button type="button" class="icon-btn side-close" id="sideClose" aria-label="Close menu"><svg class="i"><use href="#i-close"/></svg></button>
            <button type="button" class="icon-btn side-toggle" id="sideToggle" aria-controls="side" aria-expanded="true" aria-label="Fold panel into icon rail" title="Fold panel"><svg class="i"><use href="#i-panel"/></svg></button>
        </div>

        <div class="acct">
            <span class="av" id="meAv"><?= htmlspecialchars(mb_strtoupper(mb_substr($user["username"], 0, 1, "UTF-8"), "UTF-8")) ?></span>
            <div class="who"><b id="meName"><?= htmlspecialchars($user["username"]) ?></b><small><?= $user["is_admin"] ? "Admin account" : "Signed in" ?></small></div>
            <?php if ($user["is_admin"]): ?>
            <button type="button" class="icon-btn" id="adminBtn" title="Admin settings" aria-label="Admin settings"><svg class="i"><use href="#i-gear"/></svg></button>
            <?php endif; ?>
            <button type="button" class="icon-btn" id="acctBtn" title="Account: change username or password" aria-label="Account settings"><svg class="i"><use href="#i-user"/></svg></button>
            <button type="button" class="icon-btn" data-theme-btn title="Switch theme" aria-label="Switch theme"><svg class="i"><use href="#i-moon"/></svg></button>
            <form method="post" action="login.php">
                <input type="hidden" name="action" value="logout">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                <button type="submit" class="icon-btn" title="Log out" aria-label="Log out"><svg class="i"><use href="#i-logout"/></svg></button>
            </form>
        </div>

        <button type="button" class="rail-up" id="railUp" title="Upload files" aria-label="Upload files"><svg class="i"><use href="#i-upload"/></svg></button>

        <form class="upload" action="files.php" method="POST" enctype="multipart/form-data" id="uploadForm">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <h2>Upload files</h2>
            <label class="dropzone" id="dropzone">
                <svg class="i"><use href="#i-upload"/></svg>
                <strong>Choose files</strong>
                <span>or drop them anywhere on this page</span>
                <small>Any file type<?= $maxFile ? " &middot; up to " . formatSize($maxFile) . " each" : "" ?></small>
                <input type="file" name="files[]" id="fileInput" multiple>
            </label>

            <div class="queue hidden" id="queue"></div>
            <div class="qbar hidden" id="queueBar">
                <span id="queueSummary"></span>
                <button type="button" class="btn btn-sm" id="clearBtn">Clear all</button>
            </div>

            <div class="share-up">
                <label for="upVis">Who can see new uploads</label>
                <select name="visibility" id="upVis" class="vis-sel">
                    <option value="all" selected>Everyone</option>
                    <option value="private">Only me (and admin)</option>
                    <option value="custom">Specific people&hellip;</option>
                </select>
                <input type="hidden" name="shares" id="upShares" value="">
                <div class="plist hidden" id="upList"></div>
                <p class="hint-s hidden" id="upHint">Pick at least one person, otherwise the new files stay private.</p>
                <p class="dlg-note hidden" id="upFolderNote"></p>
            </div>

            <button type="submit" name="action" value="upload" class="btn btn-primary" id="uploadBtn">Upload</button>
        </form>

        <div class="lib-box">
            <h2>Library</h2>
            <nav class="groups" id="groupNav" aria-label="File types"></nav>
        </div>

        <div class="usage" id="usage"></div>
    </aside>
    <div class="scrim" id="scrim"></div>

    <!-- ============ Middle panel: search + list ============ -->
    <main class="list-panel">
        <!-- Animated background (GradientWaves). Its colours follow the page theme. -->
        <div class="gradient-waves-container" id="bgWaves" aria-hidden="true"></div>
        <!-- Title, count and view/print tools (swapped for the selection bar while selecting) -->
        <div class="resultbar">
            <div class="lp-title">
                <h2 id="lpTitle">All files</h2>
                <span class="sum" id="sum" aria-live="polite"></span>
            </div>
            <div class="lp-tools">
                <div class="seg" role="group" aria-label="Layout">
                    <button type="button" id="viewList" aria-pressed="true" title="List view" aria-label="List view"><svg class="i"><use href="#i-list"/></svg></button>
                    <button type="button" id="viewGrid" aria-pressed="false" title="Grid view" aria-label="Grid view"><svg class="i"><use href="#i-grid"/></svg></button>
                </div>
                <button type="button" class="btn btn-sm" id="newFolder" title="Create a folder here"><svg class="i"><use href="#i-folder-plus"/></svg><span class="lbl">New folder</span></button>
                <button type="button" class="btn btn-sm hidden" id="selToggle" title="Tick files to print only those (Ctrl+click also works)"><svg class="i"><use href="#i-select"/></svg><span class="lbl">Select</span></button>
                <button type="button" class="btn btn-sm hidden" id="printResults" title="Print the files shown"><svg class="i"><use href="#i-print"/></svg><span class="lbl" id="printResultsText">Print results</span></button>
                <button type="button" class="btn btn-sm" id="printAll" title="Print all files"><svg class="i"><use href="#i-print"/></svg><span class="lbl">Print all</span></button>
            </div>
        </div>

        <div class="topbar">
            <button type="button" class="btn btn-sm menu-btn" id="menuBtn"><svg class="i"><use href="#i-upload"/></svg>Upload</button>
            <div class="search">
                <svg class="i"><use href="#i-search"/></svg>
                <input type="search" id="q" placeholder="Search file names" autocomplete="off" aria-label="Search file names" title="Tip: separate several names with commas. Press / to jump here.">
                <kbd class="kbd" aria-hidden="true">/</kbd>
                <button type="button" class="icon-btn hidden" id="qClear" aria-label="Clear search"><svg class="i"><use href="#i-close"/></svg></button>
            </div>
            <select class="sort" id="sort" aria-label="Sort files">
                <option value="new">Newest</option>
                <option value="old">Oldest</option>
                <option value="name">Name</option>
                <option value="big">Largest</option>
            </select>
            <button type="button" class="acct-top" id="acctTop" aria-label="Account and menu"><?= htmlspecialchars(mb_strtoupper(mb_substr($user["username"], 0, 1, "UTF-8"), "UTF-8")) ?></button>
        </div>

        <div class="chips" id="chips" role="group" aria-label="File types"></div>

        <!-- Shown instead of the title bar while selecting -->
        <div class="selbar hidden" id="selBar">
            <b id="selCount" aria-live="polite">0 selected</b>
            <button type="button" class="btn btn-sm" id="selAll">Select all</button>
            <button type="button" class="btn btn-sm btn-primary" id="printSelected" disabled><svg class="i"><use href="#i-print"/></svg><span id="printSelectedText">Print selected</span></button>
            <button type="button" class="btn btn-sm" id="moveSelected" disabled><svg class="i"><use href="#i-move"/></svg><span>Move to folder</span></button>
            <button type="button" class="btn btn-sm" id="shareSelected" disabled><svg class="i"><use href="#i-share"/></svg><span id="shareSelectedText">Share</span></button>
            <button type="button" class="btn btn-sm btn-danger" id="deleteSelected" disabled><svg class="i"><use href="#i-trash"/></svg><span id="deleteSelectedText">Delete</span></button>
            <button type="button" class="btn btn-sm" id="selDone">Done</button>
        </div>

        <nav class="crumbs hidden" id="crumbs" aria-label="Folder path"></nav>

        <div class="rows" id="rows"></div>
    </main>

    <!-- ============ Right panel: details ============ -->
    <section class="detail" id="detail" aria-label="File details"></section>
</div>

<!-- Mini player: shown while the music player is minimized. The audio itself waits, still playing, inside #auHold. -->
<div id="auHold" hidden></div>
<div class="mini" id="mini" role="region" aria-label="Music player">
    <canvas class="mini-viz" id="miniViz" aria-hidden="true"></canvas>
    <div class="mini-bar"><i></i></div>
    <button type="button" class="mini-art" id="miniArt" aria-label="Open the player"></button>
    <button type="button" class="mini-txt" id="miniTxt" aria-label="Open the player"><b id="miniTitle"></b><small id="miniSub"></small></button>
    <button type="button" class="mini-play" id="miniPlay" aria-label="Play or pause"><svg class="i i-play" aria-hidden="true"><use href="#i-play"/></svg><svg class="i i-pause" aria-hidden="true"><use href="#i-pause"/></svg></button>
    <button type="button" class="icon-btn" id="miniOpen" title="Open the player" aria-label="Open the player"><svg class="i"><use href="#i-expand"/></svg></button>
    <button type="button" class="icon-btn" id="miniClose" title="Stop and close" aria-label="Stop and close the player"><svg class="i"><use href="#i-close"/></svg></button>
</div>

<!-- Preview -->
<div class="modal" id="pv">
    <div class="pv-box" id="pvBox">
        <div class="pv-bar">
            <div class="pv-title" id="pvTitle"></div>
            <a class="icon-btn" id="pvDl" href="#" title="Download" aria-label="Download"><svg class="i"><use href="#i-download"/></svg></a>
            <button type="button" class="icon-btn" id="pvClose" aria-label="Close preview"><svg class="i"><use href="#i-close"/></svg></button>
        </div>
        <div class="pv-body" id="pvBody"></div>
    </div>

    <!-- AI assistant: reads only the file open in this preview, and only after you open the chat and ask something -->
    <button type="button" class="ai-fab" id="aiFab" title="Ask AI about this file" aria-label="Ask AI about this file">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9zM18.5 15l.8 2.2 2.2.8-2.2.8-.8 2.2-.8-2.2-2.2-.8 2.2-.8z"/></svg>
    </button>
    <div class="ai-panel" id="aiPanel" role="dialog" aria-label="AI assistant">
        <div class="ai-head">
            <b>Ask AI</b><span id="aiFile"></span>
            <button type="button" class="icon-btn" id="aiX" aria-label="Close AI chat"><svg class="i"><use href="#i-close"/></svg></button>
        </div>
        <div class="ai-msgs" id="aiMsgs" aria-live="polite"></div>
        <div class="ai-sugg" id="aiSugg"></div>
        <form class="ai-form" id="aiForm" autocomplete="off">
            <input type="text" id="aiInput" maxlength="2000" placeholder="Ask about this file…" aria-label="Your question">
            <button type="submit" class="ai-send" id="aiSend" aria-label="Send"><svg viewBox="0 0 24 24"><path d="M4 12l16-8-6 17-3-7z"/></svg></button>
        </form>
        <p class="ai-note">The AI can read only this file, and only while this chat is open. It can make mistakes.</p>
    </div>
</div>

<!-- Rename / delete dialog -->
<div class="dlg-wrap" id="dlg">
    <form class="dlg" id="dlgForm" autocomplete="off">
        <h3 id="dlgTitle"></h3>
        <p id="dlgMsg"></p>
        <input type="text" id="dlgInput" maxlength="180" aria-label="File name">
        <div class="dlg-err" id="dlgErr" role="alert"></div>
        <div class="dlg-btns">
            <button type="button" class="btn" id="dlgCancel">Cancel</button>
            <button type="submit" class="btn btn-primary" id="dlgOk">OK</button>
        </div>
    </form>
</div>

<!-- Share dialog: everyone / only me / specific people -->
<div class="dlg-wrap" id="shareDlg">
    <form class="dlg dlg-scroll" id="shareForm" autocomplete="off">
        <h3 id="shareTitle">Share file</h3>
        <p id="shareFile"></p>
        <p class="dlg-note hidden" id="shareNote"></p>
        <label class="opt"><input type="radio" name="vis" value="all"><span>Everyone</span></label>
        <label class="opt"><input type="radio" name="vis" value="private"><span>Only me (and admin)</span></label>
        <label class="opt"><input type="radio" name="vis" value="custom"><span>Specific people</span></label>
        <div class="plist hidden" id="shareList"></div>
        <div class="dlg-err" id="shareErr" role="alert"></div>
        <div class="dlg-btns">
            <button type="button" class="btn" id="shareCancel">Cancel</button>
            <button type="submit" class="btn btn-primary">Save</button>
        </div>
    </form>
</div>

<!-- Move to a folder -->
<div class="dlg-wrap" id="moveDlg">
    <form class="dlg dlg-scroll" id="moveForm" autocomplete="off">
        <h3 id="moveTitle">Move to folder</h3>
        <p id="moveWhat"></p>
        <div class="plist mvlist" id="moveList"></div>
        <p class="dlg-note" id="moveNote"></p>
        <div class="dlg-err" id="moveErr" role="alert"></div>
        <div class="dlg-btns">
            <button type="button" class="btn" id="moveCancel">Cancel</button>
            <button type="submit" class="btn btn-primary" id="moveOk">Move here</button>
        </div>
    </form>
</div>

<!-- Delete a folder -->
<div class="dlg-wrap" id="fdelDlg">
    <form class="dlg" id="fdelForm" autocomplete="off">
        <h3 id="fdelTitle">Delete folder</h3>
        <p id="fdelMsg"></p>
        <div id="fdelOpts">
            <label class="opt"><input type="radio" name="fdel" value="up" checked><span id="fdelUp"></span></label>
            <label class="opt"><input type="radio" name="fdel" value="all"><span>Delete the folder and everything inside it</span></label>
        </div>
        <div class="dlg-err" id="fdelErr" role="alert"></div>
        <div class="dlg-btns">
            <button type="button" class="btn" id="fdelCancel">Cancel</button>
            <button type="submit" class="btn btn-primary danger" id="fdelOk">Delete</button>
        </div>
    </form>
</div>

<!-- Account dialog: change username / password -->
<div class="dlg-wrap" id="acctDlg">
    <div class="dlg dlg-scroll">
        <div class="acct-main">
        <h3>Account</h3>
        <p id="acctWho"></p>

        <div class="avsec">
            <span class="avbig" id="avPrev" aria-hidden="true"></span>
            <div class="avtxt">
                <h4>Profile picture</h4>
                <p>Only you can see it. Nobody else can open it, not even the admin. It is deleted if the admin resets your password or removes your account.</p>
                <div class="avbtns">
                    <button type="button" class="btn btn-sm" id="avPick">Choose picture</button>
                    <button type="button" class="btn btn-sm btn-danger hidden" id="avRemove">Remove</button>
                </div>
                <input type="file" id="avFile" accept="image/jpeg,image/png,image/gif,image/webp" hidden>
                <div class="dlg-err" id="avErr" role="alert"></div>
            </div>
        </div>

        <form id="unameForm" autocomplete="off">
            <h4>Change username</h4>
            <label class="fl" for="newName">New username</label>
            <input type="text" id="newName" maxlength="32" autocomplete="off" autocapitalize="none" spellcheck="false" required>
            <label class="fl" for="unamePw">Your password</label>
            <input type="password" id="unamePw" maxlength="200" autocomplete="current-password" required>
            <div class="dlg-err" id="unameErr" role="alert"></div>
            <div class="dlg-btns"><button type="submit" class="btn btn-primary">Change username</button></div>
        </form>

        <form id="pwForm" autocomplete="off">
            <h4>Change password</h4>
            <label class="fl" for="pwCur">Current password</label>
            <input type="password" id="pwCur" maxlength="200" autocomplete="current-password" required>
            <label class="fl" for="pwNew">New password (6 to 72 characters)</label>
            <input type="password" id="pwNew" minlength="6" maxlength="72" autocomplete="new-password" required>
            <label class="fl" for="pwNew2">Confirm new password</label>
            <input type="password" id="pwNew2" minlength="6" maxlength="72" autocomplete="new-password" required>
            <div class="dlg-err" id="pwErr" role="alert"></div>
            <div class="dlg-btns"><button type="submit" class="btn btn-primary">Change password</button></div>
        </form>

        <div class="danger-zone" id="delSec">
            <h4>Delete account</h4>
            <p>Permanently delete your account. You will choose what happens to the files you uploaded.</p>
            <div class="dlg-btns"><button type="button" class="btn btn-danger" id="delAcctBtn">Delete my account…</button></div>
        </div>

        <div class="dlg-btns"><button type="button" class="btn" id="acctClose">Close</button></div>
        </div>
        <aside class="acct-card" id="lanyardBox" aria-label="Your profile card"></aside>
    </div>
</div>

<!-- Account menu (phones and tablets: the side panel is hidden there) -->
<div class="dlg-wrap sheet" id="menuDlg">
    <div class="dlg dlg-scroll">
        <div class="menu-head">
            <span class="av" id="menuAv"></span>
            <div class="who"><b id="menuName"></b><small><?= $user["is_admin"] ? "Admin account" : "Signed in" ?></small></div>
        </div>
        <button type="button" class="menu-item" id="menuAcct"><svg class="i"><use href="#i-user"/></svg>Account settings</button>
        <?php if ($user["is_admin"]): ?>
        <button type="button" class="menu-item" id="menuAdmin"><svg class="i"><use href="#i-gear"/></svg>Admin settings</button>
        <button type="button" class="menu-item" id="menuLog"><svg class="i"><use href="#i-eye"/></svg>Activity log</button>
        <?php endif; ?>
        <button type="button" class="menu-item" data-theme-btn><svg class="i"><use href="#i-moon"/></svg><span data-tl>Switch theme</span></button>
        <form method="post" action="login.php">
            <input type="hidden" name="action" value="logout">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <button type="submit" class="menu-item out"><svg class="i"><use href="#i-logout"/></svg>Log out</button>
        </form>
        <div class="dlg-btns"><button type="button" class="btn" id="menuClose">Close</button></div>
    </div>
</div>

<!-- Admin: manage accounts + activity log -->
<div class="dlg-wrap" id="adminDlg">
    <div class="dlg dlg-scroll">
        <h3>Admin</h3>
        <div class="adm-tabs" id="adminTabs" role="tablist">
            <button type="button" role="tab" data-tab="users" aria-selected="true">Accounts</button>
            <button type="button" role="tab" data-tab="ai" aria-selected="false">AI usage</button>
            <button type="button" role="tab" data-tab="downloads" aria-selected="false">Downloads</button>
            <button type="button" role="tab" data-tab="logins" aria-selected="false">Sign-ins</button>
            <button type="button" role="tab" data-tab="aiset" aria-selected="false">AI setup</button>
            <button type="button" role="tab" data-tab="dbreport" aria-selected="false">Report</button>
        </div>
        <p id="adminHint"></p>
        <div id="logTools" class="hidden"><button type="button" class="btn btn-danger" id="logClear">Clear this log</button></div>
        <div id="xferBar" class="xfer hidden">
            <div class="xfer-row">
                <button type="button" class="btn" id="xfCsv">Export CSV</button>
                <button type="button" class="btn" id="xfXlsx">Export Excel</button>
                <button type="button" class="btn" id="xfImport">Import CSV / Excel</button>
                <input type="file" id="xfFile" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" hidden>
            </div>
            <label class="xfer-keys hidden" id="xfKeysL"><input type="checkbox" id="xfKeys" checked> Include the API keys in the export</label>
            <div class="xfer-box hidden" id="xfBox">
                <p><b id="xfName"></b></p>
                <label class="fl" for="xfExisting" id="xfExistingL">If it is already there</label>
                <select id="xfExisting">
                    <option value="skip">Keep the one that is already there</option>
                    <option value="replace">Replace it with the one in the file</option>
                </select>
                <div class="xfer-row">
                    <button type="button" class="btn" id="xfCancel">Cancel</button>
                    <button type="button" class="btn btn-primary" id="xfGo">Import</button>
                </div>
            </div>
            <div class="xfer-res hidden" id="xfRes" role="status"></div>
            <details class="xfer-more" id="xfMore"><summary>See more</summary><p class="xfer-note" id="xfNote"></p></details>
        </div>
        <div id="adminList"></div>

        <div id="dbPane" class="hidden">
            <div class="dbr-bar">
                <label for="dbrLimit">Records per table</label>
                <select id="dbrLimit">
                    <option value="25">25</option>
                    <option value="100" selected>100</option>
                    <option value="500">500</option>
                    <option value="2000">Up to 2000</option>
                </select>
                <span class="grow"></span>
                <button type="button" class="btn" id="dbrRefresh">Refresh</button>
                <button type="button" class="btn btn-primary" id="dbrPrint" disabled>Print report</button>
            </div>
            <div id="dbrBody"></div>
        </div>

        <div id="aiPane" class="hidden">
            <div id="aiaList"></div>
            <button type="button" class="btn btn-primary" id="aiaAdd">+ Add an AI</button>

            <form class="aia-form hidden" id="aiaForm" autocomplete="off">
                <label class="fl" for="aiaPreset" id="aiaPresetL">Which AI?</label>
                <select id="aiaPreset"></select>

                <label class="fl" for="aiaName">Name (just for you)</label>
                <input type="text" id="aiaName" maxlength="60" placeholder="e.g. Groq fast">

                <label class="fl" for="aiaKey">API key</label>
                <input type="password" id="aiaKey" maxlength="500" autocomplete="new-password" placeholder="Paste the key here">
                <p class="aia-msg" id="aiaKeyNote"></p>

                <label class="fl" for="aiaModel">Model</label>
                <div class="aia-line">
                    <input type="text" id="aiaModel" maxlength="120" list="aiaModels" autocapitalize="none" spellcheck="false" placeholder="Press “Load models”">
                    <button type="button" class="btn" id="aiaLoad">Load models</button>
                </div>
                <datalist id="aiaModels"></datalist>
                <p class="aia-msg" id="aiaModelNote"></p>

                <details>
                    <summary class="aia-msg" style="cursor:pointer">Advanced (type and web address)</summary>
                    <label class="fl" for="aiaKind">Type</label>
                    <select id="aiaKind"></select>
                    <label class="fl" for="aiaBase">Web address</label>
                    <input type="text" id="aiaBase" maxlength="255" autocapitalize="none" spellcheck="false" placeholder="https://…">
                </details>

                <div class="dlg-err" id="aiaErr" role="alert"></div>
                <div class="dlg-btns">
                    <button type="button" class="btn" id="aiaCancel">Cancel</button>
                    <button type="button" class="btn" id="aiaTest">Test</button>
                    <button type="submit" class="btn btn-primary" id="aiaSave">Save</button>
                </div>
            </form>

            <p class="aia-note" id="aiaNote"></p>
        </div>
        <div class="dlg-err" id="adminErr" role="alert"></div>
        <div class="dlg-btns"><button type="button" class="btn" id="adminClose">Close</button></div>
    </div>
</div>

<!-- Delete account (my own, or one picked by the admin) -->
<div class="dlg-wrap" id="delDlg">
    <form class="dlg dlg-scroll" id="delForm" autocomplete="off">
        <h3 id="delTitle">Delete account</h3>
        <p id="delMsg"></p>
        <label class="opt"><input type="radio" name="delfiles" value="1"><span>Delete the account <b>and</b> the files it uploaded</span></label>
        <label class="opt"><input type="radio" name="delfiles" value="0" checked><span>Delete the account only, <b>keep</b> the uploaded files</span></label>
        <p class="hint-s" id="delKeepNote">Kept files stay on the site and are handed over to the admin.</p>
        <div id="delPwBox">
            <label class="fl" for="delPw">Your password</label>
            <input type="password" id="delPw" maxlength="200" autocomplete="current-password">
        </div>
        <div class="dlg-err" id="delErr" role="alert"></div>
        <div class="dlg-btns">
            <button type="button" class="btn" id="delCancel">Cancel</button>
            <button type="submit" class="btn btn-primary danger" id="delOk">Delete account</button>
        </div>
    </form>
</div>

<div class="toast" id="toast" role="status"></div>
<div class="drop-overlay" id="dropOverlay">Drop files to add them to the upload list</div>
<div id="printArea"></div>

<script src="gradientwaves.js"></script>
<script>
/* ============================================================
   Data from the server
   ============================================================ */
const BOOT       = <?= json_encode(["files" => $files, "folders" => $folders, "v" => $version, "flash" => $flash, "me" => $me, "csrf" => $csrf, "users" => $others], $J) ?>;
const TYPE_OF    = <?= json_encode(ftTypeMap(), $J) ?>;
const ICON_SVG   = <?= json_encode(ftAllIcons(), $J) ?>;
const TYPE_COLOR = <?= json_encode($typeColors, $J) ?>;
const MAX_FILE   = <?= (int)$maxFile ?>;   // bytes, 0 = unlimited
const ME         = BOOT.me;                 // { id, name, admin }
const CSRF       = BOOT.csrf;               // secret token sent with every change

const TYPE_LABEL = {
    image: "Image", vector: "Vector", design: "Design", "3d": "3D / CAD", video: "Video", audio: "Audio",
    pdf: "PDF", word: "Document", excel: "Spreadsheet", ppt: "Presentation", archive: "Archive",
    code: "Code", data: "Data", text: "Text", ebook: "E-book", font: "Font", db: "Database",
    exe: "Program", package: "Package", disk: "Disk image", "default": "File"
};

const GROUPS = [
    { id: "all",      label: "All files",         color: "#7b96ff", types: null },
    { id: "images",   label: "Images",            color: "#42d6a4", types: ["image", "vector", "design"] },
    { id: "video",    label: "Video",             color: "#f472b6", types: ["video"] },
    { id: "audio",    label: "Audio",             color: "#b58cff", types: ["audio"] },
    { id: "docs",     label: "Documents",         color: "#5b9dff", types: ["pdf", "word", "excel", "ppt", "text", "ebook"] },
    { id: "code",     label: "Code and data",     color: "#4dd0e1", types: ["code", "data", "db"] },
    { id: "archives", label: "Archives and apps", color: "#ffc44d", types: ["archive", "package", "disk", "exe"] },
    { id: "other",    label: "Other",             color: "#8b92a3", types: ["3d", "font", "default"] }
];

const SORTS = {
    "new":  (a, b) => b.id - a.id,
    "old":  (a, b) => a.id - b.id,
    "name": (a, b) => a.name.localeCompare(b.name, undefined, { numeric: true, sensitivity: "base" }),
    "big":  (a, b) => b.size - a.size
};

/* ============================================================
   Helpers
   ============================================================ */
const $ = id => document.getElementById(id);
const esc = s => String(s).replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
const ic = (name, cls) => '<svg class="i ' + (cls || "") + '" aria-hidden="true"><use href="#i-' + name + '"/></svg>';

function fmt(bytes) {
    if (bytes < 1024) return bytes + " B";
    const u = ["KB", "MB", "GB", "TB"];
    let i = -1;
    do { bytes /= 1024; i++; } while (bytes >= 1024 && i < u.length - 1);
    return bytes.toFixed(bytes >= 10 ? 0 : 1) + " " + u[i];
}
function fmtTime(s) {
    if (!isFinite(s) || s < 0) s = 0;
    s = Math.floor(s);
    const h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60), sec = s % 60;
    return (h ? h + ":" + String(m).padStart(2, "0") : m) + ":" + String(sec).padStart(2, "0");
}
function parseDate(s) {
    if (!s) return null;
    const d = new Date(String(s).replace(" ", "T"));
    return isNaN(d) ? null : d;
}
const fmtDay = d => d.toLocaleDateString(undefined, { month: "short", day: "numeric", year: "numeric" });
const fmtDT  = d => fmtDay(d) + ", " + d.toLocaleTimeString(undefined, { hour: "numeric", minute: "2-digit" });

function extLabel(ext) { return (ext || "").replace(/[^a-z0-9]/gi, "").slice(0, 4).toUpperCase() || "FILE"; }
function iconByExt(ext) {
    const type = typeOfExt(ext);
    return ICON_SVG[type].replace("%L%", extLabel(ext));
}
const iconFor = f => iconByExt(f.ext);
const typeText = f => {
    const label = TYPE_LABEL[f.type] || "File";
    return f.ext && f.ext.toUpperCase() !== label.toUpperCase() ? label + " (" + f.ext.toUpperCase() + ")" : label;
};
const typeOfExt = ext => Object.prototype.hasOwnProperty.call(TYPE_OF, ext) ? TYPE_OF[ext] : "default";
const groupOf = type => (GROUPS.find(g => g.types && g.types.includes(type)) || GROUPS[GROUPS.length - 1]).id;

let toastTimer = null;
function toast(msg) {
    const t = $("toast");
    t.textContent = msg;
    t.classList.add("on");
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => t.classList.remove("on"), 3200);
}

async function post(data) {
    const r = await fetch("files.php", {
        method: "POST",
        headers: { "X-Requested-With": "XMLHttpRequest", "X-CSRF-Token": CSRF },
        body: new URLSearchParams(data)
    });
    if (r.status === 401) { location.href = "login.php"; throw new Error("Signed out"); }   // password changed elsewhere, or session ended
    let d = null;
    try { d = await r.json(); } catch (e) {}
    if (!d) throw new Error("Bad response");
    return d;
}

/* ============================================================
   State
   ============================================================ */
const state = {
    files: BOOT.files,
    folders: BOOT.folders || [],   // folders this person may see: { id, name, parent, owner_id, owner, mine, can, vis, shared, files, subs }
    folder: 0,           // the folder that is open (0 = top level)
    users: BOOT.users,   // accounts for the share menus (everybody except me)
    v: BOOT.v,
    q: "",
    group: "all",
    sort: "new",
    selected: null,
    sheet: false,        // details open as a full-screen sheet (phones)
    fresh: new Set(),    // ids that just arrived (highlighted once)
    pick: false,         // select mode (tick files to print)
    picked: new Set(),   // ids ticked in select mode
    lastPick: null       // last ticked id, for Shift+click ranges
};
const byId = id => state.files.find(f => f.id === id) || null;
const folderById = id => state.folders.find(d => d.id === id) || null;

/* "Photos / Holiday / Day 1" */
function folderPath(d) {
    const names = [];
    let cur = d, n = 0;
    while (cur && n++ < 12) { names.unshift(cur.name); cur = cur.parent ? folderById(cur.parent) : null; }
    return names.join(" / ");
}
/* is folder `id` the folder `ancestor` or somewhere inside it? */
function folderInside(id, ancestor) {
    let cur = folderById(id), n = 0;
    while (cur && n++ < 12) { if (cur.id === ancestor) return true; cur = cur.parent ? folderById(cur.parent) : null; }
    return false;
}

/* Who can see a file, in words (and the icon that goes with it) */
const userName = id => { const u = state.users.find(x => x.id === id); return u ? u.name : "?"; };
function visText(f) {
    if (f.vis === "all") return "Everyone";
    if (f.vis === "private") return f.mine ? "Only you and admin" : (f.can ? "Only the owner" : "Private");
    if (f.can) return f.shared.length ? f.shared.map(userName).join(", ") : "Selected people";
    return "You and others";
}
const visIcon = f => f.vis === "all" ? "globe" : (f.vis === "private" ? "lock" : "users");

function searchTerms() {
    return state.q.split(",").map(t => t.trim().toLowerCase()).filter(Boolean).map(t => t.split(/\s+/));
}
function visibleFiles() {
    const terms = searchTerms();
    const g = GROUPS.find(x => x.id === state.group) || GROUPS[0];
    const flat = isFiltered();   // searching or filtering by type looks in every folder
    return state.files
        .filter(f => flat || (f.folder_id || 0) === state.folder)
        .filter(f => !g.types || g.types.includes(f.type))
        .filter(f => {
            if (!terms.length) return true;
            const n = f.name.toLowerCase();
            return terms.some(words => words.every(w => n.includes(w)));
        })
        .sort(SORTS[state.sort] || SORTS["new"]);
}
const isFiltered = () => state.q.trim() !== "" || state.group !== "all";

/* Folders shown in the list: the ones inside the open folder, or (while searching) every folder whose name matches */
function visibleFolders() {
    if (state.group !== "all") return [];
    const terms = searchTerms();
    const list = terms.length
        ? state.folders.filter(d => { const n = d.name.toLowerCase(); return terms.some(words => words.every(w => n.includes(w))); })
        : state.folders.filter(d => (d.parent || 0) === state.folder);
    return list.sort((a, b) => a.name.localeCompare(b.name, undefined, { numeric: true, sensitivity: "base" }));
}

/* ============================================================
   Rendering: library, list, summary
   ============================================================ */
function renderGroups() {
    const counts = {}, sizes = {};
    let total = 0;
    state.files.forEach(f => {
        const g = groupOf(f.type);
        counts[g] = (counts[g] || 0) + 1;
        sizes[g] = (sizes[g] || 0) + f.size;
        total += f.size;
    });

    const html = GROUPS.map(g => {
        const n = g.id === "all" ? state.files.length : (counts[g.id] || 0);
        return '<button type="button" class="g' + (state.group === g.id ? " on" : "") + '" data-g="' + g.id + '" title="' + esc(g.label) + ' (' + n + ')" aria-pressed="' + (state.group === g.id) + '">' +
               '<i style="background:' + g.color + '"></i><span>' + esc(g.label) + '</span><b>' + n + '</b></button>';
    }).join("");
    $("groupNav").innerHTML = html;
    $("chips").innerHTML = html;

    const seg = GROUPS.filter(g => g.id !== "all" && sizes[g.id])
        .map(g => '<i title="' + esc(g.label) + '" style="background:' + g.color + ';flex:' + sizes[g.id] + '"></i>').join("");
    $("usage").innerHTML = state.files.length
        ? '<h2>Storage</h2><div class="stack">' + seg + '</div><p>' + fmt(total) + ' in ' + state.files.length + (state.files.length === 1 ? ' file' : ' files') + '</p>'
        : "";
}

const FOLDER_ICON = '<svg class="ficon" viewBox="0 0 44 56" aria-hidden="true"><path d="M5 14a3 3 0 013-3h9l4 5h15a3 3 0 013 3v25a3 3 0 01-3 3H8a3 3 0 01-3-3z" fill="#ffc44d" fill-opacity=".16" stroke="#ffc44d" stroke-width="1.6" stroke-linejoin="round"/><path d="M5 22h34" fill="none" stroke="#ffc44d" stroke-width="1.6"/></svg>';

function folderRowHTML(d) {
    const parts = [];
    parts.push(d.files + (d.files === 1 ? " file" : " files"));
    if (d.subs) parts.push(d.subs + (d.subs === 1 ? " folder" : " folders"));
    const where = isFiltered() && d.parent && folderById(d.parent) ? '<span>in ' + esc(folderPath(folderById(d.parent))) + '</span>' : "";
    const meta = '<span>' + esc(parts.join(", ")) + '</span>' + where + (d.mine ? "" : '<span class="by">by ' + esc(d.owner) + '</span>');
    const btn = (act, icon, label, extra) => '<button type="button" class="icon-btn ' + (extra || "") + '" data-act="' + act + '" title="' + label + '" aria-label="' + label + ' ' + esc(d.name) + '">' + ic(icon) + '</button>';
    return '<div class="row frow" data-fid="' + d.id + '" style="--c:#ffc44d" tabindex="0">' +
        FOLDER_ICON +
        '<div class="rinfo">' +
            '<div class="rname"><span class="rtext" title="' + esc(d.name) + '">' + esc(d.name) + '</span>' +
            '<span class="vbadge" title="Visible to: ' + esc(visText(d)) + '">' + ic(visIcon(d)) + '</span>' +
            (d.can ? btn("frename", "pencil", "Rename") : "") + '</div>' +
            '<div class="rmeta">' + meta + '</div>' +
        '</div>' +
        '<div class="ractions">' +
            (d.can ? btn("fshare", "share", "Share folder", "hide-s") + btn("fmove", "move", "Move folder", "hide-s") + btn("fdelete", "trash", "Delete folder", "danger hide-s") : "") +
        '</div></div>';
}

function rowHTML(f) {
    const d = parseDate(f.added);
    const home = isFiltered() && f.folder_id && folderById(f.folder_id) ? '<span>in ' + esc(folderPath(folderById(f.folder_id))) + '</span>' : "";
    const meta = '<span>' + esc(fmt(f.size)) + '</span>' + (d ? '<span>' + esc(fmtDay(d)) + '</span>' : "") + home + (f.mine ? "" : '<span class="by">by ' + esc(f.owner) + '</span>');
    const eye = f.preview
        ? '<button type="button" class="icon-btn" data-act="preview" title="Preview" aria-label="Preview ' + esc(f.name) + '">' + ic("eye") + '</button>' : "";
    const on = state.picked.has(f.id);
    return '<div class="row' + (state.selected === f.id ? " sel" : "") + (state.fresh.has(f.id) ? " fresh" : "") + (on ? " picked" : "") +
        '" data-id="' + f.id + '" style="--c:' + (TYPE_COLOR[f.type] || "#8b92a3") + '" tabindex="0"' + (f.can && !state.pick ? ' draggable="true"' : "") +
        (state.pick ? ' role="checkbox" aria-checked="' + on + '"' : "") + '>' +
        '<span class="pk" aria-hidden="true">' + ic("check") + '</span>' +
        iconFor(f) +
        '<div class="rinfo">' +
            '<div class="rname"><span class="rtext" title="' + esc(f.name) + '">' + esc(f.name) + '</span>' +
            '<span class="vbadge" title="Visible to: ' + esc(visText(f)) + '">' + ic(visIcon(f)) + '</span>' +
            (f.can ? '<button type="button" class="icon-btn" data-act="rename" title="Rename" aria-label="Rename ' + esc(f.name) + '">' + ic("pencil") + '</button>' : "") + '</div>' +
            '<div class="rmeta">' + meta + '</div>' +
            (f.notes ? '<div class="rnote">' + esc(f.notes.replace(/\s+/g, " ")) + '</div>' : "") +
        '</div>' +
        '<div class="ractions">' + eye +
            (f.can ? '<button type="button" class="icon-btn hide-s" data-act="move" title="Move to folder" aria-label="Move ' + esc(f.name) + ' to a folder">' + ic("move") + '</button>' : "") +
            (f.can ? '<button type="button" class="icon-btn hide-s" data-act="share" title="Share" aria-label="Share ' + esc(f.name) + '">' + ic("share") + '</button>' : "") +
            '<a class="icon-btn hide-s" href="files.php?action=download&id=' + f.id + '" title="Download" aria-label="Download ' + esc(f.name) + '">' + ic("download") + '</a>' +
            (f.can ? '<button type="button" class="icon-btn danger hide-s" data-act="delete" title="Delete" aria-label="Delete ' + esc(f.name) + '">' + ic("trash") + '</button>' : "") +
        '</div></div>';
}

/* "Files > Holiday > Day 1" above the list while a folder is open */
function renderCrumbs() {
    const el = $("crumbs");
    const cur = folderById(state.folder);
    if (!cur || isFiltered()) { el.classList.add("hidden"); el.innerHTML = ""; return; }
    const chain = [];
    let d = cur, n = 0;
    while (d && n++ < 12) { chain.unshift(d); d = d.parent ? folderById(d.parent) : null; }
    el.innerHTML = '<button type="button" data-crumb="0">' + ic("folder") + 'Files</button>' +
        chain.map((c, i) => '<span class="sep">\u203A</span>' + (i === chain.length - 1
            ? '<b>' + esc(c.name) + '</b>'
            : '<button type="button" data-crumb="' + c.id + '">' + esc(c.name) + '</button>')).join("");
    el.classList.remove("hidden");
}

/* Uploads go into the open folder when it is one of mine, otherwise to the top level */
function uploadTarget() {
    const d = folderById(state.folder);
    return d && d.mine ? d.id : 0;
}
function syncUploadTarget() {
    const d = folderById(state.folder);
    const target = uploadTarget();
    const note = $("upFolderNote");
    $("upVis").disabled = target > 0;
    if (target > 0) {
        note.textContent = "New uploads go into \u201C" + d.name + "\u201D and follow that folder\u2019s sharing.";
        note.classList.remove("hidden");
    } else if (d) {
        note.textContent = "This folder belongs to " + d.owner + ", so new uploads go to your top level.";
        note.classList.remove("hidden");
    } else {
        note.classList.add("hidden");
    }
    if (target > 0) { $("upList").classList.add("hidden"); $("upHint").classList.add("hidden"); } else syncUpload();
}

function openFolder(id) {
    if (state.pick) setPickMode(false);
    state.folder = id;
    state.q = "";
    state.group = "all";
    $("q").value = "";
    renderAll();
    $("rows").scrollTop = 0;
}

function renderRows() {
    if (state.folder && !folderById(state.folder)) state.folder = 0;
    const list = visibleFiles();
    const sub = visibleFolders();
    const total = state.files.length;
    const el = $("rows");
    const inFolder = state.folder > 0 && !isFiltered();

    if (!total && !state.folders.length) {
        el.innerHTML = '<div class="empty">' + ic("folder", "i-lg") + '<h3>No files yet</h3><p>Choose files or drop them anywhere on this page to add your first one, or create a folder to keep things tidy.</p><button type="button" class="btn btn-primary" data-act="upload">' + ic("upload") + 'Upload files</button></div>';
    } else if (!list.length && !sub.length) {
        if (inFolder) {
            const d = folderById(state.folder);
            el.innerHTML = '<div class="empty">' + ic("folder", "i-lg") + '<h3>This folder is empty</h3><p>' + (d && d.mine ? 'Upload files here, or drag files from the list onto this folder\u2019s name in the path above.' : 'Nothing in here yet.') + '</p>' + (d && d.mine ? '<button type="button" class="btn btn-primary" data-act="upload">' + ic("upload") + 'Upload files</button>' : "") + '</div>';
        } else {
            el.innerHTML = '<div class="empty">' + ic("search", "i-lg") + '<h3>Nothing matches</h3><p>Try a different name, or look in another file type.</p><button type="button" class="btn btn-sm" data-act="reset">Clear search and filter</button></div>';
        }
    } else {
        // three tidy sections: my folders, my files, and everything that belongs to someone else
        const head = (title, icon, n) => '<div class="sec">' + ic(icon) + '<span>' + title + '</span><b>' + n + '</b></div>';
        const myFolders = sub.filter(d => d.mine),  otherFolders = sub.filter(d => !d.mine);
        const myFiles   = list.filter(f => f.mine), otherFiles   = list.filter(f => !f.mine);
        let html = "";
        if (myFolders.length) html += head("Folders", "folder", myFolders.length) + myFolders.map(folderRowHTML).join("");
        if (myFiles.length)   html += head("Your Files", "user", myFiles.length) + myFiles.map(rowHTML).join("");
        if (otherFolders.length || otherFiles.length) {
            html += head("Shared With You", "users", otherFolders.length + otherFiles.length) +
                    otherFolders.map(folderRowHTML).join("") + otherFiles.map(rowHTML).join("");
        }
        el.innerHTML = html;
    }
    state.fresh = new Set();
    renderCrumbs();
    syncUploadTarget();

    // summary + print buttons
    const q = state.q.trim();
    const g = GROUPS.find(x => x.id === state.group);
    const plural = (n, one, many) => n + (n === 1 ? one : many);
    let text = plural(list.length, " file", " files");
    if (sub.length) text += ", " + plural(sub.length, " folder", " folders");
    if (isFiltered()) {
        text = list.length + " of " + total + (total === 1 ? " file" : " files");
        if (sub.length) text += " and " + plural(sub.length, " folder", " folders");
        if (q) text += " matching \u201C" + q + "\u201D";
        if (state.group !== "all") text += " in " + g.label.toLowerCase();
    }
    $("sum").textContent = text;
    $("lpTitle").textContent = inFolder ? folderById(state.folder).name : (state.group === "all" ? "All files" : g.label);
    $("printResults").classList.toggle("hidden", !(isFiltered() && list.length));
    $("printResultsText").textContent = "Print results (" + list.length + ")";
    $("printAll").classList.toggle("hidden", !total);
    $("qClear").classList.toggle("hidden", q === "");
    $("selToggle").classList.toggle("hidden", !total || state.pick);

    // select mode swaps the summary bar for the selection bar
    $("rows").classList.toggle("pickmode", state.pick);
    document.querySelector(".resultbar").classList.toggle("hidden", state.pick);
    $("selBar").classList.toggle("hidden", !state.pick);
    updateSelBar();
}

/* ============================================================
   Select mode: tick files, print only those
   ============================================================ */
function setPickMode(on) {
    state.pick = on;
    if (!on) { state.picked.clear(); state.lastPick = null; }
    renderRows();
}

/* the ticked files this person may share / delete (their own; the admin can manage everyone's) */
const pickedManageable = () => state.files.filter(f => state.picked.has(f.id) && f.can);

function updateSelBar() {
    const n = state.picked.size;
    const shown = visibleFiles();
    const allOn = shown.length > 0 && shown.every(f => state.picked.has(f.id));
    $("selCount").textContent = n ? n + (n === 1 ? " file selected" : " files selected") : "Tick the files you want";
    $("selAll").textContent = allOn ? "Deselect all" : (isFiltered() ? "Select all shown" : "Select all");
    $("selAll").disabled = !shown.length;
    $("printSelected").disabled = n === 0;
    $("moveSelected").disabled = n === 0;
    const mine = pickedManageable().length;
    $("shareSelected").disabled = mine === 0;
    $("deleteSelected").disabled = mine === 0;
    $("shareSelectedText").textContent = mine ? "Share (" + mine + ")" : "Share";
    $("deleteSelectedText").textContent = mine ? "Delete (" + mine + ")" : "Delete";
    $("printSelectedText").textContent = n ? "Print selected (" + n + ")" : "Print selected";
}

function paintPicks() {
    document.querySelectorAll("#rows .row[data-id]").forEach(r => {
        const on = state.picked.has(+r.dataset.id);
        r.classList.toggle("picked", on);
        r.setAttribute("aria-checked", on);
    });
    updateSelBar();
}

/* Click = flip one file. Shift+click = tick everything between the last click and this one. */
function togglePick(id, range) {
    if (range && state.lastPick !== null && state.lastPick !== id) {
        const ids = visibleFiles().map(f => f.id);
        const a = ids.indexOf(state.lastPick), b = ids.indexOf(id);
        if (a >= 0 && b >= 0) {
            for (let i = Math.min(a, b); i <= Math.max(a, b); i++) state.picked.add(ids[i]);
            state.lastPick = id;
            paintPicks();
            return;
        }
    }
    if (state.picked.has(id)) state.picked.delete(id); else state.picked.add(id);
    state.lastPick = id;
    paintPicks();
}

function pickAllShown() {
    const ids = visibleFiles().map(f => f.id);
    const allOn = ids.length > 0 && ids.every(id => state.picked.has(id));
    ids.forEach(id => allOn ? state.picked.delete(id) : state.picked.add(id));
    paintPicks();
}

function renderAll() {
    renderGroups();
    renderRows();
}

/* ============================================================
   Selection + detail panel
   ============================================================ */
let noteDirty = false, noteTimer = null, detailSig = "", dlKey = "";

/* Desktop: the details panel is folded away until a file is selected; selecting the same file again folds it back */
let detailShown = false;
function paintDetailPanel() {
    $("app").classList.toggle("no-detail", !(detailShown && byId(state.selected)));
}

function selectFile(id) {
    if (state.selected !== id) flushNotes();
    detailShown = !(state.selected === id && detailShown);
    state.selected = id;
    state.sheet = true;
    paintDetailPanel();
    document.querySelectorAll(".row").forEach(r => r.classList.toggle("sel", +r.dataset.id === id));
    renderDetail();
}

function closeSheet() {
    flushNotes();
    state.sheet = false;
    $("detail").classList.remove("open");
}

function renderDetail() {
    const el = $("detail");
    const f = byId(state.selected);
    noteDirty = false;

    paintDetailPanel();
    if (!f) {
        state.sheet = false;
        detailSig = "";
        el.classList.remove("open");
        el.innerHTML = '<div class="d-empty">' + ic("folder") +
            '<p>Select a file to see its details, add notes, or rename it.</p></div>';
        return;
    }

    detailSig = f.type + "|" + f.preview + "|" + f.can;
    dlKey = "";
    const canPreview = !!f.preview;
    el.innerHTML =
        '<div class="d-top"><button type="button" class="icon-btn" data-act="back" aria-label="Back to files">' + ic("back", "i-lg") + '</button><span>Details</span></div>' +
        '<div class="d-body">' +
            '<div class="d-stage' + (canPreview ? " can" : "") + '" id="dStage"' + (canPreview ? ' data-act="preview" title="Open preview"' : "") + '></div>' +
            '<div class="d-name"><h2 id="dName"></h2>' +
                (f.can ? '<button type="button" class="icon-btn" data-act="rename" title="Rename" aria-label="Rename file">' + ic("pencil") + '</button>' : "") + '</div>' +
            '<dl class="d-info">' +
                '<dt>Type</dt><dd id="dType"></dd><dt>Size</dt><dd id="dSize"></dd>' +
                '<dt>Folder</dt><dd id="dFolder"></dd><dt>Added</dt><dd id="dAdded"></dd><dt>Owner</dt><dd id="dOwner"></dd><dt>Visible to</dt><dd id="dVis"></dd><dt>ID</dt><dd id="dId"></dd></dl>' +
            '<div class="d-notes"><label for="dNotes">Notes</label>' +
                '<textarea id="dNotes" maxlength="900"' + (f.can ? ' placeholder="Write a note about this file"' : ' readonly placeholder="No notes"') + '></textarea>' +
                '<div class="d-nstat"><span id="nStatus"></span><span id="nCount"></span></div></div>' +
            '<div class="d-actions">' +
                (canPreview ? '<button type="button" class="btn btn-primary wide" data-act="preview">' + ic("eye") + 'Preview</button>' : "") +
                '<a class="btn" id="dDl" href="#">' + ic("download") + 'Download</a>' +
                (f.can ? '<button type="button" class="btn" data-act="move">' + ic("move") + 'Move</button>' : "") +
                (f.can ? '<button type="button" class="btn" data-act="share">' + ic("share") + 'Share</button>' : "") +
                (f.can ? '<button type="button" class="btn btn-danger wide" data-act="delete">' + ic("trash") + 'Delete</button>' : "") +
            '</div>' +
            (f.can ? '<div class="d-sec"><h3 id="dlHead">Downloads</h3><div class="dl-list" id="dlList"></div></div>' : "") +
        '</div>';

    fillDetail(f);
    const ta = $("dNotes");
    ta.value = f.notes;
    $("nCount").textContent = ta.value.length + " / 900";
    ta.addEventListener("input", onNotesInput);
    ta.addEventListener("blur", () => saveNotes());

    loadStage(f);
    el.classList.toggle("open", state.sheet);
    el.scrollTop = 0;
}

function fillDetail(f) {
    $("dName").textContent = f.name;
    $("dType").textContent = typeText(f);
    $("dSize").textContent = fmt(f.size);
    const d = parseDate(f.added);
    $("dAdded").textContent = d ? fmtDT(d) : "Unknown";
    $("dId").textContent = "#" + f.id;
    const home = f.folder_id ? folderById(f.folder_id) : null;
    $("dFolder").textContent = home ? folderPath(home) : "Top level";
    $("dOwner").textContent = f.owner + (f.mine ? " (you)" : "");
    $("dVis").textContent = visText(f);
    $("dDl").href = "files.php?action=download&id=" + f.id;
    loadDownloads(f);
}

/* Who downloaded this file and when (only the uploader and the admin see this) */
async function loadDownloads(f) {
    const box = $("dlList");
    if (!box || !f.can) return;
    const key = f.id + ":" + f.dl;
    if (key === dlKey) return;
    dlKey = key;
    if (!box.children.length) box.innerHTML = '<div class="dl-empty">Loading…</div>';
    try {
        const r = await fetch("files.php?action=downloads&id=" + f.id, { headers: { "X-Requested-With": "XMLHttpRequest" }, cache: "no-store" });
        const d = await r.json();
        if (!d.ok || state.selected !== f.id || !$("dlList")) return;
        $("dlHead").textContent = "Downloads" + (d.total ? " (" + d.total + ")" : "");
        $("dlList").innerHTML = d.downloads.length
            ? d.downloads.map(x => {
                const t = parseDate(x.at);
                return '<div class="dl-row"><span>' + esc(x.user) + '</span><span>' + (t ? esc(fmtDT(t)) : "") + '</span></div>';
            }).join("")
            : '<div class="dl-empty">Nobody has downloaded this file yet.</div>';
    } catch (e) { dlKey = ""; }
}

/* The big picture at the top of the details: the image itself, the album cover, or the type icon */
function loadStage(f) {
    const stage = $("dStage");
    const icon = iconFor(f);
    stage.style.setProperty("--c", TYPE_COLOR[f.type] || "#8b92a3");
    stage.innerHTML = icon;

    const badge = (f.preview === "audio" || f.preview === "video")
        ? '<span class="badge">' + ic("play", "i-lg") + '</span>' : "";

    if (f.preview === "image") {
        const im = new Image();
        im.alt = "";
        im.onload = () => { if (state.selected === f.id) stage.innerHTML = ""; stage.appendChild(im); };
        im.src = "preview.php?id=" + f.id;
    } else if (f.preview === "audio") {
        const im = new Image();
        im.alt = "Album cover";
        im.onload = () => { if (state.selected === f.id) { stage.innerHTML = ""; stage.appendChild(im); stage.insertAdjacentHTML("beforeend", badge); } };
        im.src = "preview.php?id=" + f.id + "&cover=1";
    }
    if (badge) stage.insertAdjacentHTML("beforeend", badge);
}

/* Called when the list changed (other device, rename...) without disturbing what the person is typing */
function updateDetailLive(f) {
    if (!$("dName")) return;
    if (detailSig !== f.type + "|" + f.preview + "|" + f.can) {
        const ta = $("dNotes");
        const keep = noteDirty ? ta.value : null;
        const wasDirty = noteDirty;
        renderDetail();
        if (wasDirty) { $("dNotes").value = keep; noteDirty = true; }
        $("detail").classList.toggle("open", state.sheet);
        return;
    }
    fillDetail(f);
    const ta = $("dNotes");
    if (!noteDirty && document.activeElement !== ta && ta.value !== f.notes) {
        ta.value = f.notes;
        $("nCount").textContent = ta.value.length + " / 900";
    }
}

/* ---------- Notes (saved automatically) ---------- */
function setNoteStatus(text, isErr) {
    const s = $("nStatus");
    if (!s) return;
    s.textContent = text;
    s.className = isErr ? "err" : "";
}
function onNotesInput() {
    noteDirty = true;
    $("nCount").textContent = $("dNotes").value.length + " / 900";
    setNoteStatus("Unsaved changes");
    clearTimeout(noteTimer);
    noteTimer = setTimeout(saveNotes, 700);
}
function flushNotes() {
    if (noteDirty) saveNotes();
}
async function saveNotes() {
    clearTimeout(noteTimer);
    const ta = $("dNotes");
    const id = state.selected;
    if (!ta || id === null || !noteDirty) return;

    const text = ta.value;
    setNoteStatus("Saving\u2026");
    try {
        const d = await post({ action: "notes", id: id, notes: text });
        if (!d.ok) throw new Error(d.error || "failed");
        const f = byId(id);
        if (f) f.notes = d.notes;
        if (state.selected === id && $("dNotes") && $("dNotes").value === text) noteDirty = false;
        if (state.selected === id) setNoteStatus("Saved");
        renderRows();
        poll();
    } catch (e) {
        if (state.selected === id) setNoteStatus("Could not save. Trying again\u2026", true);
        noteTimer = setTimeout(saveNotes, 3000);
    }
}

/* ============================================================
   Dialog (rename / delete)
   ============================================================ */
function ask(o) {
    return new Promise(resolve => {
        const dlg = $("dlg"), input = $("dlgInput"), form = $("dlgForm");
        $("dlgTitle").textContent = o.title;
        $("dlgMsg").textContent = o.message || "";
        $("dlgMsg").classList.toggle("hidden", !o.message);
        input.classList.toggle("hidden", o.value === undefined);
        $("dlgErr").textContent = "";
        $("dlgOk").textContent = o.okText || "OK";
        $("dlgOk").classList.toggle("danger", !!o.danger);
        $("dlgOk").disabled = false;
        dlg.classList.add("open");

        if (o.value !== undefined) {
            input.value = o.value;
            input.focus();
            const dot = o.value.lastIndexOf(".");
            input.setSelectionRange(0, dot > 0 ? dot : o.value.length);   // select the name, not the extension
        } else {
            $("dlgOk").focus();
        }

        const done = v => {
            dlg.classList.remove("open");
            form.onsubmit = null;
            $("dlgCancel").onclick = null;
            dlg.onclick = null;
            resolve(v);
        };
        form.onsubmit = async e => {
            e.preventDefault();
            const val = o.value === undefined ? true : input.value;
            $("dlgOk").disabled = true;
            const err = o.validate ? await o.validate(val) : null;
            $("dlgOk").disabled = false;
            if (err) { $("dlgErr").textContent = err; return; }
            done(val);
        };
        $("dlgCancel").onclick = () => done(null);
        dlg.onclick = e => { if (e.target === dlg) done(null); };
    });
}

function renameFile(f) {
    return ask({
        title: "Rename file",
        value: f.name,
        okText: "Save",
        validate: async name => {
            if (!name.trim()) return "Enter a file name.";
            try {
                const d = await post({ action: "rename", id: f.id, name: name });
                if (!d.ok) return d.error || "Could not rename the file.";
                f.name = d.name;
                f.ext = d.name.includes(".") ? d.name.split(".").pop().toLowerCase() : "";
                f.type = typeOfExt(f.ext);
                renderAll();
                updateDetailLive(f);
                toast("Renamed");
                poll();
                return null;
            } catch (e) {
                return "Could not reach the server.";
            }
        }
    });
}

function deleteFile(f) {
    return ask({
        title: "Delete this file?",
        message: "\u201C" + f.name + "\u201D will be permanently deleted from the server.",
        okText: "Delete",
        danger: true,
        validate: async () => {
            try {
                const d = await post({ action: "delete", id: f.id });
                if (!d.ok) return d.error || "Could not delete the file.";
                state.files = state.files.filter(x => x.id !== f.id);
                state.picked.delete(f.id);
                if (mini && mini.f.id === f.id) closeMini();
                if (state.selected === f.id) { state.selected = null; renderDetail(); }
                if (pvId === f.id) closePreview();
                renderAll();
                toast("Deleted");
                poll();
                return null;
            } catch (e) {
                return "Could not reach the server.";
            }
        }
    });
}

function deleteSelected() {
    const list = pickedManageable();
    if (!list.length) return;
    const n = list.length;
    return ask({
        title: "Delete " + (n === 1 ? "this file" : n + " files") + "?",
        message: (n === 1 ? "\u201C" + list[0].name + "\u201D" : "All " + n + " selected files") + " will be permanently deleted from the server.",
        okText: "Delete " + n,
        danger: true,
        validate: async () => {
            let done = 0, firstErr = "";
            for (const f of list) {
                try {
                    const d = await post({ action: "delete", id: f.id });
                    if (!d.ok) { if (!firstErr) firstErr = d.error || "Could not delete the file."; continue; }
                    done++;
                    state.files = state.files.filter(x => x.id !== f.id);
                    state.picked.delete(f.id);
                    if (mini && mini.f.id === f.id) closeMini();
                    if (state.selected === f.id) { state.selected = null; renderDetail(); }
                    if (pvId === f.id) closePreview();
                } catch (e) { if (!firstErr) firstErr = "Could not reach the server."; }
            }
            renderAll();
            poll();
            if (done < n) {
                if (!done) return firstErr;
                toast("Deleted " + done + " of " + n + " files. " + firstErr);
                return null;
            }
            toast(done === 1 ? "Deleted" : done + " files deleted");
            return null;
        }
    });
}

/* ============================================================
   Printing
   ============================================================ */
function printTable(list, title, filterText) {
    const rows = list.map(f => {
        const d = parseDate(f.added);
        return "<tr><td>" + f.id + "</td><td>" + esc(f.name) + "</td><td>" + esc(typeText(f)) + "</td><td>" +
               fmt(f.size) + "</td><td>" + (d ? esc(fmtDT(d)) : "") + '</td><td class="notes">' + esc(f.notes) + "</td></tr>";
    }).join("");

    $("printArea").innerHTML =
        "<h1>" + esc(title) + "</h1>" +
        "<p>" + list.length + (list.length === 1 ? " file" : " files") + (filterText ? " \u00B7 " + esc(filterText) : "") +
        " \u00B7 Printed " + esc(fmtDT(new Date())) + "</p>" +
        '<table><colgroup><col style="width:7%"><col style="width:27%"><col style="width:15%"><col style="width:9%"><col style="width:17%"><col style="width:25%"></colgroup>' +
        "<thead><tr><th>ID</th><th>Name</th><th>Type</th><th>Size</th><th>Date added</th><th>Notes</th></tr></thead><tbody>" + rows + "</tbody></table>";
    window.print();
}

$("printResults").addEventListener("click", () => {
    const parts = [];
    if (state.q.trim()) parts.push("Search: \u201C" + state.q.trim() + "\u201D");
    if (state.group !== "all") parts.push("Type: " + GROUPS.find(g => g.id === state.group).label);
    printTable(visibleFiles(), "Search results", parts.join(" \u00B7 "));
});
$("printSelected").addEventListener("click", () => {
    const list = state.files.filter(f => state.picked.has(f.id)).sort(SORTS[state.sort] || SORTS["new"]);
    if (list.length) printTable(list, "Selected files", "");
});
$("shareSelected").addEventListener("click", shareSelected);
$("deleteSelected").addEventListener("click", deleteSelected);
$("selToggle").addEventListener("click", () => setPickMode(true));
$("selDone").addEventListener("click", () => setPickMode(false));
$("selAll").addEventListener("click", pickAllShown);
$("printAll").addEventListener("click", () => {
    printTable(state.files.slice().sort(SORTS["new"]), "All files", "");
});
window.addEventListener("afterprint", () => { $("printArea").innerHTML = ""; });

/* ============================================================
   Preview + players
   ============================================================ */
let pvId = null, player = null, lastVol = 1;
let pvAudio = null;   // the song in the open player: { f, root, m, player, rgb, tinted }
let mini = null;      // the same object while the player is minimized
const PLAYPAUSE = ic("play", "i-play") + ic("pause", "i-pause");

function openPreview(f) {
    if (!f.preview) return;
    if (mini) {
        if (mini.f.id === f.id) { expandMini(); return; }      // the same song is already playing: just bring the player back
        if (f.preview === "audio") closeMini(true);               // one song at a time
        else if (f.preview === "video") mini.m.pause();
    }
    closePreview(f.preview === "audio");
    pvId = f.id;
    const url = "preview.php?id=" + f.id;
    const body = $("pvBody");

    $("pvTitle").textContent = f.name;
    $("pvDl").href = "files.php?action=download&id=" + f.id;
    $("pvBox").classList.toggle("is-audio", f.preview === "audio");

    if (f.preview === "image") {
        buildImage(body, url, f.name);
    } else if (f.preview === "video") {
        buildVideo(body, url);
    } else if (f.preview === "audio") {
        buildAudio(body, url, f);
    } else if (f.ext === "pdf") {
        body.innerHTML = '<iframe title="Preview" src="' + url + '"></iframe>';
    } else {
        buildText(body, url);
    }

    $("pv").classList.add("open");
    aiInit(f);
}

/* ---------- Zoom ----------
   Images: mouse wheel / trackpad pinch / two fingers to zoom, drag to move, double-click or double-tap to jump in and out, + and - buttons.
   Text and code: the same buttons (and Ctrl + wheel, or two fingers) make the letters bigger or smaller.
   PDFs use the browser's own viewer, which already zooms. Keys + - 0 work for both. */
let pvZoom = null;

function zoomBar(onOut, onIn, onReset) {
    const bar = document.createElement("div");
    bar.className = "pv-zoom";
    bar.innerHTML = '<button type="button" data-z="out" aria-label="Zoom out" title="Zoom out">−</button>'
                  + '<button type="button" data-z="reset" class="pct" aria-label="Reset zoom" title="Reset">100%</button>'
                  + '<button type="button" data-z="in" aria-label="Zoom in" title="Zoom in">+</button>';
    const fn = { out: onOut, in: onIn, reset: onReset };
    bar.addEventListener("click", e => { const b = e.target.closest("button"); if (b) fn[b.dataset.z](); });
    bar.addEventListener("pointerdown", e => e.stopPropagation());
    bar.addEventListener("wheel", e => e.stopPropagation());
    return bar;
}

function buildImage(body, url, name) {
    body.innerHTML = "";
    const box = document.createElement("div");
    box.className = "zv";
    const img = document.createElement("img");
    img.className = "zv-img";
    img.alt = name;
    img.draggable = false;
    box.appendChild(img);
    body.appendChild(box);

    let s = 1, fit = 1, x = 0, y = 0, iw = 1, ih = 1;
    const bar = zoomBar(() => step(1 / 1.25), () => step(1.25), () => fitIt());
    const pct = bar.querySelector(".pct");
    body.appendChild(bar);

    const W = () => box.clientWidth, H = () => box.clientHeight;
    const atFit = () => s <= fit * 1.01;
    function apply() {
        const w = iw * s, h = ih * s;
        x = w <= W() ? (W() - w) / 2 : Math.min(0, Math.max(W() - w, x));
        y = h <= H() ? (H() - h) / 2 : Math.min(0, Math.max(H() - h, y));
        img.style.transform = "translate(" + x + "px," + y + "px) scale(" + s + ")";
        pct.textContent = Math.round(s * 100) + "%";
        box.classList.toggle("zoomed", !atFit());
    }
    function calcFit() { return Math.min(1, W() / iw, H() / ih) || 1; }
    function fitIt() { fit = calcFit(); s = fit; x = y = 0; apply(); }
    function zoomAt(cx, cy, ns) {
        ns = Math.min(Math.max(ns, fit), Math.max(8, fit * 8));
        x = cx - (cx - x) * ns / s;
        y = cy - (cy - y) * ns / s;
        s = ns;
        apply();
    }
    function step(f) { zoomAt(W() / 2, H() / 2, s * f); }
    const local = e => { const r = box.getBoundingClientRect(); return [e.clientX - r.left, e.clientY - r.top]; };

    img.addEventListener("load", () => {
        iw = img.naturalWidth || 800;
        ih = img.naturalHeight || 600;
        img.style.width = iw + "px";
        img.style.height = ih + "px";
        fitIt();
    });
    img.addEventListener("error", () => {
        body.innerHTML = '<div class="pv-msg">This image can’t be previewed. You can still download it.</div>';
        pvZoom = null;
    });
    img.src = url;

    // wheel / trackpad pinch
    box.addEventListener("wheel", e => {
        e.preventDefault();
        const dy = e.deltaMode === 1 ? e.deltaY * 16 : e.deltaY;
        const [cx, cy] = local(e);
        zoomAt(cx, cy, s * Math.exp(-dy * (e.ctrlKey ? 0.01 : 0.0015)));
    }, { passive: false });

    // drag, two-finger pinch, double tap
    const pts = new Map();
    let lastDist = 0, moved = false, lastTap = 0, startX = 0, startY = 0;
    box.addEventListener("pointerdown", e => {
        if (e.button > 0) return;
        box.setPointerCapture(e.pointerId);
        pts.set(e.pointerId, { x: e.clientX, y: e.clientY });
        if (pts.size === 1) { moved = false; startX = e.clientX; startY = e.clientY; }
        if (pts.size === 2) { const [a, b] = [...pts.values()]; lastDist = Math.hypot(a.x - b.x, a.y - b.y); moved = true; }
    });
    box.addEventListener("pointermove", e => {
        const p = pts.get(e.pointerId);
        if (!p) return;
        const dx = e.clientX - p.x, dy = e.clientY - p.y;
        p.x = e.clientX; p.y = e.clientY;
        if (pts.size === 1) {
            if (Math.hypot(e.clientX - startX, e.clientY - startY) > 6) moved = true;
            if (moved && !atFit()) { x += dx; y += dy; apply(); }
        } else if (pts.size === 2) {
            const [a, b] = [...pts.values()];
            const d = Math.hypot(a.x - b.x, a.y - b.y);
            if (lastDist > 0) {
                const r = box.getBoundingClientRect();
                zoomAt((a.x + b.x) / 2 - r.left, (a.y + b.y) / 2 - r.top, s * d / lastDist);
            }
            lastDist = d;
        }
    });
    const up = e => {
        if (!pts.has(e.pointerId)) return;
        pts.delete(e.pointerId);
        if (pts.size > 0) { lastDist = 0; return; }
        if (e.type === "pointerup" && !moved) {
            const now = Date.now();
            if (now - lastTap < 320) {
                lastTap = 0;
                const [cx, cy] = local(e);
                if (atFit()) zoomAt(cx, cy, Math.max(fit * 3, 1)); else fitIt();
            } else lastTap = now;
        }
    };
    box.addEventListener("pointerup", up);
    box.addEventListener("pointercancel", up);

    // keep it fitted (or inside the frame) when the window turns or resizes
    const ro = new ResizeObserver(() => {
        if (!img.naturalWidth && !img.complete) return;
        const was = atFit();
        fit = calcFit();
        if (was) { s = fit; x = y = 0; } else s = Math.max(s, fit);
        apply();
    });
    ro.observe(box);

    pvZoom = { in: () => step(1.25), out: () => step(1 / 1.25), reset: () => fitIt(), destroy: () => ro.disconnect() };
}

function addTextZoom(body) {
    const pre = body.querySelector(".pv-text");
    if (!pre) return;
    const BASE = 13, MIN = 8, MAX = 40;
    let size = BASE;
    const bar = zoomBar(() => set(size / 1.15), () => set(size * 1.15), () => set(BASE));
    const pct = bar.querySelector(".pct");
    function set(v) {
        size = Math.min(MAX, Math.max(MIN, v));
        pre.style.setProperty("--tz", size + "px");
        pct.textContent = Math.round(size / BASE * 100) + "%";
    }
    set(BASE);
    body.appendChild(bar);

    pre.addEventListener("wheel", e => {
        if (!e.ctrlKey) return;
        e.preventDefault();
        set(size * Math.exp(-e.deltaY * 0.01));
    }, { passive: false });

    let d0 = 0, s0 = BASE;
    const dist = t => Math.hypot(t[0].clientX - t[1].clientX, t[0].clientY - t[1].clientY);
    pre.addEventListener("touchstart", e => { if (e.touches.length === 2) { d0 = dist(e.touches); s0 = size; } }, { passive: true });
    pre.addEventListener("touchmove", e => {
        if (e.touches.length !== 2 || !d0) return;
        e.preventDefault();
        set(s0 * dist(e.touches) / d0);
    }, { passive: false });
    pre.addEventListener("touchend", () => { d0 = 0; }, { passive: true });

    pvZoom = { in: () => set(size * 1.15), out: () => set(size / 1.15), reset: () => set(BASE), destroy() {} };
}

document.addEventListener("keydown", e => {
    if (!pvZoom || !$("pv").classList.contains("open") || e.ctrlKey || e.metaKey || e.altKey) return;
    const t = e.target;
    if (t && (t.tagName === "INPUT" || t.tagName === "TEXTAREA" || t.tagName === "SELECT" || t.isContentEditable)) return;
    if (e.key === "+" || e.key === "=") { e.preventDefault(); pvZoom.in(); }
    else if (e.key === "-" || e.key === "_") { e.preventDefault(); pvZoom.out(); }
    else if (e.key === "0") { e.preventDefault(); pvZoom.reset(); }
});

/* Text, code, logs, csv...: fetched and shown in our own dark viewer. Only the first 1 MB is loaded. */
const TEXT_MAX = 1048576;
function buildText(body, url) {
    const id = pvId;
    body.innerHTML = '<pre class="pv-text dim" tabindex="0">Loading…</pre>';
    addTextZoom(body);

    fetch(url, { headers: { Range: "bytes=0-" + (TEXT_MAX - 1) } }).then(async r => {
        if (!r.ok) throw new Error("HTTP " + r.status);
        const buf = new Uint8Array(await r.arrayBuffer());
        if (pvId !== id) return;                                   // preview was closed or changed meanwhile

        // total size from "Content-Range: bytes 0-1048575/123456" (only present when the file is bigger than the chunk)
        const m = /\/(\d+)\s*$/.exec(r.headers.get("Content-Range") || "");
        const cut = r.status === 206 && m && +m[1] > buf.length;

        let enc = "utf-8";
        if (buf[0] === 0xFF && buf[1] === 0xFE) enc = "utf-16le";
        else if (buf[0] === 0xFE && buf[1] === 0xFF) enc = "utf-16be";
        let text;
        try { text = new TextDecoder(enc, { fatal: true }).decode(buf); }
        catch (e) { text = new TextDecoder(enc === "utf-8" ? "windows-1252" : enc).decode(buf); }
        text = text.replace(/^﻿/, "").replace(/�+$/, "");   // BOM, and a character cut in half at the end

        const pre = body.querySelector(".pv-text");
        if (!pre) return;
        pre.classList.toggle("dim", text === "");
        pre.textContent = text === "" ? "This file is empty." : text;
        if (cut) {
            const note = document.createElement("span");
            note.className = "more";
            note.textContent = "Only the first " + fmt(TEXT_MAX) + " of " + fmt(+m[1]) + " is shown. Download the file to read all of it.";
            pre.appendChild(note);
        }
    }).catch(() => {
        const pre = body.querySelector(".pv-text");
        if (pre && pvId === id) { pre.className = "pv-text dim"; pre.textContent = "This file can’t be previewed. You can still download it."; }
    });
}

/* ============================================================
   Song colours + minimized player
   - When a song plays, the page's colours slowly change to the colour of the cover.
   - Minimize keeps the song playing in a small bar; Close stops it and the colours fade back.
   ============================================================ */
const TH_FALLBACK = [139, 108, 255];          // songs without a cover
const TH_VARS = ["bg0", "bg1", "bg2", "card", "card-2", "line", "line-2", "muted", "accent", "accent-2", "accent-ink", "accent-text", "focus", "edge", "stamp", "tint", "tint-2", "glass"];
let thRgb = null, thTimer = 0;

function rgb2hsl(r, g, b) {
    r /= 255; g /= 255; b /= 255;
    const mx = Math.max(r, g, b), mn = Math.min(r, g, b), l = (mx + mn) / 2, d = mx - mn;
    if (d === 0) return [0, 0, l];
    const s = d / (1 - Math.abs(2 * l - 1));
    let h = mx === r ? ((g - b) / d) % 6 : mx === g ? (b - r) / d + 2 : (r - g) / d + 4;
    h = (h * 60 + 360) % 360;
    return [h, s, l];
}

/* The strongest, most colourful part of the cover (greys, near-black and near-white are ignored) */
function coverColor(img) {
    try {
        const N = 32, c = document.createElement("canvas");
        c.width = c.height = N;
        const x = c.getContext("2d", { willReadFrequently: true });
        x.drawImage(img, 0, 0, N, N);
        const d = x.getImageData(0, 0, N, N).data;
        const bins = Array.from({ length: 12 }, () => ({ w: 0, r: 0, g: 0, b: 0 }));
        let tr = 0, tg = 0, tb = 0, n = 0;
        for (let p = 0; p < d.length; p += 4) {
            if (d[p + 3] < 200) continue;
            const r = d[p], g = d[p + 1], b = d[p + 2];
            tr += r; tg += g; tb += b; n++;
            const [h, s, l] = rgb2hsl(r, g, b);
            if (l < .14 || l > .9 || s < .28) continue;
            const w = s * (1 - Math.abs(l - .5) * 1.3);
            const k = Math.floor(h / 30) % 12;
            bins[k].w += w; bins[k].r += r * w; bins[k].g += g * w; bins[k].b += b * w;
        }
        const best = bins.reduce((a, b) => (b.w > a.w ? b : a));
        if (best.w > 1) return [best.r / best.w, best.g / best.w, best.b / best.w];
        return n ? [tr / n, tg / n, tb / n] : null;
    } catch (e) { return null; }
}

function themeAnimate() {
    const el = document.documentElement;
    el.classList.add("th-anim");
    clearTimeout(thTimer);
    thTimer = setTimeout(() => el.classList.remove("th-anim"), 2300);
}

function themeSet(rgb, instant) {
    thRgb = rgb;
    const [h0, s0] = rgb2hsl(rgb[0], rgb[1], rgb[2]);
    const h = Math.round(h0), s = Math.min(.9, Math.max(.4, s0));
    const light = themeNow() === "light";
    const hsl = (sat, l, a) => "hsl(" + h + " " + Math.round(sat * 100) + "% " + l + "%" + (a === undefined ? "" : " / " + a) + ")";
    const v = light ? {
        "bg0": hsl(s * .5, 88), "bg1": hsl(s * .5, 93), "bg2": hsl(s * .4, 97), "card": hsl(s * .3, 99), "card-2": hsl(s * .45, 91),
        "line": hsl(s * .35, 79), "line-2": hsl(s * .3, 60), "muted": hsl(s * .3, 36),
        "accent": hsl(s, 58), "accent-2": hsl(s, 50), "accent-ink": "#ffffff", "accent-text": hsl(s, 30), "focus": hsl(s, 32),
        "edge": hsl(s * .8, 14), "stamp": hsl(s * .8, 14), "tint": hsl(s, 40, .1), "tint-2": hsl(s, 62, .45), "glass": hsl(s * .3, 99, .93)
    } : {
        "bg0": hsl(s * .55, 6), "bg1": hsl(s * .5, 8.5), "bg2": hsl(s * .5, 11.5), "card": hsl(s * .45, 14.5), "card-2": hsl(s * .42, 19),
        "line": hsl(s * .4, 24), "line-2": hsl(s * .35, 38), "muted": hsl(s * .35, 70),
        "accent": hsl(s, 64), "accent-2": hsl(s, 72), "accent-ink": "#0b0d12", "accent-text": hsl(s, 68), "focus": hsl(s, 68),
        "edge": hsl(s, 64), "stamp": hsl(s * .8, 34), "tint": hsl(s, 64, .14), "tint-2": hsl(s, 64, .32), "glass": hsl(s * .5, 8.5, .9)
    };
    if (!instant) themeAnimate();
    const st = document.documentElement.style;
    TH_VARS.forEach(n => st.setProperty("--" + n, v[n]));
    const meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.content = v["bg0"];
}

function themeClear() {
    thRgb = null;
    themeAnimate();
    const st = document.documentElement.style;
    TH_VARS.forEach(n => st.removeProperty("--" + n));
    themeSync();
}

/* ---------- Music visualizer inside the mini bar: ribbons that follow the music (Web Audio analyser) ---------- */
const VIZ = { ctx: null, data: null, raf: 0, t: 0, last: 0, sm: new Float32Array(48), beat: 0, beatAt: 0, col: [255, 255, 255], colN: 0,
              hi: -40, bHi: -40, bLo: -80, prev: null, flux: 0, fluxNow: 0, bassEnv: 0 };

function vizConnect(m) {
    if (m._viz !== undefined) return m._viz;
    try {
        const AC = window.AudioContext || window.webkitAudioContext;
        if (!AC) throw new Error("no Web Audio");
        VIZ.ctx = VIZ.ctx || new AC();
        const src = VIZ.ctx.createMediaElementSource(m);
        const an = VIZ.ctx.createAnalyser();
        an.fftSize = 2048;                    // about 21 Hz per step: the kick (40 to 120 Hz) is read on its own, apart from voices and chords
        an.smoothingTimeConstant = 0.4;       // low, so a kick shows up at once instead of being smeared
        src.connect(an);
        an.connect(VIZ.ctx.destination);
        VIZ.data = new Float32Array(an.frequencyBinCount);
        m._viz = an;
    } catch (e) { m._viz = null; }          // no analyser: the ribbons just flow on their own
    if (VIZ.ctx && VIZ.ctx.state === "suspended") VIZ.ctx.resume().catch(() => {});
    return m._viz;
}

function vizKick() {
    if (!mini || VIZ.raf) return;
    VIZ.last = 0;
    VIZ.raf = requestAnimationFrame(vizFrame);
}

function vizFrame(ts) {
    VIZ.raf = 0;
    const c = $("miniViz");
    if (!c || !mini) return;
    const m = mini.m, playing = !m.paused && !m.ended;
    const dpr = Math.min(2, window.devicePixelRatio || 1);
    const W = c.clientWidth, H = c.clientHeight;
    if (!W || !H) { VIZ.raf = requestAnimationFrame(vizFrame); return; }
    if (c.width !== Math.round(W * dpr) || c.height !== Math.round(H * dpr)) { c.width = Math.round(W * dpr); c.height = Math.round(H * dpr); }

    const dt = VIZ.last ? Math.min(0.05, (ts - VIZ.last) / 1000) : 0.016;
    VIZ.last = ts;

    // --- what the music is doing right now
    // Spectrum: 48 log-spaced bands from 40 Hz to 14 kHz, in dB, scaled to the loudest part of the song (so quiet and loud masters both move).
    // Bass: 35 to 140 Hz only, scaled against that band's own recent lows and highs; a beat is a sudden rise of it (spectral flux).
    const N = VIZ.sm.length, an = m._viz;
    let target = null, bassN = 0;
    if (playing && an && VIZ.ctx && VIZ.ctx.state === "running") {
        an.getFloatFrequencyData(VIZ.data);
        const D = VIZ.data, hz = VIZ.ctx.sampleRate / an.fftSize;
        const bin = f => Math.min(D.length - 1, Math.max(1, Math.round(f / hz)));
        const db = k => (D[k] > -140 ? D[k] : -140);

        let peak = -140;
        for (let k = bin(40), e = bin(8000); k <= e; k++) peak = Math.max(peak, db(k));
        VIZ.hi = Math.max(peak, VIZ.hi - 6 * dt);
        const floor = VIZ.hi - 56;

        target = new Float32Array(N);
        const f0 = 40, f1 = 14000;
        for (let k = 0; k < N; k++) {
            const lo = bin(f0 * Math.pow(f1 / f0, k / N)), hi = Math.max(lo, bin(f0 * Math.pow(f1 / f0, (k + 1) / N)));
            let mx = -140;
            for (let j = lo; j <= hi; j++) mx = Math.max(mx, db(j));
            target[k] = Math.pow(Math.min(1, Math.max(0, (mx - floor) / 56)), 1.5);
        }

        let bdb = -140;
        for (let k = bin(35), e = bin(140); k <= e; k++) bdb = Math.max(bdb, db(k));
        VIZ.bHi = Math.max(bdb, VIZ.bHi - 4 * dt);
        VIZ.bLo = Math.min(bdb, VIZ.bLo + 3 * dt);
        if (VIZ.bLo > VIZ.bHi - 12) VIZ.bLo = VIZ.bHi - 12;
        bassN = Math.min(1, Math.max(0, (bdb - VIZ.bLo) / (VIZ.bHi - VIZ.bLo)));

        // onset: how much the 35 to 250 Hz bins rose since the last frame, added up in dB. A sustained bass note adds almost nothing,
        // a kick (a quick sweep across those bins) adds a lot, so it works even when the bass is loud all the time.
        const k0 = bin(35), k1 = bin(250), fl = VIZ.hi - 60;
        if (!VIZ.prev || VIZ.prev.length !== k1 - k0 + 1) VIZ.prev = new Float32Array(k1 - k0 + 1).fill(fl);
        let rise = 0;
        for (let k = k0; k <= k1; k++) {
            const v = Math.max(db(k), fl);
            rise += Math.max(0, v - VIZ.prev[k - k0]);
            VIZ.prev[k - k0] = v;
        }
        VIZ.fluxNow = rise;
    } else if (playing) {                                         // no analyser available: a calm made-up pulse
        target = new Float32Array(N);
        const p = (Math.sin(VIZ.t * 5) + 1) / 2;
        for (let k = 0; k < N; k++) target[k] = 0.25 + 0.25 * Math.sin(k * 0.5 + VIZ.t * 2) * p;
        bassN = 0.3 * p;
    }
    let level = 0;
    for (let k = 0; k < N; k++) {
        const tv = target ? target[k] : 0;
        VIZ.sm[k] += (tv - VIZ.sm[k]) * (tv > VIZ.sm[k] ? 0.55 : 0.12);
        level += VIZ.sm[k];
    }
    level /= N;

    // bass envelope: jumps up fast, falls back more slowly
    VIZ.bassEnv += (bassN - VIZ.bassEnv) * (1 - Math.exp(-dt * (bassN > VIZ.bassEnv ? 45 : 8)));
    // beat: the bass onset is clearly bigger than it usually is
    const flux = playing ? VIZ.fluxNow : 0;
    VIZ.flux += (flux - VIZ.flux) * 0.06;
    if (playing && flux > VIZ.flux * 2.2 + 6 && ts - VIZ.beatAt > 130) { VIZ.beat = 1; VIZ.beatAt = ts; }
    VIZ.beat *= Math.pow(0.0006, dt);                             // fades out in about 0.4 s whatever the frame rate
    const pump = Math.min(1, 0.22 * Math.pow(VIZ.bassEnv, 1.6) + 0.78 * VIZ.beat);   // what moves the ribbons with the bass
    VIZ.t += dt * (0.7 + level * 2.2 + pump * 1.6);

    // --- colours follow the page accent (which follows the song's cover)
    if (VIZ.colN++ % 20 === 0) {
        const mm = /rgba?\((\d+)[,\s]+(\d+)[,\s]+(\d+)/.exec(getComputedStyle($("miniPlay")).backgroundColor);
        if (mm) VIZ.col = [+mm[1], +mm[2], +mm[3]];
    }
    const [h0, s0] = rgb2hsl(VIZ.col[0], VIZ.col[1], VIZ.col[2]);
    const sat = Math.round(Math.max(65, s0 * 100));

    // --- draw: three translucent ribbons, added together so they glow where they cross
    const g = c.getContext("2d");
    g.setTransform(dpr, 0, 0, dpr, 0, 0);
    g.clearRect(0, 0, W, H);
    g.globalCompositeOperation = "lighter";
    g.lineJoin = "round";
    const mid = H / 2, TAU = Math.PI * 2;
    const spec = u => { const f = u * (N - 1), a = Math.floor(f), b = Math.min(N - 1, a + 1); return Math.min(1, 1.7 * (VIZ.sm[a] + (VIZ.sm[b] - VIZ.sm[a]) * (f - a))); };

    for (let r = 0; r < 3; r++) {
        const hue = (h0 + (r - 1) * 48 + 360) % 360;
        const col = "hsl(" + hue + " " + sat + "% " + (60 + r * 4) + "%";
        const ph = VIZ.t * (1 + r * 0.45) + r * 2.1;
        const pts = [];
        for (let x = 0; x <= W; x += 4) {
            const u = x / W;
            const env = 0.55 + 0.45 * Math.sin(Math.PI * u);
            const amp = H * 0.34 * (0.06 + 0.42 * spec(u) + 1.05 * pump) * env;
            const y = mid + amp * (0.7 * Math.sin(u * TAU * (1.5 + r * 0.6) + ph) + 0.3 * Math.sin(u * TAU * (3.2 + r) - ph * 1.35));
            pts.push(x, y);
        }
        // soft fill between the ribbon and the centre line
        g.beginPath();
        g.moveTo(0, mid);
        for (let p = 0; p < pts.length; p += 2) g.lineTo(pts[p], pts[p + 1]);
        g.lineTo(W, mid);
        g.closePath();
        g.fillStyle = col + " / " + (0.07 + VIZ.beat * 0.05) + ")";
        g.fill();
        // glowing line
        g.beginPath();
        for (let p = 0; p < pts.length; p += 2) (p ? g.lineTo(pts[p], pts[p + 1]) : g.moveTo(pts[p], pts[p + 1]));
        g.lineWidth = 1.6 + VIZ.beat * 1.2;
        g.shadowBlur = 10;
        g.shadowColor = col + ")";
        g.strokeStyle = col + " / " + (0.5 + VIZ.beat * 0.3) + ")";
        g.stroke();
    }
    g.shadowBlur = 0;
    // fade the left part (cover + title) so the text stays easy to read; the waves are strongest toward the play button
    g.globalCompositeOperation = "destination-in";
    const fade = g.createLinearGradient(0, 0, W, 0);
    fade.addColorStop(0, "rgba(0,0,0,.22)");
    fade.addColorStop(0.4, "rgba(0,0,0,.5)");
    fade.addColorStop(0.72, "rgba(0,0,0,1)");
    fade.addColorStop(1, "rgba(0,0,0,1)");
    g.fillStyle = fade;
    g.fillRect(0, 0, W, H);
    g.globalCompositeOperation = "source-over";

    // keep going while the song plays or the ribbons are still settling
    if (mini && (playing || level > 0.012 || pump > 0.012) && !window.matchMedia("(prefers-reduced-motion: reduce)").matches) VIZ.raf = requestAnimationFrame(vizFrame);
}

function miniPaint() {
    if (!mini) return;
    const m = mini.m, d = m.duration;
    $("mini").classList.toggle("playing", !m.paused && !m.ended);
    $("mini").style.setProperty("--p", (isFinite(d) && d > 0 ? m.currentTime / d * 100 : 0) + "%");
    vizKick();
}

function minimizePreview() {
    if (!pvAudio) return;
    const a = pvAudio;
    aiEnd();
    $("auHold").appendChild(a.root);                 // the <audio> moves with it and keeps playing
    mini = a;
    pvAudio = null;
    player = null;
    pvId = null;
    $("pv").classList.remove("open");
    $("pvBox").classList.remove("is-audio");

    $("miniTitle").textContent = $("auTitle").textContent;
    const artist = $("auArtist").classList.contains("hidden") ? "" : $("auArtist").textContent;
    $("miniSub").textContent = artist;
    $("miniSub").classList.toggle("hidden", !artist);
    const im = $("auArt").querySelector("img");
    $("miniArt").style.backgroundImage = im ? 'url("' + im.src + '")' : "";
    document.body.classList.add("has-mini");
    $("mini").classList.add("on");
    miniPaint();
}

function expandMini() {
    if (!mini) return;
    if ($("pv").classList.contains("open")) closePreview(true);
    const a = mini;
    mini = null;
    $("mini").classList.remove("on");
    document.body.classList.remove("has-mini");

    pvId = a.f.id;
    pvAudio = a;
    player = a.player;
    $("pvTitle").textContent = a.f.name;
    $("pvDl").href = "files.php?action=download&id=" + a.f.id;
    $("pvBody").innerHTML = "";
    $("pvBody").appendChild(a.root);
    $("pvBox").classList.add("is-audio");
    $("pv").classList.add("open");
    aiInit(a.f);
}

function closeMini(keepTheme) {
    if (!mini) return;
    const a = mini;
    mini = null;
    a.m.pause(); a.m.removeAttribute("src"); a.m.load();
    a.root.remove();
    $("mini").classList.remove("on", "playing");
    document.body.classList.remove("has-mini");
    cancelAnimationFrame(VIZ.raf); VIZ.raf = 0;
    if (!keepTheme) themeClear();
}

$("miniPlay").addEventListener("click", () => { if (mini) mini.player.toggle(); });
$("miniOpen").addEventListener("click", expandMini);
$("miniArt").addEventListener("click", expandMini);
$("miniTxt").addEventListener("click", expandMini);
$("miniClose").addEventListener("click", () => closeMini());

function closePreview(keepTheme) {
    aiEnd();
    if (pvAudio) { pvAudio = null; if (!keepTheme) themeClear(); }   // closing the player stops the song and fades the page back to its normal colours
    const body = $("pvBody");
    body.querySelectorAll("video, audio").forEach(m => { m.pause(); m.removeAttribute("src"); m.load(); });
    if (pvZoom) { pvZoom.destroy(); pvZoom = null; }
    body.innerHTML = "";
    player = null;
    pvId = null;
    $("pv").classList.remove("open");
    $("pvBox").classList.remove("is-audio");
}
$("pvClose").addEventListener("click", () => closePreview());
$("pv").addEventListener("click", e => { if (e.target === $("pv")) closePreview(); });

/* Wires up the custom controls inside `root` to the <audio>/<video> element `m` */
function bindPlayer(root, m) {
    const q = r => root.querySelector('[data-r="' + r + '"]');
    const seek = q("seek"), cur = q("cur"), dur = q("dur"), vol = q("vol"), speed = q("speed");
    let scrubbing = false;

    const toggle = () => { if (m.paused) { m.play().catch(() => {}); } else { m.pause(); } };
    const skip = d => {
        const t = m.currentTime + d;
        m.currentTime = Math.max(0, isFinite(m.duration) ? Math.min(m.duration, t) : t);
    };
    const mute = () => { m.muted = !m.muted; };
    const full = () => {
        if (document.fullscreenElement) { document.exitFullscreen(); return; }
        if (root.requestFullscreen) root.requestFullscreen().catch(() => {});
        else if (m.webkitEnterFullscreen) m.webkitEnterFullscreen();    // iPhone Safari
    };

    root.querySelectorAll('[data-r="play"]').forEach(b => b.addEventListener("click", toggle));
    if (q("back")) q("back").addEventListener("click", () => skip(-10));
    if (q("fwd"))  q("fwd").addEventListener("click", () => skip(10));
    if (q("mute")) q("mute").addEventListener("click", mute);
    if (q("full")) q("full").addEventListener("click", full);

    if (speed) {
        const rates = [1, 1.25, 1.5, 2, 0.75];
        let i = 0;
        speed.addEventListener("click", () => {
            i = (i + 1) % rates.length;
            m.playbackRate = rates[i];
            speed.textContent = rates[i] + "\u00D7";
        });
    }

    const paintSeek = () => {
        const d = m.duration;
        if (!scrubbing) seek.value = isFinite(d) && d > 0 ? (m.currentTime / d) * 1000 : 0;
        seek.style.setProperty("--p", (seek.value / 10) + "%");
        cur.textContent = fmtTime(m.currentTime);
        dur.textContent = isFinite(d) ? fmtTime(d) : "--:--";
    };
    seek.addEventListener("input", () => {
        scrubbing = true;
        if (isFinite(m.duration)) m.currentTime = (seek.value / 1000) * m.duration;
        paintSeek();
    });
    seek.addEventListener("change", () => { scrubbing = false; });
    ["timeupdate", "loadedmetadata", "durationchange", "seeked"].forEach(ev => m.addEventListener(ev, paintSeek));

    const paintState = () => root.classList.toggle("playing", !m.paused && !m.ended);
    ["play", "pause", "ended", "playing"].forEach(ev => m.addEventListener(ev, paintState));

    const paintVol = () => {
        const off = m.muted || m.volume === 0;
        root.classList.toggle("muted", off);
        if (vol) {
            vol.value = off ? 0 : m.volume;
            vol.style.setProperty("--p", (vol.value * 100) + "%");
        }
    };
    if (vol) {
        m.volume = lastVol;
        vol.addEventListener("input", () => {
            m.volume = +vol.value;
            m.muted = m.volume === 0;
            if (m.volume > 0) lastVol = m.volume;
        });
    }
    m.addEventListener("volumechange", paintVol);

    paintSeek(); paintVol();
    return { toggle, skip, mute, full, hasFull: !!q("full") };
}

function buildAudio(body, url, f) {
    const base = f.name.replace(/\.[^.]+$/, "");
    body.innerHTML =
        '<div class="au">' +
            '<div class="au-bg" id="auBg"></div>' +
            '<button type="button" class="au-x au-min" id="auMin" aria-label="Minimize player" title="Minimize">' + ic("min") + '</button>' +
            '<button type="button" class="au-x" id="auClose" aria-label="Close player" title="Stop and close">' + ic("close") + '</button>' +
            '<div class="au-card">' +
                '<div class="au-art" id="auArt" style="--c:' + TYPE_COLOR.audio + '">' + iconFor(f) + '</div>' +
                '<div class="au-title" id="auTitle">' + esc(base) + '</div>' +
                '<div class="au-artist hidden" id="auArtist"></div>' +
                '<div class="au-album hidden" id="auAlbum"></div>' +
                '<div class="au-seek"><span class="t" data-r="cur">0:00</span>' +
                    '<input class="rng" data-r="seek" type="range" min="0" max="1000" value="0" aria-label="Seek">' +
                    '<span class="t" data-r="dur" style="text-align:right">0:00</span></div>' +
                '<div class="au-ctl">' +
                    '<button type="button" class="pbtn txt" data-r="speed" title="Playback speed">1\u00D7</button>' +
                    '<button type="button" class="pbtn" data-r="back" title="Back 10 seconds" aria-label="Back 10 seconds">' + ic("back10", "i-lg") + '</button>' +
                    '<button type="button" class="pbtn big" data-r="play" title="Play / pause" aria-label="Play or pause">' + PLAYPAUSE + '</button>' +
                    '<button type="button" class="pbtn" data-r="fwd" title="Forward 10 seconds" aria-label="Forward 10 seconds">' + ic("fwd10", "i-lg") + '</button>' +
                    '<span class="vol-g"><button type="button" class="pbtn" data-r="mute" title="Mute" aria-label="Mute">' +
                        ic("vol-on", "i-vol-on") + ic("vol-off", "i-vol-off") + '</button>' +
                        '<input class="rng vol" data-r="vol" type="range" min="0" max="1" step="0.02" value="1" aria-label="Volume"></span>' +
                '</div>' +
                '<div class="au-err hidden" id="auErr">This audio file can\u2019t be played in your browser. You can still download it.</div>' +
            '</div>' +
            '<audio preload="metadata" src="' + url + '"></audio>' +
        '</div>';

    const root = body.querySelector(".au"), m = body.querySelector("audio");
    player = bindPlayer(root, m);
    const au = pvAudio = { f, root, m, player, rgb: null, tinted: false };
    $("auClose").addEventListener("click", () => closePreview());
    $("auMin").addEventListener("click", minimizePreview);
    m.addEventListener("error", () => $("auErr").classList.remove("hidden"));
    // the page takes on the colour of the song once it plays
    m.addEventListener("play", () => { vizConnect(m); au.tinted = true; themeSet(au.rgb || TH_FALLBACK); });
    ["play", "pause", "ended", "timeupdate", "durationchange"].forEach(ev => m.addEventListener(ev, miniPaint));

    // Title, artist, album and cover art come from the tags inside the file
    fetch(url + "&meta=1").then(r => r.json()).then(md => {
        if (!root.isConnected) return;
        if (md.title) $("auTitle").textContent = md.title;
        if (md.artist) { $("auArtist").textContent = md.artist; $("auArtist").classList.remove("hidden"); }
        if (md.album)  { $("auAlbum").textContent = md.album;   $("auAlbum").classList.remove("hidden"); }
        if (md.cover) {
            const coverUrl = url + "&cover=1";
            const im = new Image();
            im.alt = "Album cover";
            im.onload = () => {
                if (!root.isConnected) return;
                $("auArt").innerHTML = "";
                $("auArt").appendChild(im);
                $("auBg").style.backgroundImage = 'url("' + coverUrl + '")';
                $("auBg").classList.add("cover");
                au.rgb = coverColor(im);
                if (au.tinted) themeSet(au.rgb || TH_FALLBACK);
            };
            im.src = coverUrl;
        }
    }).catch(() => {});
}

function buildVideo(body, url) {
    body.innerHTML =
        '<div class="vd" tabindex="-1">' +
            '<video playsinline preload="metadata" src="' + url + '"></video>' +
            '<button type="button" class="vd-big" data-r="play" aria-label="Play">' + PLAYPAUSE + '</button>' +
            '<div class="vd-err hidden">This video can\u2019t be played in your browser. You can still download it.</div>' +
            '<div class="vd-ctl">' +
                '<input class="rng" data-r="seek" type="range" min="0" max="1000" value="0" aria-label="Seek">' +
                '<div class="vd-row">' +
                    '<button type="button" class="pbtn" data-r="play" aria-label="Play or pause">' + PLAYPAUSE + '</button>' +
                    '<button type="button" class="pbtn" data-r="back" aria-label="Back 10 seconds">' + ic("back10", "i-lg") + '</button>' +
                    '<button type="button" class="pbtn" data-r="fwd" aria-label="Forward 10 seconds">' + ic("fwd10", "i-lg") + '</button>' +
                    '<span class="t" style="min-width:0;margin-left:6px"><span data-r="cur">0:00</span> / <span data-r="dur">0:00</span></span>' +
                    '<span class="sp"></span>' +
                    '<button type="button" class="pbtn txt" data-r="speed" title="Playback speed">1\u00D7</button>' +
                    '<span class="vol-g"><button type="button" class="pbtn" data-r="mute" aria-label="Mute">' + ic("vol-on", "i-vol-on") + ic("vol-off", "i-vol-off") + '</button>' +
                        '<input class="rng vol" data-r="vol" type="range" min="0" max="1" step="0.02" value="1" aria-label="Volume"></span>' +
                    '<button type="button" class="pbtn" data-r="full" aria-label="Full screen">' + ic("full") + '</button>' +
                '</div>' +
            '</div>' +
        '</div>';

    const root = body.querySelector(".vd"), m = body.querySelector("video");
    player = bindPlayer(root, m);
    m.addEventListener("click", () => player && player.toggle());
    m.addEventListener("error", () => root.querySelector(".vd-err").classList.remove("hidden"));

    // controls fade away while playing and come back on movement
    let hideTimer = null;
    const show = () => {
        root.classList.add("show");
        clearTimeout(hideTimer);
        hideTimer = setTimeout(() => root.classList.remove("show"), 2600);
    };
    ["mousemove", "touchstart", "click", "keydown"].forEach(ev => root.addEventListener(ev, show));
}

/* ============================================================
   Live refresh (all devices)
   ============================================================ */
let polling = false, failCount = 0, pollTimer = null;

function setLive(on) {
    $("live").classList.toggle("off", !on);
    $("liveText").textContent = on ? "Live" : "Reconnecting";
}

function applyList(files, v, folders) {
    const oldIds = new Set(state.files.map(f => f.id));
    files.forEach(f => { if (!oldIds.has(f.id)) state.fresh.add(f.id); });
    state.files = files;
    if (folders) state.folders = folders;
    state.v = v;
    if (state.folder && !folderById(state.folder)) {
        state.folder = 0;
        toast("That folder was removed or is no longer shared with you");
    }
    state.picked.forEach(id => { if (!byId(id)) state.picked.delete(id); });   // deleted on another device

    const sel = byId(state.selected);
    if (state.selected !== null && !sel) {
        state.selected = null;
        noteDirty = false;
        renderDetail();
        toast("That file was removed");
    }
    if (pvId !== null && !byId(pvId)) closePreview();

    renderAll();
    if (sel) updateDetailLive(sel);
}

/* wait for a poll that is already running, then fetch the list again (so a change just made shows up at once) */
async function pollNow() {
    for (let i = 0; i < 12 && polling; i++) await new Promise(r => setTimeout(r, 150));
    await poll();
}

async function poll() {
    if (polling) return;
    polling = true;
    try {
        const r = await fetch("files.php?action=list&v=" + encodeURIComponent(state.v), {
            headers: { "X-Requested-With": "XMLHttpRequest" },
            cache: "no-store"
        });
        if (r.status === 401) { location.href = "login.php"; return; }   // signed out (e.g. password changed on another device)
        if (!r.ok) throw new Error("HTTP " + r.status);
        const d = await r.json();
        failCount = 0;
        setLive(true);
        if (!d.same && d.files) applyList(d.files, d.v, d.folders);
    } catch (e) {
        failCount++;
        if (failCount >= 2) setLive(false);
    }
    polling = false;
}

function schedulePoll() {
    clearTimeout(pollTimer);
    const wait = document.hidden ? 15000 : Math.min(3000 + failCount * 2000, 15000);
    pollTimer = setTimeout(async () => { await poll(); schedulePoll(); }, wait);
}
document.addEventListener("visibilitychange", () => { if (!document.hidden) { poll(); schedulePoll(); } });
window.addEventListener("online", () => { poll(); schedulePoll(); });

/* ============================================================
   Upload
   ============================================================ */
const form     = $("uploadForm");
const input    = $("fileInput");
const btn      = $("uploadBtn");
const queueEl  = $("queue");
const barEl    = $("queueBar");
const summary  = $("queueSummary");
const clearBtn = $("clearBtn");

let items = [];       // {id, file, state, el, error, tooBig}
let busy = false;
let nextId = 1;

function addFiles(fileList) {
    for (const file of fileList) {
        const dupe = items.some(i => i.file.name === file.name && i.file.size === file.size && i.file.lastModified === file.lastModified);
        if (dupe) continue;

        const item = { id: nextId++, file, state: "queued", error: "", tooBig: false };
        if (MAX_FILE && file.size > MAX_FILE) {
            item.state = "error";
            item.tooBig = true;
            item.error = "Too large (server limit " + fmt(MAX_FILE) + ")";
        }
        item.el = buildRow(item);
        queueEl.appendChild(item.el);
        items.push(item);
    }
    refresh();
}

function buildRow(item) {
    const ext = item.file.name.includes(".") ? item.file.name.split(".").pop().toLowerCase() : "";
    const el = document.createElement("div");
    el.className = "qitem";
    el.innerHTML =
        iconByExt(ext) +
        '<div class="qinfo"><div class="qname">' + esc(item.file.name) + '</div>' +
        '<div class="qmeta"></div><div class="bar"><i></i></div></div>' +
        '<button type="button" class="icon-btn qx" aria-label="Remove">' + ic("close") + '</button>';
    el.querySelector(".qx").addEventListener("click", () => {
        if (busy && item.state === "uploading") return;
        items = items.filter(i => i !== item);
        el.remove();
        refresh();
    });
    return el;
}

function paint(item) {
    item.el.className = "qitem " + item.state;
    const meta = item.el.querySelector(".qmeta");
    const size = fmt(item.file.size);
    if (item.state === "queued")    meta.textContent = size + " \u00B7 waiting";
    if (item.state === "uploading") meta.textContent = size + " \u00B7 uploading\u2026";
    if (item.state === "done")      meta.textContent = size + " \u00B7 uploaded";
    if (item.state === "error")     meta.textContent = item.error;
    item.el.querySelector(".qx").classList.toggle("hidden", busy && item.state === "uploading");
}

function refresh() {
    items.forEach(paint);

    const pending = items.filter(i => i.state === "queued" || (i.state === "error" && !i.tooBig)).length;
    const retry   = items.some(i => i.state === "error" && !i.tooBig);
    const total   = items.reduce((s, i) => s + i.file.size, 0);

    queueEl.classList.toggle("hidden", items.length === 0);
    barEl.classList.toggle("hidden", items.length === 0);
    summary.textContent = items.length + (items.length === 1 ? " file" : " files") + " \u00B7 " + fmt(total);

    btn.disabled = busy || pending === 0;
    btn.textContent = busy ? "Uploading\u2026" : (pending ? (retry ? "Retry " : "Upload ") + pending + (pending === 1 ? " file" : " files") : "Upload");
    clearBtn.disabled = busy;
}

function uploadOne(item) {
    return new Promise(resolve => {
        const xhr = new XMLHttpRequest();
        const fd = new FormData();
        fd.append("action", "upload");
        fd.append("folder_id", String(uploadTo));
        fd.append("files[]", item.file, item.file.name);
        fd.append("visibility", $("upVis").value);
        fd.append("shares", upIds().join(","));

        xhr.open("POST", "files.php");
        xhr.setRequestHeader("X-Requested-With", "XMLHttpRequest");
        xhr.setRequestHeader("X-CSRF-Token", CSRF);

        const bar = item.el.querySelector(".bar i");
        const meta = item.el.querySelector(".qmeta");

        xhr.upload.onprogress = e => {
            if (!e.lengthComputable) return;
            const p = e.loaded / e.total;
            bar.style.width = (p * 100) + "%";
            meta.textContent = fmt(item.file.size) + " \u00B7 " + Math.round(p * 100) + "%";
        };
        xhr.upload.onload = () => { meta.textContent = fmt(item.file.size) + " \u00B7 saving\u2026"; };

        xhr.onload = () => {
            if (xhr.status === 401) { location.href = "login.php"; return; }
            let data = null;
            try { data = JSON.parse(xhr.responseText); } catch (e) {}

            if (data && data.ok > 0) {
                item.state = "done";
            } else {
                item.state = "error";
                item.error = (data && data.errors && data.errors[0]) || ("Upload failed (HTTP " + xhr.status + ")");
            }
            resolve();
        };
        xhr.onerror = xhr.ontimeout = () => {
            item.state = "error";
            item.error = "Network error";
            resolve();
        };

        xhr.send(fd);
    });
}

let uploadTo = 0;   // folder id the current upload batch goes into (0 = top level)
async function startUpload() {
    if (busy) return;
    busy = true;
    uploadTo = uploadTarget();

    items.forEach(i => { if (i.state === "error" && !i.tooBig) i.state = "queued"; });
    refresh();

    for (const item of items.filter(i => i.state === "queued")) {
        item.state = "uploading";
        paint(item);
        await uploadOne(item);
        paint(item);
    }

    busy = false;
    await poll();                       // show the new files right away

    const ok  = items.filter(i => i.state === "done").length;
    const bad = items.filter(i => i.state === "error").length;

    items.filter(i => i.state === "done").forEach(i => i.el.remove());
    items = items.filter(i => i.state !== "done");
    refresh();

    if (ok) toast("Uploaded " + ok + (ok === 1 ? " file" : " files"));
    if (bad) summary.textContent = ok + " uploaded, " + bad + " failed";
    else closeDrawer();
}

input.addEventListener("change", () => { addFiles(input.files); input.value = ""; });
form.addEventListener("submit", e => { e.preventDefault(); startUpload(); });
clearBtn.addEventListener("click", () => {
    if (busy) return;
    items = [];
    queueEl.innerHTML = "";
    refresh();
});

/* Drop files anywhere on the page */
let dragDepth = 0;
const hasFiles = e => e.dataTransfer && Array.from(e.dataTransfer.types || []).includes("Files");
window.addEventListener("dragenter", e => { if (!hasFiles(e)) return; dragDepth++; $("dropOverlay").classList.add("on"); });
window.addEventListener("dragleave", e => { if (!hasFiles(e)) return; dragDepth = Math.max(0, dragDepth - 1); if (!dragDepth) $("dropOverlay").classList.remove("on"); });
window.addEventListener("dragover",  e => { if (hasFiles(e)) e.preventDefault(); });
window.addEventListener("drop", e => {
    if (!hasFiles(e)) return;
    e.preventDefault();
    dragDepth = 0;
    $("dropOverlay").classList.remove("on");
    if (e.dataTransfer.files.length) { addFiles(e.dataTransfer.files); openDrawer(); }
});

/* ============================================================
   Phone drawer + events
   ============================================================ */
const wideScreen = window.matchMedia("(min-width: 1000px)");
function openDrawer()  {
    if (wideScreen.matches) { setRail(false); return; }          // desktop: unfold the panel instead of sliding a drawer
    $("side").classList.add("open"); $("scrim").classList.add("on");
}
function closeDrawer() { $("side").classList.remove("open"); $("scrim").classList.remove("on"); }

/* Left panel: fold into an icon rail and back (desktop). The choice is remembered. */
function setRail(on) {
    document.documentElement.classList.toggle("side-c", on);
    const t = $("sideToggle");
    t.setAttribute("aria-expanded", String(!on));
    t.setAttribute("aria-label", on ? "Unfold panel" : "Fold panel into icon rail");
    t.title = on ? "Unfold panel" : "Fold panel";
    try { localStorage.setItem("fs-side", on ? "rail" : "full"); } catch (e) {}
}
setRail(document.documentElement.classList.contains("side-c"));
$("sideToggle").addEventListener("click", () => setRail(!document.documentElement.classList.contains("side-c")));
$("railUp").addEventListener("click", () => $("fileInput").click());
/* the opened left panel floats over the list: clicking the list or the details closes it again */
document.addEventListener("click", e => {
    if (wideScreen.matches && !document.documentElement.classList.contains("side-c") && e.target.closest && e.target.closest(".list-panel, .detail")) setRail(true);
});
$("fileInput").addEventListener("change", () => { if (wideScreen.matches && document.documentElement.classList.contains("side-c")) setRail(false); });

/* List or grid layout for the files (remembered) */
function setView(v) {
    $("rows").classList.toggle("grid", v === "grid");
    $("viewList").setAttribute("aria-pressed", String(v !== "grid"));
    $("viewGrid").setAttribute("aria-pressed", String(v === "grid"));
    try { localStorage.setItem("fs-view", v); } catch (e) {}
}
try { setView(localStorage.getItem("fs-view") === "grid" ? "grid" : "list"); } catch (e) { setView("list"); }
$("viewList").addEventListener("click", () => setView("list"));
$("viewGrid").addEventListener("click", () => setView("grid"));

/* "/" jumps to the search box */
document.addEventListener("keydown", e => {
    if (e.key !== "/" || e.ctrlKey || e.metaKey || e.altKey || e.target.closest("input, textarea, select, [contenteditable]")) return;
    if (document.querySelector(".modal.open, .dlg-wrap.open")) return;
    e.preventDefault(); $("q").focus();
});
$("menuBtn").addEventListener("click", openDrawer);
$("sideClose").addEventListener("click", closeDrawer);
$("scrim").addEventListener("click", closeDrawer);

/* Library filter (sidebar list and phone chips) */
document.addEventListener("click", e => {
    const g = e.target.closest("[data-g]");
    if (!g) return;
    state.group = g.dataset.g;
    renderAll();
});

/* Search + sort */
$("q").addEventListener("input", e => { state.q = e.target.value; renderRows(); });
$("qClear").addEventListener("click", () => { state.q = ""; $("q").value = ""; renderRows(); $("q").focus(); });
$("sort").addEventListener("change", e => { state.sort = e.target.value; renderRows(); });

/* File list clicks */
function handleFolderAct(act, d) {
    if (act === "frename" && d.can) renameFolder(d);
    else if (act === "fshare" && d.can) shareFolder(d);
    else if (act === "fmove" && d.can) openMove("folder", [d.id]);
    else if (act === "fdelete" && d.can) deleteFolder(d);
}
function handleAct(act, f) {
    if (act === "preview") openPreview(f);
    else if (act === "move" && f.can) openMove("file", [f.id]);
    else if (act === "rename" && f.can) renameFile(f);
    else if (act === "delete" && f.can) deleteFile(f);
    else if (act === "share" && f.can) shareFile(f);
}
$("rows").addEventListener("click", e => {
    const reset = e.target.closest('[data-act="reset"]');
    if (reset) { state.q = ""; state.group = "all"; $("q").value = ""; renderAll(); return; }

    if (e.target.closest('[data-act="upload"]')) { $("fileInput").click(); return; }

    // a folder row: click opens it, the little buttons rename / share / move / delete it
    const fr = e.target.closest(".frow");
    if (fr) {
        const d = folderById(+fr.dataset.fid);
        if (!d) return;
        const fa = e.target.closest("[data-act]");
        if (fa) handleFolderAct(fa.dataset.act, d); else openFolder(d.id);
        return;
    }

    const row = e.target.closest(".row");
    if (!row) return;
    if (e.target.closest("a")) return;                       // download link
    const f = byId(+row.dataset.id);
    if (!f) return;

    const a = e.target.closest("[data-act]");

    // Select mode: a click ticks the file. Ctrl/Cmd+click starts select mode from the normal list.
    if (state.pick || ((e.ctrlKey || e.metaKey) && !a)) {
        if (!state.pick) setPickMode(true);
        togglePick(f.id, e.shiftKey);
        return;
    }

    if (a) { handleAct(a.dataset.act, f); return; }
    selectFile(f.id);
});
$("rows").addEventListener("dblclick", e => {
    if (state.pick) return;
    const row = e.target.closest(".row");
    if (!row || e.target.closest("[data-act], a")) return;
    const f = byId(+row.dataset.id);
    if (f && f.preview) openPreview(f);
});
$("rows").addEventListener("keydown", e => {
    const fr = e.target.closest(".frow");
    if (fr && !e.target.closest("button, a")) {
        if (e.key === "Enter") { e.preventDefault(); openFolder(+fr.dataset.fid); }
        return;
    }
    const row = e.target.closest(".row");
    if (!row || e.target.closest("button, a")) return;
    if (state.pick && (e.key === "Enter" || e.key === " ")) {    // keyboard: Space / Enter ticks the focused file
        e.preventDefault();
        togglePick(+row.dataset.id, e.shiftKey);
        return;
    }
    if (e.key === "Enter") selectFile(+row.dataset.id);
});

/* Detail panel clicks */
$("detail").addEventListener("click", e => {
    const a = e.target.closest("[data-act]");
    if (!a) return;
    if (a.dataset.act === "back") { closeSheet(); return; }
    const f = byId(state.selected);
    if (f) handleAct(a.dataset.act, f);
});

/* Keyboard */
document.addEventListener("keydown", e => {
    if (e.key === "Escape") {
        for (const id of ["delDlg", "shareDlg", "moveDlg", "fdelDlg", "adminDlg", "acctDlg", "menuDlg"]) {
            if ($(id).classList.contains("open")) { $(id).classList.remove("open"); return; }
        }
        if ($("dlg").classList.contains("open")) { $("dlgCancel").click(); return; }
        if ($("pv").classList.contains("open")) { if (AI.open) { aiToggle(false); return; } closePreview(); return; }
        if (state.pick) { setPickMode(false); return; }
        if (state.sheet && window.matchMedia("(max-width: 999px)").matches) { closeSheet(); return; }
        closeDrawer();
        return;
    }
    // Ctrl/Cmd+A ticks everything shown while selecting (not while typing in a text box)
    if (state.pick && (e.ctrlKey || e.metaKey) && e.key.toLowerCase() === "a"
        && !$("pv").classList.contains("open") && !e.target.closest("textarea, input")) {
        e.preventDefault();
        const ids = visibleFiles().map(f => f.id);
        ids.forEach(id => state.picked.add(id));
        paintPicks();
        return;
    }
    if (!$("pv").classList.contains("open") || !player) return;
    if (e.target.closest("textarea, input[type=text], input[type=search], button, a")) return;

    if (e.key === " ")               { e.preventDefault(); player.toggle(); }
    else if (e.key === "ArrowLeft")  { e.preventDefault(); player.skip(-5); }
    else if (e.key === "ArrowRight") { e.preventDefault(); player.skip(5); }
    else if (e.key === "m" || e.key === "M") player.mute();
    else if ((e.key === "f" || e.key === "F") && player.hasFull) player.full();
});

/* ============================================================
   Sharing: pick people (upload panel + share dialog)
   ============================================================ */
/* Fills `box` with a checkbox per account (except `skipId`); ticked ids live in the Set `picked` */
function buildPicks(box, picked, skipId, onChange) {
    const users = state.users.filter(u => u.id !== skipId);
    if (!users.length) {
        box.innerHTML = '<p class="pk-empty">No other accounts yet. The people you want to share with have to create an account first.</p>';
        return;
    }
    box.innerHTML = users.map(u =>
        '<label class="prow"><input type="checkbox" value="' + u.id + '"' + (picked.has(u.id) ? " checked" : "") + '><span>' + esc(u.name) + '</span></label>'
    ).join("");
    box.onchange = e => {
        const c = e.target.closest("input");
        if (!c) return;
        if (c.checked) picked.add(+c.value); else picked.delete(+c.value);
        if (onChange) onChange();
    };
}

/* Newly created accounts show up without reloading the page */
async function refreshUsers() {
    try {
        const r = await fetch("files.php?action=users", { headers: { "X-Requested-With": "XMLHttpRequest" }, cache: "no-store" });
        if (r.status === 401) { location.href = "login.php"; return; }
        const d = await r.json();
        if (d.users) state.users = d.users;
    } catch (e) {}
}

/* Upload panel: who can see the files you are about to upload (default: everyone) */
const upPicked = new Set();
function upIds() { return $("upVis").value === "custom" ? Array.from(upPicked) : []; }
function syncUpload() {
    const custom = $("upVis").value === "custom";
    $("upList").classList.toggle("hidden", !custom);
    $("upHint").classList.toggle("hidden", !(custom && upPicked.size === 0));
    $("upShares").value = upIds().join(",");
    if (custom) buildPicks($("upList"), upPicked, ME.id, syncUpload);
}
$("upVis").addEventListener("change", async () => {
    syncUpload();
    if ($("upVis").value === "custom") { await refreshUsers(); syncUpload(); }
});

/* Share dialog for a file that is already uploaded */
let shareFor = null, shareKind = "file";   // the file or folder the share dialog is about
let shareMulti = [];   // the files a "Share selected" dialog is about
const shareObj = () => shareKind === "folder" ? folderById(shareFor) : (shareKind === "files" ? (shareMulti[0] || null) : byId(shareFor));
const sharePicked = new Set();
const shareVis = () => { const r = document.querySelector('#shareForm input[name="vis"]:checked'); return r ? r.value : "all"; };

function syncShare() {
    const f = shareObj();
    const custom = shareVis() === "custom";
    $("shareList").classList.toggle("hidden", !custom);
    if (custom && f) buildPicks($("shareList"), sharePicked, f.owner_id, null);
}
async function shareFile(f) {
    // a file inside a folder follows the folder: open the folder's sharing instead
    if (f.folder_id) {
        const d = folderById(f.folder_id);
        if (d && d.can) return shareFolder(d, f);
        toast("This file follows the sharing of its folder.");
        return;
    }
    shareKind = "file";
    $("shareTitle").textContent = "Share file";
    $("shareNote").classList.add("hidden");
    return openShare(f);
}
/* Share every ticked file at once. Files that sit inside a folder follow that folder, so they are skipped. */
async function shareSelected() {
    const all  = pickedManageable();
    const list = all.filter(f => !f.folder_id);
    const skipped = all.length - list.length;
    if (!list.length) { toast("Those files are inside folders, so they follow the folder\u2019s sharing."); return; }
    shareKind = "files";
    shareMulti = list;
    $("shareTitle").textContent = "Share " + list.length + (list.length === 1 ? " file" : " files");
    $("shareNote").textContent = "What you choose here replaces the current sharing of all " + list.length + " selected files." +
        (skipped ? " " + skipped + (skipped === 1 ? " file is" : " files are") + " inside a folder and keeps that folder\u2019s sharing." : "");
    $("shareNote").classList.remove("hidden");
    await openShare({ id: 0, name: list.length === 1 ? list[0].name : list.length + " selected files", vis: "private", shared: [], owner_id: list[0].owner_id });
    document.querySelectorAll('#shareForm input[name="vis"]').forEach(r => { r.checked = r.value === "private"; });
    syncShare();
}
async function shareFolder(d, fromFile) {
    shareKind = "folder";
    $("shareTitle").textContent = "Share folder";
    $("shareNote").textContent = (fromFile ? "\u201C" + fromFile.name + "\u201D is in this folder, so it follows the folder\u2019s sharing. " : "") + "Whatever you choose here applies to everything inside this folder, including its sub-folders.";
    $("shareNote").classList.remove("hidden");
    return openShare(d);
}
async function openShare(f) {
    shareFor = f.id;
    $("shareFile").textContent = "“" + f.name + "”";
    document.querySelectorAll('#shareForm input[name="vis"]').forEach(r => { r.checked = r.value === f.vis; });
    sharePicked.clear();
    (f.shared || []).forEach(id => sharePicked.add(id));
    $("shareErr").textContent = "";
    $("shareDlg").classList.add("open");
    syncShare();
    await refreshUsers();
    syncShare();
}
document.querySelectorAll('#shareForm input[name="vis"]').forEach(r => r.addEventListener("change", syncShare));
$("shareCancel").addEventListener("click", () => $("shareDlg").classList.remove("open"));
$("shareDlg").addEventListener("click", e => { if (e.target === $("shareDlg")) $("shareDlg").classList.remove("open"); });
$("shareForm").addEventListener("submit", async e => {
    e.preventDefault();
    const f = shareObj();
    if (!f) { $("shareDlg").classList.remove("open"); return; }

    const vis = shareVis();
    const ids = vis === "custom" ? Array.from(sharePicked) : [];
    if (vis === "custom" && !ids.length) { $("shareErr").textContent = "Pick at least one person, or choose “Only me”."; return; }

    if (shareKind === "files") {
        const list = shareMulti.slice();
        let done = 0, firstErr = "";
        for (const x of list) {
            try {
                const r = await post({ action: "share", id: x.id, visibility: vis, shares: ids.join(",") });
                if (r.ok) { x.vis = r.visibility; x.shared = r.shared; done++; }
                else if (!firstErr) firstErr = r.error || "Could not save the sharing settings.";
            } catch (err) { if (!firstErr) firstErr = "Could not reach the server."; }
        }
        if (!done) { $("shareErr").textContent = firstErr; return; }
        $("shareDlg").classList.remove("open");
        renderAll();
        if (byId(state.selected)) updateDetailLive(byId(state.selected));
        toast(done === list.length ? "Sharing updated for " + done + (done === 1 ? " file" : " files") : "Updated " + done + " of " + list.length + " files. " + firstErr);
        poll();
        return;
    }

    try {
        const d = await post({ action: shareKind === "folder" ? "folder_share" : "share", id: f.id, visibility: vis, shares: ids.join(",") });
        if (!d.ok) { $("shareErr").textContent = d.error || "Could not save the sharing settings."; return; }
        f.vis = d.visibility;
        f.shared = d.shared;
        if (shareKind === "folder") {   // everything inside takes the same sharing
            const inside = new Set(state.folders.filter(x => folderInside(x.id, f.id)).map(x => x.id));
            state.folders.forEach(x => { if (inside.has(x.id)) { x.vis = d.visibility; x.shared = d.shared.slice(); } });
            state.files.forEach(x => { if (inside.has(x.folder_id)) { x.vis = d.visibility; x.shared = d.shared.slice(); } });
        }
        $("shareDlg").classList.remove("open");
        renderAll();
        if (shareKind === "file") updateDetailLive(f);
        else if (byId(state.selected)) updateDetailLive(byId(state.selected));
        toast("Sharing updated");
        poll();
    } catch (err) {
        $("shareErr").textContent = "Could not reach the server.";
    }
});

/* ============================================================
   Folders: create, rename, move, delete
   ============================================================ */
function newFolder() {
    const parent = folderById(state.folder);
    if (parent && !parent.can) { toast("You can only create folders inside your own folders."); return; }
    return ask({
        title: parent ? "New folder in \u201C" + parent.name + "\u201D" : "New folder",
        message: parent ? "" : "New folders start private. Use Share on the folder to give other people access.",
        value: "New folder",
        okText: "Create",
        validate: async name => {
            if (!name.trim()) return "Enter a folder name.";
            try {
                const d = await post({ action: "folder_create", name: name, parent: parent ? parent.id : 0 });
                if (!d.ok) return d.error || "Could not create the folder.";
                toast("Folder created");
                await pollNow();
                return null;
            } catch (e) {
                return "Could not reach the server.";
            }
        }
    });
}

function renameFolder(d) {
    return ask({
        title: "Rename folder",
        value: d.name,
        okText: "Save",
        validate: async name => {
            if (!name.trim()) return "Enter a folder name.";
            try {
                const r = await post({ action: "folder_rename", id: d.id, name: name });
                if (!r.ok) return r.error || "Could not rename the folder.";
                d.name = r.name;
                renderAll();
                toast("Renamed");
                poll();
                return null;
            } catch (e) {
                return "Could not reach the server.";
            }
        }
    });
}

function deleteFolder(d) {
    const parent = d.parent ? folderById(d.parent) : null;
    const empty = !d.files && !d.subs;
    const items = [];
    if (d.files) items.push(d.files + (d.files === 1 ? " file" : " files"));
    if (d.subs) items.push(d.subs + (d.subs === 1 ? " folder" : " folders"));

    if (empty) {
        return ask({
            title: "Delete this folder?",
            message: "\u201C" + d.name + "\u201D is empty and will be removed from the server.",
            okText: "Delete",
            danger: true,
            validate: () => sendFolderDelete(d, "all")
        });
    }
    $("fdelTitle").textContent = "Delete \u201C" + d.name + "\u201D?";
    $("fdelMsg").textContent = "It has " + items.join(" and ") + " in it. What should happen to them?";
    $("fdelUp").textContent = "Keep them: move them to " + (parent ? "\u201C" + parent.name + "\u201D" : "the top level");
    document.querySelector('#fdelForm input[value="up"]').checked = true;
    $("fdelErr").textContent = "";
    $("fdelOk").disabled = false;
    $("fdelDlg").classList.add("open");
    $("fdelForm").onsubmit = async e => {
        e.preventDefault();
        const mode = document.querySelector('#fdelForm input[name="fdel"]:checked').value;
        $("fdelOk").disabled = true;
        const err = await sendFolderDelete(d, mode);
        $("fdelOk").disabled = false;
        if (err) { $("fdelErr").textContent = err; return; }
        $("fdelDlg").classList.remove("open");
    };
}
$("fdelCancel").addEventListener("click", () => $("fdelDlg").classList.remove("open"));
$("fdelDlg").addEventListener("click", e => { if (e.target === $("fdelDlg")) $("fdelDlg").classList.remove("open"); });

async function sendFolderDelete(d, mode) {
    try {
        const r = await post({ action: "folder_delete", id: d.id, mode: mode });
        if (!r.ok) return r.error || "Could not delete the folder.";
        if (state.folder && folderInside(state.folder, d.id)) state.folder = d.parent || 0;   // step out of a folder that is gone
        await pollNow();
        renderAll();
        toast("Folder deleted");
        return null;
    } catch (e) {
        return "Could not reach the server.";
    }
}

/* ---------- Move to a folder (dialog, Move selected, drag and drop) ---------- */
let moveJob = null;   // { kind: "file" | "folder", ids: [...] }

/* Folders that can receive these items: mine (or anyone's for the admin), same owner as the items, not inside the folder being moved */
function moveChoices(ownerId, excludeIds) {
    const out = [];
    const walk = (parentId, depth) => {
        state.folders
            .filter(d => (d.parent || 0) === parentId)
            .sort((a, b) => a.name.localeCompare(b.name, undefined, { numeric: true, sensitivity: "base" }))
            .forEach(d => {
                if (excludeIds.has(d.id)) return;
                const ok = d.can && d.owner_id === ownerId;
                if (ok) out.push({ d: d, depth: depth });
                walk(d.id, ok ? depth + 1 : depth);
            });
    };
    walk(0, 0);
    return out;
}

function openMove(kind, ids) {
    const items = ids.map(id => kind === "file" ? byId(id) : folderById(id)).filter(Boolean);
    if (!items.length) return;
    if (items.some(x => !x.can)) { toast("You can only move your own items."); return; }
    const owners = new Set(items.map(x => x.owner_id));
    if (owners.size > 1) { toast("Pick items that belong to the same person."); return; }
    const ownerId = items[0].owner_id;

    const exclude = new Set();
    if (kind === "folder") state.folders.forEach(d => { if (folderInside(d.id, items[0].id)) exclude.add(d.id); });
    const here = kind === "file" ? (items.length === 1 ? (items[0].folder_id || 0) : -1) : (items[0].parent || 0);

    moveJob = { kind: kind, ids: items.map(x => x.id) };
    $("moveTitle").textContent = kind === "folder" ? "Move folder" : "Move to folder";
    $("moveWhat").textContent = items.length === 1 ? "\u201C" + items[0].name + "\u201D" : items.length + " files";

    const choices = moveChoices(ownerId, exclude);
    const row = (val, label, depth, cur) =>
        '<label class="prow' + (cur ? " cur" : "") + '" style="padding-left:' + (12 + depth * 20) + 'px"><input type="radio" name="mvdest" value="' + val + '"' + (cur ? " checked" : "") + '><span>' + esc(label) + (cur ? " (current)" : "") + '</span></label>';
    $("moveList").innerHTML = row(0, "Top level (no folder)", 0, here === 0) +
        choices.map(c => row(c.d.id, c.d.name, c.depth, c.d.id === here)).join("");

    $("moveNote").textContent = choices.length
        ? "An item moved into a folder gets that folder\u2019s sharing. Moved to the top level, it keeps the sharing it has now."
        : "You don\u2019t have a folder to move into yet. Use New folder first.";
    $("moveErr").textContent = "";
    $("moveOk").disabled = false;
    $("moveDlg").classList.add("open");
}
$("moveCancel").addEventListener("click", () => $("moveDlg").classList.remove("open"));
$("moveDlg").addEventListener("click", e => { if (e.target === $("moveDlg")) $("moveDlg").classList.remove("open"); });
$("moveForm").addEventListener("submit", async e => {
    e.preventDefault();
    const pick = document.querySelector('#moveForm input[name="mvdest"]:checked');
    if (!pick || !moveJob) return;
    $("moveOk").disabled = true;
    const err = await sendMove(moveJob.kind, moveJob.ids, +pick.value);
    $("moveOk").disabled = false;
    if (err) { $("moveErr").textContent = err; return; }
    $("moveDlg").classList.remove("open");
});

/* Returns an error text, or null when it worked */
async function sendMove(kind, ids, dest) {
    try {
        const d = kind === "folder"
            ? await post({ action: "folder_move", id: ids[0], parent: dest })
            : await post({ action: "move", ids: ids.join(","), folder: dest });
        if (!d.ok) return d.error || "Could not move it.";
        if (kind === "file" && d.errors && d.errors.length) toast("Moved " + d.moved + ", but " + d.errors[0]);
        else toast("Moved");
        if (state.pick) setPickMode(false);
        await pollNow();
        renderAll();
        return null;
    } catch (e) {
        return "Could not reach the server.";
    }
}

/* Drag a file onto a folder, or onto a name in the path above the list */
const DRAG_TYPE = "application/x-fs-files";
$("rows").addEventListener("dragstart", e => {
    const row = e.target.closest && e.target.closest(".row[data-id]");
    if (!row || !e.dataTransfer) return;
    const id = +row.dataset.id;
    const ids = state.picked.has(id) ? Array.from(state.picked) : [id];
    e.dataTransfer.setData(DRAG_TYPE, ids.join(","));
    e.dataTransfer.effectAllowed = "move";
});
function dropTarget(e) {
    const fr = e.target.closest && e.target.closest(".frow");
    if (fr) return { el: fr, dest: +fr.dataset.fid };
    const cr = e.target.closest && e.target.closest("[data-crumb]");
    if (cr) return { el: cr, dest: +cr.dataset.crumb };
    return null;
}
const isMoveDrag = e => e.dataTransfer && Array.from(e.dataTransfer.types || []).includes(DRAG_TYPE);
function clearDrop() { document.querySelectorAll(".dropon").forEach(x => x.classList.remove("dropon")); }
document.addEventListener("dragover", e => {
    if (!isMoveDrag(e)) return;
    const t = dropTarget(e);
    clearDrop();
    if (t) { e.preventDefault(); e.dataTransfer.dropEffect = "move"; t.el.classList.add("dropon"); }
});
document.addEventListener("dragend", clearDrop);
document.addEventListener("drop", async e => {
    if (!isMoveDrag(e)) return;
    const t = dropTarget(e);
    clearDrop();
    if (!t) return;
    e.preventDefault();
    const ids = e.dataTransfer.getData(DRAG_TYPE).split(",").map(Number).filter(Boolean);
    const files = ids.map(byId).filter(Boolean);
    if (!files.length) return;
    const err = files.some(f => !f.can) ? "You can only move your own files."
        : (t.dest && (!folderById(t.dest) || files.some(f => f.owner_id !== folderById(t.dest).owner_id))) ? "A file can only go into a folder of the same owner."
        : null;
    if (err) { toast(err); return; }
    const moveErr = await sendMove("file", files.map(f => f.id), t.dest);
    if (moveErr) toast(moveErr);
});

$("newFolder").addEventListener("click", newFolder);
$("moveSelected").addEventListener("click", () => { if (state.picked.size) openMove("file", Array.from(state.picked)); });
$("crumbs").addEventListener("click", e => {
    const b = e.target.closest("[data-crumb]");
    if (b) openFolder(+b.dataset.crumb);
});

/* ============================================================
   Account: change username / password
   ============================================================ */
/* Show my profile picture (or my first letter) on every little avatar */
function paintAvatars() {
    const letter = ME.name.charAt(0).toUpperCase();
    const url = ME.av ? 'url("files.php?action=avatar&v=' + ME.av + '")' : "";
    for (const id of ["meAv", "acctTop", "menuAv", "avPrev"]) {
        const el = $(id);
        el.textContent = ME.av ? "" : letter;
        el.style.backgroundImage = url;
        el.style.backgroundSize = url ? "cover" : "";
        el.style.backgroundPosition = url ? "center" : "";
        el.style.backgroundRepeat = url ? "no-repeat" : "";
    }
    $("avRemove").classList.toggle("hidden", !ME.av);
    $("avPick").textContent = ME.av ? "Change picture" : "Choose picture";
    if (typeof lanyardRefresh === "function") lanyardRefresh();   // the hanging card on the right of the Account dialog
}
function renderMe() {
    $("meName").textContent = ME.name;
    paintAvatars();
    $("menuName").textContent = ME.name;
    $("acctWho").textContent = "Signed in as " + ME.name + (ME.admin ? " (admin)" : "");
}
function openAccount() {
    $("unameForm").reset();
    $("pwForm").reset();
    $("unameErr").textContent = "";
    $("pwErr").textContent = "";
    $("avErr").textContent = "";
    renderMe();
    closeDrawer();
    $("delSec").classList.toggle("hidden", !!ME.admin);
    $("acctDlg").classList.add("open");
    $("newName").focus();
}
$("avPick").addEventListener("click", () => $("avFile").click());
$("avFile").addEventListener("change", async () => {
    const file = $("avFile").files[0];
    $("avFile").value = "";
    if (!file) return;
    $("avErr").textContent = "";
    if (file.size > 8 * 1024 * 1024) { $("avErr").textContent = "The picture is bigger than 8 MB."; return; }
    $("avPick").disabled = true;
    try {
        const fd = new FormData();
        fd.append("action", "avatar_upload");
        fd.append("avatar", file);
        const r = await fetch("files.php", { method: "POST", headers: { "X-Requested-With": "XMLHttpRequest", "X-CSRF-Token": CSRF }, body: fd });
        const d = await r.json();
        if (!d.ok) { $("avErr").textContent = d.error || "Could not save the picture."; return; }
        ME.av = d.v || Date.now();
        paintAvatars();
        toast("Profile picture saved");
    } catch (e) {
        $("avErr").textContent = "Could not reach the server.";
    } finally {
        $("avPick").disabled = false;
    }
});
$("avRemove").addEventListener("click", async () => {
    $("avErr").textContent = "";
    try {
        const d = await post({ action: "avatar_delete" });
        if (!d.ok) { $("avErr").textContent = d.error || "Could not remove the picture."; return; }
        ME.av = 0;
        paintAvatars();
        toast("Profile picture removed");
    } catch (e) {
        $("avErr").textContent = "Could not reach the server.";
    }
});
$("acctBtn").addEventListener("click", openAccount);
$("acctClose").addEventListener("click", () => $("acctDlg").classList.remove("open"));
$("acctDlg").addEventListener("click", e => { if (e.target === $("acctDlg")) $("acctDlg").classList.remove("open"); });

$("unameForm").addEventListener("submit", async e => {
    e.preventDefault();
    $("unameErr").textContent = "";
    try {
        const d = await post({ action: "change_username", username: $("newName").value.trim(), password: $("unamePw").value });
        if (!d.ok) { $("unameErr").textContent = d.error || "Could not change the username."; return; }
        ME.name = d.name;
        $("unameForm").reset();
        renderMe();
        toast("Username changed");
        poll();
    } catch (err) {
        $("unameErr").textContent = "Could not reach the server.";
    }
});

$("pwForm").addEventListener("submit", async e => {
    e.preventDefault();
    $("pwErr").textContent = "";
    if ($("pwNew").value !== $("pwNew2").value) { $("pwErr").textContent = "The two new passwords don't match."; return; }
    try {
        const d = await post({ action: "change_password", current: $("pwCur").value, password: $("pwNew").value, password2: $("pwNew2").value });
        if (!d.ok) { $("pwErr").textContent = d.error || "Could not change the password."; return; }
        $("pwForm").reset();
        toast("Password changed. Your other devices were signed out.");
    } catch (err) {
        $("pwErr").textContent = "Could not reach the server.";
    }
});

/* ============================================================
   Delete account (mine, or one picked in the admin panel)
   ============================================================ */
let delTarget = null;   // null = my own account, otherwise { id, name }

function openDelete(target) {
    delTarget = target;
    $("delForm").reset();
    $("delErr").textContent = "";
    $("delTitle").textContent = target ? "Remove " + target.name + "?" : "Delete your account?";
    $("delMsg").textContent = target
        ? "This account will be removed. Choose what happens to the files it uploaded."
        : "This can't be undone. Choose what happens to the files you uploaded.";
    $("delPwBox").classList.toggle("hidden", !!target);
    $("delPw").required = !target;
    $("delDlg").classList.add("open");
    (target ? $("delCancel") : $("delPw")).focus();
}
$("delAcctBtn").addEventListener("click", () => openDelete(null));
$("delCancel").addEventListener("click", () => $("delDlg").classList.remove("open"));
$("delDlg").addEventListener("click", e => { if (e.target === $("delDlg")) $("delDlg").classList.remove("open"); });

$("delForm").addEventListener("submit", async e => {
    e.preventDefault();
    $("delErr").textContent = "";
    const withFiles = $("delForm").delfiles.value === "1" ? "1" : "0";
    const btn = $("delOk");
    btn.disabled = true;
    try {
        const d = delTarget
            ? await post({ action: "admin_delete_user", id: String(delTarget.id), delete_files: withFiles })
            : await post({ action: "delete_account", password: $("delPw").value, delete_files: withFiles });
        if (!d.ok) { $("delErr").textContent = d.error || "Could not delete the account."; return; }

        $("delDlg").classList.remove("open");
        if (!delTarget) { location.href = "login.php"; return; }
        toast("Account removed");
        loadAdminUsers();
        poll();
    } catch (err) {
        $("delErr").textContent = "Could not reach the server.";
    } finally {
        btn.disabled = false;
    }
});

/* ============================================================
   Admin panel: list / remove accounts
   ============================================================ */
/* Admin: back to the default password ("admin" for an admin account, "reset" for everyone else) */
async function resetPassword(u, btn) {
    const pw = u.admin ? "admin" : "reset";
    if (!confirm("Reset the password of " + u.name + " to \"" + pw + "\"?\n\nTheir other devices will be signed out. Tell them to log in and change it.")) return;
    $("adminErr").textContent = "";
    btn.disabled = true;
    try {
        const fd = new FormData();
        fd.append("action", "admin_reset_password");
        fd.append("id", u.id);
        const r = await fetch("files.php", { method: "POST", headers: { "X-Requested-With": "XMLHttpRequest", "X-CSRF-Token": CSRF }, body: fd });
        if (r.status === 401) { location.href = "login.php"; return; }
        const d = await r.json().catch(() => null);
        if (!d || !d.ok) { $("adminErr").textContent = (d && d.error) || "Could not reset the password."; return; }
        toast("Password of " + d.name + " is now \"" + d.password + "\"");
    } catch (e) {
        $("adminErr").textContent = "Could not reach the server.";
    } finally {
        btn.disabled = false;
    }
}
async function loadAdminUsers() {
    const box = $("adminList");
    $("adminErr").textContent = "";
    box.innerHTML = '<div class="adm-empty">Loading…</div>';
    try {
        const r = await fetch("files.php?action=admin_users", { headers: { "X-Requested-With": "XMLHttpRequest" } });
        if (r.status === 401) { location.href = "login.php"; return; }
        const d = await r.json();
        if (!d.ok) { box.innerHTML = ""; $("adminErr").textContent = d.error || "Could not load the accounts."; return; }

        box.innerHTML = "";
        if (!d.users.length) { box.innerHTML = '<div class="adm-empty">No accounts.</div>'; return; }

        d.users.forEach(u => {
            const row = document.createElement("div");
            row.className = "adm-row";

            const nm = document.createElement("div");
            nm.className = "nm";
            const b = document.createElement("b");
            b.textContent = u.name;
            const sm = document.createElement("small");
            sm.textContent = u.files + (u.files === 1 ? " file" : " files");
            nm.append(b, sm);
            row.appendChild(nm);

            const rs = document.createElement("button");
            rs.type = "button";
            rs.className = "btn";
            rs.textContent = "Reset password";
            rs.addEventListener("click", () => resetPassword(u, rs));
            if (u.admin) {
                const tag = document.createElement("span");
                tag.className = "tag";
                tag.textContent = "Admin";
                row.append(tag, rs);
            } else {
                row.appendChild(rs);
                const del = document.createElement("button");
                del.type = "button";
                del.className = "btn btn-danger";
                del.textContent = "Remove";
                del.addEventListener("click", () => openDelete({ id: u.id, name: u.name }));
                row.appendChild(del);
            }
            box.appendChild(row);
        });
    } catch (err) {
        box.innerHTML = "";
        $("adminErr").textContent = "Could not reach the server.";
    }
}
const ADMIN_HINTS = {
    users:     "Reset a password (back to \"reset\", or \"admin\" for an admin account) or remove accounts you don't want on the site. Admin accounts can't be removed here.",
    ai:        "The last 100 times someone used the AI chat (who, when, which file). What people ask is not stored. Kept for 30 days.",
    downloads: "The last 100 downloads.",
    logins:    "Who signed in, and the failed log-in and sign-up attempts, with the IP address (the last 100, kept 30 days). Failed rows show the name that was typed. A successful log-in lifts a temporary block.",
    aiset:     "The AIs the chat may use, tried from the top. If one is busy or fails, the next one gets the same question.",
    dbreport:  "Every table in the database: its fields, how many records it has, and the records. Passwords and API keys are always hidden. Press Print report for a paper copy."
};
function setAdminTab(t) {
    document.querySelectorAll("#adminTabs button").forEach(b => b.setAttribute("aria-selected", b.dataset.tab === t ? "true" : "false"));
    $("adminHint").textContent = ADMIN_HINTS[t] || "";
    $("adminErr").textContent = "";

    const isSet = t === "aiset", isDb = t === "dbreport";
    $("aiPane").classList.toggle("hidden", !isSet);
    $("dbPane").classList.toggle("hidden", !isDb);
    $("adminList").classList.toggle("hidden", isSet || isDb);
    $("logTools").classList.toggle("hidden", !(t === "ai" || t === "downloads" || t === "logins"));
    xfTab(t);
    $("adminDlg").firstElementChild.classList.toggle("wide", isDb);

    if (isSet) aiaLoad();
    else if (isDb) dbrLoad();
    else if (t === "users") loadAdminUsers();
    else loadAdminLog(t);
}
async function loadAdminLog(type) {
    const box = $("adminList");
    $("adminErr").textContent = "";
    box.innerHTML = '<div class="adm-empty">Loading…</div>';
    try {
        const r = await fetch("files.php?action=admin_log&type=" + encodeURIComponent(type), { headers: { "X-Requested-With": "XMLHttpRequest" } });
        if (r.status === 401) { location.href = "login.php"; return; }
        const d = await r.json();
        if (!d.ok) { box.innerHTML = ""; $("adminErr").textContent = d.error || "Could not load the log."; return; }
        if (!d.rows.length) { box.innerHTML = '<div class="adm-empty">Nothing here yet.</div>'; return; }

        box.innerHTML = "";
        d.rows.forEach(x => {
            const row = document.createElement("div");
            row.className = "log-row";

            const top = document.createElement("div");
            top.className = "top";
            const b = document.createElement("b");
            b.textContent = x.user;
            if (x.ok === false) row.classList.add("bad");
            const when = document.createElement("span");
            when.textContent = String(x.at).slice(0, 16);
            top.append(b, when);
            row.appendChild(top);

            const fl = document.createElement("p");
            fl.className = "tx";
            fl.textContent = x.file;
            row.appendChild(fl);
            box.appendChild(row);
        });
    } catch (err) {
        box.innerHTML = "";
        $("adminErr").textContent = "Could not reach the server.";
    }
}
document.querySelectorAll("#adminTabs button").forEach(b => b.addEventListener("click", () => setAdminTab(b.dataset.tab)));

const CLEAR_WARN = {
    ai:        "Clear the whole AI usage log? People can then send AI messages again right away (the rate limit starts over).",
    downloads: "Clear the whole download log? The “who downloaded this file” lists on every file will be empty too.",
    logins:    "Clear the whole sign-in log? Any address that was temporarily blocked can try again right away."
};
$("logClear").addEventListener("click", async () => {
    const t = document.querySelector("#adminTabs button[aria-selected=true]").dataset.tab;
    if (!CLEAR_WARN[t] || !confirm(CLEAR_WARN[t] + "\n\nThis can't be undone.")) return;
    $("adminErr").textContent = "";
    $("logClear").disabled = true;
    try {
        const r = await fetch("files.php", {
            method: "POST",
            headers: { "X-Requested-With": "XMLHttpRequest", "X-CSRF-Token": CSRF },
            body: new URLSearchParams({ action: "admin_clear_log", type: t })
        });
        if (r.status === 401) { location.href = "login.php"; return; }
        const d = await r.json();
        if (!d.ok) $("adminErr").textContent = d.error || "Could not clear the log.";
        else { toast("Log cleared (" + d.removed + " removed)"); loadAdminLog(t); }
    } catch (err) {
        $("adminErr").textContent = "Could not reach the server.";
    }
    $("logClear").disabled = false;
});

/* ============================================================
   Admin > AI setup: add AIs, order them, test them
   ============================================================ */
const AIA = { ais: [], presets: {}, kinds: {}, def: null, editId: 0, autoName: true, autoModel: true, filled: false };

async function aiaApi(action, data) {
    const opt = { headers: { "X-Requested-With": "XMLHttpRequest", "X-CSRF-Token": CSRF } };
    let url = "ai.php?action=" + action;
    if (data) {
        opt.method = "POST";
        opt.body = new URLSearchParams(Object.assign({ action }, data));
        url = "ai.php";
    }
    const r = await fetch(url, opt);
    if (r.status === 401) { location.href = "login.php"; throw new Error("Signed out"); }
    let d = null;
    try { d = await r.json(); } catch (e) {}
    if (!d) throw new Error("Bad response");
    return d;
}

function aiaSay(text, ok) {
    const el = $("aiaErr");
    el.textContent = text || "";
    el.style.color = ok ? "var(--ok)" : "";
}

function aiaFillSelects() {
    if (AIA.filled) return;
    AIA.filled = true;
    const ps = $("aiaPreset");
    ps.innerHTML = "";
    const first = document.createElement("option");
    first.value = ""; first.textContent = "Choose…";
    ps.appendChild(first);
    Object.keys(AIA.presets).forEach(k => {
        const o = document.createElement("option");
        o.value = k; o.textContent = AIA.presets[k][0];
        ps.appendChild(o);
    });
    const ks = $("aiaKind");
    ks.innerHTML = "";
    Object.keys(AIA.kinds).forEach(k => {
        const o = document.createElement("option");
        o.value = k; o.textContent = AIA.kinds[k];
        ks.appendChild(o);
    });
}

function aiaBtn(label, cls, fn) {
    const b = document.createElement("button");
    b.type = "button";
    b.className = "btn" + (cls ? " " + cls : "");
    b.textContent = label;
    b.addEventListener("click", fn);
    return b;
}

function aiaRow(title, sub, off, tag) {
    const row = document.createElement("div");
    row.className = "aia-row" + (off ? " off" : "");
    const top = document.createElement("div");
    top.className = "aia-top";
    const b = document.createElement("b");
    b.textContent = title;
    top.appendChild(b);
    if (tag) {
        const t = document.createElement("span");
        t.className = "tag";
        t.textContent = tag;
        top.appendChild(t);
    }
    row.appendChild(top);
    const s = document.createElement("p");
    s.className = "aia-sub";
    s.textContent = sub;
    row.appendChild(s);
    return row;
}

function aiaStatus(row, text, cls) {
    let st = row.querySelector(".aia-st");
    if (!st) { st = document.createElement("p"); row.insertBefore(st, row.querySelector(".aia-btns")); }
    st.className = "aia-st " + (cls || "");
    st.textContent = text;
}

async function aiaTestRow(row, btn, id) {
    const old = btn.textContent;
    btn.disabled = true; btn.textContent = "Testing…";
    try {
        const d = await aiaApi("test", { id: String(id) });
        aiaStatus(row, d.ok ? "Test passed (" + d.ms + " ms)" : "Test failed: " + (d.error || "unknown error"), d.ok ? "ok" : "bad");
    } catch (e) {
        aiaStatus(row, "Could not reach the server.", "bad");
    }
    btn.disabled = false; btn.textContent = old;
}

function aiaRender() {
    const box = $("aiaList");
    box.innerHTML = "";

    AIA.ais.forEach((p, i) => {
        const tag = !p.enabled ? "Off" : (p.cooling ? "Resting " + p.cool_s + "s" : "");
        const row = aiaRow(p.name, (AIA.kinds[p.kind] || p.kind) + " · " + p.model + " · " + (p.has_key ? "key " + p.key_hint : "no key"), !p.enabled, tag);

        if (p.status) {
            // older rows were saved as "OKanswered": show them as "OK: Answered" too
            const st = p.status.replace(/^OK(?=[^:\s])(.)/, (m, c) => "OK: " + c.toUpperCase());
            aiaStatus(row, st + (p.last ? " \u00b7 " + String(p.last).slice(0, 16) : ""), p.status.indexOf("OK") === 0 ? "ok" : "bad");
        }

        const btns = document.createElement("div");
        btns.className = "aia-btns";
        const act = async (action, data) => {
            try {
                const d = await aiaApi(action, data);
                if (!d.ok) { $("adminErr").textContent = d.error || "That did not work."; return; }
                $("adminErr").textContent = "";
                aiaLoad();
            } catch (e) { $("adminErr").textContent = "Could not reach the server."; }
        };
        if (i > 0)                     btns.appendChild(aiaBtn("↑ Up",   "", () => act("move", { id: String(p.id), dir: "up" })));
        if (i < AIA.ais.length - 1)    btns.appendChild(aiaBtn("↓ Down", "", () => act("move", { id: String(p.id), dir: "down" })));
        const t = aiaBtn("Test", "", () => aiaTestRow(row, t, p.id));
        btns.appendChild(t);
        btns.appendChild(aiaBtn("Edit", "", () => aiaOpenForm(p)));
        btns.appendChild(aiaBtn(p.enabled ? "Turn off" : "Turn on", "", () => act("toggle", { id: String(p.id) })));
        btns.appendChild(aiaBtn("Delete", "btn-danger", () => { if (confirm("Delete “" + p.name + "”?")) act("delete", { id: String(p.id) }); }));
        row.appendChild(btns);
        box.appendChild(row);
    });

    // the default AI from config.php (always last)
    const d = AIA.def;
    const row = aiaRow("Default (config.php)", d && d.set ? (AIA.kinds[d.kind] || d.kind) + " · " + d.model : "Not set up: paste a key and model in config.php", false, "Last resort");
    if (d && d.set) {
        const btns = document.createElement("div");
        btns.className = "aia-btns";
        const t = aiaBtn("Test", "", () => aiaTestRow(row, t, 0));
        btns.appendChild(t);
        row.appendChild(btns);
    }
    box.appendChild(row);

    $("aiaNote").textContent = AIA.ais.length
        ? "Order of use: top to bottom, then the default. An AI that is busy gets " + AIA.tries + " tries, then the same question goes to the next one. An AI that refuses (wrong key or model) is skipped and rests for a few minutes."
        : "No AI added yet, so the default from config.php answers. Add one to get automatic switching when an AI is busy.";
}

async function aiaLoad() {
    $("aiaList").innerHTML = '<div class="adm-empty">Loading…</div>';
    try {
        const d = await aiaApi("list");
        if (!d.ok) { $("aiaList").innerHTML = ""; $("adminErr").textContent = d.error || "Could not load the AI list."; return; }
        AIA.ais = d.ais; AIA.presets = d.presets; AIA.kinds = d.kinds; AIA.def = d.default; AIA.tries = d.tries;
        aiaFillSelects();
        aiaRender();
    } catch (e) {
        $("aiaList").innerHTML = "";
        $("adminErr").textContent = "Could not reach the server.";
    }
}

/* ---- the add / edit form ---- */
function aiaOpenForm(p) {
    AIA.editId = p ? p.id : 0;
    AIA.autoName = !p; AIA.autoModel = !p;
    $("aiaPreset").value = "";
    $("aiaPreset").classList.toggle("hidden", !!p);
    $("aiaPresetL").classList.toggle("hidden", !!p);
    $("aiaName").value  = p ? p.name : "";
    $("aiaKey").value   = "";
    $("aiaModel").value = p ? p.model : "";
    $("aiaKind").value  = p ? p.kind : "openai";
    $("aiaBase").value  = p ? p.base_url : "";
    $("aiaModels").innerHTML = "";
    $("aiaKeyNote").textContent   = p && p.has_key ? "Saved key: " + p.key_hint + ". Leave this empty to keep it." : "";
    $("aiaModelNote").textContent = "";
    $("aiaSave").textContent = p ? "Save changes" : "Add AI";
    aiaSay("");
    $("aiaForm").classList.remove("hidden");
    $("aiaAdd").classList.add("hidden");
    $(p ? "aiaKey" : "aiaPreset").focus();
    $("aiaForm").scrollIntoView({ block: "nearest", behavior: "smooth" });
}
function aiaCloseForm() {
    $("aiaForm").classList.add("hidden");
    $("aiaAdd").classList.remove("hidden");
    $("aiaKey").value = "";
}

function aiaData() {
    return {
        id: String(AIA.editId > 0 ? AIA.editId : -1),   // -1 = not saved yet (0 would mean the config.php default)
        name: $("aiaName").value.trim(), kind: $("aiaKind").value, base_url: $("aiaBase").value.trim(),
        api_key: $("aiaKey").value.trim(), model: $("aiaModel").value.trim(), enabled: "1"
    };
}

$("aiaAdd").addEventListener("click", () => aiaOpenForm(null));
$("aiaCancel").addEventListener("click", aiaCloseForm);
$("aiaName").addEventListener("input", () => { AIA.autoName = false; });
$("aiaModel").addEventListener("input", () => { AIA.autoModel = false; });

/* Picking a service fills in the type, web address and a starting model */
$("aiaPreset").addEventListener("change", () => {
    const pr = AIA.presets[$("aiaPreset").value];
    if (!pr) return;
    $("aiaKind").value = pr[1];
    $("aiaBase").value = pr[2];
    if (!$("aiaName").value || AIA.autoName) { $("aiaName").value = pr[0]; AIA.autoName = true; }
    if (!$("aiaModel").value || AIA.autoModel) { $("aiaModel").value = pr[3]; AIA.autoModel = true; }
    $("aiaModels").innerHTML = "";
    $("aiaModelNote").textContent = pr[3] ? "Press “Load models” to see everything this service offers." : "Paste the key, then press “Load models”.";
});

/* ... or just paste the key: its first letters tell which service it belongs to */
const AIA_KEYS = [["sk-or-", "openrouter"], ["AIza", "gemini"], ["sk-ant-", "anthropic"], ["gsk_", "groq"], ["xai-", "xai"], ["sk-", "openai"]];
$("aiaKey").addEventListener("input", () => {
    if (AIA.editId > 0) return;
    const cur = $("aiaPreset").value;
    if (cur && cur !== "custom") return;
    const k = $("aiaKey").value.trim();
    for (const [pre, preset] of AIA_KEYS) {
        if (k.indexOf(pre) === 0) {
            $("aiaPreset").value = preset;
            $("aiaPreset").dispatchEvent(new Event("change"));
            break;
        }
    }
});

/* Asks the service which models it has, picks a sensible one */
$("aiaLoad").addEventListener("click", async () => {
    const note = $("aiaModelNote");
    note.className = "aia-msg";
    note.textContent = "Loading…";
    try {
        const d = await aiaApi("models", aiaData());
        if (!d.ok) { note.className = "aia-msg bad"; note.textContent = d.error || "Could not load the models."; return; }

        const dl = $("aiaModels");
        dl.innerHTML = "";
        d.models.forEach(m => { const o = document.createElement("option"); o.value = m; dl.appendChild(o); });

        if (!$("aiaModel").value || AIA.autoModel) { $("aiaModel").value = d.suggest; AIA.autoModel = true; }
        note.className = "aia-msg ok";
        note.textContent = d.models.length + " models found. Tap the model box to pick another one.";
    } catch (e) {
        note.className = "aia-msg bad";
        note.textContent = "Could not reach the server.";
    }
});

$("aiaTest").addEventListener("click", async () => {
    const b = $("aiaTest");
    b.disabled = true; b.textContent = "Testing…";
    aiaSay("");
    try {
        const d = await aiaApi("test", aiaData());
        if (d.ok) aiaSay("Works (" + d.ms + " ms). It answered: " + d.reply, true);
        else aiaSay("Test failed: " + (d.error || "unknown error"), false);
    } catch (e) { aiaSay("Could not reach the server.", false); }
    b.disabled = false; b.textContent = "Test";
});

$("aiaForm").addEventListener("submit", async e => {
    e.preventDefault();
    const b = $("aiaSave");
    b.disabled = true;
    aiaSay("");
    try {
        const data = aiaData();
        data.id = String(AIA.editId);   // 0 = add a new one
        const d = await aiaApi("save", data);
        if (!d.ok) { aiaSay(d.error || "Could not save.", false); }
        else { aiaCloseForm(); toast("Saved"); aiaLoad(); }
    } catch (err) { aiaSay("Could not reach the server.", false); }
    b.disabled = false;
});

/* ============================================================
   Admin: export / import of accounts and AI setup (CSV or Excel)
   ============================================================ */
const XF = { file: null };
const XF_NOTES = {
    users: "Export saves every account except the admin: the username and its password hash (real passwords are never stored anywhere). " +
           "When you import, the password column can hold a hash from an export or a plain password, which is hashed for you. Keep exported files private.",
    ai:    "Export saves your AIs in their order of use. Import adds the ones that are not there yet; the only column that has to be filled is the model. " +
           "Keep exported files private: anyone with the API keys in them can use your keys."
};

function xfType() {
    const t = document.querySelector("#adminTabs button[aria-selected=true]");
    return t && t.dataset.tab === "aiset" ? "ai" : "users";
}

function xfReset() {
    XF.file = null;
    $("xfFile").value = "";
    $("xfExisting").value = "skip";   // "replace" must be chosen again each time
    $("xfBox").classList.add("hidden");
}

/* Called when the admin switches tab: the bar belongs to Accounts and AI setup only */
function xfTab(t) {
    const on = t === "users" || t === "aiset";
    $("xferBar").classList.toggle("hidden", !on);
    xfReset();
    $("xfRes").classList.add("hidden");
    if (!on) return;
    const ai = t === "aiset";
    $("xfKeysL").classList.toggle("hidden", !ai);
    $("xfNote").textContent = XF_NOTES[ai ? "ai" : "users"];
    $("xfMore").open = false;
    $("xfExistingL").textContent = ai ? "If an AI with the same name, model and address is already there" : "If an account with the same username is already there";
}

async function xfExport(format) {
    const type = xfType();
    $("adminErr").textContent = "";
    const url = "files.php?action=admin_export&type=" + type + "&format=" + format + (type === "ai" && !$("xfKeys").checked ? "&keys=0" : "");
    const btns = [$("xfCsv"), $("xfXlsx")];
    btns.forEach(b => { b.disabled = true; });
    try {
        const r = await fetch(url, { headers: { "X-Requested-With": "XMLHttpRequest" } });
        if (r.status === 401) { location.href = "login.php"; return; }
        if (!r.ok || (r.headers.get("Content-Type") || "").indexOf("application/json") === 0) {
            let d = null;
            try { d = await r.json(); } catch (e) {}
            $("adminErr").textContent = (d && d.error) || "Could not export.";
            return;
        }
        const m = /filename="([^"]+)"/.exec(r.headers.get("Content-Disposition") || "");
        const href = URL.createObjectURL(await r.blob());
        const a = document.createElement("a");
        a.href = href;
        a.download = m ? m[1] : "export." + format;
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(href), 5000);
        toast("Exported");
    } catch (e) {
        $("adminErr").textContent = "Could not reach the server.";
    } finally {
        btns.forEach(b => { b.disabled = false; });
    }
}

function xfShowResult(d, type) {
    const box = $("xfRes");
    box.className = "xfer-res " + (d.ok ? "ok" : "bad");
    box.textContent = "";

    const head = document.createElement("div");
    if (!d.ok) {
        head.textContent = d.error || (d.errors && d.errors[0]) || "The import did not work.";
        box.appendChild(head);
        return;
    }
    const what = type === "ai" ? "AI" : "account";
    const bits = [];
    if (d.added)    bits.push(d.added + " added");
    if (d.updated)  bits.push(d.updated + " replaced");
    if (d.kept)     bits.push(d.kept + " already there and left as they were");
    if (d.rejected) bits.push(d.rejected + " skipped");
    head.textContent = bits.length ? "Done: " + bits.join(", ") + "." : "Nothing to import: the file has no " + what + " rows.";
    box.appendChild(head);

    if (d.nokey) {
        const nk = document.createElement("div");
        nk.textContent = d.nokey + (d.nokey === 1 ? " AI has" : " AIs have") + " no API key yet. Press Edit on it to add one.";
        box.appendChild(nk);
    }
    if (d.problems && d.problems.length) {
        const ul = document.createElement("ul");
        d.problems.forEach(t => { const li = document.createElement("li"); li.textContent = t; ul.appendChild(li); });
        box.appendChild(ul);
        if (d.rejected > d.problems.length) {
            const more = document.createElement("div");
            more.textContent = "…and " + (d.rejected - d.problems.length) + " more.";
            box.appendChild(more);
        }
    }
}

async function xfImport() {
    const type = xfType();
    const file = XF.file;
    if (!file) return;
    const existing = $("xfExisting").value;
    if (existing === "replace" && !confirm(type === "ai"
        ? "Replace the AIs that are already there with the ones in the file?"
        : "Replace the password of every account that is already there with the one in the file?")) return;

    const fd = new FormData();
    fd.append("action", "admin_import");
    fd.append("type", type);
    fd.append("existing", existing);
    fd.append("file", file, file.name);

    $("adminErr").textContent = "";
    $("xfGo").disabled = true;
    $("xfGo").textContent = "Importing…";
    try {
        const r = await fetch("files.php", { method: "POST", headers: { "X-Requested-With": "XMLHttpRequest", "X-CSRF-Token": CSRF }, body: fd });
        if (r.status === 401) { location.href = "login.php"; return; }
        let d = null;
        try { d = await r.json(); } catch (e) {}
        if (!d) d = { ok: false, error: "The server sent an answer that could not be read." };
        if (d.ok === 0) d.ok = false;
        xfShowResult(d, type);
        if (d.ok) {
            xfReset();
            if (type === "ai") aiaLoad(); else loadAdminUsers();
        }
    } catch (e) {
        xfShowResult({ ok: false, error: "Could not reach the server." }, type);
    } finally {
        $("xfGo").disabled = false;
        $("xfGo").textContent = "Import";
    }
}

$("xfCsv").addEventListener("click", () => xfExport("csv"));
$("xfXlsx").addEventListener("click", () => xfExport("xlsx"));
$("xfImport").addEventListener("click", () => $("xfFile").click());
$("xfFile").addEventListener("change", () => {
    const f = $("xfFile").files[0];
    $("xfRes").classList.add("hidden");
    if (!f) { xfReset(); return; }
    if (f.size > 5 * 1024 * 1024) {
        xfReset();
        xfShowResult({ ok: false, error: "That file is too big (the most is 5 MB)." }, xfType());
        $("xfRes").classList.remove("hidden");
        return;
    }
    XF.file = f;
    $("xfName").textContent = f.name + " (" + fmt(f.size) + ")";
    $("xfBox").classList.remove("hidden");
});
$("xfCancel").addEventListener("click", xfReset);
$("xfGo").addEventListener("click", async () => { await xfImport(); $("xfRes").classList.remove("hidden"); });

/* ============================================================
   Admin > Report: the whole database, grouped, on screen and on paper
   ============================================================ */
const DBR = { data: null };

function dbrFieldsHtml(t, cls) {
    const rows = t.columns.map(c =>
        "<tr><td>" + esc(c.name) + (t.hidden.indexOf(c.name) >= 0 ? " (hidden)" : "") + "</td><td>" + esc(c.type) + "</td><td>" + (c["null"] ? "yes" : "no") +
        "</td><td>" + esc(c.key) + "</td><td>" + esc(c["default"] === null ? "" : c["default"]) + "</td><td>" + esc(c.extra) + "</td></tr>").join("");
    return '<table class="' + cls + '"><thead><tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr></thead><tbody>' + rows + "</tbody></table>";
}

function dbrRowsHtml(t, cls) {
    if (!t.rows.length) return '<p class="dbr-note">No records.</p>';
    const head = t.columns.map(c => "<th>" + esc(c.name) + "</th>").join("");
    const body = t.rows.map(r => "<tr>" + r.map(v => v === null ? '<td class="nul">NULL</td>' : "<td>" + esc(v) + "</td>").join("") + "</tr>").join("");
    return '<table class="' + cls + '"><thead><tr>' + head + "</tr></thead><tbody>" + body + "</tbody></table>";
}

function dbrOverviewHtml(d, cls) {
    let h = '<table class="' + cls + '"><thead><tr><th>Group</th><th>Table</th><th class="num">Fields</th><th class="num">Records</th><th class="num">Size</th></tr></thead><tbody>';
    d.groups.forEach(g => g.tables.forEach(t => {
        h += "<tr><td>" + esc(g.name) + "</td><td>" + esc(t.name) + '</td><td class="num">' + t.columns.length + '</td><td class="num">' +
             t.count.toLocaleString() + '</td><td class="num">' + (t.size === null ? "" : fmt(t.size)) + "</td></tr>";
    }));
    return h + "</tbody></table>";
}

function dbrSummary(d) {
    return d.totals.tables + (d.totals.tables === 1 ? " table" : " tables") + " · " + d.totals.fields + " fields · " + d.totals.records.toLocaleString() + " records";
}

function dbrNote(t) {
    const parts = [];
    if (t.shown < t.count) parts.push("Showing " + t.shown + " of " + t.count.toLocaleString() + " records.");
    if (t.hidden.length) parts.push("Hidden for safety: " + t.hidden.join(", ") + ".");
    return parts.length ? '<p class="dbr-note">' + esc(parts.join(" ")) + "</p>" : "";
}

function dbrRender() {
    const d = DBR.data;
    let h = '<p class="dbr-sum"><b>' + esc(d.db) + "</b> · " + esc(dbrSummary(d)) + " · generated " + esc(String(d.generated).slice(0, 16)) + "</p>";
    h += '<div class="dbr-scroll">' + dbrOverviewHtml(d, "dbr-tab") + "</div>";

    d.groups.forEach(g => {
        h += '<div class="dbr-group">' + esc(g.name) + "</div>";
        g.tables.forEach(t => {
            h += '<details class="dbr-t"><summary><b>' + esc(t.name) + "</b><span>" + t.columns.length + " fields · " + t.count.toLocaleString() + " records</span></summary>" +
                 '<div class="dbr-in"><h5>Fields</h5><div class="dbr-scroll">' + dbrFieldsHtml(t, "dbr-tab") + "</div>" +
                 "<h5>Records</h5>" + (t.rows.length ? '<div class="dbr-scroll">' + dbrRowsHtml(t, "dbr-tab") + "</div>" : "") + dbrNote(t) + "</div></details>";
        });
    });
    $("dbrBody").innerHTML = h;
}

async function dbrLoad() {
    $("adminErr").textContent = "";
    $("dbrPrint").disabled = true;
    $("dbrBody").innerHTML = '<div class="adm-empty">Loading…</div>';
    try {
        const r = await fetch("files.php?action=admin_db&limit=" + encodeURIComponent($("dbrLimit").value), { headers: { "X-Requested-With": "XMLHttpRequest" } });
        if (r.status === 401) { location.href = "login.php"; return; }
        const d = await r.json();
        if (!d.ok) { $("dbrBody").innerHTML = ""; $("adminErr").textContent = d.error || "Could not load the report."; return; }
        DBR.data = d;
        dbrRender();
        $("dbrPrint").disabled = false;
    } catch (err) {
        $("dbrBody").innerHTML = "";
        $("adminErr").textContent = "Could not reach the server.";
    }
}

function dbrPrintReport() {
    const d = DBR.data;
    if (!d) return;

    let h = "<h1>Database report</h1><p><b>" + esc(d.db) + "</b> · " + esc(dbrSummary(d)) + " · Printed " + esc(fmtDT(new Date())) +
            " · Passwords and API keys are hidden.</p><h2>Overview</h2>" + dbrOverviewHtml(d, "dbt");

    d.groups.forEach(g => {
        h += '<h2 class="pbr">' + esc(g.name) + "</h2>";
        g.tables.forEach(t => {
            h += "<h3>" + esc(t.name) + ' <span style="font-weight:normal">— ' + t.columns.length + " fields, " + t.count.toLocaleString() + " records</span></h3>" +
                 "<h4>Fields</h4>" + dbrFieldsHtml(t, "dbt") +
                 "<h4>Records" + (t.shown < t.count ? " (first " + t.shown + " of " + t.count.toLocaleString() + ")" : "") + "</h4>" + dbrRowsHtml(t, "dbt");
        });
    });

    $("printArea").innerHTML = h;
    window.print();
}

$("dbrRefresh").addEventListener("click", dbrLoad);
$("dbrLimit").addEventListener("change", dbrLoad);
$("dbrPrint").addEventListener("click", dbrPrintReport);

/* Theme: follows the device until the switch is used, then the choice is remembered in this browser */
function themeNow() { return document.documentElement.dataset.theme || (matchMedia("(prefers-color-scheme: light)").matches ? "light" : "dark"); }
function themeSync() {
    const t = themeNow(), label = t === "light" ? "Switch to dark theme" : "Switch to light theme";
    const m = document.querySelector('meta[name="theme-color"]');
    if (m) m.content = t === "light" ? "#dde8f1" : "#061423";
    document.querySelectorAll("[data-theme-btn]").forEach(b => {
        b.querySelector("use").setAttribute("href", t === "light" ? "#i-moon" : "#i-sun");
        const tl = b.querySelector("[data-tl]");
        if (tl) tl.textContent = label; else { b.title = label; b.setAttribute("aria-label", label); }
    });
}
document.querySelectorAll("[data-theme-btn]").forEach(b => b.addEventListener("click", () => {
    const next = themeNow() === "light" ? "dark" : "light";
    document.documentElement.dataset.theme = next;
    try { localStorage.setItem("fs-theme", next); } catch (e) {}
    themeSync();
    if (thRgb) themeSet(thRgb, true);       // a song is colouring the page: redo its colours for the new mode
}));
matchMedia("(prefers-color-scheme: light)").addEventListener("change", themeSync);
themeSync();

/* Account menu (the avatar button in the top bar, shown where the side panel is hidden) */
function openAdmin(tab) { closeDrawer(); $("menuDlg").classList.remove("open"); $("adminDlg").classList.add("open"); setAdminTab(tab); }
$("acctTop").addEventListener("click", () => $("menuDlg").classList.add("open"));
$("menuClose").addEventListener("click", () => $("menuDlg").classList.remove("open"));
$("menuDlg").addEventListener("click", e => { if (e.target === $("menuDlg")) $("menuDlg").classList.remove("open"); });
$("menuAcct").addEventListener("click", () => { $("menuDlg").classList.remove("open"); openAccount(); });
if ($("menuAdmin")) $("menuAdmin").addEventListener("click", () => openAdmin("users"));
if ($("menuLog"))   $("menuLog").addEventListener("click",   () => openAdmin("ai"));

if ($("adminBtn")) {
    $("adminBtn").addEventListener("click", () => openAdmin("users"));
    $("adminClose").addEventListener("click", () => $("adminDlg").classList.remove("open"));
    $("adminDlg").addEventListener("click", e => { if (e.target === $("adminDlg")) $("adminDlg").classList.remove("open"); });
}

/*
    ThoughtLine: the "Thinking… 3.2s" line that settles into "Thought for 3.2s", with an optional
    step trace beneath it. A plain-JavaScript port of the React Bits <ThoughtLine /> (no React, no libraries).
    Its styles are in the <style> block at the top of this file (search "ThoughtLine").

        const tl = ThoughtLine.create({ label: "Thinking…", fontSize: 14 });
        parent.appendChild(tl.el);
        tl.step("Reading the file");     // add a step (the previous one gets ticked)
        tl.settle();                     // finished: line becomes "Thought for 3.2s", trace folds
        tl.work();                       // start a new round (new clock)
        tl.destroy();                    // stop timers and remove it

    Options (same names as the React version): label, doneLabel, glyph ("sparkle" | "dot" | "none"), steps,
    collapsible, collapseOnSettle, color, glyphColor, fontSize, breathPeriod, breathDepth, shimmer,
    shimmerDuration, settleDuration, settleBlur, showTimer, settleAfter, elapsed, onSettle(seconds).
*/
(function () {
    "use strict";

    const SPARKLE = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9zM18.5 15l.8 2.2 2.2.8-2.2.8-.8 2.2-.8-2.2-2.2-.8 2.2-.8z"/></svg>';
    const CHEVRON = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>';
    const TICK    = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>';

    const fmt = ds => ds < 600 ? (ds / 10).toFixed(1) + "s" : Math.floor(ds / 600) + "m " + ((ds % 600) / 10).toFixed(1) + "s";
    const spoken = ds => ds < 600 ? (ds / 10).toFixed(1) + " seconds" : Math.floor(ds / 600) + " minutes " + ((ds % 600) / 10).toFixed(1) + " seconds";

    function h(tag, cls, attrs) {
        const e = document.createElement(tag);
        if (cls) e.className = cls;
        if (attrs) for (const k in attrs) e.setAttribute(k, attrs[k]);
        return e;
    }

    function create(opt) {
        const o = Object.assign({
            label: "Thinking…", doneLabel: "", glyph: "sparkle", steps: [],
            collapsible: true, collapseOnSettle: true, color: "currentColor", glyphColor: "", fontSize: 16,
            breathPeriod: 1.6, breathDepth: 0.45, shimmer: true, shimmerDuration: 1.8,
            settleDuration: 350, settleBlur: 2, settleAfter: 0, elapsed: null, showTimer: true,
            onSettle: null, className: ""
        }, opt || {});

        const reduce = window.matchMedia && matchMedia("(prefers-reduced-motion: reduce)").matches;
        const depth  = reduce ? Math.min(o.breathDepth, 0.2) : o.breathDepth;
        const period = reduce ? o.breathPeriod * 1.5 : o.breathPeriod;
        const sheen  = o.shimmer && !reduce;
        const doneText = o.doneLabel || (o.showTimer ? "Thought for" : "Done thinking");

        const steps = [];
        let working = true, open = true, ds = 0, startedAt = 0, tick = null, ro = null, prevWorking = true, destroyed = false;

        /* ---- build ---- */
        const root = h("div", "thought-line" + (o.className ? " " + o.className : ""));
        const st = root.style;
        st.setProperty("--tl-font", o.fontSize + "px");
        st.setProperty("--tl-color", o.color);
        st.setProperty("--tl-glyph", o.glyphColor || o.color);
        st.setProperty("--tl-settle", o.settleDuration + "ms");
        st.setProperty("--tl-blur", o.settleBlur + "px");
        st.setProperty("--tl-shimmer", o.shimmerDuration + "s");
        st.setProperty("--tl-breath", period + "s");
        st.setProperty("--tl-trough", String(1 - depth));
        if (depth <= 0) st.setProperty("--tl-trough", "1");

        const head = h(o.collapsible ? "button" : "div", "thought-line__head");
        if (o.collapsible) head.type = "button";

        if (o.glyph !== "none") {
            const g = h("span", "thought-line__glyph", { "aria-hidden": "true" });
            if (o.glyph === "dot") g.appendChild(h("span", "thought-line__dot"));
            else if (o.glyph === "sparkle") g.innerHTML = SPARKLE;
            else if (o.glyph instanceof Node) g.appendChild(o.glyph);
            head.appendChild(g);
        }

        const stack = h("span", "thought-line__label", { "aria-hidden": "true" });
        const work  = h("span", "thought-line__text");
        const breath = h("span", "thought-line__breath");
        if (sheen) breath.setAttribute("data-shimmer", "");
        breath.textContent = o.label;
        work.appendChild(breath);
        const done = h("span", "thought-line__text thought-line__text--done");
        done.textContent = doneText;
        stack.appendChild(work);
        stack.appendChild(done);
        head.appendChild(stack);

        let timer = null;
        if (o.showTimer) {
            timer = h("span", "thought-line__timer", { "aria-hidden": "true" });
            timer.textContent = "0.0s";
            head.appendChild(timer);
        }

        let chevron = null;
        if (o.collapsible) {
            chevron = h("span", "thought-line__chevron", { "aria-hidden": "true" });
            chevron.innerHTML = CHEVRON;
            head.appendChild(chevron);
        }

        const sr = h("span", "thought-line__sr", { role: "status" });
        sr.textContent = o.label;
        head.appendChild(sr);
        root.appendChild(head);

        const trace = h("div", "thought-line__trace");
        const fold  = h("div", "thought-line__fold");
        const list  = h("div", "thought-line__steps");
        fold.appendChild(list);
        trace.appendChild(fold);
        root.appendChild(trace);

        /* ---- state -> DOM ---- */
        function paintStates() {
            if (working) root.setAttribute("data-working", ""); else root.removeAttribute("data-working");
            if (working) work.setAttribute("data-active", ""); else work.removeAttribute("data-active");
            if (working) done.removeAttribute("data-active"); else done.setAttribute("data-active", "");
            if (timer) { if (working) timer.removeAttribute("data-done"); else timer.setAttribute("data-done", ""); }

            const hasTrace = steps.length > 0;
            const toggle = hasTrace && o.collapsible;
            if (open && hasTrace) root.setAttribute("data-open", ""); else root.removeAttribute("data-open");
            if (open) trace.setAttribute("data-open", ""); else trace.removeAttribute("data-open");
            trace.setAttribute("aria-hidden", open ? "false" : "true");
            if (o.collapsible) {
                if (toggle) { head.setAttribute("data-toggle", ""); head.setAttribute("aria-expanded", String(open)); head.tabIndex = 0; }
                else { head.removeAttribute("data-toggle"); head.removeAttribute("aria-expanded"); head.tabIndex = -1; }
                if (hasTrace) chevron.setAttribute("data-on", ""); else chevron.removeAttribute("data-on");
            }
            list.querySelectorAll(".thought-line__step").forEach((el, i) => markStep(el, i));
        }

        function markStep(el, i) {
            const isDone = !working || i < steps.length - 1;
            const mark = el.firstChild;
            if (isDone) {
                if (!el.hasAttribute("data-done")) { el.setAttribute("data-done", ""); mark.innerHTML = TICK; }
            } else {
                el.removeAttribute("data-done");
                if (!mark.firstChild || mark.firstChild.className !== "thought-line__pulse") { mark.innerHTML = ""; mark.appendChild(h("i", "thought-line__pulse")); }
            }
        }

        function addStepEl(text) {
            const el = h("div", "thought-line__step");
            el.appendChild(h("span", "thought-line__mark", { "aria-hidden": "true" }));
            const t = h("span", "thought-line__step-text");
            t.textContent = text;
            el.appendChild(t);
            list.appendChild(el);
        }

        /* the timer glides to sit right after whichever line is showing */
        function place(glide) {
            if (!timer) return;
            const active = working ? work : done;
            const shift = active.offsetWidth - stack.offsetWidth;
            if (!glide) timer.style.transition = "none";
            timer.style.transform = "translateX(" + shift + "px)";
            if (!glide) { void timer.offsetWidth; timer.style.transition = ""; }
        }

        function paintTimer(v) {
            ds = v;
            if (timer) timer.textContent = fmt(v);
        }

        function stopClock() { if (tick) { clearInterval(tick); tick = null; } }

        function startClock() {
            stopClock();
            if (o.elapsed != null) { paintTimer(Math.round(o.elapsed * 10)); return; }
            startedAt = performance.now();
            paintTimer(0);
            tick = setInterval(() => {
                const v = Math.floor((performance.now() - startedAt) / 100);
                paintTimer(v);
                if (o.settleAfter > 0 && v >= Math.round(o.settleAfter * 10)) api.settle();
            }, 100);
        }

        /* ---- public ---- */
        const api = {
            el: root,

            /* add a step; the one before it gets ticked */
            step(text) {
                if (destroyed) return api;
                steps.push(String(text));
                addStepEl(String(text));
                if (working) open = true;
                paintStates();
                return api;
            },

            /* finished: freeze the clock, show "Thought for ...", fold the trace */
            settle() {
                if (destroyed || !working) return api;
                if (o.elapsed == null) paintTimer(Math.floor((performance.now() - startedAt) / 100));
                stopClock();
                working = false;
                if (o.collapseOnSettle) open = false;
                paintStates();
                place(true);
                sr.textContent = o.showTimer ? doneText + " " + spoken(ds) : doneText;
                if (typeof o.onSettle === "function") o.onSettle(ds / 10);
                return api;
            },

            /* start another round: new clock */
            work() {
                if (destroyed || working) return api;
                working = true;
                open = true;
                sr.textContent = o.label;
                paintStates();
                place(true);
                startClock();
                return api;
            },

            setLabel(text) { breath.textContent = text; sr.textContent = working ? text : sr.textContent; place(false); return api; },

            seconds() { return ds / 10; },

            destroy() {
                destroyed = true;
                stopClock();
                if (ro) ro.disconnect();
                root.remove();
            }
        };

        head.addEventListener("click", () => {
            if (o.collapsible && steps.length) { open = !open; paintStates(); }
        });

        (o.steps || []).forEach(s => { steps.push(String(s)); addStepEl(String(s)); });
        paintStates();
        startClock();

        // measure once it is on the page; keep following size changes (fonts loading, label changes)
        if (window.ResizeObserver) {
            ro = new ResizeObserver(() => place(false));
            ro.observe(work);
            ro.observe(done);
        }
        requestAnimationFrame(() => { if (!destroyed) place(false); });

        return api;
    }

    window.ThoughtLine = { create: create };
})();

/* ============================================================
   AI assistant (one file at a time, only inside the preview)
   ============================================================ */
const AI = { id: null, file: null, open: false, busy: false, history: [], asked: false };

const AI_SUGGEST = {
    image: ["Describe this image", "What text is in this image?", "What are the main objects?"],
    audio: ["What can you tell me about this track?", "Transcribe the words in it", "Describe the mood and genre"],
    pdf:   ["Summarize this document", "List the key points", "What are the action items?"],
    code:  ["Explain what this code does", "Find possible bugs", "Summarize in 3 bullet points"],
    text:  ["Summarize this file", "List the key points", "Are there any mistakes in this text?"],
    video: ["Describe this frame", "What text is on the screen?", "Who or what is shown here?"]
};

function aiSuggestions(f) {
    if (f.preview === "image") return AI_SUGGEST.image;
    if (f.preview === "audio") return AI_SUGGEST.audio;
    if (f.preview === "video") return AI_SUGGEST.video;
    if (f.ext === "pdf") return AI_SUGGEST.pdf;
    if (f.type === "code" || f.type === "data" || f.type === "db") return AI_SUGGEST.code;
    return AI_SUGGEST.text;
}

function aiFormat(t) {
    return t.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
        .replace(/\*\*([^*\n]+)\*\*/g, "<b>$1</b>")
        .replace(/`([^`\n]+)`/g, "<code>$1</code>")
        .replace(/^[ \t]*[*-] /gm, "• ")
        .replace(/\n/g, "<br>");
}

function aiAdd(kind, text) {
    const m = document.createElement("div");
    m.className = "ai-m " + kind;
    if (kind === "ai") m.innerHTML = aiFormat(text); else m.textContent = text;
    $("aiMsgs").appendChild(m);
    $("aiMsgs").scrollTop = $("aiMsgs").scrollHeight;
    return m;
}

function aiShowSuggestions() {
    const box = $("aiSugg");
    box.innerHTML = "";
    if (AI.asked || !AI.file || !ME.ai) return;
    aiSuggestions(AI.file).forEach(t => {
        const b = document.createElement("button");
        b.type = "button";
        b.textContent = t;
        b.addEventListener("click", () => aiAsk(t));
        box.appendChild(b);
    });
}

/* Builds the chat contents: either the agreement card (first use) or the welcome message */
function aiRender() {
    $("aiMsgs").innerHTML = "";
    $("aiSugg").innerHTML = "";
    const usable = ME.ai;
    $("aiForm").classList.toggle("hidden", !usable);
    $("aiPanel").querySelector(".ai-note").classList.toggle("hidden", !usable);

    if (!usable) {
        const c = document.createElement("div");
        c.className = "ai-consent";
        c.innerHTML = "<p>Before you use the AI: when you ask something, the <b>content of this file</b> and your messages are sent to an AI provider chosen by the site admin (if one is busy, another may be used). " +
                      "They may be used to train and improve AI models. The AI never reads files you haven't selected.</p>";
        const b = document.createElement("button");
        b.type = "button";
        b.className = "btn btn-primary";
        b.textContent = "I agree";
        b.addEventListener("click", async () => {
            b.disabled = true;
            try {
                const d = await aiPost({ action: "consent" });
                if (d && d.ok) { ME.ai = true; aiRender(); $("aiInput").focus(); }
                else { b.disabled = false; toast("Could not save your choice"); }
            } catch (e) { b.disabled = false; toast("Could not reach the server"); }
        });
        c.appendChild(b);
        $("aiMsgs").appendChild(c);
        return;
    }

    if (aiFrameKind(AI.file) === "image") {
        aiAdd("ai", "Hi! I can\u2019t read animated pictures like **" + (AI.file ? AI.file.name : "this one") + "** directly, but I can look at **one frame**: the picture that is showing when you send your question.");
    } else if (AI.file && AI.file.preview === "video") {
        aiAdd("ai", "Hi! I can\u2019t watch videos, but I can look at **one frame**. Pause on the moment you want, then ask: I use the picture that is showing when you send your question.");
    } else {
        aiAdd("ai", "Hi! I can read **" + (AI.file ? AI.file.name : "this file") + "** and answer questions about it. Pick a suggestion or type your own question.");
    }
    aiShowSuggestions();
}

async function aiPost(data) {
    const r = await fetch("ai.php", {
        method: "POST",
        headers: { "X-Requested-With": "XMLHttpRequest", "X-CSRF-Token": CSRF },
        body: new URLSearchParams(data)
    });
    if (r.status === 401) { location.href = "login.php"; throw new Error("Signed out"); }
    let d = null;
    try { d = await r.json(); } catch (e) {}
    if (!d) throw new Error("Bad response");
    return d;
}

/* A file was opened in the preview: the AI button appears (not for video) but nothing is read yet */
function aiInit(f) {
    AI.id = f.id;
    AI.file = f;
    AI.open = false;
    AI.busy = false;
    AI.history = [];
    AI.asked = false;
    $("aiPanel").classList.remove("open");
    $("aiMsgs").innerHTML = "";
    $("aiSugg").innerHTML = "";
    $("aiFile").textContent = f.name;
    $("aiFab").classList.add("on");
}

function aiEnd() {
    AI.id = null;
    AI.file = null;
    AI.open = false;
    AI.history = [];
    $("aiPanel").classList.remove("open");
    $("aiFab").classList.remove("on");
    $("aiMsgs").innerHTML = "";
}

function aiToggle(on) {
    AI.open = on === undefined ? !AI.open : on;
    $("aiPanel").classList.toggle("open", AI.open);
    if (AI.open) {
        if (!$("aiMsgs").children.length) aiRender();
        if (ME.ai) $("aiInput").focus();
    }
}
$("aiFab").addEventListener("click", () => aiToggle());
$("aiX").addEventListener("click", () => aiToggle(false));

/* The picture showing in the video player right now, as a JPEG (longest side 1280 px) */
function videoFrame() {
    const v = document.querySelector("#pvBody video");
    if (!v || v.readyState < 2 || !v.videoWidth) throw new Error("The video has no picture yet. Press play or move the slider, then ask again.");
    const k = Math.min(1, 1280 / Math.max(v.videoWidth, v.videoHeight));
    const c = document.createElement("canvas");
    c.width = Math.max(1, Math.round(v.videoWidth * k));
    c.height = Math.max(1, Math.round(v.videoHeight * k));
    c.getContext("2d").drawImage(v, 0, 0, c.width, c.height);
    try { return { url: c.toDataURL("image/jpeg", 0.82), time: v.currentTime }; }
    catch (e) { throw new Error("The browser would not let me take a picture of this video."); }
}

/* Images the AI can't take as a file (GIF, BMP, AVIF, ICO): the browser sends the picture that is showing as a PNG instead */
const AI_FRAME_EXT = ["gif", "bmp", "avif", "ico"];
function aiFrameKind(f) {
    if (!f) return "";
    if (f.preview === "video") return "video";
    if (f.preview === "image" && AI_FRAME_EXT.indexOf(String(f.ext || "").toLowerCase()) !== -1) return "image";
    return "";
}
function imageFrame() {
    const im = document.querySelector("#pvBody img");
    if (!im || !im.complete || !im.naturalWidth) throw new Error("The image has not finished loading. Wait a moment, then ask again.");
    const k = Math.min(1, 1280 / Math.max(im.naturalWidth, im.naturalHeight));
    const c = document.createElement("canvas");
    c.width = Math.max(1, Math.round(im.naturalWidth * k));
    c.height = Math.max(1, Math.round(im.naturalHeight * k));
    c.getContext("2d").drawImage(im, 0, 0, c.width, c.height);
    try { return { url: c.toDataURL("image/png"), time: 0 }; }
    catch (e) { throw new Error("The browser would not let me take a picture of this image."); }
}

async function aiAsk(text) {
    text = (text || "").trim();
    if (!text || AI.busy || AI.id === null || !ME.ai) return;

    const id = AI.id;
    AI.asked = true;
    $("aiSugg").innerHTML = "";
    aiAdd("user", text);
    $("aiInput").value = "";

    // videos: the AI looks at the frame that is showing right now
    const post = { action: "chat", id: String(id), message: text, history: JSON.stringify(AI.history.slice(-10)) };
    let note = null;
    const fk = aiFrameKind(AI.file);
    if (fk) {
        try {
            const fr = fk === "video" ? videoFrame() : imageFrame();
            post.frame = fr.url;
            post.frame_time = String(fr.time);
            note = aiAdd("ai dim", fk === "video" ? "Looking at the frame at " + fmtTime(fr.time) : "Looking at the picture that is showing");
            const th = document.createElement("img");
            th.src = fr.url; th.alt = "The frame sent to the AI";
            th.style.cssText = "display:block;max-width:100%;max-height:110px;margin-top:6px;border-radius:4px;font-style:normal";
            note.appendChild(th);
        } catch (e) {
            aiAdd("err", e.message || "Could not take a picture of the video.");
            $("aiInput").value = text;
            return;
        }
    }

    AI.busy = true;
    $("aiSend").disabled = true;
    // "Thinking… 3.2s" line with the steps beneath it; it settles into "Thought for 3.2s" above the answer
    const think = aiAdd("think", "");
    const tl = ThoughtLine.create({ label: "Thinking…", fontSize: 14, color: "var(--text)" });
    think.appendChild(tl.el);
    const toEnd = () => { $("aiMsgs").scrollTop = $("aiMsgs").scrollHeight; };
    tl.step(fk ? "Sending the frame" : "Reading the file");
    const stepTimer = setTimeout(() => { tl.step("Waiting for the AI"); toEnd(); }, 600);
    let kept = false;

    try {
        const d = await aiPost(post);
        if (AI.id !== id) return;                      // the preview was closed or changed meanwhile

        if (d.ok) {
            clearTimeout(stepTimer);
            tl.settle();
            kept = true;
            AI.history.push({ role: "user", text: text }, { role: "model", text: d.reply });
            aiAdd("ai", d.reply);
        } else if (d.need_consent) {
            ME.ai = false;
            aiRender();
        } else {
            aiAdd("err", d.error || "Something went wrong.");
        }
    } catch (err) {
        if (AI.id !== id) return;
        aiAdd("err", "Could not reach the server.");
    } finally {
        clearTimeout(stepTimer);
        if (!kept) { tl.destroy(); think.remove(); }   // errors and consent screens: no "Thought for" line
        if (AI.id === id) { AI.busy = false; $("aiSend").disabled = false; }
    }
}
$("aiForm").addEventListener("submit", e => { e.preventDefault(); aiAsk($("aiInput").value); });

/* ============================================================
   Start
   ============================================================ */
try {
    // The waves read the page's theme colours (light/dark and the song colours), so they always match the page
    const cssVar = n => getComputedStyle(document.documentElement).getPropertyValue("--" + n).trim();
    const waves = new GradientWaves($("bgWaves"), {
        quality: "low", dpr: 1, grain: 0.1, opacity: 0.6, speed: 0.35, amplitude: 2.2, relief: 1, lightAngle: 120,
        mouseInteraction: true, swellOnClick: false,
        colors: () => themeNow() === "light"
            ? { lightMode: true,  horizonColor: cssVar("line-2"), waveColor: cssVar("focus"),  crestColor: cssVar("accent") }
            : { lightMode: false, horizonColor: cssVar("line-2"), waveColor: cssVar("accent"), crestColor: cssVar("accent-2") }
    });
    if (waves.ok && matchMedia("(prefers-reduced-motion: reduce)").matches) waves.update({ paused: true });
} catch (e) {}

renderAll();
renderDetail();
refresh();
renderMe();
syncUpload();
setLive(true);
schedulePoll();

if (BOOT.flash) {
    if (BOOT.flash.ok > 0) toast("Uploaded " + BOOT.flash.ok + (BOOT.flash.ok === 1 ? " file" : " files"));
    else if (BOOT.flash.errors && BOOT.flash.errors.length) toast(BOOT.flash.errors[0]);
}
</script>
<script>
/* Small motion helpers: click ripple on buttons, and a light that follows the pointer over file rows */
(function () {
    if (matchMedia("(prefers-reduced-motion: reduce)").matches) return;
    document.addEventListener("pointerdown", function (e) {
        var b = e.target.closest && e.target.closest(".btn, .ai-send, .mini-play, .menu-item, .ai-sugg button");
        if (!b || b.disabled) return;
        var r = b.getBoundingClientRect(), d = Math.max(r.width, r.height) * 2.2;
        var s = document.createElement("span");
        s.className = "ripple";
        s.style.cssText = "width:" + d + "px;height:" + d + "px;left:" + (e.clientX - r.left - d / 2) + "px;top:" + (e.clientY - r.top - d / 2) + "px";
        b.appendChild(s);
        setTimeout(function () { s.remove(); }, 650);
    }, { passive: true });

    var rows = document.getElementById("rows");
    if (rows && matchMedia("(hover: hover)").matches) {
        var raf = 0;
        rows.addEventListener("pointermove", function (e) {
            if (raf) return;
            raf = requestAnimationFrame(function () {
                raf = 0;
                var row = e.target.closest && e.target.closest(".row");
                if (!row) return;
                var b = row.getBoundingClientRect();
                row.style.setProperty("--mx", (e.clientX - b.left) + "px");
                row.style.setProperty("--my", (e.clientY - b.top) + "px");
            });
        }, { passive: true });
    }
})();
</script>
<script>
/* ============================================================
   Account card: the profile picture hangs on a 3D lanyard on the right side of the Account dialog.
   Adapted from the open-source React Bits <Lanyard /> component (plain JavaScript, no React).
   three.js is only downloaded the first time the Account dialog is opened on a wide screen.
   ============================================================ */
(function () {
    const THREE_URL = "https://cdn.jsdelivr.net/npm/three@0.170.0/build/three.module.js";
    let threeLoad = null;
    let factory = null;
    let inst = null;
    let starting = false;

    function makeLanyard(THREE) {
        const CARD_SIZES = { portrait: [1.6, 2.25], landscape: [2.25, 1.6] };
        const ANCHORS = { left: 0.27, center: 0.5, right: 0.73 };
        const THICKNESS = 0.018, BEVEL = 0.007, JOINTS = 4, NODE_WEIGHT = 1 / 0.05, MAX_STEP = 1 / 960, ITERATIONS = 2;
        const SAMPLES = 72, FOV = 24, STRAP_WIDTH = 0.36, RING_RADIUS = 0.095, RING_TUBE = 0.0135, EYELET_RADIUS = 0.036;
        const CLAMP_BODY = 0.15, FACE_WIDTH = 1024;
        const FINISH = { roughness: 0.42, metalness: 0, clearcoat: 1, clearcoatRoughness: 0.06, anisotropy: 0 };
        const BAND_COLUMNS = [0, 0.07, 0.93, 1];
        const BAND_BEND = [-0.95, -0.28, 0.28, 0.95];
        const FLIP_SPIN = 9;
        const METALS = { silver: { color: "#d9dce2", roughness: 0.16 }, gold: { color: "#e4b965", roughness: 0.2 }, graphite: { color: "#4d4f55", roughness: 0.28 } };

        const clamp = (value, min, max) => Math.min(max, Math.max(min, value));
        const mix = (a, b, t) => a + (b - a) * t;

        const parseColor = (value, fallback) => {
            try {
                const ctx = document.createElement("canvas").getContext("2d");
                if (!ctx) return fallback;
                ctx.fillStyle = "#000000";
                ctx.fillStyle = value;
                const resolved = ctx.fillStyle;
                if (resolved.startsWith("#")) {
                    const n = parseInt(resolved.slice(1), 16);
                    return [((n >> 16) & 255) / 255, ((n >> 8) & 255) / 255, (n & 255) / 255];
                }
                const parts = resolved.match(/[\d.]+/g);
                if (!parts || parts.length < 3) return fallback;
                return [Number(parts[0]) / 255, Number(parts[1]) / 255, Number(parts[2]) / 255];
            } catch (e) { return fallback; }
        };
        const luminance = rgb => 0.2126 * rgb[0] + 0.7152 * rgb[1] + 0.0722 * rgb[2];
        const toCss = (rgb, alpha = 1) => "rgba(" + Math.round(rgb[0] * 255) + ", " + Math.round(rgb[1] * 255) + ", " + Math.round(rgb[2] * 255) + ", " + alpha + ")";

        const seeded = start => {
            let state = start >>> 0;
            return () => {
                state = (state + 0x6d2b79f5) >>> 0;
                let t = state;
                t = Math.imul(t ^ (t >>> 15), t | 1);
                t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
                return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
            };
        };

        const traceRoundedRect = (path, x, y, width, height, radius) => {
            const r = Math.max(0, Math.min(radius, width / 2, height / 2));
            const k = r * 0.4477;
            path.moveTo(x + r, y);
            path.lineTo(x + width - r, y);
            path.bezierCurveTo(x + width - k, y, x + width, y + k, x + width, y + r);
            path.lineTo(x + width, y + height - r);
            path.bezierCurveTo(x + width, y + height - k, x + width - k, y + height, x + width - r, y + height);
            path.lineTo(x + r, y + height);
            path.bezierCurveTo(x + k, y + height, x, y + height - k, x, y + height - r);
            path.lineTo(x, y + r);
            path.bezierCurveTo(x, y + k, x + k, y, x + r, y);
        };

        const loadImage = (cache, url) => {
            if (!url) return Promise.resolve(null);
            const cached = cache.get(url);
            if (cached) return cached;
            const promise = new Promise(resolve => {
                const image = new Image();
                image.crossOrigin = "anonymous";
                image.decoding = "async";
                image.onload = () => resolve(image);
                image.onerror = () => resolve(null);
                image.src = url;
            });
            cache.set(url, promise);
            return promise;
        };

        const cardLayout = (orientation, cornerRadius) => {
            const [width, height] = CARD_SIZES[orientation] || CARD_SIZES.portrait;
            const radius = mix(0.03, Math.min(width, height) * 0.2, clamp(cornerRadius, 0, 1));
            const slot = { width: 0.34, height: 0.07, y: height / 2 - 0.13 };
            const ringY = height / 2 - 0.055;
            return { width, height, radius, slot, ringY, hangY: ringY + RING_RADIUS };
        };

        const buildCardGeometry = layout => {
            const { width, height, radius, slot } = layout;
            const outline = new THREE.Shape();
            traceRoundedRect(outline, -width / 2 + BEVEL, -height / 2 + BEVEL, width - BEVEL * 2, height - BEVEL * 2, Math.max(radius - BEVEL, 0.005));
            const hole = new THREE.Path();
            traceRoundedRect(hole, -slot.width / 2 - BEVEL, slot.y - slot.height / 2 - BEVEL, slot.width + BEVEL * 2, slot.height + BEVEL * 2, slot.height / 2 + BEVEL);
            outline.holes.push(hole);
            const body = new THREE.ExtrudeGeometry(outline, { depth: THICKNESS, bevelEnabled: true, bevelThickness: BEVEL, bevelSize: BEVEL, bevelSegments: 5, curveSegments: 24 });
            body.translate(0, 0, -THICKNESS / 2);
            const face = new THREE.ShapeGeometry(outline, 24);
            const position = face.getAttribute("position");
            const uv = face.getAttribute("uv");
            for (let i = 0; i < position.count; i++) {
                uv.setXY(i, (position.getX(i) + width / 2) / width, (position.getY(i) + height / 2) / height);
            }
            uv.needsUpdate = true;
            return { body, face };
        };

        const buildClampGeometry = width => {
            const shape = new THREE.Shape();
            const w = width * 1.16;
            traceRoundedRect(shape, -w / 2 + 0.014, -CLAMP_BODY / 2 + 0.014, w - 0.028, CLAMP_BODY - 0.028, 0.024);
            const geometry = new THREE.ExtrudeGeometry(shape, { depth: 0.02, bevelEnabled: true, bevelThickness: 0.014, bevelSize: 0.014, bevelSegments: 6, curveSegments: 10 });
            geometry.translate(0, 0, -0.01);
            return geometry;
        };

        const buildBandGeometry = count => {
            const columns = BAND_COLUMNS.length;
            const geometry = new THREE.BufferGeometry();
            geometry.setAttribute("position", new THREE.BufferAttribute(new Float32Array(count * columns * 3), 3));
            geometry.setAttribute("normal", new THREE.BufferAttribute(new Float32Array(count * columns * 3), 3));
            geometry.setAttribute("uv", new THREE.BufferAttribute(new Float32Array(count * columns * 2), 2));
            const index = [];
            for (let i = 0; i < count - 1; i++) {
                for (let c = 0; c < columns - 1; c++) {
                    const a = i * columns + c;
                    index.push(a, a + columns, a + 1, a + 1, a + columns, a + columns + 1);
                }
            }
            geometry.setIndex(index);
            return geometry;
        };

        const buildEnvironment = renderer => {
            const scene = new THREE.Scene();
            scene.background = new THREE.Color(0.035, 0.035, 0.04);
            const geometry = new THREE.PlaneGeometry(1, 1);
            const materials = [];
            const panel = (intensity, position, scale, rotation = 0) => {
                const material = new THREE.MeshBasicMaterial({ color: new THREE.Color(intensity, intensity, intensity), side: THREE.DoubleSide });
                materials.push(material);
                const mesh = new THREE.Mesh(geometry, material);
                mesh.position.set(...position);
                mesh.scale.set(scale[0], scale[1], 1);
                mesh.lookAt(0, 0, 0);
                mesh.rotateZ(rotation);
                scene.add(mesh);
            };
            panel(2.6, [0, 4.5, 8], [9, 4]);
            panel(6, [-5.5, 0.5, 7], [0.9, 14]);
            panel(4, [5, -0.5, 8], [0.6, 14]);
            panel(3.5, [0, -1, 9], [24, 0.35], Math.PI / 3);
            panel(2.5, [1, 2.5, 9], [24, 0.2], Math.PI / 3);
            panel(1.4, [0, 6, -6], [12, 4]);
            panel(0.5, [0, -7, 1], [16, 6]);
            panel(0.9, [8, 0, -2], [4, 10]);
            const pmrem = new THREE.PMREMGenerator(renderer);
            const target = pmrem.fromScene(scene, 0.035);
            pmrem.dispose();
            geometry.dispose();
            materials.forEach(material => material.dispose());
            return target;
        };

        const makeCanvasTexture = (canvas, anisotropy) => {
            const texture = new THREE.CanvasTexture(canvas);
            texture.colorSpace = THREE.SRGBColorSpace;
            texture.anisotropy = anisotropy;
            return texture;
        };

        const buildGrainTexture = () => {
            const size = 128;
            const canvas = document.createElement("canvas");
            canvas.width = size;
            canvas.height = size;
            const ctx = canvas.getContext("2d");
            if (!ctx) return new THREE.CanvasTexture(canvas);
            const random = seeded(7);
            const field = Float32Array.from({ length: size * size }, () => random());
            const height = (x, y) => {
                let sum = 0;
                for (let j = -1; j <= 1; j++) {
                    for (let i = -1; i <= 1; i++) sum += field[((y + j + size) % size) * size + ((x + i + size) % size)];
                }
                return (sum / 9) * 1.6;
            };
            const image = ctx.createImageData(size, size);
            for (let y = 0; y < size; y++) {
                for (let x = 0; x < size; x++) {
                    const nx = height((x - 1 + size) % size, y) - height((x + 1) % size, y);
                    const ny = height(x, (y - 1 + size) % size) - height(x, (y + 1) % size);
                    const length = Math.hypot(nx, ny, 1);
                    const i = (y * size + x) * 4;
                    image.data[i] = ((nx / length) * 0.5 + 0.5) * 255;
                    image.data[i + 1] = ((ny / length) * 0.5 + 0.5) * 255;
                    image.data[i + 2] = ((1 / length) * 0.5 + 0.5) * 255;
                    image.data[i + 3] = 255;
                }
            }
            ctx.putImageData(image, 0, 0);
            const texture = new THREE.CanvasTexture(canvas);
            texture.wrapS = THREE.RepeatWrapping;
            texture.wrapT = THREE.RepeatWrapping;
            texture.repeat.set(5, 7);
            return texture;
        };

        const buildWeaveTexture = () => {
            const size = 128;
            const threads = 8;
            const random = seeded(19);
            const fiber = Float32Array.from({ length: size * size }, () => random());
            const profile = f => Math.sqrt(Math.max(0, 1 - 4 * f * f));
            const height = (x, y) => {
                const px = ((x % size) + size) % size;
                const py = ((y % size) + size) % size;
                const u = (px / size) * threads;
                const v = (py / size) * threads;
                const cu = Math.floor(u);
                const cv = Math.floor(v);
                const fu = u - cu - 0.5;
                const fv = v - cv - 0.5;
                const lift = (cu + cv) % 2 === 0;
                const warp = profile(fu) * (0.55 + 0.45 * Math.cos(fv * Math.PI * (lift ? 1 : 0.6)));
                const weft = profile(fv) * (0.55 + 0.45 * Math.cos(fu * Math.PI * (lift ? 0.6 : 1)));
                return (lift ? Math.max(warp, weft * 0.7) : Math.max(weft, warp * 0.7)) + fiber[py * size + px] * 0.08;
            };
            const data = new Uint8Array(size * size * 4);
            for (let y = 0; y < size; y++) {
                for (let x = 0; x < size; x++) {
                    const nx = (height(x - 1, y) - height(x + 1, y)) * 1.6;
                    const ny = (height(x, y - 1) - height(x, y + 1)) * 1.6;
                    const length = Math.hypot(nx, ny, 1);
                    const i = (y * size + x) * 4;
                    data[i] = ((nx / length) * 0.5 + 0.5) * 255;
                    data[i + 1] = ((ny / length) * 0.5 + 0.5) * 255;
                    data[i + 2] = ((1 / length) * 0.5 + 0.5) * 255;
                    data[i + 3] = 255;
                }
            }
            const texture = new THREE.DataTexture(data, size, size);
            texture.wrapS = THREE.RepeatWrapping;
            texture.wrapT = THREE.RepeatWrapping;
            texture.magFilter = THREE.LinearFilter;
            texture.minFilter = THREE.LinearMipmapLinearFilter;
            texture.generateMipmaps = true;
            texture.needsUpdate = true;
            return texture;
        };

        const drawFitted = (ctx, image, width, height, fit) => {
            const scale = (fit === "contain" ? Math.min : Math.max)(width / image.width, height / image.height);
            const w = image.width * scale;
            const h = image.height * scale;
            ctx.drawImage(image, (width - w) / 2, (height - h) / 2, w, h);
        };

        /* transparent = no card colour at all: only the picture's own shape stays (a picture without a background) */
        const paintFace = (canvas, image, color, fit, transparent) => {
            const ctx = canvas.getContext("2d");
            if (!ctx) return;
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            if (!transparent) {
                ctx.fillStyle = toCss(color);
                ctx.fillRect(0, 0, canvas.width, canvas.height);
            }
            if (image) drawFitted(ctx, image, canvas.width, canvas.height, fit);
        };

        const paintStrap = (canvas, image, color) => {
            const across = 192;
            const along = image ? Math.max(64, Math.round((image.width / image.height) * across)) : across * 2;
            canvas.width = across;
            canvas.height = along;
            const ctx = canvas.getContext("2d");
            if (!ctx) return along / across;
            ctx.fillStyle = toCss(color);
            ctx.fillRect(0, 0, across, along);
            if (image) {
                ctx.save();
                ctx.translate(across, 0);
                ctx.rotate(Math.PI / 2);
                ctx.drawImage(image, 0, 0, along, across);
                ctx.restore();
                return along / across;
            }
            const ink = luminance(color) > 0.5 ? [0, 0, 0] : [1, 1, 1];
            ctx.fillStyle = toCss(ink, 0.05);
            for (let y = 0; y < along; y += 4) ctx.fillRect(0, y, across, 1);
            ctx.fillStyle = toCss(ink, 0.2);
            for (let y = 0; y < along; y += 14) {
                ctx.fillRect(across * 0.075, y, 2, 8);
                ctx.fillRect(across * 0.925 - 2, y, 2, 8);
            }
            return along / across;
        };

        const createSimulation = () => {
            const vectors = () => Array.from({ length: JOINTS + 1 }, () => new THREE.Vector3());
            return {
                nodes: vectors(), previous: vectors(), velocities: vectors(),
                lengths: new Float64Array(JOINTS), impulses: new Float64Array(JOINTS),
                rest: 1, anchor: new THREE.Vector3(),
                body: {
                    position: new THREE.Vector3(), previous: new THREE.Vector3(), velocity: new THREE.Vector3(),
                    quaternion: new THREE.Quaternion(), previousQuaternion: new THREE.Quaternion(),
                    angular: new THREE.Vector3(), mass: 1, inverseInertia: new THREE.Vector3(1, 1, 1), hang: new THREE.Vector3()
                },
                grab: null, turn: 0, twist: 0, twistRaw: 0, calm: 0
            };
        };

        const scratch = {
            a: new THREE.Vector3(), b: new THREE.Vector3(), c: new THREE.Vector3(), d: new THREE.Vector3(),
            e: new THREE.Vector3(), f: new THREE.Vector3(), g: new THREE.Vector3(), pin: new THREE.Vector3(),
            q: new THREE.Quaternion(), r: new THREE.Quaternion()
        };

        const applyInverseInertia = (body, vector, out) => {
            scratch.r.copy(body.quaternion).invert();
            out.copy(vector).applyQuaternion(scratch.r);
            out.set(out.x * body.inverseInertia.x, out.y * body.inverseInertia.y, out.z * body.inverseInertia.z);
            return out.applyQuaternion(body.quaternion);
        };

        const rotateBody = (q, w) => {
            const { x: qx, y: qy, z: qz, w: qw } = q;
            q.x += 0.5 * (w.x * qw + w.y * qz - w.z * qy);
            q.y += 0.5 * (w.y * qw + w.z * qx - w.x * qz);
            q.z += 0.5 * (w.z * qw + w.x * qy - w.y * qx);
            q.w += 0.5 * (-w.x * qx - w.y * qy - w.z * qz);
            q.normalize();
        };

        const bodyWeight = (body, r, normal) => {
            const rn = scratch.c.crossVectors(r, normal);
            return 1 / body.mass + rn.dot(applyInverseInertia(body, rn, scratch.d));
        };

        const pushBody = (body, r, impulse) => {
            body.position.addScaledVector(impulse, 1 / body.mass);
            rotateBody(body.quaternion, applyInverseInertia(body, scratch.e.crossVectors(r, impulse), scratch.f));
        };

        const solveSegment = (sim, i, h, physics) => {
            const { nodes, body } = sim;
            const a = nodes[i];
            const wa = i === 0 ? 0 : NODE_WEIGHT;
            const last = i === JOINTS - 1;
            const r = scratch.g;
            let b = nodes[i + 1];
            if (last) {
                r.copy(body.hang).applyQuaternion(body.quaternion);
                b = scratch.b.copy(body.position).add(r);
            }
            const delta = scratch.a.subVectors(b, a);
            const length = delta.length();
            const stretch = length - sim.rest;
            if (stretch <= 0 || length < 1e-9) return;
            const normal = delta.divideScalar(length);
            const wb = last ? bodyWeight(body, r, normal) : NODE_WEIGHT;
            const rate = (length - sim.lengths[i]) / h;
            const tension = physics.hold * Math.tanh((physics.stiffness * stretch) / physics.hold) + physics.spring * stretch + physics.bandDamping * Math.max(0, rate);
            const room = tension * h * h - sim.impulses[i];
            if (room <= 0) return;
            const lambda = Math.min(stretch / (wa + wb), room);
            sim.impulses[i] += lambda;
            if (wa) a.addScaledVector(normal, lambda * wa);
            if (last) pushBody(body, r, normal.multiplyScalar(-lambda));
            else b.addScaledVector(normal, -lambda * wb);
        };

        const solvePin = (body, grab, target) => {
            const r = scratch.g.copy(grab.local).applyQuaternion(body.quaternion);
            const delta = scratch.a.copy(target).sub(body.position).sub(r);
            const distance = delta.length();
            if (distance < 1e-9) return;
            const normal = delta.divideScalar(distance);
            const lambda = distance / bodyWeight(body, r, normal);
            pushBody(body, r, normal.multiplyScalar(lambda));
        };

        const placeHanging = (sim, layout, intro) => {
            const { nodes, previous, velocities, body, anchor } = sim;
            nodes[0].copy(anchor);
            for (let i = 1; i <= JOINTS; i++) {
                nodes[i].copy(nodes[i - 1]);
                if (intro) nodes[i].x += sim.rest;
                else nodes[i].y -= sim.rest;
            }
            body.quaternion.identity();
            if (intro) body.quaternion.setFromAxisAngle(new THREE.Vector3(0, 0, 1), Math.PI / 2);
            body.hang.set(0, layout.hangY, 0);
            const offset = scratch.a.copy(body.hang).applyQuaternion(body.quaternion);
            body.position.copy(nodes[JOINTS]).sub(offset);
            body.previous.copy(body.position);
            body.previousQuaternion.copy(body.quaternion);
            body.velocity.set(0, 0, 0);
            body.angular.set(0, 0, 0);
            for (let i = 0; i <= JOINTS; i++) {
                previous[i].copy(nodes[i]);
                velocities[i].set(0, 0, 0);
            }
            sim.lengths.fill(sim.rest);
            sim.turn = 0; sim.twist = 0; sim.twistRaw = 0; sim.calm = 0;
        };

        const configureSimulation = (sim, layout, length) => {
            sim.rest = length / JOINTS;
            const { width, height } = layout;
            const body = sim.body;
            const t = THICKNESS + BEVEL * 2;
            body.mass = 1;
            body.inverseInertia.set(12 / (height * height + t * t), 12 / (width * width + t * t), 12 / (width * width + height * height));
            body.hang.set(0, layout.hangY, 0);
        };

        const stepSimulation = (sim, dt, physics, time) => {
            const steps = Math.max(1, Math.ceil(dt / MAX_STEP - 1e-6));
            const h = dt / steps;
            const { nodes, previous, velocities, body } = sim;
            const windX = physics.breeze * (Math.sin(time * 0.53) * 0.7 + Math.sin(time * 1.31 + 1.7) * 0.3) * 3;
            const windZ = physics.breeze * Math.sin(time * 0.37 + 0.6) * 2;
            const grab = sim.grab;
            const pin = scratch.pin;
            const nodeDrag = Math.exp(-physics.nodeDrag * h);
            const linearDrag = Math.exp(-physics.linearDrag * h);
            const angularDrag = Math.exp(-physics.angularDrag * h);

            for (let s = 0; s < steps; s++) {
                for (let i = 1; i < JOINTS; i++) {
                    const v = velocities[i];
                    v.y -= physics.gravity * h;
                    v.x += windX * h;
                    v.z += windZ * h;
                    previous[i].copy(nodes[i]);
                    nodes[i].addScaledVector(v, h);
                }

                body.previous.copy(body.position);
                body.previousQuaternion.copy(body.quaternion);
                const q = body.quaternion;
                if (grab) {
                    const turn = scratch.q.copy(grab.rotation).multiply(scratch.r.copy(q).invert());
                    if (turn.w < 0) turn.set(-turn.x, -turn.y, -turn.z, -turn.w);
                    const torque = scratch.a.set(turn.x, turn.y, turn.z).multiplyScalar(2 * physics.grip);
                    torque.addScaledVector(body.angular, -physics.gripDamping);
                    body.angular.addScaledVector(applyInverseInertia(body, torque, scratch.b), h);
                }
                const up = scratch.a.set(0, 1, 0).applyQuaternion(q);
                const front = scratch.b.set(-up.x * up.z, -up.y * up.z, 1 - up.z * up.z);
                if (front.lengthSq() > 1e-3) {
                    const facing = scratch.d.set(0, 0, 1).applyQuaternion(q);
                    const raw = Math.atan2(scratch.e.crossVectors(front, facing).dot(up), front.dot(facing));
                    let turned = raw - sim.twistRaw;
                    if (turned > Math.PI) turned -= Math.PI * 2;
                    else if (turned < -Math.PI) turned += Math.PI * 2;
                    sim.twist += turned;
                    sim.twistRaw = raw;
                }
                if (!grab) {
                    const spin = body.angular.dot(up);
                    body.angular.addScaledVector(up, (physics.twist * (sim.turn - sim.twist) - physics.twistDamping * spin) * h);
                }
                body.velocity.y -= physics.gravity * h;
                body.velocity.x += windX * 0.35 * h;
                body.velocity.z += windZ * 0.35 * h;
                body.position.addScaledVector(body.velocity, h);
                rotateBody(body.quaternion, scratch.f.copy(body.angular).multiplyScalar(h));

                sim.impulses.fill(0);
                if (grab) pin.lerpVectors(grab.from, grab.target, (s + 1) / steps);
                for (let iteration = 0; iteration < ITERATIONS; iteration++) {
                    for (let i = 0; i < JOINTS; i++) solveSegment(sim, i, h, physics);
                    if (grab) solvePin(body, grab, pin);
                }

                for (let i = 1; i < JOINTS; i++) {
                    const v = velocities[i].subVectors(nodes[i], previous[i]).divideScalar(h).multiplyScalar(nodeDrag);
                    v.multiplyScalar(1 / (1 + physics.nodeAir * v.length() * h));
                }
                body.velocity.subVectors(body.position, body.previous).divideScalar(h);
                const dq = scratch.q.copy(body.previousQuaternion).invert().premultiply(body.quaternion);
                body.angular.set(dq.x, dq.y, dq.z).multiplyScalar((2 / h) * (dq.w < 0 ? -1 : 1));

                const normal = scratch.a.set(0, 0, 1).applyQuaternion(body.quaternion);
                const across = body.velocity.dot(normal);
                const along = scratch.b.copy(body.velocity).addScaledVector(normal, -across);
                along.multiplyScalar(1 / (1 + physics.air * along.length() * h));
                body.velocity.copy(along).addScaledVector(normal, across / (1 + physics.broadside * Math.abs(across) * h)).multiplyScalar(linearDrag);
                body.angular.multiplyScalar(angularDrag / (1 + physics.spinAir * body.angular.length() * h));
                if (body.velocity.lengthSq() > 3600) body.velocity.setLength(60);
                if (body.angular.lengthSq() > 1600) body.angular.setLength(40);

                nodes[JOINTS].copy(body.hang).applyQuaternion(body.quaternion).add(body.position);
                for (let i = 0; i < JOINTS; i++) sim.lengths[i] = nodes[i].distanceTo(nodes[i + 1]);
            }

            if (grab) grab.from.copy(grab.target);

            let energy = body.velocity.lengthSq() + body.angular.lengthSq() * 0.3;
            for (let i = 1; i < JOINTS; i++) energy += velocities[i].lengthSq() * 0.05;
            sim.calm = energy < 2e-4 ? sim.calm + dt : 0;
        };

        /* ---------- one card on a band, drawn into `container` ---------- */
        return function create(container, options) {
            const S = Object.assign({
                front: "", back: "", transparent: false, imageFit: "contain", cardColor: "#ffffff",
                orientation: "portrait", cornerRadius: 0.3, size: 0.6, anchor: "center", strapLength: 0.5,
                strapImage: "", strapColor: "#151515", strapWidth: 0.65, metal: "silver",
                gravity: 1, damping: 0.5, elasticity: 0.5, breeze: 0.5, interactive: true, intro: true
            }, options || {});

            let renderer;
            try {
                renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true, powerPreference: "high-performance" });
            } catch (e) { return null; }
            renderer.setClearColor(0x000000, 0);
            renderer.outputColorSpace = THREE.SRGBColorSpace;
            renderer.toneMapping = THREE.NeutralToneMapping;
            renderer.toneMappingExposure = 1;
            const canvas = renderer.domElement;
            canvas.className = "lanyard-canvas";
            canvas.setAttribute("aria-hidden", "true");
            container.appendChild(canvas);

            const anisotropy = Math.min(8, renderer.capabilities.getMaxAnisotropy());
            const scene = new THREE.Scene();
            const environment = buildEnvironment(renderer);
            scene.environment = environment.texture;
            const camera = new THREE.PerspectiveCamera(FOV, 1, 0.1, 200);
            const keyLight = new THREE.DirectionalLight(0xffffff, 1.6);
            keyLight.position.set(-2.5, 4, 6);
            const fillLight = new THREE.DirectionalLight(0xffffff, 0.35);
            fillLight.position.set(3, -1, 4);
            scene.add(keyLight, fillLight);

            const imageCache = new Map();
            const images = { front: null, back: null, strap: null };
            const frontCanvas = document.createElement("canvas");
            const backCanvas = document.createElement("canvas");
            const strapCanvas = document.createElement("canvas");
            const frontTexture = makeCanvasTexture(frontCanvas, anisotropy);
            const backTexture = makeCanvasTexture(backCanvas, anisotropy);
            const strapTexture = makeCanvasTexture(strapCanvas, anisotropy);
            strapTexture.wrapS = THREE.ClampToEdgeWrapping;
            strapTexture.wrapT = THREE.RepeatWrapping;
            const grain = buildGrainTexture();
            const weave = buildWeaveTexture();

            const frontMaterial = new THREE.MeshPhysicalMaterial({ map: frontTexture, normalMap: grain, normalScale: new THREE.Vector2(0.06, 0.06) });
            const backMaterial = new THREE.MeshPhysicalMaterial({ map: backTexture, normalMap: grain, normalScale: new THREE.Vector2(0.06, 0.06) });
            const edgeMaterial = new THREE.MeshPhysicalMaterial();
            const metalMaterial = new THREE.MeshStandardMaterial({ metalness: 1 });
            const bandMaterial = new THREE.MeshPhysicalMaterial({
                map: strapTexture, normalMap: weave, normalScale: new THREE.Vector2(0.7, 0.7), roughness: 0.68,
                sheen: 1, sheenRoughness: 0.42, sheenColor: new THREE.Color(0.32, 0.32, 0.34), side: THREE.DoubleSide
            });

            const cardGroup = new THREE.Group();
            const bodyMesh = new THREE.Mesh(undefined, edgeMaterial);
            const frontMesh = new THREE.Mesh(undefined, frontMaterial);
            const backMesh = new THREE.Mesh(undefined, backMaterial);
            const ring = new THREE.Mesh(new THREE.TorusGeometry(RING_RADIUS, RING_TUBE, 18, 72), metalMaterial);
            ring.rotation.y = Math.PI / 2 - 0.7;
            cardGroup.add(bodyMesh, frontMesh, backMesh, ring);
            scene.add(cardGroup);

            const bandGeometry = buildBandGeometry(SAMPLES);
            const band = new THREE.Mesh(bandGeometry, bandMaterial);
            band.frustumCulled = false;
            scene.add(band);

            const clampGroup = new THREE.Group();
            const clampMesh = new THREE.Mesh(buildClampGeometry(STRAP_WIDTH), metalMaterial);
            clampMesh.position.y = EYELET_RADIUS + CLAMP_BODY / 2 - 0.004;
            const eyelet = new THREE.Mesh(new THREE.TorusGeometry(EYELET_RADIUS, 0.0105, 14, 40), metalMaterial);
            clampGroup.add(clampMesh, eyelet);
            scene.add(clampGroup);

            const sim = createSimulation();
            const controls = [];
            const mouth = new THREE.Vector3();
            const tuck = new THREE.Vector3();
            const curve = new THREE.CatmullRomCurve3(controls, false, "centripetal");
            const view = { width: 1, height: 1 };
            let layout = cardLayout("portrait", 0.3);
            let applied = {};
            let strapScale = STRAP_WIDTH;
            let tileRatio = 2;
            let raf = 0;
            let last = performance.now();
            let time = 0;
            let visible = true;
            let alive = true;
            let placed = false;
            let hovering = false;
            let press = null;
            let imageToken = 0;
            const reducedMotion = window.matchMedia ? window.matchMedia("(prefers-reduced-motion: reduce)").matches : false;

            const paintFaces = () => {
                const color = parseColor(S.cardColor, [1, 1, 1]);
                const width = FACE_WIDTH;
                const height = Math.round((FACE_WIDTH * layout.height) / layout.width);
                if (frontCanvas.width !== width || frontCanvas.height !== height) {
                    frontCanvas.width = width; frontCanvas.height = height;
                    backCanvas.width = width; backCanvas.height = height;
                    frontTexture.dispose(); backTexture.dispose();
                }
                paintFace(frontCanvas, images.front, color, S.imageFit, S.transparent);
                paintFace(backCanvas, images.back || images.front, color, S.imageFit, S.transparent);
                frontTexture.needsUpdate = true;
                backTexture.needsUpdate = true;
                start();
            };

            const repaintStrap = () => {
                tileRatio = paintStrap(strapCanvas, images.strap, parseColor(S.strapColor, [0.07, 0.07, 0.07]));
                weave.repeat.set(2, 2 * tileRatio);
                strapTexture.dispose();
                strapTexture.needsUpdate = true;
                start();
            };

            const frameView = () => {
                const aspect = view.width / view.height;
                let viewHeight = layout.height / clamp(S.size, 0.15, 0.9);
                const minimumWidth = layout.width / 0.72;
                if (viewHeight * aspect < minimumWidth) viewHeight = minimumWidth / aspect;
                const viewWidth = viewHeight * aspect;
                camera.aspect = aspect;
                camera.position.set(0, 0, viewHeight / 2 / Math.tan((FOV * Math.PI) / 360));
                camera.lookAt(0, 0, 0);
                camera.updateProjectionMatrix();
                camera.updateMatrixWorld();
                const anchorX = ((ANCHORS[S.anchor] !== undefined ? ANCHORS[S.anchor] : 0.5) - 0.5) * viewWidth;
                const anchorY = viewHeight / 2 + 0.2;
                const cardTop = viewHeight / 2 - viewHeight * mix(0.12, 0.42, clamp(S.strapLength, 0, 1));
                const hangTop = cardTop + (layout.hangY - layout.height / 2);
                sim.anchor.set(anchorX, anchorY, 0);
                configureSimulation(sim, layout, anchorY - hangTop);
                sim.nodes[0].copy(sim.anchor);
                sim.previous[0].copy(sim.anchor);
            };

            const apply = () => {
                const layoutKey = S.orientation + "|" + S.cornerRadius;
                if (layoutKey !== applied.layoutKey) {
                    layout = cardLayout(S.orientation, S.cornerRadius);
                    const geometry = buildCardGeometry(layout);
                    bodyMesh.geometry.dispose();
                    frontMesh.geometry.dispose();
                    backMesh.geometry.dispose();
                    bodyMesh.geometry = geometry.body;
                    frontMesh.geometry = geometry.face;
                    backMesh.geometry = geometry.face.clone();
                    frontMesh.position.z = THICKNESS / 2 + BEVEL + 0.0006;
                    backMesh.rotation.y = Math.PI;
                    backMesh.position.z = -(THICKNESS / 2 + BEVEL + 0.0006);
                    ring.position.set(0, layout.ringY, 0);
                    applied.framingKey = "";
                }
                const framingKey = [layoutKey, S.size, S.anchor, S.strapLength, view.width, view.height].join("|");
                if (framingKey !== applied.framingKey) {
                    frameView();
                    if (!placed) {
                        placeHanging(sim, layout, S.intro && !reducedMotion);
                        placed = true;
                    } else if (layoutKey !== applied.layoutKey) {
                        placeHanging(sim, layout, false);
                    }
                }

                const imageKey = S.front + "|" + S.back + "|" + S.strapImage;
                if (imageKey !== applied.imageKey) {
                    const token = ++imageToken;
                    Promise.all([loadImage(imageCache, S.front), loadImage(imageCache, S.back), loadImage(imageCache, S.strapImage)]).then(([front, back, strap]) => {
                        if (!alive || token !== imageToken) return;
                        images.front = front; images.back = back; images.strap = strap;
                        paintFaces();
                        repaintStrap();
                    });
                }
                const faceKey = [layoutKey, S.cardColor, S.imageFit, S.transparent].join("|");
                if (faceKey !== applied.faceKey) paintFaces();
                if (S.strapColor !== applied.strapColor) repaintStrap();

                const card = parseColor(S.cardColor, [1, 1, 1]);
                [frontMaterial, backMaterial].forEach(material => {
                    material.roughness = FINISH.roughness;
                    material.metalness = FINISH.metalness;
                    material.clearcoat = FINISH.clearcoat;
                    material.clearcoatRoughness = FINISH.clearcoatRoughness;
                    material.anisotropy = FINISH.anisotropy;
                    if (material.alphaTest !== (S.transparent ? 0.35 : 0)) {
                        material.alphaTest = S.transparent ? 0.35 : 0;   // cut the card out along the picture
                        material.needsUpdate = true;
                    }
                });
                bodyMesh.visible = !S.transparent;   // no plastic slab behind a picture that has no background
                edgeMaterial.color.setRGB(card[0] * 0.92, card[1] * 0.92, card[2] * 0.92, THREE.SRGBColorSpace);
                edgeMaterial.roughness = Math.min(FINISH.roughness, 0.35);
                edgeMaterial.metalness = FINISH.metalness;
                edgeMaterial.clearcoat = 1;
                edgeMaterial.clearcoatRoughness = 0.08;
                const metalLook = METALS[S.metal] || METALS.silver;
                metalMaterial.color.set(metalLook.color);
                metalMaterial.roughness = metalLook.roughness;
                strapScale = STRAP_WIDTH * clamp(S.strapWidth, 0.4, 2);
                if (strapScale !== applied.strapScale) {
                    clampMesh.geometry.dispose();
                    clampMesh.geometry = buildClampGeometry(strapScale);
                }
                canvas.style.touchAction = S.interactive ? "pan-y" : "auto";

                applied = { layoutKey, framingKey, imageKey, faceKey, strapColor: S.strapColor, strapScale };
                start();
            };

            const points = Array.from({ length: SAMPLES }, () => new THREE.Vector3());
            const sides = Array.from({ length: SAMPLES }, () => new THREE.Vector3());
            const bends = BAND_BEND.map(angle => [Math.cos(angle), Math.sin(angle)]);
            const work = {
                tangent: new THREE.Vector3(), toCamera: new THREE.Vector3(), basis: new THREE.Matrix4(),
                up: new THREE.Vector3(), across: new THREE.Vector3(), facing: new THREE.Vector3(), end: new THREE.Vector3()
            };

            const updateBand = () => {
                const hang = sim.nodes[JOINTS];
                const reach = EYELET_RADIUS + CLAMP_BODY + 0.05;
                let guide = JOINTS - 1;
                while (guide > 0 && sim.nodes[guide].distanceTo(hang) < reach * 1.1) guide--;
                const axis = work.up.subVectors(sim.nodes[guide], hang);
                if (axis.lengthSq() < 1e-8) axis.set(0, 1, 0).applyQuaternion(sim.body.quaternion);
                axis.normalize();
                mouth.copy(hang).addScaledVector(axis, reach);
                tuck.copy(hang).addScaledVector(axis, EYELET_RADIUS + CLAMP_BODY * 0.35);
                controls.length = 0;
                for (let i = 0; i <= guide; i++) controls.push(sim.nodes[i]);
                controls.push(mouth, tuck);
                curve.updateArcLengths();
                for (let i = 0; i < SAMPLES; i++) curve.getPointAt(i / (SAMPLES - 1), points[i]);
                const length = curve.getLength();
                const held = 1 - mouth.distanceTo(tuck) / Math.max(length, 0.001);
                const position = bandGeometry.getAttribute("position");
                const normal = bandGeometry.getAttribute("normal");
                const uv = bandGeometry.getAttribute("uv");
                const columns = BAND_COLUMNS.length;
                const restLength = sim.rest * JOINTS;
                const repeats = restLength / (strapScale * tileRatio);
                const width = strapScale / Math.pow(Math.max(1, length / Math.max(restLength, 0.001)), 0.35);
                const { tangent, toCamera, across, facing } = work;
                for (let i = 0; i < SAMPLES; i++) {
                    tangent.subVectors(points[Math.min(SAMPLES - 1, i + 1)], points[Math.max(0, i - 1)]);
                    if (tangent.lengthSq() < 1e-12) tangent.set(0, -1, 0);
                    tangent.normalize();
                    toCamera.subVectors(camera.position, points[i]).normalize();
                    const side = sides[i].crossVectors(toCamera, tangent);
                    if (side.lengthSq() < 1e-10) side.copy(i > 0 ? sides[i - 1] : scratch.a.set(1, 0, 0));
                    side.normalize();
                    if (i > 0 && side.dot(sides[i - 1]) < 0) side.negate();
                    const angle = -sim.twist * Math.pow(Math.min(1, i / (SAMPLES - 1) / held), 1.3);
                    facing.crossVectors(tangent, side);
                    across.copy(side).multiplyScalar(Math.cos(angle)).addScaledVector(facing, Math.sin(angle));
                    facing.crossVectors(tangent, across);
                    const p = points[i];
                    const v = (1 - i / (SAMPLES - 1)) * repeats;
                    for (let c = 0; c < columns; c++) {
                        const k = i * columns + c;
                        const offset = (BAND_COLUMNS[c] - 0.5) * width;
                        const bendCos = bends[c][0], bendSin = bends[c][1];
                        position.setXYZ(k, p.x + across.x * offset, p.y + across.y * offset, p.z + across.z * offset);
                        normal.setXYZ(k,
                            facing.x * bendCos + across.x * bendSin,
                            facing.y * bendCos + across.y * bendSin,
                            facing.z * bendCos + across.z * bendSin);
                        uv.setXY(k, BAND_COLUMNS[c], v);
                    }
                }
                work.end.copy(across);
                position.needsUpdate = true;
                normal.needsUpdate = true;
                uv.needsUpdate = true;

                across.copy(work.end).addScaledVector(axis, -work.end.dot(axis)).normalize();
                facing.crossVectors(across, axis).normalize();
                work.basis.makeBasis(across, axis, facing);
                clampGroup.quaternion.setFromRotationMatrix(work.basis);
                clampGroup.position.copy(hang);
            };

            const render = () => {
                cardGroup.position.copy(sim.body.position);
                cardGroup.quaternion.copy(sim.body.quaternion);
                updateBand();
                renderer.render(scene, camera);
            };

            const physics = () => {
                const gravity = 40 * clamp(S.gravity, 0, 3);
                const weight = Math.max(gravity, 20);
                const springy = clamp(S.elasticity, 0, 1);
                const settle = clamp(S.damping, 0, 1);
                return {
                    gravity,
                    hold: weight * mix(2.2, 5.5, springy),
                    stiffness: weight * 10 * JOINTS,
                    spring: weight * mix(0.1, 0.4, springy) * JOINTS,
                    bandDamping: weight * mix(0.3, 0.08, springy) * JOINTS,
                    linearDrag: 0.25 * Math.pow(16, settle),
                    angularDrag: 0.5 * Math.pow(12, settle),
                    air: 0.15, broadside: 0.5, spinAir: 0.03, nodeDrag: 2, nodeAir: 0.2,
                    twist: 30, twistDamping: 3, grip: 40, gripDamping: 6,
                    breeze: reducedMotion ? 0 : clamp(S.breeze, 0, 1)
                };
            };

            const tick = now => {
                raf = 0;
                if (!alive) return;
                const dt = Math.min(1 / 30, Math.max(1 / 240, (now - last) / 1000));
                last = now;
                time += dt;
                const current = physics();
                stepSimulation(sim, dt, current, time);
                const body = sim.body;
                if (!Number.isFinite(body.position.x + body.position.y + body.position.z + body.quaternion.w)) {
                    sim.grab = null;
                    placeHanging(sim, layout, false);
                }
                render();
                const resting = sim.calm > 1.2 && !sim.grab && current.breeze === 0;
                if (visible && !resting) raf = requestAnimationFrame(tick);
            };

            const start = () => {
                if (raf || !visible || !alive) return;
                sim.calm = 0;
                last = performance.now();
                raf = requestAnimationFrame(tick);
            };

            const raycaster = new THREE.Raycaster();
            const pointer = new THREE.Vector2();

            const toPointer = event => {
                const rect = canvas.getBoundingClientRect();
                pointer.set(((event.clientX - rect.left) / rect.width) * 2 - 1, -((event.clientY - rect.top) / rect.height) * 2 + 1);
                raycaster.setFromCamera(pointer, camera);
            };

            const pickCard = () => {
                cardGroup.updateMatrixWorld();
                const hits = raycaster.intersectObjects([bodyMesh, frontMesh, backMesh], false);
                return hits[0] || null;
            };

            const setCursor = value => { if (canvas.style.cursor !== value) canvas.style.cursor = value; };

            const flip = point => {
                const body = sim.body;
                const up = scratch.a.set(0, 1, 0).applyQuaternion(body.quaternion);
                let direction = sim.turn > 0 ? -1 : 1;
                if (sim.turn === 0) {
                    const away = scratch.c.subVectors(point, camera.position).normalize();
                    direction = scratch.b.subVectors(point, body.position).cross(away).dot(up) < 0 ? -1 : 1;
                }
                sim.turn = sim.turn === 0 ? Math.PI * direction : 0;
                body.angular.addScaledVector(up, FLIP_SPIN * direction);
            };

            const onPointerDown = event => {
                if (!S.interactive || event.button > 0) return;
                toPointer(event);
                const hit = pickCard();
                if (!hit) return;
                sim.grab = {
                    local: hit.point.clone().sub(sim.body.position).applyQuaternion(sim.body.quaternion.clone().invert()),
                    rotation: sim.body.quaternion.clone(),
                    reach: hit.distance,
                    from: hit.point.clone(),
                    target: hit.point.clone()
                };
                press = { x: event.clientX, y: event.clientY, time: performance.now(), point: hit.point.clone() };
                if (canvas.setPointerCapture) canvas.setPointerCapture(event.pointerId);
                setCursor("grabbing");
                event.preventDefault();
                start();
            };

            const onPointerMove = event => {
                if (!S.interactive) return;
                toPointer(event);
                if (sim.grab) {
                    if (press && Math.hypot(event.clientX - press.x, event.clientY - press.y) > 6) press = null;
                    raycaster.ray.at(sim.grab.reach, sim.grab.target);
                    start();
                    return;
                }
                if (event.pointerType === "touch") return;
                hovering = !!pickCard();
                setCursor(hovering ? "grab" : "");
            };

            const onPointerUp = event => {
                if (!sim.grab) return;
                sim.grab = null;
                if (press && event.type === "pointerup" && performance.now() - press.time < 450) flip(press.point);
                press = null;
                if (canvas.releasePointerCapture) { try { canvas.releasePointerCapture(event.pointerId); } catch (e) {} }
                setCursor(hovering && event.pointerType !== "touch" ? "grab" : "");
                start();
            };

            const onTouchStart = event => {
                if (!S.interactive || event.touches.length !== 1) return;
                toPointer(event.touches[0]);
                if (pickCard()) event.preventDefault();
            };

            canvas.addEventListener("pointerdown", onPointerDown);
            canvas.addEventListener("pointermove", onPointerMove);
            canvas.addEventListener("pointerup", onPointerUp);
            canvas.addEventListener("pointercancel", onPointerUp);
            canvas.addEventListener("lostpointercapture", onPointerUp);
            canvas.addEventListener("touchstart", onTouchStart, { passive: false });

            const resize = () => {
                view.width = Math.max(1, container.clientWidth);
                view.height = Math.max(1, container.clientHeight);
                renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
                renderer.setSize(view.width, view.height, false);
                apply();
                render();
            };

            const resizeObserver = new ResizeObserver(resize);
            resizeObserver.observe(container);
            const intersectionObserver = new IntersectionObserver(entries => {
                visible = entries[0].isIntersecting;
                if (visible) start();
            });
            intersectionObserver.observe(container);
            const onVisibility = () => { if (!document.hidden) start(); };
            document.addEventListener("visibilitychange", onVisibility);

            resize();

            return {
                update(next) { Object.assign(S, next); apply(); },
                destroy() {
                    alive = false;
                    cancelAnimationFrame(raf);
                    resizeObserver.disconnect();
                    intersectionObserver.disconnect();
                    document.removeEventListener("visibilitychange", onVisibility);
                    canvas.removeEventListener("pointerdown", onPointerDown);
                    canvas.removeEventListener("pointermove", onPointerMove);
                    canvas.removeEventListener("pointerup", onPointerUp);
                    canvas.removeEventListener("pointercancel", onPointerUp);
                    canvas.removeEventListener("lostpointercapture", onPointerUp);
                    canvas.removeEventListener("touchstart", onTouchStart);
                    [bodyMesh, frontMesh, backMesh, ring, clampMesh, eyelet, band].forEach(mesh => { if (mesh.geometry) mesh.geometry.dispose(); });
                    [frontMaterial, backMaterial, edgeMaterial, metalMaterial, bandMaterial].forEach(material => material.dispose());
                    [frontTexture, backTexture, strapTexture, grain, weave].forEach(texture => texture.dispose());
                    environment.dispose();
                    renderer.dispose();
                    if (canvas.parentNode) canvas.parentNode.removeChild(canvas);
                }
            };
        };
    }

    /* ---------- the card face: my picture (and name) drawn like an ID card ---------- */
    const FACE_W = 768, FACE_H = 1080;   // same shape as the card (1.6 : 2.25)

    function loadPicture(url) {
        return new Promise(resolve => {
            const img = new Image();
            img.onload = () => resolve(img);
            img.onerror = () => resolve(null);
            img.src = url;
        });
    }

    /* does the picture have see-through parts? then it is shown without any card behind it */
    function hasTransparency(img) {
        try {
            const c = document.createElement("canvas");
            c.width = c.height = 128;
            const ctx = c.getContext("2d");
            ctx.drawImage(img, 0, 0, 128, 128);
            const d = ctx.getImageData(0, 0, 128, 128).data;
            let clear = 0;
            for (let i = 3; i < d.length; i += 4) if (d[i] < 200) clear++;
            return clear > 128 * 128 * 0.02;
        } catch (e) { return false; }
    }

    async function composeCard() {
        const c = document.createElement("canvas");
        c.width = FACE_W;
        c.height = FACE_H;
        const ctx = c.getContext("2d");
        const name = ME.name;
        const img = ME.av ? await loadPicture("files.php?action=avatar&v=" + ME.av) : null;
        const clear = !!img && hasTransparency(img);

        if (clear) {   // no background: only the picture itself hangs from the band
            ctx.drawImage(img, 24, 150, FACE_W - 48, FACE_W - 48);
            return { url: c.toDataURL("image/png"), transparent: true };
        }

        ctx.fillStyle = "#ffffff";
        ctx.fillRect(0, 0, FACE_W, FACE_H);
        const pad = 56, top = 150, side = FACE_W - pad * 2;
        if (img) {
            ctx.save();
            ctx.beginPath();
            ctx.roundRect(pad, top, side, side, 36);
            ctx.clip();
            ctx.drawImage(img, pad, top, side, side);
            ctx.restore();
        } else {
            ctx.fillStyle = "#151515";
            ctx.beginPath();
            ctx.roundRect(pad, top, side, side, 36);
            ctx.fill();
            ctx.fillStyle = "#ffffff";
            ctx.font = "700 300px 'Public Sans', 'Segoe UI', system-ui, sans-serif";
            ctx.textAlign = "center";
            ctx.textBaseline = "middle";
            ctx.fillText(name.charAt(0).toUpperCase(), FACE_W / 2, top + side / 2 + 14);
        }
        ctx.fillStyle = "#151515";
        ctx.textAlign = "center";
        ctx.textBaseline = "alphabetic";
        let size = 76;
        ctx.font = "700 " + size + "px 'Public Sans', 'Segoe UI', system-ui, sans-serif";
        while (ctx.measureText(name).width > FACE_W - 112 && size > 30) {
            size -= 4;
            ctx.font = "700 " + size + "px 'Public Sans', 'Segoe UI', system-ui, sans-serif";
        }
        ctx.fillText(name, FACE_W / 2, top + side + 120);
        if (!img) {
            ctx.fillStyle = "#6b6b66";
            ctx.font = "500 34px 'Public Sans', 'Segoe UI', system-ui, sans-serif";
            ctx.fillText("Add a profile picture", FACE_W / 2, top + side + 180);
        }
        return { url: c.toDataURL("image/png"), transparent: false };
    }

    /* ---------- start / refresh / stop, driven by the Account dialog ---------- */
    const dlg = document.getElementById("acctDlg");
    const box = document.getElementById("lanyardBox");
    const wide = () => window.matchMedia("(min-width: 860px)").matches;
    const isOpen = () => dlg.classList.contains("open");

    function stop() {
        if (inst) { inst.destroy(); inst = null; }
        box.textContent = "";
    }

    async function begin() {
        if (inst || starting || !isOpen() || !wide()) return;
        starting = true;
        try {
            if (!threeLoad) threeLoad = import(THREE_URL);
            const THREE = await threeLoad;
            if (!factory) factory = makeLanyard(THREE);
            const face = await composeCard();
            if (!isOpen() || inst) return;
            inst = factory(box, { front: face.url, back: face.url, transparent: face.transparent });
            if (!inst) box.textContent = "The 3D card needs WebGL, which this browser has turned off.";
        } catch (e) {
            threeLoad = null;
            box.innerHTML = '<p class="lw-note">The 3D card needs an internet connection to load.</p>';
        } finally {
            starting = false;
        }
    }

    window.lanyardRefresh = async function () {
        if (!inst || !isOpen()) return;
        const face = await composeCard();
        if (inst) inst.update({ front: face.url, back: face.url, transparent: face.transparent });
    };

    new MutationObserver(() => { if (isOpen()) begin(); else stop(); }).observe(dlg, { attributes: true, attributeFilter: ["class"] });
    window.addEventListener("resize", () => { if (isOpen() && !wide()) stop(); else if (isOpen()) begin(); });
})();
</script>
</body>
</html>  
