<?php
require "config.php";

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

function fileExt($filename) {
    return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
}

function canPreview($filename) {
    return in_array(fileExt($filename), ["jpg", "jpeg", "png", "gif", "webp", "pdf", "txt"]);
}

function isImage($filename) {
    return in_array(fileExt($filename), ["jpg", "jpeg", "png", "gif", "webp"]);
}

function formatSize($bytes) {
    if ($bytes < 1024) return $bytes . " B";
    $units = ["KB", "MB", "GB", "TB"];
    $i = -1;
    do {
        $bytes /= 1024;
        $i++;
    } while ($bytes >= 1024 && $i < count($units) - 1);
    return number_format($bytes, $bytes >= 10 ? 0 : 1) . " " . $units[$i];
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
.dropzone span { color: var(--muted); font-size: .88rem; word-break: break-all; }
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
.badge {
    flex: 0 0 44px;
    height: 44px;
    border-radius: 11px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: .68rem;
    font-weight: 800;
    letter-spacing: .03em;
    text-transform: uppercase;
    background: rgba(108, 140, 255, .14);
    color: var(--accent);
}
.badge.img { background: rgba(66, 214, 164, .14); color: #42d6a4; }
.badge.pdf { background: rgba(255, 93, 108, .14); color: var(--danger); }
.badge.txt { background: rgba(255, 196, 77, .14); color: #ffc44d; }

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

    <form class="upload" action="files.php" method="POST" enctype="multipart/form-data" id="uploadForm">
        <label class="dropzone" id="dropzone">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16V4m0 0l-4 4m4-4l4 4"/><path d="M4 16v3a1 1 0 001 1h14a1 1 0 001-1v-3"/></svg>
            <strong>Tap to choose a file</strong>
            <span id="fileLabel">or drag and drop it here</span>
            <input type="file" name="file" id="fileInput" required>
        </label>
        <button type="submit" name="action" value="upload" class="btn-primary" id="uploadBtn" disabled>Upload File</button>
    </form>

    <div class="list-head">
        <h2>Files</h2>
        <span class="count"><?= $count ?> <?= $count === 1 ? "file" : "files" ?></span>
    </div>

    <?php if ($count > 0): ?>
        <?php foreach ($files as $file):
            $ext = fileExt($file["filename"]);
            $cls = isImage($file["filename"]) ? "img" : ($ext === "pdf" ? "pdf" : ($ext === "txt" ? "txt" : ""));
            $label = $ext !== "" ? substr($ext, 0, 4) : "file";
        ?>
            <div class="file">
                <div class="badge <?= $cls ?>"><?= htmlspecialchars($label) ?></div>

                <div class="info">
                    <div class="name" title="<?= htmlspecialchars($file["filename"]) ?>"><?= htmlspecialchars($file["filename"]) ?></div>
                    <div class="meta"><?= formatSize($file["size"]) ?></div>
                </div>

                <div class="actions">
                    <?php if (canPreview($file["filename"])): ?>
                        <button type="button" class="btn"
                            data-id="<?= (int)$file["id"] ?>"
                            data-name="<?= htmlspecialchars($file["filename"], ENT_QUOTES) ?>"
                            data-type="<?= isImage($file["filename"]) ? "image" : "frame" ?>"
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
        </div>
    </div>
</div>

<script>
const modal = document.getElementById("previewModal");
const frame = document.getElementById("previewFrame");
const img = document.getElementById("previewImg");

function openPreview(btn) {
    const url = "preview.php?id=" + btn.dataset.id;
    document.getElementById("previewTitle").textContent = btn.dataset.name;

    frame.classList.add("hidden");
    img.classList.add("hidden");

    if (btn.dataset.type === "image") {
        img.src = url;
        img.alt = btn.dataset.name;
        img.classList.remove("hidden");
    } else {
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
    document.body.style.overflow = "";
}

document.addEventListener("keydown", e => {
    if (e.key === "Escape") closePreview();
});

// Upload UI
const input = document.getElementById("fileInput");
const label = document.getElementById("fileLabel");
const btn = document.getElementById("uploadBtn");
const zone = document.getElementById("dropzone");

function updateLabel() {
    if (input.files.length) {
        label.textContent = input.files[0].name;
        btn.disabled = false;
    } else {
        label.textContent = "or drag and drop it here";
        btn.disabled = true;
    }
}
input.addEventListener("change", updateLabel);

["dragenter", "dragover"].forEach(ev =>
    zone.addEventListener(ev, e => { e.preventDefault(); zone.classList.add("drag"); }));
["dragleave", "drop"].forEach(ev =>
    zone.addEventListener(ev, e => { e.preventDefault(); zone.classList.remove("drag"); }));
zone.addEventListener("drop", e => {
    if (e.dataTransfer.files.length) {
        input.files = e.dataTransfer.files;
        updateLabel();
    }
});
</script>

</body>
</html>
