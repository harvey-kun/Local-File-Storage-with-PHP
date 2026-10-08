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

function canPreview($filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($ext, ["jpg", "jpeg", "png", "gif", "webp", "pdf", "txt"]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Local File Storage</title>
<style>
* { box-sizing: border-box; }
body {
    margin: 0;
    font-family: Arial, sans-serif;
    background: #121212;
    color: #eee;
}
.container {
    width: 90%;
    max-width: 900px;
    margin: 50px auto;
}
h1 { text-align: center; }
.upload {
    background: #1e1e1e;
    padding: 20px;
    border-radius: 12px;
    margin-bottom: 25px;
}
input[type=file] {
    width: 100%;
    padding: 10px;
    margin-bottom: 12px;
    background: #292929;
    color: white;
    border-radius: 8px;
}
button, .btn {
    border: 0;
    padding: 9px 14px;
    border-radius: 7px;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
    background: #3a3a3a;
    color: white;
}
button:hover, .btn:hover { background: #505050; }
.file {
    background: #1e1e1e;
    padding: 15px;
    border-radius: 10px;
    margin: 10px 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
}
.name { word-break: break-all; }
.actions { white-space: nowrap; }
.empty { text-align: center; color: #999; }

.modal {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.75);
    justify-content: center;
    align-items: center;
    z-index: 1000;
}

.modal-content {
    position: relative;
    width: 80%;
    max-width: 900px;
    height: 80%;
    background: #1e1e1e;
    border-radius: 12px;
    padding: 15px;
}

#previewFrame {
    width: 100%;
    height: 100%;
    border: none;
    border-radius: 8px;
    background: white;
}

.close {
    position: absolute;
    right: 8px;
    top: 8px;
    z-index: 2;
    width: 35px;
    height: 35px;
    border-radius: 50%;
    border: none;
    background: #333;
    color: white;
    font-size: 24px;
    cursor: pointer;
}

.close:hover {
    background: #555;
}
</style>
</head>


<!-- Preview Modal -->
<div id="previewModal" class="modal">
    <div class="modal-content">
        <button class="close" onclick="closePreview()">×</button>

        <iframe id="previewFrame"></iframe>
    </div>
</div>

<script>
function openPreview(id) {
    document.getElementById("previewFrame").src = "preview.php?id=" + id;
    document.getElementById("previewModal").style.display = "flex";
}

function closePreview() {
    document.getElementById("previewModal").style.display = "none";
    document.getElementById("previewFrame").src = "";
}
</script>


<body>

<div class="container">
    <h1>Local File Storage</h1>

    <div class="upload">
        <form action="files.php" method="POST" enctype="multipart/form-data">
            <input type="file" name="file" required>
            <button type="submit" name="action" value="upload">Upload File</button>
        </form>
    </div>

    <h2>Files</h2>

    <?php if ($result && $result->num_rows > 0): ?>
        <?php while ($file = $result->fetch_assoc()): ?>
            <div class="file">
                <div class="name">
                    <?= htmlspecialchars($file["filename"]) ?>
                </div>

                <div class="actions">
                    <?php if (canPreview($file["filename"])): ?>
                        <button class="btn" onclick="openPreview(<?= (int)$file["id"] ?>)">
    Preview
</button>
                    <?php endif; ?>

                    <a class="btn" href="files.php?action=download&id=<?= (int)$file["id"] ?>">Download</a>
<a class="btn delete-btn"
   href="files.php?action=delete&id=<?= (int)$file["id"] ?>"
   onclick="return confirm('Are you sure you want to delete this file?')">
    Delete
</a>
                </div>
            </div>
        <?php endwhile; ?>
    <?php else: ?>
        <p class="empty">No files uploaded yet.</p>
    <?php endif; ?>
</div>

</body>
</html>
