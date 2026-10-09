<?php
/*
    Shared helpers: file types, icons, previews, size parsing, file streaming.
    Used by index.php, files.php and preview.php
*/

function ftTypes(): array {
    static $t = null;
    if ($t !== null) return $t;

    // type => [color, extensions, svg glyph (24x24 box)]
    $t = [
        'image' => ['#42d6a4',
            'jpg jpeg jfif png gif webp bmp avif ico tif tiff heic heif raw cr2 nef dng',
            '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="1.6"/><path d="M3 17l5-5 4 4 3-3 6 6"/>'],
        'vector' => ['#7dd3fc',
            'svg eps cdr',
            '<path d="M4 19C4 9 9 5 20 5"/><circle cx="4" cy="19" r="1.8"/><circle cx="20" cy="5" r="1.8"/>'],
        'design' => ['#d946ef',
            'psd psb ai fig sketch xd indd xcf kra',
            '<path d="M12 3l6 10-6 8-6-8z"/><circle cx="12" cy="11" r="1.5"/>'],
        '3d' => ['#a3e635',
            'obj stl fbx blend 3ds dae gltf glb step stp dwg dxf',
            '<path d="M12 3l8 4.5v9L12 21l-8-4.5v-9zM12 12l8-4.5M12 12v9M12 12L4 7.5"/>'],
        'video' => ['#f472b6',
            'mp4 mkv mov avi wmv flv webm m4v mpg mpeg 3gp ogv mts m2ts',
            '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M10 9.5l5 2.5-5 2.5z" fill="{c}"/>'],
        'audio' => ['#b58cff',
            'mp3 wav ogg oga m4a aac flac wma opus aiff aif mid midi amr',
            '<path d="M9 18V6l11-2v12"/><circle cx="6.5" cy="18" r="2.5"/><circle cx="17.5" cy="16" r="2.5"/>'],
        'pdf' => ['#ff4d4d',
            'pdf',
            '<path d="M6 7h12M6 12h12M6 17h7"/>'],
        'word' => ['#5b9dff',
            'doc docx odt rtf pages dot dotx',
            '<path d="M3 6l3.5 12L12 9l5.5 9L21 6"/>'],
        'excel' => ['#3ccf7e',
            'xls xlsx xlsm ods csv tsv numbers',
            '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 10h18M3 15h18M9 4v16"/>'],
        'ppt' => ['#ff7a45',
            'ppt pptx pps ppsx odp key',
            '<rect x="3" y="4" width="18" height="12" rx="1.5"/><path d="M12 16v4M8 20h8"/>'],
        'archive' => ['#ffc44d',
            'zip rar 7z tar gz tgz bz2 xz zst cab lz',
            '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M12 3v4M12 9v2"/><rect x="10" y="13" width="4" height="5" rx="1"/>'],
        'code' => ['#4dd0e1',
            'html htm css scss js mjs jsx ts tsx php py java c cpp h hpp cs go rb rs swift kt vue lua pl r dart scala sh bash zsh ps1 bat cmd',
            '<path d="M8 7l-5 5 5 5M16 7l5 5-5 5M13.5 5l-3 14"/>'],
        'data' => ['#e0a84a',
            'json jsonl ndjson geojson xml yml yaml toml ini env cfg conf',
            '<path d="M9 4c-2 0-3 1-3 3v2c0 1.5-1 3-3 3 2 0 3 1.5 3 3v2c0 2 1 3 3 3M15 4c2 0 3 1 3 3v2c0 1.5 1 3 3 3-2 0-3 1.5-3 3v2c0 2-1 3-3 3"/>'],
        'text' => ['#aab2c5',
            'txt md markdown log nfo readme',
            '<path d="M5 6h14M12 6v13M9 19h6"/>'],
        'ebook' => ['#c58f6b',
            'epub mobi azw azw3 fb2 djvu cbz cbr',
            '<path d="M12 6c-2-1.5-5-2-8-2v14c3 0 6 .5 8 2 2-1.5 5-2 8-2V4c-3 0-6 .5-8 2zM12 6v14"/>'],
        'font' => ['#818cf8',
            'ttf otf woff woff2 eot',
            '<path d="M5 19L12 5l7 14M8 14h8"/>'],
        'db' => ['#5eb0ef',
            'sql sqlite sqlite3 db mdb accdb dump',
            '<ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v12c0 1.7 3.6 3 8 3s8-1.3 8-3V6M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>'],
        'exe' => ['#ff9f1c',
            'exe msi dll sys',
            '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 10l3 2-3 2M12 15h5"/>'],
        'package' => ['#c8a96a',
            'apk xapk aab ipa dmg pkg deb rpm appimage jar whl',
            '<rect x="3" y="4" width="18" height="5" rx="1"/><path d="M5 9v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V9M10 13h4"/>'],
        'disk' => ['#8d99ae',
            'iso img bin vhd vhdx vmdk vdi',
            '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="2.5"/>'],
        'default' => ['#8b92a3',
            '',
            '<path d="M7 8h10M7 12h10M7 16h6"/>'],
    ];
    return $t;
}

function ftExt(string $filename): string {
    return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
}

/* ext => type key */
function ftTypeMap(): array {
    static $map = null;
    if ($map !== null) return $map;
    $map = [];
    foreach (ftTypes() as $type => $def) {
        foreach (preg_split('/\s+/', trim($def[1])) as $e) {
            if ($e !== '') $map[$e] = $type;
        }
    }
    return $map;
}

function ftTypeOf(string $ext): string {
    return ftTypeMap()[$ext] ?? 'default';
}

function ftLabel(string $ext): string {
    $l = strtoupper(substr(preg_replace('/[^a-z0-9]/i', '', $ext), 0, 4));
    return $l === '' ? 'FILE' : $l;
}

/* $label must already be safe (see ftLabel) or the "%L%" placeholder */
function ftIcon(string $type, string $label): string {
    $types = ftTypes();
    $def = $types[$type] ?? $types['default'];
    $c = $def[0];
    $glyph = str_replace('{c}', $c, $def[2]);

    return '<svg class="ficon" viewBox="0 0 44 56" aria-hidden="true">'
        . '<path d="M6 2h22l12 12v38a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z" fill="' . $c . '" fill-opacity=".12" stroke="' . $c . '" stroke-width="1.6" stroke-linejoin="round"/>'
        . '<path d="M28 2v10a2 2 0 0 0 2 2h10" fill="none" stroke="' . $c . '" stroke-width="1.6" stroke-linejoin="round"/>'
        . '<g transform="translate(10 17)" fill="none" stroke="' . $c . '" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $glyph . '</g>'
        . '<text x="22" y="49.5" text-anchor="middle" font-size="7.5" font-weight="800" fill="' . $c . '" font-family="-apple-system,Segoe UI,Roboto,Arial,sans-serif">' . $label . '</text>'
        . '</svg>';
}

/* type => svg with %L% placeholder (for the JS upload queue) */
function ftAllIcons(): array {
    $out = [];
    foreach (array_keys(ftTypes()) as $type) {
        $out[$type] = ftIcon($type, '%L%');
    }
    return $out;
}

/* Preview support: ext => [kind, mime]  (kind: image | video | audio | frame) */
function ftPreview(string $ext): ?array {
    static $p = null;
    if ($p === null) {
        $p = [];
        foreach (["jpg" => "image/jpeg", "jpeg" => "image/jpeg", "jfif" => "image/jpeg", "png" => "image/png",
                  "gif" => "image/gif", "webp" => "image/webp", "bmp" => "image/bmp", "avif" => "image/avif",
                  "ico" => "image/x-icon", "svg" => "image/svg+xml"] as $e => $m) $p[$e] = ["image", $m];

        foreach (["mp4" => "video/mp4", "m4v" => "video/mp4", "webm" => "video/webm",
                  "ogv" => "video/ogg", "mov" => "video/quicktime"] as $e => $m) $p[$e] = ["video", $m];

        foreach (["mp3" => "audio/mpeg", "wav" => "audio/wav", "ogg" => "audio/ogg", "oga" => "audio/ogg",
                  "opus" => "audio/ogg", "m4a" => "audio/mp4", "aac" => "audio/aac", "flac" => "audio/flac"] as $e => $m) $p[$e] = ["audio", $m];

        $p["pdf"] = ["frame", "application/pdf"];

        // Text-like files are always served as text/plain so nothing can execute
        foreach (explode(" ", "txt md markdown log csv tsv json jsonl xml yml yaml ini conf cfg toml env sql " .
                               "html htm css js mjs jsx ts tsx php py java c cpp h hpp cs go rb rs sh bash bat cmd ps1") as $e) {
            $p[$e] = ["frame", "text/plain; charset=utf-8"];
        }
    }
    return $p[$ext] ?? null;
}

function iniBytes($v): int {
    $v = trim((string)$v);
    if ($v === "") return 0;
    $unit = strtolower(substr($v, -1));
    $n = (float)$v;
    switch ($unit) {
        case "g": $n *= 1024;
        case "m": $n *= 1024;
        case "k": $n *= 1024;
    }
    return $n > 0 ? (int)$n : 0;   // 0 = unlimited
}

function formatSize($bytes): string {
    if ($bytes < 1024) return $bytes . " B";
    $units = ["KB", "MB", "GB", "TB"];
    $i = -1;
    do {
        $bytes /= 1024;
        $i++;
    } while ($bytes >= 1024 && $i < count($units) - 1);
    return number_format($bytes, $bytes >= 10 ? 0 : 1) . " " . $units[$i];
}

function dispositionHeader(string $type, string $name): string {
    $ascii = preg_replace('/[^\x20-\x7E]/', '_', $name);
    $ascii = str_replace(['"', '\\', '%'], '_', $ascii);
    return "Content-Disposition: " . $type . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name);
}

/*
    Streams a file with HTTP Range support (video seeking, resumable downloads)
    and without loading it into memory.
*/
function sendFile(string $path, string $mime, string $disposition, array $extraHeaders = []): void {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    $size  = filesize($path);
    $start = 0;
    $end   = $size - 1;
    $status = 200;

    if (isset($_SERVER["HTTP_RANGE"]) && $size > 0
        && preg_match('/^bytes=(\d*)-(\d*)$/', trim($_SERVER["HTTP_RANGE"]), $m)
        && ($m[1] !== "" || $m[2] !== "")) {

        if ($m[1] === "") {                       // last N bytes
            $start = max(0, $size - (int)$m[2]);
        } else {
            $start = (int)$m[1];
            if ($m[2] !== "") $end = min((int)$m[2], $size - 1);
        }

        if ($start > $end || $start >= $size) {
            http_response_code(416);
            header("Content-Range: bytes */" . $size);
            exit;
        }
        $status = 206;
    }

    $length = max(0, $end - $start + 1);

    http_response_code($status);
    header("Content-Type: " . $mime);
    header("Accept-Ranges: bytes");
    header("Content-Length: " . $length);
    if ($status === 206) header("Content-Range: bytes $start-$end/$size");
    header($disposition);
    foreach ($extraHeaders as $h) header($h);

    while (ob_get_level() > 0) ob_end_clean();
    set_time_limit(0);

    $fp = fopen($path, "rb");
    if ($fp === false) exit;
    fseek($fp, $start);

    $remaining = $length;
    while ($remaining > 0 && !feof($fp) && !connection_aborted()) {
        $chunk = fread($fp, min(1048576, $remaining));
        if ($chunk === false) break;
        echo $chunk;
        flush();
        $remaining -= strlen($chunk);
    }
    fclose($fp);
    exit;
}
