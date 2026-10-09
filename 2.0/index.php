<?php
require "config.php";
require "filetypes.php";
session_start();

// One-time message after a classic (non-JavaScript) upload
$flash = $_SESSION["flash"] ?? null;
unset($_SESSION["flash"]);

// Remove database records for files that no longer exist
$checkFiles = $conn->query("SELECT id, filepath FROM files");

while ($file = $checkFiles->fetch_assoc()) {
    $path = __DIR__ . "/" . $file["filepath"];

    if (!is_file($path)) {
        $stmt = $conn->prepare("DELETE FROM files WHERE id = ?");
        $stmt->bind_param("i", $file["id"]);
        $stmt->execute();
    }
}

// Get the remaining files
$result = $conn->query("SELECT * FROM files ORDER BY id DESC");

function previewKind($filename) {
    $info = ftPreview(ftExt($filename));
    return $info ? $info[0] : null;
}

$files = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $p = __DIR__ . "/" . $row["filepath"];
        $row["size"] = is_file($p) ? filesize($p) : 0;
        $files[] = $row;
    }
}
$count = count($files);

// Server limits (a single file can't exceed the smaller of the two)
$limits = array_filter([iniBytes(ini_get("upload_max_filesize")), iniBytes(ini_get("post_max_size"))]);
$maxFile = $limits ? min($limits) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#0f1115">
<title>Local File Storage</title>
<style>
:root {
    --bg: #0f1115;
    --surface: #171a21;
    --surface-2: #1f232c;
    --border: #2a2f3a;
    --text: #e8eaf0;
    --muted: #8b92a3;
    --accent: #6c8cff;
    --accent-hover: #859fff;
    --danger: #ff5d6c;
    --ok: #42d6a4;
    --radius: 14px;
}
* { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
html { -webkit-text-size-adjust: 100%; }
body {
    margin: 0;
    min-height: 100vh;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    background: var(--bg);
    color: var(--text);
    line-height: 1.45;
}

.container {
    width: 100%;
    max-width: 820px;
    margin: 0 auto;
    padding: 28px 16px calc(40px + env(safe-area-inset-bottom));
}

header { margin-bottom: 22px; }
header h1 { margin: 0 0 4px; font-size: 1.6rem; letter-spacing: -0.02em; }
header p { margin: 0; color: var(--muted); font-size: .95rem; }

.flash {
    border-radius: 12px;
    padding: 12px 14px;
    margin-bottom: 16px;
    font-size: .9rem;
    border: 1px solid var(--border);
    background: var(--surface);
}
.flash.good { border-color: rgba(66, 214, 164, .5); }
.flash.bad  { border-color: rgba(255, 93, 108, .5); }
.flash ul { margin: 6px 0 0; padding-left: 18px; color: var(--danger); }

/* Upload */
.upload {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 16px;
    margin-bottom: 28px;
}
.dropzone {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 6px;
    text-align: center;
    padding: 28px 16px;
    border: 2px dashed var(--border);
    border-radius: 12px;
    background: var(--surface-2);
    cursor: pointer;
    transition: border-color .15s, background .15s;
}
.dropzone:hover, .dropzone.drag { border-color: var(--accent); background: #1b2030; }
.dropzone svg { width: 34px; height: 34px; color: var(--accent); }
.dropzone strong { font-size: 1rem; }
.dropzone span { color: var(--muted); font-size: .88rem; }
.dropzone small { color: var(--muted); font-size: .78rem; opacity: .8; }
.dropzone input { display: none; }

button, .btn {
    font: inherit;
    font-size: .9rem;
    font-weight: 600;
    border: 1px solid var(--border);
    background: var(--surface-2);
    color: var(--text);
    padding: 10px 14px;
    min-height: 42px;
    border-radius: 10px;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    transition: background .15s, border-color .15s;
}
button:hover, .btn:hover { background: #2a2f3a; }
.btn-primary { background: var(--accent); border-color: var(--accent); color: #0b1020; }
.btn-primary:hover { background: var(--accent-hover); border-color: var(--accent-hover); }
.btn-danger { color: var(--danger); }
.btn-danger:hover { background: rgba(255, 93, 108, .12); border-color: var(--danger); }
.upload .btn-primary { width: 100%; margin-top: 12px; }
.upload .btn-primary:disabled { opacity: .5; cursor: not-allowed; }

/* Upload queue */
.queue { margin-top: 14px; display: flex; flex-direction: column; gap: 8px; max-height: 340px; overflow-y: auto; }
.qitem {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 12px;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: 12px;
}
.qitem .ficon { width: 28px; height: 36px; flex: 0 0 28px; }
.qinfo { flex: 1; min-width: 0; }
.qname { font-size: .9rem; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.qmeta { font-size: .78rem; color: var(--muted); }
.qitem.done .qmeta { color: var(--ok); }
.qitem.error .qmeta { color: var(--danger); }
.bar { height: 4px; background: var(--border); border-radius: 4px; margin-top: 6px; overflow: hidden; display: none; }
.qitem.uploading .bar, .qitem.done .bar { display: block; }
.bar i { display: block; height: 100%; width: 0; background: var(--accent); transition: width .1s linear; }
.qitem.done .bar i { background: var(--ok); width: 100% !important; }
.qx { width: 34px; min-height: 34px; padding: 0; font-size: 1.2rem; line-height: 1; flex: 0 0 34px; }
.qbar { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-top: 10px; font-size: .85rem; color: var(--muted); }
.qbar button { min-height: 32px; padding: 4px 10px; font-size: .8rem; }

/* List */
.list-head {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    margin-bottom: 12px;
}
.list-head h2 { margin: 0; font-size: 1.1rem; }
.list-head .count { color: var(--muted); font-size: .88rem; }

.file {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 14px;
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    gap: 14px;
}
.file > .ficon { flex: 0 0 40px; width: 40px; height: 51px; }

.info { flex: 1; min-width: 0; }
.name {
    font-weight: 600;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.meta { color: var(--muted); font-size: .82rem; margin-top: 2px; }
.actions { display: flex; gap: 8px; flex-shrink: 0; }

.empty {
    text-align: center;
    color: var(--muted);
    padding: 44px 16px;
    border: 1px dashed var(--border);
    border-radius: var(--radius);
}

/* Modal */
.modal {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(5, 7, 10, .8);
    backdrop-filter: blur(4px);
    justify-content: center;
    align-items: center;
    padding: 20px;
    z-index: 1000;
}
.modal.open { display: flex; }
.modal-content {
    width: 100%;
    max-width: 960px;
    height: 100%;
    max-height: 88vh;
    display: flex;
    flex-direction: column;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: hidden;
}
.modal-bar {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 10px 10px 16px;
    border-bottom: 1px solid var(--border);
}
.modal-title {
    flex: 1;
    min-width: 0;
    font-weight: 600;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.close { width: 42px; padding: 0; font-size: 1.4rem; line-height: 1; }
.modal-body {
    flex: 1;
    min-height: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #0b0d11;
}
#previewFrame { width: 100%; height: 100%; border: 0; background: #fff; }
#previewImg { max-width: 100%; max-height: 100%; object-fit: contain; }
#previewVideo { width: 100%; height: 100%; background: #000; }
#previewAudio { width: 90%; max-width: 600px; }
.hidden { display: none !important; }

/* Phones */
@media (max-width: 600px) {
    .container { padding-top: 20px; }
    header h1 { font-size: 1.35rem; }
    .file { flex-wrap: wrap; gap: 12px; }
    .info { flex: 1 1 calc(100% - 60px); }
    .actions { width: 100%; }
    .actions .btn { flex: 1; }
    .modal { padding: 0; }
    .modal-content { max-height: 100%; border-radius: 0; border: 0; }
}
</style>
</head>
<body>

<div class="container">
    <header>
        <h1>Local File Storage</h1>
        <p>Upload, preview and manage your files.</p>
    </header>

    <?php if ($flash): ?>
        <div class="flash <?= $flash["errors"] ? "bad" : "good" ?>">
            <?php if ($flash["ok"] > 0): ?>
                <div>Uploaded <?= (int)$flash["ok"] ?> <?= $flash["ok"] === 1 ? "file" : "files" ?>.</div>
            <?php endif; ?>
            <?php if ($flash["errors"]): ?>
                <ul>
                    <?php foreach ($flash["errors"] as $err): ?>
                        <li><?= htmlspecialchars($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <form class="upload" action="files.php" method="POST" enctype="multipart/form-data" id="uploadForm">
        <label class="dropzone" id="dropzone">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16V4m0 0l-4 4m4-4l4 4"/><path d="M4 16v3a1 1 0 001 1h14a1 1 0 001-1v-3"/></svg>
            <strong>Tap to choose files</strong>
            <span>or drag and drop them here</span>
            <small>Any file type<?= $maxFile ? " &middot; up to " . formatSize($maxFile) . " each" : "" ?></small>
            <input type="file" name="files[]" id="fileInput" multiple>
        </label>

        <div class="queue hidden" id="queue"></div>

        <div class="qbar hidden" id="queueBar">
            <span id="queueSummary"></span>
            <button type="button" id="clearBtn">Clear all</button>
        </div>

        <button type="submit" name="action" value="upload" class="btn-primary" id="uploadBtn">Upload</button>
    </form>

    <div class="list-head">
        <h2>Files</h2>
        <span class="count"><?= $count ?> <?= $count === 1 ? "file" : "files" ?></span>
    </div>

    <?php if ($count > 0): ?>
        <?php foreach ($files as $file):
            $ext  = ftExt($file["filename"]);
            $kind = previewKind($file["filename"]);
            $when = !empty($file["uploaded_at"]) && ($ts = strtotime($file["uploaded_at"])) ? date("M j, Y", $ts) : "";
        ?>
            <div class="file">
                <?= ftIcon(ftTypeOf($ext), htmlspecialchars(ftLabel($ext))) ?>

                <div class="info">
                    <div class="name" title="<?= htmlspecialchars($file["filename"]) ?>"><?= htmlspecialchars($file["filename"]) ?></div>
                    <div class="meta"><?= formatSize($file["size"]) ?><?= $when ? " &middot; " . htmlspecialchars($when) : "" ?></div>
                </div>

                <div class="actions">
                    <?php if ($kind !== null): ?>
                        <button type="button" class="btn"
                            data-id="<?= (int)$file["id"] ?>"
                            data-name="<?= htmlspecialchars($file["filename"], ENT_QUOTES) ?>"
                            data-type="<?= $kind ?>"
                            onclick="openPreview(this)">Preview</button>
                    <?php endif; ?>

                    <a class="btn" href="files.php?action=download&id=<?= (int)$file["id"] ?>">Download</a>

                    <a class="btn btn-danger"
                       href="files.php?action=delete&id=<?= (int)$file["id"] ?>"
                       onclick="return confirm('Are you sure you want to delete this file?')">Delete</a>
                </div>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <p class="empty">No files uploaded yet.</p>
    <?php endif; ?>
</div>

<!-- Preview Modal -->
<div id="previewModal" class="modal" onclick="if (event.target === this) closePreview()">
    <div class="modal-content">
        <div class="modal-bar">
            <div class="modal-title" id="previewTitle"></div>
            <button type="button" class="close" onclick="closePreview()" aria-label="Close preview">&times;</button>
        </div>
        <div class="modal-body">
            <iframe id="previewFrame" class="hidden"></iframe>
            <img id="previewImg" class="hidden" alt="">
            <video id="previewVideo" class="hidden" controls playsinline preload="metadata"></video>
            <audio id="previewAudio" class="hidden" controls preload="metadata"></audio>
        </div>
    </div>
</div>

<script>
/* ---------- Preview ---------- */
const modal = document.getElementById("previewModal");
const frame = document.getElementById("previewFrame");
const img   = document.getElementById("previewImg");
const video = document.getElementById("previewVideo");
const audio = document.getElementById("previewAudio");

function openPreview(btn) {
    const url = "preview.php?id=" + btn.dataset.id;
    document.getElementById("previewTitle").textContent = btn.dataset.name;

    [frame, img, video, audio].forEach(el => el.classList.add("hidden"));

    switch (btn.dataset.type) {
        case "image":
            img.src = url; img.alt = btn.dataset.name;
            img.classList.remove("hidden");
            break;
        case "video":
            video.src = url;
            video.classList.remove("hidden");
            break;
        case "audio":
            audio.src = url;
            audio.classList.remove("hidden");
            break;
        default:
            frame.src = url;
            frame.classList.remove("hidden");
    }

    modal.classList.add("open");
    document.body.style.overflow = "hidden";
}

function closePreview() {
    modal.classList.remove("open");
    frame.src = "about:blank";
    img.removeAttribute("src");
    [video, audio].forEach(m => { m.pause(); m.removeAttribute("src"); m.load(); });
    document.body.style.overflow = "";
}

document.addEventListener("keydown", e => {
    if (e.key === "Escape") closePreview();
});

/* ---------- Upload queue ---------- */
const TYPE_OF  = <?= json_encode(ftTypeMap(), JSON_UNESCAPED_SLASHES) ?>;
const ICON_SVG = <?= json_encode(ftAllIcons(), JSON_UNESCAPED_SLASHES) ?>;
const MAX_FILE = <?= (int)$maxFile ?>;   // bytes, 0 = unlimited

const form     = document.getElementById("uploadForm");
const input    = document.getElementById("fileInput");
const zone     = document.getElementById("dropzone");
const btn      = document.getElementById("uploadBtn");
const queueEl  = document.getElementById("queue");
const barEl    = document.getElementById("queueBar");
const summary  = document.getElementById("queueSummary");
const clearBtn = document.getElementById("clearBtn");

let items = [];       // {id, file, state, el, error}
let busy = false;
let nextId = 1;

function esc(s) {
    return String(s).replace(/[&<>"']/g, c => ({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c]));
}

function fmt(bytes) {
    if (bytes < 1024) return bytes + " B";
    const u = ["KB", "MB", "GB", "TB"];
    let i = -1;
    do { bytes /= 1024; i++; } while (bytes >= 1024 && i < u.length - 1);
    return bytes.toFixed(bytes >= 10 ? 0 : 1) + " " + u[i];
}

function iconFor(name) {
    const ext = name.includes(".") ? name.split(".").pop().toLowerCase() : "";
    const type = TYPE_OF[ext] || "default";
    const label = ext.replace(/[^a-z0-9]/g, "").slice(0, 4).toUpperCase() || "FILE";
    return ICON_SVG[type].replace("%L%", label);
}

function addFiles(fileList) {
    for (const file of fileList) {
        const dupe = items.some(i => i.file.name === file.name && i.file.size === file.size && i.file.lastModified === file.lastModified);
        if (dupe) continue;

        const item = { id: nextId++, file, state: "queued", error: "" };

        if (MAX_FILE && file.size > MAX_FILE) {
            item.state = "error";
            item.error = "Too large (server limit " + fmt(MAX_FILE) + ")";
        }

        item.el = buildRow(item);
        queueEl.appendChild(item.el);
        items.push(item);
    }
    refresh();
}

function buildRow(item) {
    const el = document.createElement("div");
    el.className = "qitem";
    el.innerHTML =
        iconFor(item.file.name) +
        '<div class="qinfo"><div class="qname">' + esc(item.file.name) + '</div>' +
        '<div class="qmeta"></div><div class="bar"><i></i></div></div>' +
        '<button type="button" class="qx" aria-label="Remove">&times;</button>';
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
    if (item.state === "queued")    meta.textContent = size + " · waiting";
    if (item.state === "uploading") meta.textContent = size + " · uploading…";
    if (item.state === "done")      meta.textContent = size + " · uploaded";
    if (item.state === "error")     meta.textContent = item.error;
    item.el.querySelector(".qx").classList.toggle("hidden", busy && item.state === "uploading");
}

function refresh() {
    items.forEach(paint);

    const pending = items.filter(i => i.state === "queued").length;
    const failed  = items.filter(i => i.state === "error" && !i.tooBig).length;
    const total   = items.reduce((s, i) => s + i.file.size, 0);

    queueEl.classList.toggle("hidden", items.length === 0);
    barEl.classList.toggle("hidden", items.length === 0);
    summary.textContent = items.length + (items.length === 1 ? " file" : " files") + " · " + fmt(total);

    btn.disabled = busy || pending === 0;
    btn.textContent = busy ? "Uploading…" : (pending ? "Upload " + pending + (pending === 1 ? " file" : " files") : "Upload");
    clearBtn.disabled = busy;
}

function uploadOne(item) {
    return new Promise(resolve => {
        const xhr = new XMLHttpRequest();
        const fd = new FormData();
        fd.append("action", "upload");
        fd.append("files[]", item.file, item.file.name);

        xhr.open("POST", "files.php");
        xhr.setRequestHeader("X-Requested-With", "XMLHttpRequest");

        const bar = item.el.querySelector(".bar i");
        const meta = item.el.querySelector(".qmeta");

        xhr.upload.onprogress = e => {
            if (!e.lengthComputable) return;
            const p = e.loaded / e.total;
            bar.style.width = (p * 100) + "%";
            meta.textContent = fmt(item.file.size) + " · " + Math.round(p * 100) + "%";
        };
        xhr.upload.onload = () => { meta.textContent = fmt(item.file.size) + " · saving…"; };

        xhr.onload = () => {
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

    // retry anything that failed on the network/server, skip files that were too big
    items.forEach(i => { if (i.state === "error" && !(MAX_FILE && i.file.size > MAX_FILE)) i.state = "queued"; });
    refresh();

    for (const item of items.filter(i => i.state === "queued")) {
        item.state = "uploading";
        paint(item);
        await uploadOne(item);
        paint(item);
    }

    busy = false;
    refresh();

    const ok = items.filter(i => i.state === "done").length;
    const bad = items.filter(i => i.state === "error").length;

    if (bad === 0) {
        summary.textContent = "Uploaded " + ok + (ok === 1 ? " file" : " files") + " ✓";
        setTimeout(() => location.reload(), 600);
    } else {
        summary.textContent = ok + " uploaded, " + bad + " failed";
        clearBtn.textContent = "Done";
        clearBtn.onclick = () => location.reload();
    }
}

input.addEventListener("change", () => {
    addFiles(input.files);
    input.value = "";
});

form.addEventListener("submit", e => {
    e.preventDefault();
    startUpload();
});

clearBtn.addEventListener("click", () => {
    if (busy) return;
    items = [];
    queueEl.innerHTML = "";
    refresh();
});

["dragenter", "dragover"].forEach(ev =>
    zone.addEventListener(ev, e => { e.preventDefault(); zone.classList.add("drag"); }));
["dragleave", "drop"].forEach(ev =>
    zone.addEventListener(ev, e => { e.preventDefault(); zone.classList.remove("drag"); }));
zone.addEventListener("drop", e => {
    if (e.dataTransfer.files.length) addFiles(e.dataTransfer.files);
});

refresh();
</script>

</body>
</html>
