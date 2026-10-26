<?php
/*
    Feeds the moving picture wall on the login page.

    This page is open to people who are NOT signed in, so it only ever touches files whose sharing is set to
    "everyone" (visibility = "all"). Private files and files shared with specific people are never listed or sent,
    and that is checked again on every single request, so changing a file to private hides it straight away.

      loginwall.php?list=1   JSON { "items": [ picture urls ] }, a random pick each time
      loginwall.php?id=12    a small picture for one file: the picture itself, or an audio file's album cover

    Only pictures and album covers are used. Names, owners and file contents are never sent.
*/
require "config.php";
require "filetypes.php";
require "audiometa.php";

const LW_IMAGES = ["jpg", "jpeg", "jfif", "png", "gif", "webp", "bmp", "avif"];   // svg and ico are left out on purpose
const LW_AUDIO  = ["mp3", "m4a", "flac", "ogg", "oga", "opus", "aac", "wav"];
const LW_WIDTH  = 480;                    // thumbnails are scaled down to this width
const LW_MAX_SOURCE = 40 * 1024 * 1024;   // bigger pictures are skipped, they would be too heavy to shrink

function lwNone(int $code = 404): void {
    http_response_code($code);
    header("Cache-Control: no-store");
    exit;
}

/* A file row that anybody may see: visibility "everyone" only */
function lwPublicFile(mysqli $conn, int $id): ?array {
    $stmt = $conn->prepare("SELECT id, filename, filepath FROM files WHERE id = ? AND visibility = 'all'");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) return null;

    $path = __DIR__ . "/" . $row["filepath"];
    // the stored path must stay inside this folder
    $real = realpath($path);
    if ($real === false || !is_file($real) || strpos($real, realpath(__DIR__) . DIRECTORY_SEPARATOR) !== 0) return null;

    $row["id"]   = (int)$row["id"];
    $row["path"] = $real;
    $row["ext"]  = ftExt($row["filename"]);
    return $row;
}

/* Raw picture bytes for a file (the picture itself, or the cover of an audio file); null when there is none */
function lwSource(array $f): ?string {
    if (in_array($f["ext"], LW_IMAGES, true)) {
        $size = filesize($f["path"]);
        if ($size === false || $size > LW_MAX_SOURCE) return null;
        $dim = @getimagesize($f["path"]);
        if ($dim && $dim[0] * $dim[1] > 40000000) return null;   // over 40 megapixels
        $data = @file_get_contents($f["path"]);
        return $data === false ? null : $data;
    }
    if (in_array($f["ext"], LW_AUDIO, true)) {
        $tags = audioMeta($f["path"]);
        return $tags["cover"]["data"] ?? null;
    }
    return null;
}

/* Scales to LW_WIDTH and returns JPEG bytes; null if the picture can't be read */
function lwThumb(string $data): ?string {
    if (!function_exists("imagecreatefromstring")) return null;
    $img = @imagecreatefromstring($data);
    if (!$img) return null;

    $w = imagesx($img);
    $h = imagesy($img);
    if ($w < 1 || $h < 1) return null;

    $tw = min(LW_WIDTH, $w);
    $th = max(1, (int)round($h * $tw / $w));
    $out = imagecreatetruecolor($tw, $th);
    imagefill($out, 0, 0, imagecolorallocate($out, 12, 37, 64));   // transparent areas turn dark blue
    imagecopyresampled($out, $img, 0, 0, 0, 0, $tw, $th, $w, $h);

    ob_start();
    imagejpeg($out, null, 78);
    $jpg = ob_get_clean();
    return $jpg === "" ? null : $jpg;
}

/* ---------- one picture ---------- */
if (isset($_GET["id"])) {
    $f = lwPublicFile($conn, (int)$_GET["id"]);
    if (!$f) lwNone();

    // thumbnails are cached outside the web folder; the file's change time is part of the name
    $dir   = sys_get_temp_dir() . "/lfs_loginwall";
    $cache = $dir . "/" . $f["id"] . "-" . filemtime($f["path"]) . ".jpg";

    $jpg = is_file($cache) ? @file_get_contents($cache) : false;
    if ($jpg === false) {
        $src = lwSource($f);
        $jpg = $src === null ? null : lwThumb($src);
        if ($jpg === null) lwNone();
        if (is_dir($dir) || @mkdir($dir, 0700, true)) @file_put_contents($cache, $jpg);
    }

    header("Content-Type: image/jpeg");
    header("X-Content-Type-Options: nosniff");
    header("Cache-Control: public, max-age=3600");
    header("Content-Length: " . strlen($jpg));
    echo $jpg;
    exit;
}

/* ---------- the list ---------- */
if (isset($_GET["list"])) {
    $items = [];
    $audioChecks = 0;

    $res = $conn->query("SELECT id, filename, filepath FROM files WHERE visibility = 'all' ORDER BY RAND() LIMIT 150");
    while ($row = $res->fetch_assoc()) {
        if (count($items) >= 48) break;

        $ext = ftExt($row["filename"]);
        $isImage = in_array($ext, LW_IMAGES, true);
        $isAudio = in_array($ext, LW_AUDIO, true);
        if (!$isImage && !$isAudio) continue;

        $real = realpath(__DIR__ . "/" . $row["filepath"]);
        if ($real === false || !is_file($real) || strpos($real, realpath(__DIR__) . DIRECTORY_SEPARATOR) !== 0) continue;

        // only keep audio files that really have a cover (checking costs a little, so only a few are checked)
        if ($isAudio) {
            if (++$audioChecks > 12) continue;
            $tags = audioMeta($real);
            if (empty($tags["cover"])) continue;
        }

        $items[] = "loginwall.php?id=" . (int)$row["id"] . "&v=" . filemtime($real);
    }

    header("Content-Type: application/json; charset=utf-8");
    header("Cache-Control: no-store");
    header("X-Content-Type-Options: nosniff");
    echo json_encode(["items" => $items], JSON_UNESCAPED_SLASHES);
    exit;
}

lwNone(400);
