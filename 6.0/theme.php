<?php
/*
    The shared look of the site: font, colours, the base elements and the buttons.
    Used by index.php, login.php and preview.php. Print it inside <head> with:  <?= uiTheme() ?>

    The colour names (bg0, card, accent ...) are also read by the music player in index.php, which
    tints the page from the cover of the song that is playing. Keep the names when you change a value.
*/

function uiTheme(): string {

    /* Dark colours, printed twice below: once for the saved choice, once for "the device is dark and nothing was chosen". */
    $dark = '
    --bg0:#121212; --bg1:#161616; --bg2:#1b1b1b;
    --card:#1f1f1f; --card-2:#2a2a2a;
    --line:#2e2e2e; --line-2:#4a4a4a;
    --text:#f2f1ee; --muted:#a9a7a1;
    --accent:#f2f1ee; --accent-2:#ffffff; --accent-ink:#121212; --accent-text:#8ab4ff; --focus:#8ab4ff;
    --edge:#f2f1ee; --stamp:#4a4a4a;
    --danger:#ef6b73; --danger-text:#ff9aa0; --on-danger:#1a0508;
    --ok:#4cc38a; --warn:#f0b45a;
    --hover:rgba(255,255,255,.06); --row-line:rgba(255,255,255,.07);
    --tint:rgba(138,180,255,.12); --tint-2:rgba(138,180,255,.26);
    --scrim:rgba(0,0,0,.62); --glass:#242424; --shadow:rgba(0,0,0,.55);
    --viewer:#0d0d0d; --code-bg:#101010; --code-text:#dcdad5;
    color-scheme:dark;';

    return <<<HTML
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700&display=swap">
<style>
/* ---------- Colour: stone ground, ink chrome, one blue for what is open or focused ---------- */
:root {
    --bg0:#efefec;       /* left panel */
    --bg1:#f5f5f3;       /* file list ground */
    --bg2:#ffffff;       /* details panel, dialogs */
    --card:#ffffff;
    --card-2:#e9e9e6;    /* hover and pressed */
    --line:#e2e2de;      /* hairlines and sheet edges */
    --line-2:#bcbcb6;    /* borders of controls */
    --text:#151515;
    --muted:#5d5d58;
    --accent:#151515;    /* the one filled button: ink */
    --accent-2:#2e2e2c;
    --accent-ink:#ffffff;
    --accent-text:#1d5fbf;   /* links, the open record, counts */
    --focus:#1d5fbf;
    --edge:#151515;
    --stamp:#bcbcb6;
    --danger:#c4242a; --danger-text:#a51c22; --on-danger:#ffffff;
    --ok:#247b50; --warn:#92400e;
    --hover:rgba(21,21,21,.045); --row-line:rgba(21,21,21,.07);
    --tint:rgba(29,95,191,.08); --tint-2:rgba(29,95,191,.2);
    --scrim:rgba(15,15,13,.42); --glass:#ffffff; --shadow:rgba(15,15,13,.18);
    --viewer:#e8e8e5; --code-bg:#ffffff; --code-text:#1d1d1b;
    --radius:8px; --radius-control:6px;
    --ease:cubic-bezier(.2,.8,.2,1);
    color-scheme:light;
}
:root[data-theme="dark"] {{$dark}
}
@media (prefers-color-scheme: dark) {
    :root:not([data-theme]) {{$dark}
    }
}

/* ---------- Base ---------- */
* { box-sizing:border-box; -webkit-tap-highlight-color:transparent; scrollbar-width:thin; scrollbar-color:var(--line-2) transparent; }
html { -webkit-text-size-adjust:100%; }
body {
    margin:0; background:var(--bg1); color:var(--text);
    font-family:"Public Sans","Segoe UI",system-ui,-apple-system,Roboto,Helvetica,Arial,sans-serif;
    font-size:14px; line-height:1.5; font-variant-numeric:tabular-nums;
    -webkit-font-smoothing:antialiased; text-rendering:optimizeLegibility;
}
button, input, select, textarea { font:inherit; color:inherit; }
a { color:var(--accent-text); text-underline-offset:3px; }
:focus-visible { outline:2px solid var(--focus); outline-offset:2px; }
::selection { background:color-mix(in srgb, var(--focus) 26%, transparent); }
input, textarea { caret-color:var(--focus); }
::placeholder { color:var(--muted); opacity:1; }
.hidden { display:none !important; }

/* Drawn icons: one set, 1.7px outline */
.i { width:18px; height:18px; fill:none; stroke:currentColor; stroke-width:1.7; stroke-linecap:round; stroke-linejoin:round; flex:none; }
.i-lg { width:24px; height:24px; }

/* ---------- Buttons ---------- */
.btn {
    display:inline-flex; align-items:center; justify-content:center; gap:8px;
    min-height:36px; padding:7px 14px;
    font-size:.875rem; font-weight:500; line-height:1.2; text-decoration:none; white-space:nowrap;
    background:var(--card); color:var(--text);
    border:1px solid var(--line-2); border-radius:var(--radius-control);
    cursor:pointer; transition:background .15s, border-color .15s, color .15s;
}
.btn:hover { background:var(--card-2); }
.btn:active { background:var(--line); }
.btn:disabled { opacity:.45; cursor:not-allowed; }
.btn-primary { background:var(--accent); border-color:var(--accent); color:var(--accent-ink); }
.btn-primary:hover { background:var(--accent-2); border-color:var(--accent-2); }
.btn-primary:active { background:var(--accent-2); }
.btn-danger { color:var(--danger-text); }
.btn-danger:hover { background:color-mix(in srgb, var(--danger) 10%, transparent); border-color:var(--danger); }
.btn-primary.danger { background:var(--danger); border-color:var(--danger); color:var(--on-danger); }
.btn-primary.danger:hover { background:color-mix(in srgb, var(--danger) 88%, #000); }
.btn-sm { min-height:32px; padding:5px 11px; font-size:.8125rem; }

.icon-btn {
    display:inline-flex; align-items:center; justify-content:center;
    width:32px; height:32px; padding:0; flex:none;
    background:transparent; border:0; border-radius:var(--radius-control);
    color:var(--muted); cursor:pointer; text-decoration:none;
    transition:background .15s, color .15s;
}
.icon-btn:hover { background:var(--card-2); color:var(--text); }
.icon-btn:active { background:var(--line); }
.icon-btn.danger:hover { background:color-mix(in srgb, var(--danger) 12%, transparent); color:var(--danger-text); }

/* ---------- Fields: label above, 38px, one border step darker than the sheet ---------- */
:where(input:not([type=checkbox]):not([type=radio]):not([type=file]):not([type=range]):not([type=hidden]):not([type=submit])),
:where(select, textarea) {
    width:100%; height:38px; padding:0 12px;
    background:var(--card); color:var(--text);
    border:1px solid var(--line-2); border-radius:var(--radius-control); outline:none;
    transition:border-color .15s, box-shadow .15s;
}
textarea { height:auto; padding:9px 12px; line-height:1.5; }
select { cursor:pointer; }
select option { background:var(--card); color:var(--text); }
:where(input:not([type=checkbox]):not([type=radio]):not([type=range]), select, textarea):focus {
    border-color:var(--focus); box-shadow:0 0 0 3px color-mix(in srgb, var(--focus) 20%, transparent);
}
input[type=checkbox], input[type=radio] { accent-color:var(--accent-text); }
@media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation:none !important; transition:none !important; } }
</style>
HTML;
}
