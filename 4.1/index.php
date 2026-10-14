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
$me     = ["id" => $user["id"], "name" => $user["username"], "admin" => $user["is_admin"], "ai" => !empty($user["agreed_ai_at"])];

// Files this person may see (records of files that vanished from disk are cleaned up inside loadFiles)
$files   = loadFiles($conn, $user);
$version = filesVersion($files);

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
<meta name="theme-color" content="#0b0d11">
<title>Local File Storage</title>
<style>
:root {
    --bg0: #0b0d11;      /* sidebar */
    --bg1: #11141a;      /* file list */
    --bg2: #171b23;      /* details */
    --card: #1d222c;
    --card-2: #252b37;
    --line: #262c38;
    --text: #e7eaf1;
    --muted: #8b94a8;
    --accent: #7b96ff;
    --accent-2: #94abff;
    --accent-ink: #0b1020;
    --danger: #ff6b78;
    --ok: #42d6a4;
    --warn: #ffc44d;
}
* { box-sizing: border-box; -webkit-tap-highlight-color: transparent; scrollbar-width: thin; scrollbar-color: #3a4152 transparent; }
html { color-scheme: dark; }   /* dark scrollbars and form controls on every OS / browser */
html, body { height: 100%; }
html { -webkit-text-size-adjust: 100%; }
body {
    margin: 0;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    background: var(--bg1);
    color: var(--text);
    line-height: 1.45;
    overflow: hidden;
}
button, input, select, textarea { font: inherit; color: inherit; }
:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
.hidden { display: none !important; }
.i { width: 18px; height: 18px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; flex: none; }
.i-lg { width: 24px; height: 24px; }

/* ---------- Buttons ---------- */
.btn {
    display: inline-flex; align-items: center; justify-content: center; gap: 7px;
    min-height: 40px; padding: 8px 14px;
    font-size: .9rem; font-weight: 600; text-decoration: none;
    background: var(--card); border: 1px solid var(--line); border-radius: 9px; color: var(--text);
    cursor: pointer; transition: background .15s, border-color .15s;
}
.btn:hover { background: var(--card-2); }
.btn:disabled { opacity: .5; cursor: not-allowed; }
.btn-primary { background: var(--accent); border-color: var(--accent); color: var(--accent-ink); }
.btn-primary:hover { background: var(--accent-2); border-color: var(--accent-2); }
.btn-danger { color: var(--danger); }
.btn-danger:hover { background: rgba(255,107,120,.12); border-color: var(--danger); }
.btn-primary.danger { background: var(--danger); border-color: var(--danger); color: #1a0508; }
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
.brand .logo { width: 34px; height: 34px; border-radius: 10px; background: var(--accent); color: var(--accent-ink); display: grid; place-items: center; }
.brand h1 { margin: 0; font-size: 1.05rem; letter-spacing: -.01em; line-height: 1.2; }
.live { margin-left: auto; display: inline-flex; align-items: center; gap: 6px; font-size: .76rem; color: var(--muted); }
.live i { width: 8px; height: 8px; border-radius: 50%; background: var(--ok); box-shadow: 0 0 0 3px rgba(66,214,164,.16); }
.live.off i { background: var(--warn); box-shadow: 0 0 0 3px rgba(255,196,77,.16); }
.side-close { display: none; }

.side h2 { margin: 0 0 8px; font-size: .82rem; font-weight: 600; color: var(--muted); }

/* Upload */
.dropzone {
    display: flex; flex-direction: column; align-items: center; gap: 4px; text-align: center;
    padding: 20px 12px; border: 1.5px dashed var(--line); border-radius: 12px; background: var(--bg1);
    cursor: pointer; transition: border-color .15s, background .15s;
}
.dropzone:hover { border-color: var(--accent); background: #141927; }
.dropzone .i { width: 28px; height: 28px; color: var(--accent); }
.dropzone strong { font-size: .95rem; }
.dropzone span, .dropzone small { color: var(--muted); font-size: .8rem; }
.dropzone input { display: none; }
.upload .btn-primary { width: 100%; margin-top: 10px; }
.queue { margin-top: 10px; display: flex; flex-direction: column; gap: 6px; max-height: 260px; overflow-y: auto; }
.qitem { display: flex; align-items: center; gap: 10px; padding: 8px 10px; background: var(--card); border: 1px solid var(--line); border-radius: 10px; }
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
    padding: 8px 10px; border: 0; border-radius: 9px; background: transparent;
    color: var(--muted); text-align: left; cursor: pointer; font-size: .9rem;
    transition: background .15s, color .15s;
}
.g i { width: 9px; height: 9px; border-radius: 3px; flex: none; }
.g span { flex: 1; }
.g b { font-weight: 500; font-size: .8rem; }
.g:hover { background: var(--card); color: var(--text); }
.g.on { background: var(--card); color: var(--text); }
.g.on b { color: var(--accent-2); }
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
    background: var(--card); border: 1px solid var(--line); border-radius: 10px; outline: none;
}
.search input:focus { border-color: var(--accent); }
.search input::-webkit-search-cancel-button { -webkit-appearance: none; display: none; }
.search input { -webkit-appearance: none; appearance: none; }
.search input::placeholder { color: var(--muted); }
.search .icon-btn { position: absolute; right: 4px; top: 4px; }
.sort { height: 42px; padding: 0 10px; background: var(--card); border: 1px solid var(--line); border-radius: 10px; cursor: pointer; }
.sort option { background: var(--card); }

.chips { display: none; gap: 8px; padding: 0 16px 8px; overflow-x: auto; scrollbar-width: none; }
.chips::-webkit-scrollbar { display: none; }
.chips .g { width: auto; flex: none; padding: 6px 12px; border: 1px solid var(--line); border-radius: 999px; gap: 7px; }
.chips .g.on { border-color: var(--accent); }

.resultbar { display: flex; align-items: center; gap: 8px; padding: 6px 16px 10px; border-bottom: 1px solid var(--line); }
.resultbar .sum { flex: 1; min-width: 0; font-size: .85rem; color: var(--muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

.rows { flex: 1; overflow-y: auto; padding: 8px 10px calc(24px + env(safe-area-inset-bottom)); }
.row {
    position: relative; display: flex; align-items: center; gap: 12px;
    padding: 10px 10px 10px 16px; border-radius: 10px; cursor: pointer;
    border-bottom: 1px solid rgba(255,255,255,.04);
}
.row::before { content: ""; position: absolute; left: 4px; top: 10px; bottom: 10px; width: 3px; border-radius: 3px; background: var(--c); opacity: .45; }
.row:hover { background: rgba(255,255,255,.035); }
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
@keyframes fresh { from { background: rgba(123,150,255,.3); } to { background: transparent; } }

/* ---------- Select mode (pick files to print) ---------- */
.selbar { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; padding: 8px 16px; background: rgba(123,150,255,.13); border-bottom: 1px solid var(--line); }
.selbar b { flex: 1; min-width: 110px; font-size: .9rem; }
.pk { display: none; place-items: center; flex: 0 0 22px; width: 22px; height: 22px; border: 1.5px solid #4b5468; border-radius: 7px; color: transparent; transition: background .12s, border-color .12s, color .12s; }
.pk .i { width: 14px; height: 14px; stroke-width: 2.8; }
.pickmode .pk { display: grid; }
.pickmode .row { cursor: pointer; user-select: none; }
.pickmode .ractions, .pickmode .rname .icon-btn { display: none; }
.pickmode .row.sel:not(.picked) { background: transparent; }
.row.picked { background: rgba(123,150,255,.14); }
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
    position: relative; height: 230px; border-radius: 12px; background: var(--bg0);
    border: 1px solid var(--line); display: grid; place-items: center; overflow: hidden;
}
.d-stage.can { cursor: pointer; }
.d-stage .ficon { width: 78px; height: 100px; }
.d-stage img { max-width: 100%; max-height: 100%; object-fit: contain; display: block; }
.d-stage .badge { position: absolute; right: 12px; bottom: 12px; width: 44px; height: 44px; border-radius: 50%; background: var(--accent); color: var(--accent-ink); display: grid; place-items: center; box-shadow: 0 4px 14px rgba(0,0,0,.45); transition: transform .15s; }
.d-stage.can:hover .badge { transform: scale(1.08); }
.d-name { display: flex; align-items: flex-start; gap: 4px; }
.d-name h2 { margin: 0; flex: 1; min-width: 0; font-size: 1.1rem; line-height: 1.3; word-break: break-word; }
.d-info { margin: 0; display: grid; grid-template-columns: auto 1fr; gap: 6px 16px; font-size: .88rem; }
.d-info dt { color: var(--muted); }
.d-info dd { margin: 0; text-align: right; }
.d-notes label { display: block; margin-bottom: 6px; font-weight: 600; font-size: .9rem; }
.d-notes textarea {
    width: 100%; min-height: 120px; resize: vertical; padding: 10px 12px;
    background: var(--bg0); border: 1px solid var(--line); border-radius: 10px; outline: none; line-height: 1.5;
}
.d-notes textarea:focus { border-color: var(--accent); }
.d-nstat { display: flex; justify-content: space-between; margin-top: 4px; font-size: .78rem; color: var(--muted); min-height: 1.2em; }
.d-nstat .err { color: var(--danger); }
.d-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.d-actions .wide { grid-column: 1 / -1; }

/* ---------- Dialog, toast, drop overlay ---------- */
.dlg-wrap { position: fixed; inset: 0; z-index: 300; display: none; align-items: center; justify-content: center; padding: 20px; background: rgba(5,7,10,.7); backdrop-filter: blur(3px); }
.dlg-wrap.open { display: flex; }
.dlg { width: 100%; max-width: 420px; background: var(--bg2); border: 1px solid var(--line); border-radius: 14px; padding: 20px; box-shadow: 0 20px 60px rgba(0,0,0,.5); }
.dlg h3 { margin: 0 0 6px; font-size: 1.05rem; }
.dlg p { margin: 0 0 12px; color: var(--muted); font-size: .9rem; word-break: break-word; }
.dlg input { width: 100%; height: 42px; padding: 0 12px; margin: 6px 0 4px; background: var(--bg0); border: 1px solid var(--line); border-radius: 10px; outline: none; }
.dlg input:focus { border-color: var(--accent); }
.dlg .dlg-err { color: var(--danger); font-size: .84rem; min-height: 1.2em; margin: 4px 0 8px; }
.dlg-btns { display: flex; justify-content: flex-end; gap: 8px; margin-top: 8px; }

.toast { position: fixed; left: 50%; bottom: calc(22px + env(safe-area-inset-bottom)); transform: translate(-50%, 20px); z-index: 400; max-width: calc(100vw - 32px); padding: 10px 16px; background: var(--card-2); border: 1px solid var(--line); border-radius: 10px; font-size: .88rem; box-shadow: 0 10px 30px rgba(0,0,0,.45); opacity: 0; pointer-events: none; transition: opacity .2s, transform .2s; }
.toast.on { opacity: 1; transform: translate(-50%, 0); }

.drop-overlay { position: fixed; inset: 0; z-index: 250; display: none; align-items: center; justify-content: center; background: rgba(11,13,17,.82); border: 3px dashed var(--accent); font-size: 1.2rem; font-weight: 600; pointer-events: none; }
.drop-overlay.on { display: flex; }
.scrim { position: fixed; inset: 0; z-index: 55; background: rgba(5,7,10,.6); display: none; }

/* ---------- Preview modal ---------- */
.modal { position: fixed; inset: 0; z-index: 200; display: none; align-items: center; justify-content: center; padding: 20px; background: rgba(5,7,10,.82); backdrop-filter: blur(4px); }
.modal.open { display: flex; }
.pv-box { width: 100%; max-width: 1040px; height: 100%; max-height: 90vh; max-height: 90dvh; display: flex; flex-direction: column; background: var(--bg2); border: 1px solid var(--line); border-radius: 14px; overflow: hidden; }
.pv-box.is-audio { max-width: 460px; height: auto; max-height: 94dvh; }
.pv-box.is-audio .pv-bar { display: none; }      /* the audio player has no title bar, only a small close button */
.au-x { position: absolute; top: 10px; right: 10px; z-index: 3; width: 36px; height: 36px; display: grid; place-items: center; border: 0; border-radius: 50%; background: rgba(0,0,0,.35); color: #fff; cursor: pointer; transition: background .15s; }
.au-x:hover { background: rgba(0,0,0,.6); }
.pv-bar { display: flex; align-items: center; gap: 4px; padding: 6px 8px 6px 16px; border-bottom: 1px solid var(--line); }
.pv-title { flex: 1; min-width: 0; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pv-body { flex: 1; min-height: 0; display: flex; align-items: center; justify-content: center; background: #080a0d; position: relative; }
.pv-body iframe { width: 100%; height: 100%; border: 0; background: #fff; }
.pv-img { max-width: 100%; max-height: 100%; object-fit: contain; }
.pv-msg { color: var(--muted); padding: 24px; text-align: center; }

/* Text / code viewer: dark like the rest of the page (a plain iframe would be white with white text) */
.pv-text { align-self: stretch; width: 100%; height: 100%; margin: 0; padding: 18px 22px 24px; overflow: auto; background: #0b0d11; color: #dfe4f0;
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
.au-art { width: min(68vw, 290px); aspect-ratio: 1; border-radius: 16px; display: grid; place-items: center; overflow: hidden; background: rgba(0,0,0,.28); box-shadow: 0 18px 44px rgba(0,0,0,.5); transform: scale(.93); transition: transform .35s ease; margin-bottom: 14px; }
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
.acct { display: flex; align-items: center; gap: 10px; padding: 10px; background: var(--card); border: 1px solid var(--line); border-radius: 12px; }
.acct .av { flex: none; width: 34px; height: 34px; border-radius: 50%; background: var(--accent); color: var(--accent-ink); display: grid; place-items: center; font-weight: 700; text-transform: uppercase; }
.acct .who { flex: 1; min-width: 0; display: flex; flex-direction: column; line-height: 1.25; }
.acct .who b { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: .92rem; }
.acct .who small { color: var(--muted); font-size: .74rem; }
.acct form { margin: 0; display: inline-flex; }

.share-up { margin-top: 12px; }
.share-up > label { display: block; margin-bottom: 6px; font-size: .8rem; font-weight: 600; color: var(--muted); }
.vis-sel { width: 100%; height: 40px; padding: 0 10px; background: var(--card); border: 1px solid var(--line); border-radius: 10px; cursor: pointer; }
.vis-sel option { background: var(--card); }
.plist { margin-top: 8px; max-height: 190px; overflow-y: auto; background: var(--bg0); border: 1px solid var(--line); border-radius: 10px; padding: 6px; display: flex; flex-direction: column; gap: 2px; }
.prow { display: flex; align-items: center; gap: 12px; padding: 9px 12px; border-radius: 8px; cursor: pointer; font-size: .92rem; font-weight: 500; color: var(--text); line-height: 1.3; }
.prow span { flex: 1; min-width: 0; overflow-wrap: anywhere; }
.prow:hover { background: var(--card); }
.prow input, .dlg .prow input, .dlg .opt input { flex: none; width: 18px; height: 18px; min-width: 18px; margin: 0; padding: 0; accent-color: var(--accent); cursor: pointer; }
.pk-empty { margin: 0; padding: 10px; color: var(--muted); font-size: .82rem; }
.hint-s { margin: 6px 0 0; font-size: .78rem; color: var(--warn); }
.opt { display: flex; align-items: center; gap: 12px; padding: 10px 12px; margin-bottom: 6px; border: 1px solid var(--line); border-radius: 10px; cursor: pointer; font-size: .92rem; }
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
.adm-row { display: flex; align-items: center; gap: 10px; padding: 10px 12px; margin-bottom: 6px; border: 1px solid var(--line); border-radius: 10px; background: var(--card); }
.adm-row .nm { flex: 1; min-width: 0; display: flex; flex-direction: column; line-height: 1.3; }
.adm-row .nm b { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: .92rem; }
.adm-row .nm small { color: var(--muted); font-size: .78rem; }
.adm-row .tag { flex: none; padding: 2px 8px; border-radius: 99px; background: var(--bg0); color: var(--muted); font-size: .72rem; font-weight: 600; }
#logTools { display: flex; justify-content: flex-end; margin: 0 0 10px; }
#logTools.hidden { display: none; }
.adm-empty { color: var(--muted); font-size: .88rem; padding: 8px 2px; }

/* ---------- AI assistant (inside the preview) ---------- */
.ai-fab { position: fixed; right: 20px; bottom: calc(20px + env(safe-area-inset-bottom)); z-index: 215; width: 54px; height: 54px; display: none; place-items: center; border: 0; border-radius: 50%; background: var(--accent); color: var(--accent-ink); cursor: pointer; box-shadow: 0 8px 26px rgba(0,0,0,.5); transition: transform .15s, background .15s; }
.ai-fab:hover { background: var(--accent-2, var(--accent)); transform: scale(1.06); }
.ai-fab.on { display: grid; }
.ai-fab svg, .ai-send svg { width: 24px; height: 24px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
.ai-panel { position: fixed; right: 20px; bottom: calc(86px + env(safe-area-inset-bottom)); z-index: 215; width: 370px; max-width: calc(100vw - 24px); height: 520px; max-height: calc(100dvh - 120px); display: none; flex-direction: column; background: var(--bg2); border: 1px solid var(--line); border-radius: 16px; box-shadow: 0 20px 60px rgba(0,0,0,.6); overflow: hidden; }
.ai-panel.open { display: flex; }
.ai-head { display: flex; align-items: center; gap: 8px; padding: 8px 8px 8px 14px; border-bottom: 1px solid var(--line); }
.ai-head b { font-size: .95rem; }
.ai-head span { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--muted); font-size: .78rem; }
.ai-msgs { flex: 1; min-height: 0; overflow-y: auto; padding: 12px; display: flex; flex-direction: column; gap: 8px; }
.ai-m { max-width: 88%; padding: 8px 12px; border-radius: 12px; font-size: .9rem; line-height: 1.45; overflow-wrap: anywhere; user-select: text; }
.ai-m.user { align-self: flex-end; background: var(--accent); color: var(--accent-ink); border-bottom-right-radius: 4px; }
.ai-m.ai { align-self: flex-start; background: var(--card); border-bottom-left-radius: 4px; }
.ai-m.ai code { padding: 1px 5px; border-radius: 5px; background: var(--bg0); font: .85em ui-monospace, Menlo, Consolas, monospace; }
.ai-m.err { align-self: flex-start; background: rgba(255,107,120,.1); border: 1px solid rgba(255,107,120,.4); color: #ffb4bb; }
.ai-m.dim { color: var(--muted); font-style: italic; }
.ai-consent { padding: 12px; border: 1px solid var(--line); border-radius: 12px; background: var(--card); font-size: .86rem; line-height: 1.45; }
.ai-consent p { margin: 0 0 10px; }
.ai-sugg { display: flex; flex-wrap: wrap; gap: 6px; padding: 0 12px 10px; }
.ai-sugg:empty { display: none; }
.ai-sugg button { padding: 6px 11px; border: 1px solid var(--line); border-radius: 99px; background: var(--card); color: var(--text); font-size: .8rem; cursor: pointer; transition: border-color .15s, background .15s; }
.ai-sugg button:hover { border-color: var(--accent); background: var(--bg0); }
.ai-form { display: flex; gap: 8px; padding: 10px 12px 6px; border-top: 1px solid var(--line); }
.ai-form input { flex: 1; min-width: 0; height: 40px; padding: 0 12px; background: var(--bg0); border: 1px solid var(--line); border-radius: 10px; outline: none; font-size: .92rem; }
.ai-form input:focus { border-color: var(--accent); }
.ai-send { flex: none; width: 40px; height: 40px; display: grid; place-items: center; border: 0; border-radius: 10px; background: var(--accent); color: var(--accent-ink); cursor: pointer; }
.ai-send:disabled { opacity: .5; cursor: default; }
.ai-note { margin: 0; padding: 0 14px 10px; color: var(--muted); font-size: .72rem; }
@media (max-width: 640px) {
    .ai-panel { right: 12px; left: 12px; width: auto; max-width: none; height: min(70dvh, 560px); }
    .ai-fab { right: 14px; }
}

/* ---------- Mobile account button + menu sheet, admin log ---------- */
.acct-top { display: none; flex: none; width: 42px; height: 42px; padding: 0; border: 0; border-radius: 50%; background: var(--accent); color: var(--accent-ink); font-weight: 700; font-size: 1rem; text-transform: uppercase; cursor: pointer; place-items: center; }
.menu-head { display: flex; align-items: center; gap: 12px; margin-bottom: 14px; }
.menu-head .av { flex: none; width: 42px; height: 42px; border-radius: 50%; background: var(--accent); color: var(--accent-ink); display: grid; place-items: center; font-weight: 700; text-transform: uppercase; }
.menu-head .who { display: flex; flex-direction: column; min-width: 0; line-height: 1.3; }
.menu-head .who b { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.menu-head .who small { color: var(--muted); font-size: .78rem; }
.menu-item { display: flex; align-items: center; gap: 12px; width: 100%; min-height: 48px; padding: 0 14px; margin: 0 0 6px; border: 1px solid var(--line); border-radius: 10px; background: var(--card); color: var(--text); font-size: .95rem; text-align: left; cursor: pointer; }
.menu-item:hover { border-color: var(--accent); }
.menu-item.out { color: var(--danger); }
#menuDlg form { margin: 0; }
#adminDlg .dlg { max-width: 560px; }
.adm-tabs { display: grid; grid-auto-flow: column; grid-auto-columns: minmax(max-content, 1fr); gap: 4px; padding: 4px; margin-bottom: 12px; background: var(--bg0); border-radius: 10px; overflow-x: auto; scrollbar-width: none; }
.adm-tabs::-webkit-scrollbar { display: none; }
.adm-tabs button { min-height: 36px; padding: 0 12px; white-space: nowrap; border: 0; border-radius: 7px; background: transparent; color: var(--muted); font-size: .86rem; font-weight: 600; cursor: pointer; }
.adm-tabs button[aria-selected="true"] { background: var(--card); color: var(--text); }
.log-row { padding: 10px 12px; margin-bottom: 6px; border: 1px solid var(--line); border-radius: 10px; background: var(--card); font-size: .86rem; }
.log-row .top { display: flex; justify-content: space-between; gap: 10px; color: var(--muted); font-size: .76rem; }
.log-row .top b { color: var(--text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.log-row .top span { flex: none; }
.log-row .fl { margin: 3px 0 0; color: var(--muted); font-size: .76rem; overflow-wrap: anywhere; }
.log-row .tx { margin: 4px 0 0; color: var(--text); white-space: pre-wrap; overflow-wrap: anywhere; }

/* Admin > Report (database overview) */
#adminDlg .dlg.wide { max-width: 980px; }
.dbr-bar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 10px; }
.dbr-bar label { color: var(--muted); font-size: .8rem; font-weight: 600; }
.dbr-bar select { height: 38px; padding: 0 10px; background: var(--bg0); color: var(--text); border: 1px solid var(--line); border-radius: 10px; }
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
.dbr-t { margin-bottom: 6px; border: 1px solid var(--line); border-radius: 10px; background: var(--card); }
.dbr-t > summary { display: flex; align-items: center; gap: 10px; padding: 10px 12px; cursor: pointer; list-style: none; }
.dbr-t > summary::-webkit-details-marker { display: none; }
.dbr-t > summary b { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: .92rem; }
.dbr-t > summary span { flex: none; color: var(--muted); font-size: .78rem; }
.dbr-in { padding: 0 12px 12px; }
.dbr-in h5 { margin: 10px 0 2px; font-size: .78rem; color: var(--muted); font-weight: 600; }
.dbr-note { margin: 4px 0 0; color: var(--muted); font-size: .76rem; }

/* Admin > AI setup */
.aia-row { padding: 10px 12px; margin-bottom: 6px; border: 1px solid var(--line); border-radius: 10px; background: var(--card); }
.aia-row.off { opacity: .6; }
.aia-top { display: flex; align-items: center; gap: 8px; min-width: 0; }
.aia-top b { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: .92rem; }
.aia-sub { margin: 2px 0 0; color: var(--muted); font-size: .78rem; overflow-wrap: anywhere; }
.aia-st { margin: 4px 0 0; font-size: .76rem; color: var(--muted); overflow-wrap: anywhere; }
.aia-st.ok { color: var(--ok); }
.aia-st.bad { color: var(--danger); }
.aia-btns { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
.aia-btns .btn { min-height: 34px; padding: 0 11px; font-size: .8rem; }
.aia-form { margin-top: 10px; padding: 12px; border: 1px solid var(--line); border-radius: 10px; background: var(--bg0); }
.aia-form .dlg-err { min-height: 0; }
.aia-form select, .aia-form input[type=text], .aia-form input[type=password] { width: 100%; height: 42px; padding: 0 12px; margin: 4px 0 2px; background: var(--bg2); color: var(--text); border: 1px solid var(--line); border-radius: 10px; outline: none; }
.aia-form select:focus, .aia-form input:focus { border-color: var(--accent); }
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
    .side .groups { display: none; }

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
    .dlg-wrap.sheet .dlg { max-width: none; border-radius: 16px 16px 0 0; padding-bottom: calc(20px + env(safe-area-inset-bottom)); }
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
@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after { animation: none !important; transition: none !important; }
}
</style>
</head>
<body>

<!-- Icon sprite -->
<svg width="0" height="0" style="position:absolute" aria-hidden="true">
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
    <symbol id="i-user" viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.6"/><path d="M5 20c0-3.6 3-6 7-6s7 2.4 7 6"/></symbol>
    <symbol id="i-users" viewBox="0 0 24 24"><circle cx="9" cy="8" r="3.2"/><path d="M3 19c0-3.2 2.7-5.2 6-5.2s6 2 6 5.2M16 5.2a3.2 3.2 0 010 5.6M18 14.2c1.8.7 3 2.3 3 4.8"/></symbol>
    <symbol id="i-logout" viewBox="0 0 24 24"><path d="M10 4H6a1 1 0 00-1 1v14a1 1 0 001 1h4M15 8l4 4-4 4M19 12H9"/></symbol>
</svg>

<div class="app">

    <!-- ============ Left panel: upload + library ============ -->
    <aside class="side" id="side">
        <div class="brand">
            <span class="logo"><svg class="i"><use href="#i-folder"/></svg></span>
            <h1>File Storage</h1>
            <span class="live" id="live" title="Updates automatically on all devices"><i></i><span id="liveText">Live</span></span>
            <button type="button" class="icon-btn side-close" id="sideClose" aria-label="Close menu"><svg class="i"><use href="#i-close"/></svg></button>
        </div>

        <div class="acct">
            <span class="av" id="meAv"><?= htmlspecialchars(mb_strtoupper(mb_substr($user["username"], 0, 1, "UTF-8"), "UTF-8")) ?></span>
            <div class="who"><b id="meName"><?= htmlspecialchars($user["username"]) ?></b><small><?= $user["is_admin"] ? "Admin account" : "Signed in" ?></small></div>
            <?php if ($user["is_admin"]): ?>
            <button type="button" class="icon-btn" id="adminBtn" title="Manage accounts" aria-label="Manage accounts"><svg class="i"><use href="#i-users"/></svg></button>
            <?php endif; ?>
            <button type="button" class="icon-btn" id="acctBtn" title="Account: change username or password" aria-label="Account settings"><svg class="i"><use href="#i-user"/></svg></button>
            <form method="post" action="logout.php">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                <button type="submit" class="icon-btn" title="Log out" aria-label="Log out"><svg class="i"><use href="#i-logout"/></svg></button>
            </form>
        </div>

        <form class="upload" action="files.php" method="POST" enctype="multipart/form-data" id="uploadForm">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <h2>Upload</h2>
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
            </div>

            <button type="submit" name="action" value="upload" class="btn btn-primary" id="uploadBtn">Upload</button>
        </form>

        <div>
            <h2>Library</h2>
            <nav class="groups" id="groupNav" aria-label="File types"></nav>
        </div>

        <div class="usage" id="usage"></div>
    </aside>
    <div class="scrim" id="scrim"></div>

    <!-- ============ Middle panel: search + list ============ -->
    <main class="list-panel">
        <div class="topbar">
            <button type="button" class="btn btn-sm menu-btn" id="menuBtn"><svg class="i"><use href="#i-upload"/></svg>Upload</button>
            <div class="search">
                <svg class="i"><use href="#i-search"/></svg>
                <input type="search" id="q" placeholder="Search file names" autocomplete="off" aria-label="Search file names" title="Tip: separate several names with commas">
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

        <div class="chips" id="chips"></div>

        <div class="resultbar">
            <span class="sum" id="sum" aria-live="polite"></span>
            <button type="button" class="btn btn-sm hidden" id="selToggle" title="Tick files to print only those (Ctrl+click also works)"><svg class="i"><use href="#i-select"/></svg>Select</button>
            <button type="button" class="btn btn-sm hidden" id="printResults"><svg class="i"><use href="#i-print"/></svg><span id="printResultsText">Print results</span></button>
            <button type="button" class="btn btn-sm" id="printAll"><svg class="i"><use href="#i-print"/></svg>Print all</button>
        </div>

        <!-- Shown instead of the bar above while selecting -->
        <div class="selbar hidden" id="selBar">
            <b id="selCount" aria-live="polite">0 selected</b>
            <button type="button" class="btn btn-sm" id="selAll">Select all</button>
            <button type="button" class="btn btn-sm btn-primary" id="printSelected" disabled><svg class="i"><use href="#i-print"/></svg><span id="printSelectedText">Print selected</span></button>
            <button type="button" class="btn btn-sm" id="selDone">Done</button>
        </div>

        <div class="rows" id="rows"></div>
    </main>

    <!-- ============ Right panel: details ============ -->
    <section class="detail" id="detail" aria-label="File details"></section>
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
        <h3>Share file</h3>
        <p id="shareFile"></p>
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

<!-- Account dialog: change username / password -->
<div class="dlg-wrap" id="acctDlg">
    <div class="dlg dlg-scroll">
        <h3>Account</h3>
        <p id="acctWho"></p>

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
        <button type="button" class="menu-item" id="menuAdmin"><svg class="i"><use href="#i-users"/></svg>Manage accounts</button>
        <button type="button" class="menu-item" id="menuLog"><svg class="i"><use href="#i-eye"/></svg>Activity log</button>
        <?php endif; ?>
        <form method="post" action="logout.php">
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

<script>
/* ============================================================
   Data from the server
   ============================================================ */
const BOOT       = <?= json_encode(["files" => $files, "v" => $version, "flash" => $flash, "me" => $me, "csrf" => $csrf, "users" => $others], $J) ?>;
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
    return state.files
        .filter(f => !g.types || g.types.includes(f.type))
        .filter(f => {
            if (!terms.length) return true;
            const n = f.name.toLowerCase();
            return terms.some(words => words.every(w => n.includes(w)));
        })
        .sort(SORTS[state.sort] || SORTS["new"]);
}
const isFiltered = () => state.q.trim() !== "" || state.group !== "all";

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
        return '<button type="button" class="g' + (state.group === g.id ? " on" : "") + '" data-g="' + g.id + '">' +
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

function rowHTML(f) {
    const d = parseDate(f.added);
    const meta = fmt(f.size) + (d ? " · " + fmtDay(d) : "") + (f.mine ? "" : " · by " + f.owner);
    const eye = f.preview
        ? '<button type="button" class="icon-btn" data-act="preview" title="Preview" aria-label="Preview ' + esc(f.name) + '">' + ic("eye") + '</button>' : "";
    const on = state.picked.has(f.id);
    return '<div class="row' + (state.selected === f.id ? " sel" : "") + (state.fresh.has(f.id) ? " fresh" : "") + (on ? " picked" : "") +
        '" data-id="' + f.id + '" style="--c:' + (TYPE_COLOR[f.type] || "#8b92a3") + '" tabindex="0"' +
        (state.pick ? ' role="checkbox" aria-checked="' + on + '"' : "") + '>' +
        '<span class="pk" aria-hidden="true">' + ic("check") + '</span>' +
        iconFor(f) +
        '<div class="rinfo">' +
            '<div class="rname"><span class="rtext" title="' + esc(f.name) + '">' + esc(f.name) + '</span>' +
            '<span class="vbadge" title="Visible to: ' + esc(visText(f)) + '">' + ic(visIcon(f)) + '</span>' +
            (f.can ? '<button type="button" class="icon-btn" data-act="rename" title="Rename" aria-label="Rename ' + esc(f.name) + '">' + ic("pencil") + '</button>' : "") + '</div>' +
            '<div class="rmeta">' + esc(meta) + '</div>' +
            (f.notes ? '<div class="rnote">' + esc(f.notes.replace(/\s+/g, " ")) + '</div>' : "") +
        '</div>' +
        '<div class="ractions">' + eye +
            (f.can ? '<button type="button" class="icon-btn hide-s" data-act="share" title="Share" aria-label="Share ' + esc(f.name) + '">' + ic("share") + '</button>' : "") +
            '<a class="icon-btn hide-s" href="files.php?action=download&id=' + f.id + '" title="Download" aria-label="Download ' + esc(f.name) + '">' + ic("download") + '</a>' +
            (f.can ? '<button type="button" class="icon-btn danger hide-s" data-act="delete" title="Delete" aria-label="Delete ' + esc(f.name) + '">' + ic("trash") + '</button>' : "") +
        '</div></div>';
}

function renderRows() {
    const list = visibleFiles();
    const total = state.files.length;
    const el = $("rows");

    if (!total) {
        el.innerHTML = '<div class="empty">' + ic("folder") + '<p>No files yet.<br>Choose files or drop them on this page to add your first one.</p></div>';
    } else if (!list.length) {
        el.innerHTML = '<div class="empty">' + ic("search") + '<p>No files match your search.</p><button type="button" class="btn btn-sm" data-act="reset">Clear search and filter</button></div>';
    } else {
        el.innerHTML = list.map(rowHTML).join("");
    }
    state.fresh = new Set();

    // summary + print buttons
    const q = state.q.trim();
    const g = GROUPS.find(x => x.id === state.group);
    let text = total + (total === 1 ? " file" : " files");
    if (isFiltered()) {
        text = list.length + " of " + total + (total === 1 ? " file" : " files");
        if (q) text += " matching \u201C" + q + "\u201D";
        if (state.group !== "all") text += " in " + g.label.toLowerCase();
    }
    $("sum").textContent = text;
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

function updateSelBar() {
    const n = state.picked.size;
    const shown = visibleFiles();
    const allOn = shown.length > 0 && shown.every(f => state.picked.has(f.id));
    $("selCount").textContent = n ? n + (n === 1 ? " file selected" : " files selected") : "Tick the files you want";
    $("selAll").textContent = allOn ? "Deselect all" : (isFiltered() ? "Select all shown" : "Select all");
    $("selAll").disabled = !shown.length;
    $("printSelected").disabled = n === 0;
    $("printSelectedText").textContent = n ? "Print selected (" + n + ")" : "Print selected";
}

function paintPicks() {
    document.querySelectorAll("#rows .row").forEach(r => {
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

function selectFile(id) {
    if (state.selected !== id) flushNotes();
    state.selected = id;
    state.sheet = true;
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
                '<dt>Added</dt><dd id="dAdded"></dd><dt>Owner</dt><dd id="dOwner"></dd><dt>Visible to</dt><dd id="dVis"></dd><dt>ID</dt><dd id="dId"></dd></dl>' +
            '<div class="d-notes"><label for="dNotes">Notes</label>' +
                '<textarea id="dNotes" maxlength="900"' + (f.can ? ' placeholder="Write a note about this file"' : ' readonly placeholder="No notes"') + '></textarea>' +
                '<div class="d-nstat"><span id="nStatus"></span><span id="nCount"></span></div></div>' +
            '<div class="d-actions">' +
                (canPreview ? '<button type="button" class="btn btn-primary wide" data-act="preview">' + ic("eye") + 'Preview</button>' : "") +
                '<a class="btn" id="dDl" href="#">' + ic("download") + 'Download</a>' +
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
const PLAYPAUSE = ic("play", "i-play") + ic("pause", "i-pause");

function openPreview(f) {
    if (!f.preview) return;
    closePreview();
    pvId = f.id;
    const url = "preview.php?id=" + f.id;
    const body = $("pvBody");

    $("pvTitle").textContent = f.name;
    $("pvDl").href = "files.php?action=download&id=" + f.id;
    $("pvBox").classList.toggle("is-audio", f.preview === "audio");

    if (f.preview === "image") {
        body.innerHTML = '<img class="pv-img" alt="' + esc(f.name) + '" src="' + url + '">';
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

/* Text, code, logs, csv...: fetched and shown in our own dark viewer. Only the first 1 MB is loaded. */
const TEXT_MAX = 1048576;
function buildText(body, url) {
    const id = pvId;
    body.innerHTML = '<pre class="pv-text dim" tabindex="0">Loading…</pre>';

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

function closePreview() {
    aiEnd();
    const body = $("pvBody");
    body.querySelectorAll("video, audio").forEach(m => { m.pause(); m.removeAttribute("src"); m.load(); });
    body.innerHTML = "";
    player = null;
    pvId = null;
    $("pv").classList.remove("open");
    $("pvBox").classList.remove("is-audio");
}
$("pvClose").addEventListener("click", closePreview);
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
            '<button type="button" class="au-x" id="auClose" aria-label="Close player">' + ic("close") + '</button>' +
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
    $("auClose").addEventListener("click", closePreview);
    m.addEventListener("error", () => $("auErr").classList.remove("hidden"));

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

function applyList(files, v) {
    const oldIds = new Set(state.files.map(f => f.id));
    files.forEach(f => { if (!oldIds.has(f.id)) state.fresh.add(f.id); });
    state.files = files;
    state.v = v;
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
        if (!d.same && d.files) applyList(d.files, d.v);
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

async function startUpload() {
    if (busy) return;
    busy = true;

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
function openDrawer()  { $("side").classList.add("open");    $("scrim").classList.add("on"); }
function closeDrawer() { $("side").classList.remove("open"); $("scrim").classList.remove("on"); }
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
function handleAct(act, f) {
    if (act === "preview") openPreview(f);
    else if (act === "rename" && f.can) renameFile(f);
    else if (act === "delete" && f.can) deleteFile(f);
    else if (act === "share" && f.can) shareFile(f);
}
$("rows").addEventListener("click", e => {
    const reset = e.target.closest('[data-act="reset"]');
    if (reset) { state.q = ""; state.group = "all"; $("q").value = ""; renderAll(); return; }

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
        for (const id of ["delDlg", "shareDlg", "adminDlg", "acctDlg", "menuDlg"]) {
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
let shareFor = null;
const sharePicked = new Set();
const shareVis = () => { const r = document.querySelector('#shareForm input[name="vis"]:checked'); return r ? r.value : "all"; };

function syncShare() {
    const f = byId(shareFor);
    const custom = shareVis() === "custom";
    $("shareList").classList.toggle("hidden", !custom);
    if (custom && f) buildPicks($("shareList"), sharePicked, f.owner_id, null);
}
async function shareFile(f) {
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
    const f = byId(shareFor);
    if (!f) { $("shareDlg").classList.remove("open"); return; }

    const vis = shareVis();
    const ids = vis === "custom" ? Array.from(sharePicked) : [];
    if (vis === "custom" && !ids.length) { $("shareErr").textContent = "Pick at least one person, or choose “Only me”."; return; }

    try {
        const d = await post({ action: "share", id: f.id, visibility: vis, shares: ids.join(",") });
        if (!d.ok) { $("shareErr").textContent = d.error || "Could not save the sharing settings."; return; }
        f.vis = d.visibility;
        f.shared = d.shared;
        $("shareDlg").classList.remove("open");
        renderAll();
        updateDetailLive(f);
        toast("Sharing updated");
        poll();
    } catch (err) {
        $("shareErr").textContent = "Could not reach the server.";
    }
});

/* ============================================================
   Account: change username / password
   ============================================================ */
function renderMe() {
    $("meName").textContent = ME.name;
    $("meAv").textContent = ME.name.charAt(0).toUpperCase();
    $("acctTop").textContent = ME.name.charAt(0).toUpperCase();
    $("menuAv").textContent = ME.name.charAt(0).toUpperCase();
    $("menuName").textContent = ME.name;
    $("acctWho").textContent = "Signed in as " + ME.name + (ME.admin ? " (admin)" : "");
}
function openAccount() {
    $("unameForm").reset();
    $("pwForm").reset();
    $("unameErr").textContent = "";
    $("pwErr").textContent = "";
    renderMe();
    closeDrawer();
    $("delSec").classList.toggle("hidden", !!ME.admin);
    $("acctDlg").classList.add("open");
    $("newName").focus();
}
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

            if (u.admin) {
                const tag = document.createElement("span");
                tag.className = "tag";
                tag.textContent = "Admin";
                row.appendChild(tag);
            } else {
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
    users:     "Remove accounts you don't want on the site. Admin accounts can't be removed here.",
    ai:        "The last 100 times someone used the AI chat (who, when, which file). What people ask is not stored. Kept for 30 days.",
    downloads: "The last 100 downloads.",
    logins:    "Failed log-in and sign-up attempts by IP address (the last 100, kept 30 days). A successful log-in clears that address's attempts.",
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
    logins:    "Clear all failed sign-in attempts? Any address that was temporarily blocked can try again right away."
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
    let url = "ai_admin.php?action=" + action;
    if (data) {
        opt.method = "POST";
        opt.body = new URLSearchParams(Object.assign({ action }, data));
        url = "ai_admin.php";
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

        if (p.status) aiaStatus(row, p.status + (p.last ? " · " + String(p.last).slice(0, 16) : ""), p.status.indexOf("OK") === 0 ? "ok" : "bad");

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

/* ============================================================
   AI assistant (one file at a time, only inside the preview)
   ============================================================ */
const AI = { id: null, file: null, open: false, busy: false, history: [], asked: false };

const AI_SUGGEST = {
    image: ["Describe this image", "What text is in this image?", "What are the main objects?"],
    audio: ["What can you tell me about this track?", "Transcribe the words in it", "Describe the mood and genre"],
    pdf:   ["Summarize this document", "List the key points", "What are the action items?"],
    code:  ["Explain what this code does", "Find possible bugs", "Summarize in 3 bullet points"],
    text:  ["Summarize this file", "List the key points", "Are there any mistakes in this text?"]
};

function aiSuggestions(f) {
    if (f.preview === "image") return AI_SUGGEST.image;
    if (f.preview === "audio") return AI_SUGGEST.audio;
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

    aiAdd("ai", "Hi! I can read **" + (AI.file ? AI.file.name : "this file") + "** and answer questions about it. Pick a suggestion or type your own question.");
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
    $("aiFab").classList.toggle("on", f.preview !== "video");
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

async function aiAsk(text) {
    text = (text || "").trim();
    if (!text || AI.busy || AI.id === null || !ME.ai) return;

    const id = AI.id;
    AI.asked = true;
    $("aiSugg").innerHTML = "";
    aiAdd("user", text);
    $("aiInput").value = "";

    AI.busy = true;
    $("aiSend").disabled = true;
    const wait = aiAdd("ai dim", "Reading the file…");

    try {
        const d = await aiPost({ action: "chat", id: String(id), message: text, history: JSON.stringify(AI.history.slice(-10)) });
        if (AI.id !== id) return;                      // the preview was closed or changed meanwhile
        wait.remove();

        if (d.ok) {
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
        wait.remove();
        aiAdd("err", "Could not reach the server.");
    } finally {
        if (AI.id === id) { AI.busy = false; $("aiSend").disabled = false; }
    }
}
$("aiForm").addEventListener("submit", e => { e.preventDefault(); aiAsk($("aiInput").value); });

/* ============================================================
   Start
   ============================================================ */
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
</body>
</html>  
