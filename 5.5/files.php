<?php
require "config.php";
require "filetypes.php";
require "auth.php";

/*
    This file handles (every request needs a signed-in user):
    - Uploading files (one or many at once) together with who may see them
    - Downloading files (every download is logged: who and when)
    - Deleting / renaming / notes / sharing: the uploader and the admin only
    - Live file list for auto-refresh      (GET  ?action=list&v=...)
    - People for the share menu            (GET  ?action=users)
    - Download log of one file             (GET  ?action=downloads&id=...)
    - Change username / change password    (POST action=change_username / change_password)
    - Delete my account                    (POST action=delete_account)
    - Admin: list / remove accounts        (GET ?action=admin_users, POST action=admin_delete_user)
    - Admin: reset a password              (POST action=admin_reset_password, id)
    - Admin: activity log                  (GET ?action=admin_log&type=ai|downloads|logins)
    - Admin: clear one log                 (POST action=admin_clear_log, type=ai|downloads|logins)
    - Admin: whole-database report         (GET ?action=admin_db&limit=25|100|500|2000)
    - Admin: export accounts / AI setup    (GET ?action=admin_export&type=users|ai&format=csv|xlsx)
    - Admin: import accounts / AI setup    (POST action=admin_import, type=users|ai, file=.csv/.xlsx)
*/

$isAjax = ($_SERVER["HTTP_X_REQUESTED_WITH"] ?? "") === "XMLHttpRequest";
$isPost = $_SERVER["REQUEST_METHOD"] === "POST";
$act    = (string)($_POST["action"] ?? $_GET["action"] ?? "");

function jsonOut(array $data, int $status = 200): void {
    http_response_code($status);
    header("Content-Type: application/json; charset=utf-8");
    header("Cache-Control: no-store");
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function finishUpload(int $ok, array $errors): void {
    global $isAjax;

    if ($isAjax) {
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode(["ok" => $ok, "errors" => $errors]);
        exit;
    }

    // The session is only needed here (flash message for a classic, non-JavaScript upload)
    authStart(true);
    $_SESSION["flash"] = ["ok" => $ok, "errors" => $errors];
    header("Location: index.php");
    exit;
}

/* ADMIN: whole-database report (Report tab). Returns the data; the caller sends it. */
function dbrQuote(string $name): string {
    return "`" . str_replace("`", "``", $name) . "`";
}

function dbrCell($v) {
    if ($v === null) return null;
    $v = (string)$v;
    if (!mb_check_encoding($v, "UTF-8")) return "[binary data, " . strlen($v) . " bytes]";
    return mb_strlen($v, "UTF-8") > 300 ? mb_substr($v, 0, 300, "UTF-8") . "…" : $v;
}

function adminDbReport(mysqli $conn, int $limit): array {
    // Which section each known table belongs to; anything new ends up under "Other tables"
    $DB_GROUPS = [
        "Accounts & security" => ["users", "login_attempts"],
        "Files & sharing"     => ["files", "file_shares", "file_downloads"],
        "AI assistant"        => ["ai_providers", "ai_log"],
    ];
    // Columns that look like secrets are never sent (so a printed report can't leak them)
    $DB_HIDDEN_RE = '/pass(word)?|secret|token|api[_-]?key|^key$/i';
    $DB_DATE_COLS = ["downloaded_at", "attempted_at", "asked_at", "uploaded_at", "created_at"];

    $dbRes  = $conn->query("SELECT DATABASE(), NOW()");
    [$dbName, $now] = $dbRes->fetch_row();

    // table sizes (data + indexes), when the server lets us read them
    $sizes = [];
    try {
        $r = $conn->query("SELECT table_name, data_length + index_length FROM information_schema.tables WHERE table_schema = DATABASE()");
        while ($row = $r->fetch_row()) $sizes[strtolower((string)$row[0])] = (int)$row[1];
    } catch (mysqli_sql_exception $e) { /* optional */ }

    $names = [];
    $r = $conn->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
    while ($row = $r->fetch_row()) $names[] = (string)$row[0];

    $byName = [];
    foreach ($names as $t) {
        $cols = [];
        $hidden = [];
        $order = "";
        $pk = "";

        $c = $conn->query("SHOW COLUMNS FROM " . dbrQuote($t));
        while ($f = $c->fetch_assoc()) {
            $cols[] = [
                "name"    => (string)$f["Field"],
                "type"    => (string)$f["Type"],
                "null"    => $f["Null"] === "YES",
                "key"     => (string)$f["Key"],
                "default" => $f["Default"] === null ? null : (string)$f["Default"],
                "extra"   => (string)$f["Extra"],
            ];
            if (preg_match($DB_HIDDEN_RE, $f["Field"])) $hidden[] = (string)$f["Field"];
            if ($pk === "" && $f["Key"] === "PRI") $pk = (string)$f["Field"];
        }

        // newest first when the table has a date column, otherwise by its primary key
        foreach ($DB_DATE_COLS as $dc) {
            if (in_array($dc, array_column($cols, "name"), true)) { $order = " ORDER BY " . dbrQuote($dc) . " DESC"; break; }
        }
        if ($order === "" && $pk !== "") $order = " ORDER BY " . dbrQuote($pk);

        $count = (int)($conn->query("SELECT COUNT(*) FROM " . dbrQuote($t))->fetch_row()[0] ?? 0);

        $rows = [];
        $res  = $conn->query("SELECT * FROM " . dbrQuote($t) . $order . " LIMIT " . $limit);
        while ($row = $res->fetch_assoc()) {
            $line = [];
            foreach ($cols as $col) {
                $line[] = in_array($col["name"], $hidden, true) ? "••• hidden •••" : dbrCell($row[$col["name"]] ?? null);
            }
            $rows[] = $line;
        }

        $byName[$t] = [
            "name"    => $t,
            "count"   => $count,
            "shown"   => count($rows),
            "size"    => $sizes[strtolower($t)] ?? null,
            "columns" => $cols,
            "hidden"  => $hidden,
            "rows"    => $rows,
        ];
    }

    // organise into groups (known tables first, in the order above; unknown tables last)
    $groups = [];
    $used   = [];
    foreach ($DB_GROUPS as $gname => $list) {
        $tables = [];
        foreach ($list as $t) {
            if (isset($byName[$t])) { $tables[] = $byName[$t]; $used[$t] = true; }
        }
        if ($tables) $groups[] = ["name" => $gname, "tables" => $tables];
    }
    $other = [];
    foreach ($byName as $t => $info) if (!isset($used[$t])) $other[] = $info;
    if ($other) $groups[] = ["name" => "Other tables", "tables" => $other];

    $totals = ["tables" => count($byName), "fields" => 0, "records" => 0];
    foreach ($byName as $info) {
        $totals["fields"]  += count($info["columns"]);
        $totals["records"] += $info["count"];
    }

    return ["ok" => true, "db" => (string)$dbName, "generated" => (string)$now, "limit" => $limit, "totals" => $totals, "groups" => $groups];
}

/* ================= ADMIN: export / import of accounts and AI setup (CSV or Excel) =================
    Export: GET  ?action=admin_export&type=users|ai&format=csv|xlsx[&keys=0]
    Import: POST action=admin_import, type=users|ai, existing=skip|replace, file=(.csv or .xlsx)
    Plain PHP: the Excel files are written and read right here, so no library and no zip extension is needed.
*/
const XF_MAX_BYTES = 5242880;     // biggest file that can be imported (5 MB)
const XF_MAX_ROWS  = 5000;        // most data rows in one import
const XF_MAX_PART  = 20971520;    // biggest part of an .xlsx after unzipping (20 MB), against zip bombs

/* ---------- CSV ---------- */

/* A CSV cell that starts with = + - @ is run as a formula by Excel / Sheets: a leading ' keeps it text (taken off again on import) */
function xfDanger(string $v): bool {
    $t = ltrim($v, "'");
    return $t !== "" && strpos("=+-@\t\r", $t[0]) !== false;
}

function xfSafe(string $v): string {
    return xfDanger($v) ? "'" . $v : $v;
}

function xfUnsafe(string $v): string {
    return $v !== "" && $v[0] === "'" && xfDanger($v) ? substr($v, 1) : $v;
}

function xfCsvWrite(array $head, array $rows): string {
    $q   = fn($c) => '"' . str_replace('"', '""', xfSafe((string)$c)) . '"';
    $out = "\xEF\xBB\xBF" . implode(",", array_map($q, $head)) . "\r\n";      // the BOM makes Excel read it as UTF-8
    foreach ($rows as $r) $out .= implode(",", array_map($q, $r)) . "\r\n";
    return $out;
}

/* Reads CSV text: UTF-8, UTF-16 or Windows-1252; the separator may be a comma, semicolon or tab (Excel uses ; in many countries) */
function xfCsvParse(string $s): array {
    if (strncmp($s, "\xEF\xBB\xBF", 3) === 0)      $s = substr($s, 3);
    elseif (strncmp($s, "\xFF\xFE", 2) === 0)      $s = (string)mb_convert_encoding(substr($s, 2), "UTF-8", "UTF-16LE");
    elseif (strncmp($s, "\xFE\xFF", 2) === 0)      $s = (string)mb_convert_encoding(substr($s, 2), "UTF-8", "UTF-16BE");
    elseif (!mb_check_encoding($s, "UTF-8"))       $s = (string)mb_convert_encoding($s, "UTF-8", "Windows-1252");

    $delim = null;
    if (preg_match('/^sep=(.)\r?\n/i', $s, $m)) {                  // the optional first line "sep=;"
        $delim = $m[1];
        $s = substr($s, strlen($m[0]));
    }
    if ($delim === null) {
        $first = strtok($s, "\r\n");
        $first = $first === false ? "" : $first;
        $best  = -1;
        $delim = ",";
        foreach ([",", ";", "\t"] as $d) {
            $n = substr_count($first, $d);
            if ($n > $best) { $best = $n; $delim = $d; }
        }
    }

    $rows = [];
    $row  = [];
    $cell = "";
    $inQ  = false;
    $len  = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $ch = $s[$i];
        if ($inQ) {
            if ($ch === '"') {
                if ($i + 1 < $len && $s[$i + 1] === '"') { $cell .= '"'; $i++; }
                else $inQ = false;
            } else {
                $cell .= $ch;
            }
        } elseif ($ch === '"' && $cell === "") {
            $inQ = true;
        } elseif ($ch === $delim) {
            $row[] = $cell;
            $cell  = "";
        } elseif ($ch === "\n" || $ch === "\r") {
            if ($ch === "\r" && $i + 1 < $len && $s[$i + 1] === "\n") $i++;
            $row[]  = $cell;
            $cell   = "";
            $rows[] = $row;
            $row    = [];
            if (count($rows) > XF_MAX_ROWS + 1) break;
        } else {
            $cell .= $ch;
        }
    }
    if ($cell !== "" || $row) { $row[] = $cell; $rows[] = $row; }

    foreach ($rows as &$r) foreach ($r as &$c) $c = xfUnsafe($c);
    unset($r, $c);
    return $rows;
}

/* ---------- Excel (.xlsx) ---------- */

function xfXml(string $v): string {
    $v = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', "", $v) ?? "";   // characters XML can't hold
    return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, "UTF-8");
}

function xfColName(int $i): string {
    $s = "";
    for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s;
    return $s;
}

/* A zip file with every entry stored as it is (no compression needed for files this small) */
function xfZip(array $files): string {
    $out = "";
    $cd  = "";
    $n   = 0;
    $t   = getdate();
    $dosTime = ($t["hours"] << 11) | ($t["minutes"] << 5) | intdiv($t["seconds"], 2);
    $dosDate = ((max(1980, $t["year"]) - 1980) << 9) | ($t["mon"] << 5) | $t["mday"];
    foreach ($files as $name => $data) {
        $name = (string)$name;
        $crc  = crc32($data);
        $size = strlen($data);
        $cd  .= pack("VvvvvvvVVVvvvvvVV", 0x02014b50, 20, 20, 0, 0, $dosTime, $dosDate, $crc, $size, $size, strlen($name), 0, 0, 0, 0, 0, strlen($out)) . $name;
        $out .= pack("VvvvvvVVVvv", 0x04034b50, 20, 0, 0, $dosTime, $dosDate, $crc, $size, $size, strlen($name), 0) . $name . $data;
        $n++;
    }
    return $out . $cd . pack("VvvvvVVv", 0x06054b50, 0, 0, $n, $n, strlen($cd), strlen($out), 0);
}

function xfXlsxWrite(array $head, array $rows, string $sheet = "Sheet1"): string {
    $sheet = substr(preg_replace('/[\[\]:*?\/\\\\]/', " ", $sheet) ?? "Sheet1", 0, 31);
    $all   = array_merge([$head], $rows);

    $w = [];
    foreach ($all as $r) foreach ($r as $i => $c) $w[$i] = max($w[$i] ?? 8, min(60, mb_strlen((string)$c, "UTF-8") + 2));
    $cols = "";
    foreach ($w as $i => $width) $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $width . '" customWidth="1"/>';

    $data = "";
    foreach ($all as $ri => $r) {
        $data .= '<row r="' . ($ri + 1) . '">';
        foreach ($r as $ci => $c) {
            $data .= '<c r="' . xfColName($ci) . ($ri + 1) . '" t="inlineStr" s="' . ($ri === 0 ? 1 : 2) . '"><is><t xml:space="preserve">' . xfXml((string)$c) . '</t></is></c>';
        }
        $data .= '</row>';
    }

    $ns  = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $rel = 'http://schemas.openxmlformats.org/package/2006/relationships';
    $off = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
    $ct  = 'application/vnd.openxmlformats-officedocument.spreadsheetml.';

    return xfZip([
        "[Content_Types].xml" => $xml . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="' . $ct . 'sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="' . $ct . 'worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="' . $ct . 'styles+xml"/></Types>',
        "_rels/.rels" => $xml . '<Relationships xmlns="' . $rel . '"><Relationship Id="rId1" Type="' . $off . '/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        "xl/workbook.xml" => $xml . '<workbook xmlns="' . $ns . '" xmlns:r="' . $off . '"><sheets><sheet name="' . xfXml($sheet) . '" sheetId="1" r:id="rId1"/></sheets></workbook>',
        "xl/_rels/workbook.xml.rels" => $xml . '<Relationships xmlns="' . $rel . '"><Relationship Id="rId1" Type="' . $off . '/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="' . $off . '/styles" Target="styles.xml"/></Relationships>',
        "xl/styles.xml" => $xml . '<styleSheet xmlns="' . $ns . '"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="49" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/><xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>',
        "xl/worksheets/sheet1.xml" => $xml . '<worksheet xmlns="' . $ns . '"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="15"/><cols>' . $cols . '</cols><sheetData>' . $data . '</sheetData></worksheet>',
    ]);
}

/* The "xl/..." parts of a zip file (name => text), or null when it isn't a usable zip */
function xfUnzip(string $z): ?array {
    $p = strrpos($z, "PK\x05\x06");
    if ($p === false || $p + 22 > strlen($z)) return null;
    $e   = unpack("vdisk/vcdisk/ventries/vtotal/Vcdsize/Vcdoff/vclen", substr($z, $p + 4, 18));
    $o   = $e["cdoff"];
    $out = [];
    $sum = 0;
    for ($i = 0; $i < $e["total"] && $i < 2000; $i++) {
        if (substr($z, $o, 4) !== "PK\x01\x02") return null;
        $h    = unpack("vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/velen/vclen/vdisk/vint/Vext/Voff", substr($z, $o + 4, 42));
        $name = substr($z, $o + 46, $h["nlen"]);
        $o   += 46 + $h["nlen"] + $h["elen"] + $h["clen"];

        if (substr($name, -1) === "/" || strncmp($name, "xl/", 3) !== 0) continue;      // only the workbook's own parts are read
        if ($h["usize"] > XF_MAX_PART || $h["csize"] > XF_MAX_PART || $h["csize"] === 0xFFFFFFFF) return null;
        if (substr($z, $h["off"], 4) !== "PK\x03\x04") return null;

        $l    = unpack("vnlen/velen", substr($z, $h["off"] + 26, 4));
        $raw  = substr($z, $h["off"] + 30 + $l["nlen"] + $l["elen"], $h["csize"]);
        if ($h["method"] === 0)      $d = $raw;
        elseif ($h["method"] === 8)  $d = @gzinflate($raw, XF_MAX_PART + 1);
        else return null;

        if ($d === false || strlen($d) > XF_MAX_PART) return null;
        $sum += strlen($d);
        if ($sum > 3 * XF_MAX_PART) return null;
        $out[$name] = $d;
    }
    return $out;
}

function xfDom(string $xml): ?DOMDocument {
    if (stripos($xml, "<!DOCTYPE") !== false || stripos($xml, "<!ENTITY") !== false) return null;     // Excel never writes these: refuse entity tricks
    $d    = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $ok   = $d->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return $ok ? $d : null;
}

function xfTexts(DOMElement $el): string {
    $t = "";
    foreach ($el->getElementsByTagNameNS("*", "t") as $n) {
        if ($n->parentNode && $n->parentNode->localName === "rPh") continue;      // phonetic hints are not part of the text
        $t .= $n->textContent;
    }
    return $t;
}

/* The first worksheet as a list of rows (each a list of strings), or null */
function xfXlsxRead(string $bin): ?array {
    $parts = xfUnzip($bin);
    if ($parts === null) return null;

    // which file is the first sheet?
    $path = null;
    if (isset($parts["xl/workbook.xml"]) && isset($parts["xl/_rels/workbook.xml.rels"])) {
        $wb  = xfDom($parts["xl/workbook.xml"]);
        $rl  = xfDom($parts["xl/_rels/workbook.xml.rels"]);
        $sh  = $wb ? $wb->getElementsByTagNameNS("*", "sheet")->item(0) : null;
        $rid = $sh ? $sh->getAttributeNS("http://schemas.openxmlformats.org/officeDocument/2006/relationships", "id") : "";
        if ($rl && $rid !== "") {
            foreach ($rl->getElementsByTagNameNS("*", "Relationship") as $r) {
                if ($r->getAttribute("Id") === $rid) {
                    $tg   = $r->getAttribute("Target");
                    $path = $tg !== "" && $tg[0] === "/" ? ltrim($tg, "/") : "xl/" . $tg;
                }
            }
        }
    }
    if ($path === null || !isset($parts[$path])) {
        $path = null;
        foreach (array_keys($parts) as $k) if (preg_match('#^xl/worksheets/[^/]+\.xml$#', $k)) { $path = $k; break; }
    }
    if ($path === null) return null;

    $strings = [];
    if (isset($parts["xl/sharedStrings.xml"])) {
        $sd = xfDom($parts["xl/sharedStrings.xml"]);
        if ($sd) foreach ($sd->getElementsByTagNameNS("*", "si") as $si) $strings[] = xfTexts($si);
    }

    $d = xfDom($parts[$path]);
    if (!$d) return null;

    $rows = [];
    foreach ($d->getElementsByTagNameNS("*", "row") as $row) {
        $cells = [];
        $idx   = 0;
        foreach ($row->childNodes as $c) {
            if ($c->nodeType !== XML_ELEMENT_NODE || $c->localName !== "c") continue;
            if (preg_match('/^([A-Z]+)/', $c->getAttribute("r"), $m)) {
                $col = 0;
                foreach (str_split($m[1]) as $ch) $col = $col * 26 + ord($ch) - 64;
                $idx = $col - 1;
            }
            $v = "";
            $is = "";
            foreach ($c->childNodes as $k) {
                if ($k->nodeType !== XML_ELEMENT_NODE) continue;
                if ($k->localName === "v")  $v  = $k->textContent;
                if ($k->localName === "is") $is = xfTexts($k);
            }
            $t = $c->getAttribute("t");
            if ($t === "s")              $val = $strings[(int)$v] ?? "";
            elseif ($t === "inlineStr")  $val = $is;
            elseif ($t === "b")          $val = $v === "1" ? "1" : "0";
            elseif ($t === "e")          $val = "";
            else                         $val = $v;
            if ($idx < 60) $cells[$idx] = $val;
            $idx++;
        }
        $r   = [];
        $max = $cells ? max(array_keys($cells)) : -1;
        for ($i = 0; $i <= $max; $i++) $r[] = $cells[$i] ?? "";
        $rows[] = $r;
        if (count($rows) > XF_MAX_ROWS + 1) break;
    }
    return $rows;
}

/* ---------- Reading an uploaded table ---------- */

/* CSV or Excel bytes -> ["ok" => true, "head" => [column names], "rows" => [[name => value]]] or ["ok" => false, "error" => "..."] */
function xfParseTable(string $bin): array {
    if (strncmp($bin, "PK\x03\x04", 4) === 0) {
        $rows = xfXlsxRead($bin);
        if ($rows === null) return ["ok" => false, "error" => "That Excel file could not be read. Save it again as .xlsx or as CSV and try again."];
    } elseif (strncmp($bin, "\xD0\xCF\x11\xE0", 4) === 0) {
        return ["ok" => false, "error" => "Old Excel files (.xls) can't be read. In Excel choose Save As and pick Excel Workbook (.xlsx) or CSV."];
    } else {
        $utf16 = strncmp($bin, "\xFF\xFE", 2) === 0 || strncmp($bin, "\xFE\xFF", 2) === 0;
        if (!$utf16 && strpos(substr($bin, 0, 4096), "\0") !== false) return ["ok" => false, "error" => "That doesn't look like a CSV or Excel (.xlsx) file."];
        $rows = xfCsvParse($bin);
    }

    $blank = fn(array $r) => !array_filter($r, fn($c) => trim((string)$c) !== "");
    while ($rows && $blank($rows[0])) array_shift($rows);
    if (!$rows) return ["ok" => false, "error" => "The file is empty."];

    $head = array_map(fn($h) => trim((string)preg_replace('/[^a-z0-9]+/', "_", strtolower(trim((string)$h))), "_"), array_shift($rows));
    $out  = [];
    foreach ($rows as $r) {
        if ($blank($r)) continue;
        $a = [];
        foreach ($head as $i => $h) if ($h !== "" && !isset($a[$h])) $a[$h] = (string)($r[$i] ?? "");
        $out[] = $a;
    }
    if (count($out) > XF_MAX_ROWS) return ["ok" => false, "error" => "Too many rows (the most that can be imported at once is " . XF_MAX_ROWS . ")."];
    return ["ok" => true, "head" => $head, "rows" => $out];
}

/* The first filled-in cell among several possible column names */
function xfPick(array $r, array $names): string {
    foreach ($names as $k) if (isset($r[$k]) && trim($r[$k]) !== "") return trim($r[$k]);
    return "";
}

/* ---------- Importing ---------- */

/*
    Accounts. Columns: username, password. The password can be the hash from an export, or a plain password
    (it is hashed here). The admin account is never added, changed or exported.
    $replace: an account that already exists gets the new password (otherwise it is left as it is).
*/
function xfImportUsers(mysqli $conn, array $head, array $rows, bool $replace): array {
    if (!array_intersect(["username", "user", "name"], $head)) {
        return ["ok" => false, "error" => "The file needs a \"username\" column (and a \"password\" column)."];
    }

    $added = $updated = $kept = $bad = 0;
    $problems = [];
    $reject = function (string $who, string $why) use (&$bad, &$problems) {
        $bad++;
        if (count($problems) < 25) $problems[] = $who . ": " . $why;
    };

    $conn->begin_transaction();
    try {
        $ins = $conn->prepare("INSERT INTO users (username, password, is_admin) VALUES (?, ?, 0)");
        $upd = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $n   = 0;

        foreach ($rows as $r) {
            $n++;
            $name = xfPick($r, ["username", "user", "name"]);
            $pw   = (string)($r["password"] ?? $r["password_hash"] ?? $r["hash"] ?? "");
            $who  = $name !== "" ? $name : "Row " . $n;

            if ($name === "") { $reject($who, "no username"); continue; }
            if (in_array(strtolower($name), AUTH_RESERVED, true)) { $reject($who, "reserved name (the admin account is never imported)"); continue; }
            $err = validUsername($name);
            if ($err !== null) { $reject($who, $err); continue; }

            $existing = authFindByName($conn, $name);
            if ($existing && $existing["is_admin"]) { $reject($who, "that is the admin account, which an import never changes"); continue; }
            if ($existing && !$replace) { $kept++; continue; }
            if ($existing && trim($pw) === "") { $kept++; continue; }              // nothing to change
            if (!$existing && trim($pw) === "") { $reject($who, "no password"); continue; }

            $hash = trim($pw);
            if (!(bool)password_get_info($hash)["algo"]) {                          // not a hash: a plain password, so hash it
                $err = validPassword($pw);
                if ($err !== null) { $reject($who, $err); continue; }
                $hash = password_hash($pw, PASSWORD_DEFAULT);
            }

            try {
                if ($existing) {
                    $id = (int)$existing["id"];
                    $upd->bind_param("si", $hash, $id);
                    $upd->execute();
                    $updated++;
                } else {
                    $ins->bind_param("ss", $name, $hash);
                    $ins->execute();
                    $added++;
                }
            } catch (mysqli_sql_exception $e) {
                $reject($who, "the database refused it");
            }
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        return ["ok" => false, "error" => "The import was stopped by a database error. Nothing was changed."];
    }

    return ["ok" => true, "added" => $added, "updated" => $updated, "kept" => $kept, "rejected" => $bad, "problems" => $problems];
}

function xfAiId(string $name, string $model, string $base): string {
    return mb_strtolower($name, "UTF-8") . "|" . mb_strtolower($model, "UTF-8") . "|" . strtolower(rtrim($base, "/"));
}

/*
    AI setup. Columns: name, type, web_address, model, api_key, enabled, order (only "model" is really needed;
    the type and web address are worked out from the model name and key when empty).
    An AI counts as already there when name + model + web address are all the same.
*/
function xfImportAi(mysqli $conn, array $head, array $rows, bool $replace): array {
    require_once __DIR__ . "/aicore.php";
    aiEnsureTable($conn);

    if (!in_array("model", $head, true)) {
        return ["ok" => false, "error" => "The file needs a \"model\" column (usually together with name, type, web_address and api_key)."];
    }
    if (count($rows) > 200) return ["ok" => false, "error" => "Too many AIs in one file (the most is 200)."];

    $byLabel = [];
    foreach (aiKinds() as $k => $label) $byLabel[strtolower($label)] = $k;

    $have = [];
    $prio = 0;
    foreach (aiRows($conn) as $p) {
        $have[xfAiId($p["name"], $p["model"], $p["base_url"])] = $p;
        $prio = max($prio, (int)$p["priority"]);
    }

    // new AIs are added in the file's "order" (then row order)
    $n = 0;
    foreach ($rows as $k => $r) $rows[$k]["_n"] = ++$n;
    $ord = fn($r) => (int)($r["order"] ?? 0) > 0 ? (int)$r["order"] : PHP_INT_MAX;
    usort($rows, fn($a, $b) => $ord($a) <=> $ord($b) ?: $a["_n"] <=> $b["_n"]);

    $added = $updated = $kept = $bad = $nokey = 0;
    $problems = [];
    $reject = function (string $who, string $why) use (&$bad, &$problems) {
        $bad++;
        if (count($problems) < 25) $problems[] = $who . ": " . $why;
    };

    $conn->begin_transaction();
    try {
        $ins = $conn->prepare("INSERT INTO ai_providers (name, kind, base_url, api_key, model, priority, enabled, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
        $upd = $conn->prepare("UPDATE ai_providers SET name = ?, kind = ?, base_url = ?, api_key = ?, model = ?, enabled = ?, cooldown_until = NULL, last_status = '' WHERE id = ?");

        foreach ($rows as $r) {
            $model = xfPick($r, ["model"]);
            $key   = xfPick($r, ["api_key", "key"]);
            $name  = xfPick($r, ["name"]);
            $base  = xfPick($r, ["web_address", "base_url", "url", "address"]);
            $kind  = strtolower(xfPick($r, ["type", "kind", "provider"]));
            $who   = $name !== "" ? $name : ($model !== "" ? $model : "Row " . $r["_n"]);

            if ($kind !== "" && !isset(aiKinds()[$kind])) $kind = $byLabel[$kind] ?? $kind;
            if ($kind === "") $kind = aiGuessKind($model, $key);

            if (!isset(aiKinds()[$kind]))                                              { $reject($who, "unknown type (use gemini, openai or anthropic)"); continue; }
            if ($model === "" || mb_strlen($model, "UTF-8") > 120 || preg_match('/\s/', $model)) { $reject($who, "the model is missing or contains spaces"); continue; }
            if (preg_match('/\s/', $key) || strlen($key) > 500)                         { $reject($who, "the API key can't contain spaces"); continue; }
            if ($base === "") $base = aiGuessBase($kind, $key);
            if (!aiValidBase($base) || strlen($base) > 255)                             { $reject($who, "the web address must start with http:// or https://"); continue; }
            if ($name === "") $name = (string)(parse_url($base, PHP_URL_HOST) ?: "AI");
            $name = mb_substr($name, 0, 60, "UTF-8");

            $enRaw = strtolower(xfPick($r, ["enabled", "on", "active"]));
            $en    = $enRaw === "" ? null : (in_array($enRaw, ["0", "no", "off", "false", "n"], true) ? 0 : 1);
            $id    = xfAiId($name, $model, $base);

            try {
                if (isset($have[$id])) {
                    if (!$replace || !empty($have[$id]["fresh"])) { $kept++; continue; }      // (an AI listed twice in the file: the first one wins)
                    $p  = $have[$id];
                    $k2 = $key !== "" ? $key : (string)$p["key"];
                    if ($k2 === "" && $kind !== "openai") { $reject($who, "needs an API key"); continue; }
                    $e2  = $en ?? ($p["enabled"] ? 1 : 0);
                    $pid = (int)$p["id"];
                    $upd->bind_param("sssssii", $name, $kind, $base, $k2, $model, $e2, $pid);
                    $upd->execute();
                    $have[$id]["key"] = $k2;
                    $updated++;
                    if ($k2 === "") $nokey++;
                } else {
                    if ($key === "" && $kind !== "openai") { $reject($who, "needs an API key (the file was exported without keys, or the cell is empty)"); continue; }
                    $prio += 10;
                    $e2 = $en ?? 1;
                    $ins->bind_param("sssssii", $name, $kind, $base, $key, $model, $prio, $e2);
                    $ins->execute();
                    $have[$id] = ["fresh" => true];
                    $added++;
                    if ($key === "") $nokey++;
                }
            } catch (mysqli_sql_exception $e) {
                $reject($who, "the database refused it");
            }
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        return ["ok" => false, "error" => "The import was stopped by a database error. Nothing was changed."];
    }

    return ["ok" => true, "added" => $added, "updated" => $updated, "kept" => $kept, "rejected" => $bad, "nokey" => $nokey, "problems" => $problems];
}

function xfSend(string $bin, string $mime, string $filename): void {
    while (ob_get_level() > 0) ob_end_clean();
    header("Content-Type: " . $mime);
    header("Content-Disposition: attachment; filename=\"" . $filename . "\"");
    header("Content-Length: " . strlen($bin));
    header("Cache-Control: no-store");
    header("X-Content-Type-Options: nosniff");
    echo $bin;
    exit;
}

/*
    Everything below needs a signed-in user. The session is opened read-only (it never blocks the live
    refresh or media streaming) except when the password changes, because that renews the session fingerprint.
*/
$user = requireLogin($conn, $isAjax ? "json" : ($isPost ? "page" : "plain"), $act === "change_password");

/* Same name already uploaded by the same person? Add " (1)", " (2)"... like a folder on a computer does. */
function uniqueDisplayName(mysqli $conn, int $ownerId, string $name, int $exceptId = 0): string {
    $ext  = pathinfo($name, PATHINFO_EXTENSION);
    $base = $ext !== "" ? substr($name, 0, -(strlen($ext) + 1)) : $name;
    $stmt = $conn->prepare("SELECT 1 FROM files WHERE owner_id = ? AND filename = ? AND id <> ? LIMIT 1");

    for ($n = 0; $n < 1000; $n++) {
        $try = $n === 0 ? $name : $base . " (" . $n . ")" . ($ext !== "" ? "." . $ext : "");
        $stmt->bind_param("isi", $ownerId, $try, $exceptId);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_row()) return $try;
    }
    return $name;
}

function cleanFileName(string $name): string {
    $name = basename(str_replace("\\", "/", $name));
    $name = preg_replace('/[\x00-\x1F\x7F]/', "", $name);
    $name = trim($name);

    if ($name === "" || $name === "." || $name === "..") {
        $name = "file";
    }

    if (mb_strlen($name, "UTF-8") > 180) {
        $ext  = pathinfo($name, PATHINFO_EXTENSION);
        $base = pathinfo($name, PATHINFO_FILENAME);
        $keep = 180 - ($ext !== "" ? mb_strlen($ext, "UTF-8") + 1 : 0);
        $name = mb_substr($base, 0, max(1, $keep), "UTF-8") . ($ext !== "" ? "." . $ext : "");
    }

    return $name;
}

/*
    Name used on disk = the file's real name: test.txt is saved as test.txt, music.flac as music.flac.
    The uploads folder stays readable in Windows Explorer / Linux file managers and every file keeps its type.
    - Characters Windows does not allow are replaced with "_"
    - Script / server-config files are saved as "name_php.txt" so they can never run as code
    (a name that is already taken is handled by reserveDiskName)
*/
function diskFileName(string $original): string {
    $name = $original;

    // PHP on Windows uses the ANSI code page for file names, so plain letters are the safe choice there
    if (DIRECTORY_SEPARATOR === "\\" && preg_match('/[^\x20-\x7E]/', $name)) {
        $t = function_exists("iconv") ? @iconv("UTF-8", "ASCII//TRANSLIT//IGNORE", $name) : false;
        $name = ($t !== false && trim($t) !== "") ? $t : $name;
        $name = preg_replace('/[^\x20-\x7E]+/', "_", $name);
    }

    // Characters Windows file names can't contain (the pattern only matches ASCII, so UTF-8 text is untouched)
    $name = preg_replace('/[<>:"\/\\\\|?*\x00-\x1F\x7F]/', "_", $name);
    $name = rtrim((string)$name, ". ");
    if ($name === "" || $name[0] === ".") $name = "file" . $name;

    // Keep the name short enough for Windows paths
    if (mb_strlen($name, "UTF-8") > 100) {
        $ext  = pathinfo($name, PATHINFO_EXTENSION);
        $base = pathinfo($name, PATHINFO_FILENAME);
        $keep = 100 - ($ext !== "" ? mb_strlen($ext, "UTF-8") + 1 : 0);
        $name = mb_substr($base, 0, max(1, $keep), "UTF-8") . ($ext !== "" ? "." . $ext : "");
    }

    // Windows reserved device names (CON, NUL, COM1...)
    if (preg_match('/^(con|prn|aux|nul|com[1-9]|lpt[1-9])(\..*)?$/i', $name)) $name = "_" . $name;

    // Anything that a web server could execute or treat as config: "shell.php" -> "shell_php.txt"
    $danger = "php php3 php4 php5 php7 php8 phtml phar pht phps inc cgi pl py asp aspx jsp htaccess htpasswd shtml";
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (in_array($ext, explode(" ", $danger), true)) {
        $name = substr($name, 0, -(strlen($ext) + 1)) . "_" . $ext . ".txt";
    }

    return $name;
}

/*
    Claims a free file name inside $dir by creating an empty file ("x" mode fails if the name exists,
    so two uploads can never take the same name). If "test.txt" is taken it tries "test (1).txt",
    "test (2).txt"... like Windows does. Returns the claimed name, or "" if nothing could be created.
*/
function reserveDiskName(string $dir, string $name): string {
    $ext  = pathinfo($name, PATHINFO_EXTENSION);
    $dot  = $ext !== "" ? "." . $ext : "";
    $base = $ext !== "" ? substr($name, 0, -strlen($dot)) : $name;

    for ($n = 0; $n < 1000; $n++) {
        $cand = $n === 0 ? $name : $base . " (" . $n . ")" . $dot;
        $h = @fopen($dir . $cand, "x");
        if ($h) { fclose($h); return $cand; }
    }

    $cand = $base . "-" . bin2hex(random_bytes(4)) . $dot;
    $h = @fopen($dir . $cand, "x");
    if ($h) { fclose($h); return $cand; }

    return "";
}

function uploadErrorText(int $code): string {
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
            return "is larger than the server limit (upload_max_filesize = " . ini_get("upload_max_filesize") . ")";
        case UPLOAD_ERR_FORM_SIZE:
            return "is larger than the allowed size";
        case UPLOAD_ERR_PARTIAL:
            return "was only partially uploaded";
        case UPLOAD_ERR_NO_TMP_DIR:
            return "could not be saved (missing temporary folder)";
        case UPLOAD_ERR_CANT_WRITE:
            return "could not be written to disk";
        case UPLOAD_ERR_EXTENSION:
            return "was stopped by a PHP extension";
        default:
            return "failed to upload";
    }
}

/* The file, but only when this person may change it (uploader or admin). Otherwise answers and stops. */
function manageableFile(mysqli $conn, array $user, int $id): array {
    $f = findAccessibleFile($conn, $id, $user);
    if (!$f) jsonOut(["ok" => false, "error" => "File not found."], 404);
    if (!canManageFile($f, $user)) {
        jsonOut(["ok" => false, "error" => "Only the person who uploaded this file (or the admin) can do that."], 403);
    }
    return $f;
}

/* Deletes the physical file, its sharing and download records, and the database record */
function deleteFile(mysqli $conn, array $file): void {
    $path = __DIR__ . "/" . $file["filepath"];
    if (is_file($path)) {
        @unlink($path);
    }

    $id = (int)$file["id"];
    foreach (["file_shares" => "file_id", "file_downloads" => "file_id", "files" => "id"] as $table => $col) {
        $stmt = $conn->prepare("DELETE FROM `$table` WHERE `$col` = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
    }
}

/*
    Removes an account.
    $deleteFiles = true  -> the files it uploaded are deleted too
    $deleteFiles = false -> the files stay on the site and are handed to the admin
    Download history rows stay (they show as "deleted account").
*/
function removeAccount(mysqli $conn, array $target, bool $deleteFiles): void {
    $tid = (int)$target["id"];

    if ($deleteFiles) {
        $stmt = $conn->prepare("SELECT id, filepath FROM files WHERE owner_id = ?");
        $stmt->bind_param("i", $tid);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) deleteFile($conn, $row);
    } else {
        $r   = $conn->query("SELECT id FROM users WHERE is_admin = 1 ORDER BY id LIMIT 1");
        $adm = (int)($r ? ($r->fetch_row()[0] ?? 0) : 0);
        if ($adm > 0) {
            $stmt = $conn->prepare("UPDATE files SET owner_id = ? WHERE owner_id = ?");
            $stmt->bind_param("ii", $adm, $tid);
            $stmt->execute();
        }
    }

    $stmt = $conn->prepare("DELETE FROM file_shares WHERE user_id = ?");
    $stmt->bind_param("i", $tid);
    $stmt->execute();

    $stmt = $conn->prepare("DELETE FROM ai_log WHERE user_id = ?");
    $stmt->bind_param("i", $tid);
    $stmt->execute();

    $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
    $stmt->bind_param("i", $tid);
    $stmt->execute();
}

/*
    Renames a file: the name shown on the site AND the real file in the uploads folder.
    If the new name has no extension, the old extension is kept so the file type and preview still work.
*/
function renameFile(mysqli $conn, array $file, string $newName): array {
    $id = (int)$file["id"];

    $new = trim(str_replace("\\", "/", $newName));
    $new = rtrim(basename($new), ". ");
    if ($new === "" || $new === "..") return [false, "Enter a file name."];

    $oldExt = pathinfo($file["filename"], PATHINFO_EXTENSION);
    $newExt = pathinfo($new, PATHINFO_EXTENSION);
    if ($oldExt !== "" && $newExt === "") {
        $new .= "." . $oldExt;
    }

    $new = cleanFileName($new);
    $new = uniqueDisplayName($conn, (int)($file["owner_id"] ?? 0), $new, $id);   // renaming onto a name you already use adds " (1)"

    $dir      = __DIR__ . "/uploads/";
    $oldPath  = __DIR__ . "/" . $file["filepath"];
    $newRel   = $file["filepath"];     // new location (relative), changes only if the file on disk is renamed
    $newPath  = $oldPath;
    $moved    = false;                 // the physical file was renamed
    $reserved = false;                 // we created an empty placeholder that must be cleaned up on failure

    if (is_file($oldPath)) {
        $wanted  = diskFileName($new);
        $oldBase = basename($file["filepath"]);

        if ($wanted !== $oldBase) {
            if (file_exists($dir . $wanted) && realpath($dir . $wanted) === realpath($oldPath)) {
                $target = $wanted;                          // same file, only the letter case changes (Windows)
            } else {
                $target = reserveDiskName($dir, $wanted);   // adds " (1)" if another file already has this name
                $reserved = $target !== "";
            }
            if ($target === "") return [false, "Could not rename the file on the server."];

            $newPath = $dir . $target;
            if (!@rename($oldPath, $newPath)) {
                if ($reserved) @unlink($newPath);
                return [false, "Could not rename the file on the server."];
            }
            $moved  = true;
            $newRel = "uploads/" . $target;
        }
    }

    $stmt = $conn->prepare("UPDATE files SET filename = ?, filepath = ? WHERE id = ?");
    $stmt->bind_param("ssi", $new, $newRel, $id);
    if (!$stmt->execute()) {
        if ($moved) @rename($newPath, $oldPath);            // put the file back, nothing changed
        return [false, "Could not save the new name."];
    }

    return [true, $new];
}

if ($isPost) {

    // The whole request was bigger than post_max_size: PHP throws everything away
    $contentLength = (int)($_SERVER["CONTENT_LENGTH"] ?? 0);
    if (empty($_POST) && empty($_FILES) && $contentLength > 0) {
        finishUpload(0, ["The upload is larger than the server allows (post_max_size = " . ini_get("post_max_size") . ")."]);
    }

    // Every change needs the page's secret token (stops other websites from sending requests for you)
    if (!csrfValid()) {
        if ($act === "upload" && !$isAjax) finishUpload(0, ["This page was open for too long. Reload it and try again."]);
        jsonOut(["ok" => false, "error" => "This page is out of date. Reload it and try again."], 403);
    }

    /* UPLOAD */
    if ($act === "upload") {

        if (empty($_FILES["files"])) {
            finishUpload(0, ["No files were received."]);
        }

        // Normalise to arrays so one file and many files use the same code
        $f = $_FILES["files"];
        if (!is_array($f["name"])) {
            foreach (["name", "type", "tmp_name", "error", "size"] as $k) {
                $f[$k] = [$f[$k]];
            }
        }

        $uploadDir = __DIR__ . "/uploads/";

        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
            finishUpload(0, ["Could not create the uploads folder."]);
        }

        // Block direct web access to uploaded files (they are served by files.php / preview.php)
        $htaccess = $uploadDir . ".htaccess";
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess,
                "# Files are served through files.php and preview.php only\n" .
                "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n" .
                "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
        }

        if (!is_writable($uploadDir)) {
            finishUpload(0, ["The uploads folder is not writable."]);
        }

        // Who may see the new files: nothing chosen = everyone (the default)
        $ownerId = (int)$user["id"];
        [$vis, $shareIds] = normalizeSharing($conn, $ownerId, (string)($_POST["visibility"] ?? ""), parseIdList($_POST["shares"] ?? ""));

        // notes is NOT NULL in the table and has no default, so it must be given a value here
        $stmt = $conn->prepare("INSERT INTO files (filename, filepath, uploaded_at, notes, owner_id, visibility) VALUES (?, ?, NOW(), '', ?, ?)");
        $dbName = "";
        $dbPath = "";
        $stmt->bind_param("ssis", $dbName, $dbPath, $ownerId, $vis);

        $okCount = 0;
        $errors  = [];

        foreach ($f["name"] as $i => $rawName) {
            $code = (int)$f["error"][$i];

            if ($code === UPLOAD_ERR_NO_FILE) continue;

            $originalName = cleanFileName((string)$rawName);

            if ($code !== UPLOAD_ERR_OK) {
                $errors[] = "\"" . $originalName . "\" " . uploadErrorText($code) . ".";
                continue;
            }

            // The file keeps its real name on disk (test.txt stays test.txt). If that name is already
            // taken, " (1)", " (2)"... is added. Script files are saved as name_php.txt so they never run.
            $storedName = reserveDiskName($uploadDir, diskFileName($originalName));
            if ($storedName === "") {
                $errors[] = "\"" . $originalName . "\" could not be saved on the server.";
                continue;
            }
            $destination = $uploadDir . $storedName;

            // (this replaces the empty placeholder that reserveDiskName just created)
            if (!move_uploaded_file($f["tmp_name"][$i], $destination)) {
                @unlink($destination);
                $errors[] = "\"" . $originalName . "\" could not be saved on the server.";
                continue;
            }

            $dbName = uniqueDisplayName($conn, $ownerId, $originalName);   // the list shows "name (1).ext" too, not just the folder on the server
            $dbPath = "uploads/" . $storedName;

            if (!$stmt->execute()) {
                @unlink($destination);
                $errors[] = "\"" . $originalName . "\" could not be recorded in the database.";
                continue;
            }

            if ($vis === "custom") writeShares($conn, (int)$conn->insert_id, $shareIds);

            $okCount++;
        }

        finishUpload($okCount, $errors);
    }

    /* RENAME */
    if ($act === "rename") {
        $file = manageableFile($conn, $user, (int)($_POST["id"] ?? 0));
        [$ok, $result] = renameFile($conn, $file, (string)($_POST["name"] ?? ""));
        if ($ok) jsonOut(["ok" => true, "name" => $result]);
        jsonOut(["ok" => false, "error" => $result], 400);
    }

    /* NOTES */
    if ($act === "notes") {
        $file  = manageableFile($conn, $user, (int)($_POST["id"] ?? 0));
        $notes = str_replace(["\r\n", "\r"], "\n", (string)($_POST["notes"] ?? ""));

        if (!mb_check_encoding($notes, "UTF-8")) {
            jsonOut(["ok" => false, "error" => "Notes contain invalid characters."], 400);
        }

        $notes = mb_substr($notes, 0, 900, "UTF-8");   // column is varchar(900)
        $id    = (int)$file["id"];

        $stmt = $conn->prepare("UPDATE files SET notes = ? WHERE id = ?");
        $stmt->bind_param("si", $notes, $id);
        if (!$stmt->execute()) {
            jsonOut(["ok" => false, "error" => "Could not save the notes."], 500);
        }
        jsonOut(["ok" => true, "notes" => $notes]);
    }

    /* SHARE: everyone / only me / specific people */
    if ($act === "share") {
        $file = manageableFile($conn, $user, (int)($_POST["id"] ?? 0));
        $id   = (int)$file["id"];

        $wanted = (string)($_POST["visibility"] ?? "private");
        [$vis, $shareIds] = normalizeSharing($conn, (int)$file["owner_id"], $wanted === "" ? "private" : $wanted, parseIdList($_POST["shares"] ?? ""));

        $stmt = $conn->prepare("UPDATE files SET visibility = ? WHERE id = ?");
        $stmt->bind_param("si", $vis, $id);
        if (!$stmt->execute()) {
            jsonOut(["ok" => false, "error" => "Could not save the sharing settings."], 500);
        }
        writeShares($conn, $id, $vis === "custom" ? $shareIds : []);

        jsonOut(["ok" => true, "visibility" => $vis, "shared" => $shareIds]);
    }

    /* DELETE */
    if ($act === "delete") {
        $file = manageableFile($conn, $user, (int)($_POST["id"] ?? 0));
        deleteFile($conn, $file);
        jsonOut(["ok" => true]);
    }

    /* CHANGE USERNAME (needs the current password) */
    if ($act === "change_username") {
        if (authThrottled($conn)) {
            jsonOut(["ok" => false, "error" => "Too many attempts. Please wait a few minutes and try again."], 429);
        }
        if (!authCheckPassword($conn, $user, (string)($_POST["password"] ?? ""), false)) {
            authRecordAttempt($conn, (string)$user["username"], "failed");
            jsonOut(["ok" => false, "error" => "Your password is wrong."], 400);
        }

        $new = trim((string)($_POST["username"] ?? ""));
        $err = authChangeUsername($conn, $user, $new);
        if ($err !== null) jsonOut(["ok" => false, "error" => $err], 400);

        jsonOut(["ok" => true, "name" => $new]);
    }

    /* CHANGE PASSWORD (needs the current password; every other device is signed out) */
    if ($act === "change_password") {
        if (authThrottled($conn)) {
            jsonOut(["ok" => false, "error" => "Too many attempts. Please wait a few minutes and try again."], 429);
        }

        $new = (string)($_POST["password"] ?? "");
        if ($new !== (string)($_POST["password2"] ?? "")) {
            jsonOut(["ok" => false, "error" => "The two new passwords don't match."], 400);
        }

        $err = authChangePassword($conn, $user, (string)($_POST["current"] ?? ""), $new);
        if ($err !== null) {
            if ($err === "Your current password is wrong.") authRecordAttempt($conn, (string)$user["username"], "failed");
            jsonOut(["ok" => false, "error" => $err], 400);
        }

        jsonOut(["ok" => true]);
    }

    /* DELETE MY ACCOUNT (needs the password; the admin account can't be deleted) */
    if ($act === "delete_account") {
        if ($user["is_admin"]) {
            jsonOut(["ok" => false, "error" => "The admin account can't be deleted."], 403);
        }
        if (authThrottled($conn)) {
            jsonOut(["ok" => false, "error" => "Too many attempts. Please wait a few minutes and try again."], 429);
        }
        if (!authCheckPassword($conn, $user, (string)($_POST["password"] ?? ""), false)) {
            authRecordAttempt($conn, (string)$user["username"], "failed");
            jsonOut(["ok" => false, "error" => "Your password is wrong."], 400);
        }

        removeAccount($conn, $user, ($_POST["delete_files"] ?? "") === "1");
        authLogout();
        jsonOut(["ok" => true]);
    }

    /* ADMIN: remove somebody else's account */
    /* ADMIN: empty one log (ai_log, file_downloads or login_attempts). Nothing else is touched. */
    if ($act === "admin_clear_log") {
        if (!$user["is_admin"]) jsonOut(["ok" => false, "error" => "Only the admin can do that."], 403);
        $tables = ["ai" => "ai_log", "downloads" => "file_downloads", "logins" => "login_attempts"];
        $type = (string)($_POST["type"] ?? "");
        if (!isset($tables[$type])) jsonOut(["ok" => false, "error" => "Unknown log."], 400);
        $conn->query("DELETE FROM `" . $tables[$type] . "`");
        jsonOut(["ok" => true, "removed" => (int)$conn->affected_rows]);
    }

    /* ADMIN: put an account's password back to the default ("admin" for an admin account, "reset" for everyone else).
       The account's other devices are signed out because the password changes. */
    if ($act === "admin_reset_password") {
        if (!$user["is_admin"]) jsonOut(["ok" => false, "error" => "Only the admin can do that."], 403);

        $target = authUserById($conn, (int)($_POST["id"] ?? 0));
        if (!$target) jsonOut(["ok" => false, "error" => "Account not found."], 404);

        $newPw = $target["is_admin"] ? "admin" : "reset";
        authSetPasswordHash($conn, $target, password_hash($newPw, PASSWORD_DEFAULT));
        if ((int)$target["id"] === (int)$user["id"]) $_SESSION["pwf"] = pwFingerprint((string)$target["password"]);   // stay signed in here
        jsonOut(["ok" => true, "name" => (string)$target["username"], "password" => $newPw]);
    }

    if ($act === "admin_delete_user") {
        if (!$user["is_admin"]) jsonOut(["ok" => false, "error" => "Only the admin can do that."], 403);

        $target = authUserById($conn, (int)($_POST["id"] ?? 0));
        if (!$target) jsonOut(["ok" => false, "error" => "Account not found."], 404);
        if ($target["is_admin"]) jsonOut(["ok" => false, "error" => "Admin accounts can't be removed here."], 400);

        removeAccount($conn, $target, ($_POST["delete_files"] ?? "") === "1");
        jsonOut(["ok" => true]);
    }

    /* ADMIN: import accounts or AI setup from a CSV / Excel file (see xfImportUsers / xfImportAi) */
    if ($act === "admin_import") {
        if (!$user["is_admin"]) jsonOut(["ok" => false, "error" => "Only the admin can do that."], 403);

        $type = (string)($_POST["type"] ?? "");
        if ($type !== "users" && $type !== "ai") jsonOut(["ok" => false, "error" => "Unknown import."], 400);

        $up = $_FILES["file"] ?? null;
        if (!is_array($up) || is_array($up["tmp_name"] ?? null) || ($up["error"] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            jsonOut(["ok" => false, "error" => "Choose a file first."], 400);
        }
        if ($up["error"] === UPLOAD_ERR_INI_SIZE || $up["error"] === UPLOAD_ERR_FORM_SIZE || (int)$up["size"] > XF_MAX_BYTES) {
            jsonOut(["ok" => false, "error" => "That file is too big (the most is 5 MB)."], 413);
        }
        if ($up["error"] !== UPLOAD_ERR_OK || !is_uploaded_file($up["tmp_name"])) {
            jsonOut(["ok" => false, "error" => "The file could not be received. Try again."], 400);
        }

        $t = xfParseTable((string)file_get_contents($up["tmp_name"]));
        if (!$t["ok"]) jsonOut(["ok" => false, "error" => $t["error"]], 400);

        $replace = ($_POST["existing"] ?? "skip") === "replace";
        $res = $type === "users" ? xfImportUsers($conn, $t["head"], $t["rows"], $replace) : xfImportAi($conn, $t["head"], $t["rows"], $replace);
        jsonOut($res, $res["ok"] ? 200 : 400);
    }

    jsonOut(["ok" => false, "error" => "Unknown request."], 400);
}


/* LIVE LIST: browsers poll this; they only get the full list back when something changed */
if ($act === "list") {
    $files   = loadFiles($conn, $user);
    $version = filesVersion($files);

    if (($_GET["v"] ?? "") === $version) {
        jsonOut(["v" => $version, "same" => true]);
    }
    jsonOut(["v" => $version, "files" => $files]);
}

/* PEOPLE FOR THE SHARE MENU (everybody except me; the admin sees every file anyway) */
if ($act === "users") {
    $others = array_values(array_filter(shareableUsers($conn), fn($u) => $u["id"] !== (int)$user["id"]));
    jsonOut(["users" => $others]);
}

/* ADMIN: all accounts with their file counts */
if ($act === "admin_users") {
    if (!$user["is_admin"]) jsonOut(["ok" => false, "error" => "Only the admin can do that."], 403);

    $res = $conn->query("SELECT u.id, u.username, u.is_admin, u.agreed_ai_at,
                                (SELECT COUNT(*) FROM files f WHERE f.owner_id = u.id) AS n
                           FROM users u ORDER BY u.is_admin DESC, u.username");
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $rows[] = ["id" => (int)$r["id"], "name" => (string)$r["username"], "admin" => (int)$r["is_admin"] === 1, "files" => (int)$r["n"]];
    }
    jsonOut(["ok" => true, "users" => $rows]);
}

/* ADMIN: export accounts or AI setup as CSV / Excel. The admin account is never included. */
if ($act === "admin_export") {
    if (!$user["is_admin"]) jsonOut(["ok" => false, "error" => "Only the admin can do that."], 403);

    $type = (string)($_GET["type"] ?? "");
    $fmt  = ($_GET["format"] ?? "csv") === "xlsx" ? "xlsx" : "csv";

    if ($type === "users") {
        // the password column holds the stored hash (real passwords are never kept anywhere)
        $head = ["username", "password"];
        $rows = [];
        $res  = $conn->query("SELECT username, password FROM users WHERE is_admin = 0 ORDER BY username");
        while ($r = $res->fetch_assoc()) $rows[] = [(string)$r["username"], (string)$r["password"]];
        $base = "accounts";
        $sheet = "Accounts";
    } elseif ($type === "ai") {
        require_once __DIR__ . "/aicore.php";
        $keys = ($_GET["keys"] ?? "1") !== "0";
        $head = ["name", "type", "web_address", "model", "api_key", "enabled", "order"];
        $rows = [];
        foreach (aiRows($conn) as $i => $p) {
            $rows[] = [$p["name"], $p["kind"], $p["base_url"], $p["model"], $keys ? $p["key"] : "", $p["enabled"] ? "1" : "0", (string)($i + 1)];
        }
        $base = "ai-setup";
        $sheet = "AI setup";
    } else {
        jsonOut(["ok" => false, "error" => "Unknown export."], 400);
    }

    $stamp = date("Y-m-d");
    if ($fmt === "xlsx") {
        xfSend(xfXlsxWrite($head, $rows, $sheet), "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet", $base . "-" . $stamp . ".xlsx");
    }
    xfSend(xfCsvWrite($head, $rows), "text/csv; charset=utf-8", $base . "-" . $stamp . ".csv");
}

/* ADMIN: activity log. type=ai -> last AI uses (from ai_log, no question text), type=downloads -> last downloads (from file_downloads) */
if ($act === "admin_log") {
    if (!$user["is_admin"]) jsonOut(["ok" => false, "error" => "Only the admin can do that."], 403);

    $type = (string)($_GET["type"] ?? "ai");
    $rows = [];

    if ($type === "downloads") {
        $res = $conn->query("SELECT d.downloaded_at AS at, u.username, f.filename
                               FROM file_downloads d
                               LEFT JOIN users u ON u.id = d.user_id
                               LEFT JOIN files f ON f.id = d.file_id
                              ORDER BY d.id DESC LIMIT 100");
        while ($r = $res->fetch_assoc()) {
            $rows[] = ["at" => (string)$r["at"],
                       "user" => $r["username"] !== null ? (string)$r["username"] : "deleted account",
                       "file" => $r["filename"] !== null ? (string)$r["filename"] : "deleted file"];
        }
    } elseif ($type === "logins") {
        // Every sign-in line (login_attempts): who, when, from which IP, and how it went. Newest first.
        $labels = ["failed" => "Failed log-in", "signup" => "Sign-up attempt", "ok" => "Signed in", "new" => "Account created"];
        $res = $conn->query("SELECT ip, attempted_at AS at, username, result FROM login_attempts ORDER BY attempted_at DESC LIMIT 100");
        while ($r = $res->fetch_assoc()) {
            $res0 = (string)$r["result"];
            $rows[] = ["at" => (string)$r["at"],
                       "user" => $r["username"] !== null ? (string)$r["username"] : "(no name)",
                       "file" => ($labels[$res0] ?? "Attempt") . " · " . (string)$r["ip"],
                       "ok" => $res0 === "ok" || $res0 === "new"];
        }
    } else {
        $res = $conn->query("SELECT l.asked_at AS at, u.username, f.filename
                               FROM ai_log l
                               LEFT JOIN users u ON u.id = l.user_id
                               LEFT JOIN files f ON f.id = l.file_id
                              ORDER BY l.asked_at DESC LIMIT 100");
        while ($r = $res->fetch_assoc()) {
            $rows[] = ["at" => (string)$r["at"],
                       "user" => $r["username"] !== null ? (string)$r["username"] : "deleted account",
                       "file" => $r["filename"] !== null ? (string)$r["filename"] : "unknown file"];
        }
    }
    jsonOut(["ok" => true, "type" => $type, "rows" => $rows]);
}

/* ADMIN: database report, GET ?action=admin_db&limit=25|100|500|2000 (counts are always exact) */
if ($act === "admin_db") {
    if (!$user["is_admin"]) jsonOut(["ok" => false, "error" => "Only the admin can do that."], 403);
    $limit = (int)($_GET["limit"] ?? 100);
    if (!in_array($limit, [25, 100, 500, 2000], true)) $limit = 100;
    jsonOut(adminDbReport($conn, $limit));
}

/* WHO DOWNLOADED A FILE (uploader and admin only) */
if ($act === "downloads") {
    $file = findAccessibleFile($conn, (int)($_GET["id"] ?? 0), $user);
    if (!$file) jsonOut(["ok" => false, "error" => "File not found."], 404);
    if (!canManageFile($file, $user)) jsonOut(["ok" => false, "error" => "Not allowed."], 403);

    $id = (int)$file["id"];
    $stmt = $conn->prepare("SELECT u.username, d.downloaded_at FROM file_downloads d LEFT JOIN users u ON u.id = d.user_id WHERE d.file_id = ? ORDER BY d.id DESC LIMIT 200");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $rows[] = ["user" => $r["username"] !== null ? (string)$r["username"] : "deleted account", "at" => $r["downloaded_at"]];
    }

    $stmt = $conn->prepare("SELECT COUNT(*) FROM file_downloads WHERE file_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $total = (int)($stmt->get_result()->fetch_row()[0] ?? 0);

    jsonOut(["ok" => true, "total" => $total, "downloads" => $rows]);
}

/* DOWNLOAD */
if ($act === "download") {

    $id   = isset($_GET["id"]) ? (int)$_GET["id"] : 0;
    $file = findAccessibleFile($conn, $id, $user);   // same answer for "no such file" and "not yours to see"

    if (!$file) {
        http_response_code(404);
        die("File not found.");
    }

    $path = __DIR__ . "/" . $file["filepath"];

    if (!is_file($path)) {
        http_response_code(404);
        die("The file is missing from the server.");
    }

    // Remember who downloaded it (a resumed download that starts in the middle is not counted again)
    $range = trim((string)($_SERVER["HTTP_RANGE"] ?? ""));
    if ($range === "" || preg_match('/^bytes=0-/', $range)) {
        $uid = (int)$user["id"];
        $fid = (int)$file["id"];
        $stmt = $conn->prepare("INSERT INTO file_downloads (file_id, user_id, downloaded_at) VALUES (?, ?, NOW())");
        $stmt->bind_param("ii", $fid, $uid);
        $stmt->execute();
    }

    sendFile($path, "application/octet-stream", dispositionHeader("attachment", basename($file["filename"])));
}

http_response_code(400);
die("Invalid request.");
?>
