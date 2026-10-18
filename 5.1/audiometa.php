<?php
/*
    Reads title / artist / album and the embedded cover art from audio files.
    Supports MP3 (ID3v2.2 / 2.3 / 2.4), FLAC and M4A / MP4 (iTunes tags). No libraries needed.
    Used by preview.php (?meta=1 and ?cover=1).

    Anything unreadable just returns empty values, so it can never break a preview.
*/

const AM_MAX_TAG = 26214400;   // never read more than 25 MB of tag data

function audioMeta(string $path): array {
    $meta = ["title" => "", "artist" => "", "album" => "", "cover" => null];

    $fp = @fopen($path, "rb");
    if (!$fp) return $meta;

    try {
        $head = (string)fread($fp, 12);

        if (strlen($head) >= 10 && substr($head, 0, 3) === "ID3") {
            $found = amId3($fp, $head);
            $meta  = amMerge($meta, $found);

            // Some FLAC files have an ID3 block in front of the FLAC data
            if (!empty($found["_end"])) {
                fseek($fp, $found["_end"]);
                if (fread($fp, 4) === "fLaC") {
                    $meta = amMerge($meta, amFlac($fp));
                }
            }
        } elseif (substr($head, 0, 4) === "fLaC") {
            fseek($fp, 4);
            $meta = amMerge($meta, amFlac($fp));
        } elseif (substr($head, 4, 4) === "ftyp") {
            $meta = amMerge($meta, amMp4($fp));
        }
    } catch (Throwable $e) {
        // ignore: return whatever was read so far
    }

    fclose($fp);
    return $meta;
}

function amEmpty(): array {
    return ["title" => "", "artist" => "", "album" => "", "cover" => null];
}

/* Fills the empty fields of $base with values from $add */
function amMerge(array $base, array $add): array {
    foreach (["title", "artist", "album"] as $k) {
        if ($base[$k] === "" && !empty($add[$k])) $base[$k] = (string)$add[$k];
    }
    if ($base["cover"] === null && !empty($add["cover"])) $base["cover"] = $add["cover"];
    if (!empty($add["_end"])) $base["_end"] = $add["_end"];
    return $base;
}

/* Only real image data is accepted, never the mime type a tag claims */
function amImageMime(string $b): ?string {
    if (strncmp($b, "\xFF\xD8\xFF", 3) === 0) return "image/jpeg";
    if (strncmp($b, "\x89PNG", 4) === 0)       return "image/png";
    if (strncmp($b, "GIF8", 4) === 0)          return "image/gif";
    if (strncmp($b, "RIFF", 4) === 0 && substr($b, 8, 4) === "WEBP") return "image/webp";
    if (strncmp($b, "BM", 2) === 0)            return "image/bmp";
    return null;
}

function amSynchsafe(string $b): int {
    return ((ord($b[0]) & 0x7F) << 21) | ((ord($b[1]) & 0x7F) << 14) | ((ord($b[2]) & 0x7F) << 7) | (ord($b[3]) & 0x7F);
}

function amDecode(string $s, int $enc): string {
    if ($enc === 1 || $enc === 2) {
        $from = "UTF-16BE";
        if (strncmp($s, "\xFF\xFE", 2) === 0)      { $from = "UTF-16LE"; $s = substr($s, 2); }
        elseif (strncmp($s, "\xFE\xFF", 2) === 0) { $s = substr($s, 2); }
        elseif ($enc === 1)                        { $from = "UTF-16LE"; }
        return (string)@mb_convert_encoding($s, "UTF-8", $from);
    }
    if ($enc === 3) return $s;
    return (string)@mb_convert_encoding($s, "UTF-8", "ISO-8859-1");
}

/* Text frame: first byte = encoding, rest = text (several values may be separated by a null) */
function amText(string $b): string {
    if ($b === "") return "";
    $t = amDecode(substr($b, 1), ord($b[0]));
    $t = explode("\0", $t, 2)[0];
    return trim($t);
}

/* ---------- MP3 (ID3v2) ---------- */

function amId3($fp, string $head): array {
    $r = amEmpty();

    $ver   = ord($head[3]);
    $flags = ord($head[5]);
    $size  = amSynchsafe(substr($head, 6, 4));
    $r["_end"] = 10 + $size;

    if ($ver < 2 || $ver > 4 || $size <= 0 || $size > AM_MAX_TAG) return $r;

    fseek($fp, 10);
    $d = (string)fread($fp, $size);
    if (($flags & 0x80) && $ver < 4) {
        $d = str_replace("\xFF\x00", "\xFF", $d);          // undo unsynchronisation
    }

    $n = strlen($d);
    $p = 0;

    if ($ver >= 3 && ($flags & 0x40) && $n >= 4) {          // skip extended header
        $ext = $ver === 4 ? amSynchsafe(substr($d, 0, 4)) : unpack("N", substr($d, 0, 4))[1] + 4;
        $p = min($n, $ext);
    }

    $idLen  = $ver === 2 ? 3 : 4;
    $hdrLen = $ver === 2 ? 6 : 10;
    $bestPic = -1;

    while ($p + $hdrLen <= $n) {
        $id = substr($d, $p, $idLen);
        if ($id[0] === "\0") break;                         // padding

        if ($ver === 2) {
            $fs = (ord($d[$p + 3]) << 16) | (ord($d[$p + 4]) << 8) | ord($d[$p + 5]);
        } elseif ($ver === 3) {
            $fs = unpack("N", substr($d, $p + 4, 4))[1];
        } else {
            $fs = amSynchsafe(substr($d, $p + 4, 4));
        }
        $fl = $ver >= 3 ? ord($d[$p + 9]) : 0;

        $p += $hdrLen;
        if ($fs <= 0 || $p + $fs > $n) break;

        $body = substr($d, $p, $fs);
        $p += $fs;

        if ($ver === 4) {
            if ($fl & 0x01) $body = substr($body, 4);       // data length indicator
            if ($fl & 0x02) $body = str_replace("\xFF\x00", "\xFF", $body);
        }

        switch ($id) {
            case "TIT2": case "TT2":
                if ($r["title"] === "") $r["title"] = amText($body);
                break;
            case "TPE1": case "TP1":
                if ($r["artist"] === "") $r["artist"] = amText($body);
                break;
            case "TALB": case "TAL":
                if ($r["album"] === "") $r["album"] = amText($body);
                break;
            case "APIC": case "PIC":
                $pic = amApic($body, $ver);
                // prefer picture type 3 (front cover), otherwise keep the first one found
                if ($pic && ($r["cover"] === null || ($pic["type"] === 3 && $bestPic !== 3))) {
                    $r["cover"] = ["mime" => $pic["mime"], "data" => $pic["data"]];
                    $bestPic = $pic["type"];
                }
                break;
        }
    }

    return $r;
}

function amApic(string $b, int $ver): ?array {
    $n = strlen($b);
    if ($n < 6) return null;

    $enc = ord($b[0]);
    if ($ver === 2) {
        $p = 4;                                             // 3-letter image format
    } else {
        $z = strpos($b, "\0", 1);                           // mime type, null terminated
        if ($z === false) return null;
        $p = $z + 1;
    }
    if ($p >= $n) return null;

    $type = ord($b[$p]);
    $p++;

    // description: ends with one null byte (latin1 / utf-8) or two (utf-16)
    if ($enc === 1 || $enc === 2) {
        while ($p + 1 < $n && !($b[$p] === "\0" && $b[$p + 1] === "\0")) $p += 2;
        $p += 2;
    } else {
        $z = strpos($b, "\0", $p);
        if ($z === false) return null;
        $p = $z + 1;
    }

    $img  = (string)substr($b, $p);
    $mime = amImageMime($img);

    if (!$mime) {
        // Badly written tag: look for the start of the image data
        foreach (["\xFF\xD8\xFF", "\x89PNG"] as $sig) {
            $q = strpos($b, $sig);
            if ($q !== false) {
                $img  = substr($b, $q);
                $mime = amImageMime($img);
                if ($mime) break;
            }
        }
    }

    return $mime ? ["type" => $type, "mime" => $mime, "data" => $img] : null;
}

/* ---------- FLAC ---------- */

/* File pointer must be right after the "fLaC" marker */
function amFlac($fp): array {
    $r = amEmpty();
    $bestPic = -1;

    for ($i = 0; $i < 64; $i++) {
        $h = (string)fread($fp, 4);
        if (strlen($h) < 4) break;

        $last = (ord($h[0]) & 0x80) !== 0;
        $type = ord($h[0]) & 0x7F;
        $len  = (ord($h[1]) << 16) | (ord($h[2]) << 8) | ord($h[3]);

        if ($type === 4 && $len > 0 && $len <= AM_MAX_TAG) {            // VORBIS_COMMENT
            amVorbis((string)fread($fp, $len), $r);
        } elseif ($type === 6 && $len > 0 && $len <= AM_MAX_TAG) {      // PICTURE
            $pic = amFlacPicture((string)fread($fp, $len));
            if ($pic && ($r["cover"] === null || ($pic["type"] === 3 && $bestPic !== 3))) {
                $r["cover"] = ["mime" => $pic["mime"], "data" => $pic["data"]];
                $bestPic = $pic["type"];
            }
        } else {
            fseek($fp, $len, SEEK_CUR);
        }

        if ($last) break;
    }

    return $r;
}

function amVorbis(string $d, array &$r): void {
    $n = strlen($d);
    if ($n < 8) return;

    $p = 4 + unpack("V", substr($d, 0, 4))[1];              // skip vendor string
    if ($p + 4 > $n) return;

    $count = unpack("V", substr($d, $p, 4))[1];
    $p += 4;

    for ($i = 0; $i < $count && $p + 4 <= $n; $i++) {
        $l = unpack("V", substr($d, $p, 4))[1];
        $p += 4;
        if ($l > $n - $p) break;

        $c  = substr($d, $p, $l);
        $p += $l;

        $eq = strpos($c, "=");
        if ($eq === false) continue;

        $k = strtolower(substr($c, 0, $eq));
        if (($k === "title" || $k === "artist" || $k === "album") && $r[$k] === "") {
            $r[$k] = trim(substr($c, $eq + 1));
        }
    }
}

function amFlacPicture(string $d): ?array {
    $n = strlen($d);
    if ($n < 32) return null;

    $type = unpack("N", substr($d, 0, 4))[1];
    $p = 8 + unpack("N", substr($d, 4, 4))[1];              // mime type
    if ($p + 4 > $n) return null;

    $p += 4 + unpack("N", substr($d, $p, 4))[1];            // description
    $p += 16;                                               // width, height, depth, colours
    if ($p + 4 > $n) return null;

    $len = unpack("N", substr($d, $p, 4))[1];
    $img = (string)substr($d, $p + 4, $len);
    $mime = amImageMime($img);

    return $mime ? ["type" => $type, "mime" => $mime, "data" => $img] : null;
}

/* ---------- M4A / MP4 ---------- */

/* Lists the atoms found between $s and $e of $b as [type, bodyStart, bodyEnd] */
function amAtoms(string $b, int $s, int $e): array {
    $out = [];
    $e = min($e, strlen($b));

    while ($s + 8 <= $e) {
        $size = unpack("N", substr($b, $s, 4))[1];
        $type = substr($b, $s + 4, 4);
        $hdr  = 8;

        if ($size === 1) {
            if ($s + 16 > $e) break;
            $size = unpack("N", substr($b, $s + 8, 4))[1] * 4294967296 + unpack("N", substr($b, $s + 12, 4))[1];
            $hdr  = 16;
        } elseif ($size === 0) {
            $size = $e - $s;
        }

        if ($size < $hdr) break;
        if ($s + $size > $e) $size = $e - $s;               // truncated: clamp

        $out[] = [$type, $s + $hdr, $s + $size];
        $s += $size;
    }

    return $out;
}

function amMp4($fp): array {
    $r = amEmpty();
    $fileSize = (int)(fstat($fp)["size"] ?? 0);
    $pos = 0;

    while ($pos + 8 <= $fileSize) {
        fseek($fp, $pos);
        $h = (string)fread($fp, 16);
        if (strlen($h) < 8) break;

        $len  = unpack("N", substr($h, 0, 4))[1];
        $type = substr($h, 4, 4);
        $hdr  = 8;

        if ($len === 1 && strlen($h) >= 16) {
            $len = unpack("N", substr($h, 8, 4))[1] * 4294967296 + unpack("N", substr($h, 12, 4))[1];
            $hdr = 16;
        } elseif ($len === 0) {
            $len = $fileSize - $pos;
        }
        if ($len < $hdr) break;

        if ($type === "moov" && $len > $hdr) {                 // the tags live inside moov
            fseek($fp, $pos + $hdr);
            amMp4Tags((string)fread($fp, min($len - $hdr, AM_MAX_TAG)), $r);
            break;
        }

        $pos += $len;                                       // jump over mdat etc. without reading it
    }

    return $r;
}

function amMp4Tags(string $b, array &$r): void {
    $s = 0;
    $e = strlen($b);

    foreach (["udta", "meta", "ilst"] as $name) {
        $hit = null;
        foreach (amAtoms($b, $s, $e) as $a) {
            if ($a[0] === $name) { $hit = $a; break; }
        }
        if ($hit === null) return;

        $s = $hit[1];
        $e = $hit[2];
        if ($name === "meta") $s += 4;                      // meta has 4 version/flag bytes
    }

    $names = ["\xA9nam" => "title", "\xA9ART" => "artist", "\xA9alb" => "album"];

    foreach (amAtoms($b, $s, $e) as $item) {
        foreach (amAtoms($b, $item[1], $item[2]) as $a) {
            if ($a[0] !== "data" || $a[2] - $a[1] < 8) continue;

            $payload = substr($b, $a[1] + 8, $a[2] - $a[1] - 8);

            if (isset($names[$item[0]])) {
                $k = $names[$item[0]];
                if ($r[$k] === "") $r[$k] = trim($payload);
            } elseif ($item[0] === "covr" && $r["cover"] === null) {
                $mime = amImageMime($payload);
                if ($mime) $r["cover"] = ["mime" => $mime, "data" => $payload];
            }
            break;
        }
    }
}
