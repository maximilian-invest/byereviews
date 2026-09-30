<?php
// Inbox for the admin panel: mirrors info@byereviews.com over IMAP (same mailbox login as SMTP in /etc/byereviews/smtp.php),
// replies via SMTP (+ copy in "Sent"), links mails to orders and prepares AI reply drafts (Anthropic API key set in the admin).
// Data (server only, gitignored): <data_dir>/mail/<folder>/<uid>.json, mailsys/ (index, threads, state), maildrafts/, settings/ai.json
declare(strict_types=1);

const INBOX_KEEP = 400;          // newest messages mirrored per folder
const INBOX_FETCH_PER_SYNC = 60; // new message bodies per folder and sync
const INBOX_BG_INTERVAL = 600;   // background sync every 10 min (triggered by site traffic, after the response is sent)
const INBOX_MAX_BODY = 4000000;  // bigger mails: headers only

final class ImapClient {
    private $fp;
    private int $tag = 0;

    public function __construct(string $host, int $port, string $user, string $pass, bool $verify = true) {
        $ctx = stream_context_create(['ssl' => ['verify_peer' => $verify, 'verify_peer_name' => $verify, 'allow_self_signed' => !$verify]]);
        $this->fp = @stream_socket_client("ssl://$host:$port", $en, $es, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$this->fp) throw new RuntimeException('imap_connect_failed');
        stream_set_timeout($this->fp, 40);
        $g = (string)fgets($this->fp);
        if (strncmp($g, '* OK', 4) !== 0 && strncmp($g, '* PREAUTH', 9) !== 0) throw new RuntimeException('imap_bad_greeting');
        [$ok] = $this->cmd('LOGIN ' . self::q($user) . ' ' . self::q($pass));
        if (!$ok) throw new RuntimeException('imap_login_failed');
    }

    public function __destruct() {
        if ($this->fp) { @fwrite($this->fp, 'Z LOGOUT' . "\r\n"); @fclose($this->fp); }
    }

    public static function q(string $s): string {
        return '"' . addcslashes($s, "\"\\") . '"';
    }

    public static function folderArg(string $utf8): string {
        return self::q(mb_convert_encoding($utf8, 'UTF7-IMAP', 'UTF-8'));
    }

    /** Runs a command. Returns [ok, untagged response (literals included), tagged status line]. $literal = APPEND payload. */
    public function cmd(string $c, ?string $literal = null): array {
        $t = 'A' . (++$this->tag);
        if ($literal !== null) {
            fwrite($this->fp, "$t $c {" . strlen($literal) . "}\r\n");
            $l = fgets($this->fp);
            if ($l === false || $l[0] !== '+') return [false, '', (string)$l];
            fwrite($this->fp, $literal . "\r\n");
        } else {
            fwrite($this->fp, "$t $c\r\n");
        }
        $raw = '';
        $tl = strlen($t) + 1;
        while (true) {
            $line = fgets($this->fp);
            if ($line === false) return [false, $raw, 'EOF'];
            if (strncmp($line, "$t ", $tl) === 0) return [strncmp(substr($line, $tl), 'OK', 2) === 0, $raw, trim($line)];
            $raw .= $line;
            while (preg_match('/\{(\d+)\}\r\n$/', $line, $m)) {
                $n = (int)$m[1];
                $buf = '';
                while (strlen($buf) < $n) {
                    $chunk = fread($this->fp, min(65536, $n - strlen($buf)));
                    if ($chunk === false || $chunk === '') return [false, $raw, 'EOF'];
                    $buf .= $chunk;
                }
                $raw .= $buf;
                $line = fgets($this->fp);
                if ($line === false) return [false, $raw, 'EOF'];
                $raw .= $line;
            }
        }
    }

    /** Parses an untagged response block into lines of values (lists → arrays, NIL → null). */
    public static function parse(string $s): array {
        $i = 0; $n = strlen($s); $lines = [];
        while ($i < $n) {
            $vals = [];
            while ($i < $n) {
                if ($s[$i] === "\r" || $s[$i] === "\n") { $i += ($s[$i] === "\r" && ($s[$i + 1] ?? '') === "\n") ? 2 : 1; break; }
                if ($s[$i] === ' ') { $i++; continue; }
                $vals[] = self::value($s, $i);
            }
            if ($vals) $lines[] = $vals;
        }
        return $lines;
    }

    private static function value(string $s, int &$i) {
        $n = strlen($s);
        $c = $s[$i];
        if ($c === '(') {
            $i++; $list = [];
            while ($i < $n && $s[$i] !== ')') {
                if ($s[$i] === ' ' || $s[$i] === "\r" || $s[$i] === "\n") { $i++; continue; }
                $list[] = self::value($s, $i);
            }
            $i++;
            return $list;
        }
        if ($c === '"') {
            $i++; $out = '';
            while ($i < $n && $s[$i] !== '"') { if ($s[$i] === '\\') $i++; $out .= $s[$i] ?? ''; $i++; }
            $i++;
            return $out;
        }
        if ($c === '{' && preg_match('/\G\{(\d+)\}\r\n/', $s, $m, 0, $i)) {
            $i += strlen($m[0]);
            $out = substr($s, $i, (int)$m[1]);
            $i += (int)$m[1];
            return $out;
        }
        $start = $i; $depth = 0;
        while ($i < $n) {
            $ch = $s[$i];
            if ($ch === '[') $depth++;
            elseif ($ch === ']') $depth--;
            elseif ($depth <= 0 && ($ch === ' ' || $ch === '(' || $ch === ')' || $ch === "\r" || $ch === "\n")) break;
            $i++;
        }
        $atom = substr($s, $start, $i - $start);
        return strtoupper($atom) === 'NIL' ? null : $atom;
    }

    /** FETCH lines → [uid => [KEY => value]]. */
    public static function fetchItems(string $raw): array {
        $out = [];
        foreach (self::parse($raw) as $line) {
            if (($line[0] ?? '') !== '*' || strtoupper((string)($line[2] ?? '')) !== 'FETCH' || !is_array($line[3] ?? null)) continue;
            $kv = [];
            $l = $line[3];
            for ($k = 0; $k + 1 < count($l); $k += 2) $kv[strtoupper((string)$l[$k])] = $l[$k + 1];
            if (isset($kv['UID'])) $out[(int)$kv['UID']] = ($out[(int)$kv['UID']] ?? []) + $kv;
        }
        return $out;
    }
}

// ---------- MIME ----------

function mime_split(string $raw): array {
    $p = strpos($raw, "\r\n\r\n"); $sep = 4;
    $q = strpos($raw, "\n\n");
    if ($p === false || ($q !== false && $q < $p)) { $p = $q; $sep = 2; }
    if ($p === false) return [$raw, ''];
    return [substr($raw, 0, $p), substr($raw, $p + $sep)];
}

function mime_headers(string $h): array {
    $h = preg_replace("/\r?\n[ \t]+/", ' ', $h) ?? $h;
    $out = [];
    foreach (preg_split("/\r?\n/", $h) as $line) {
        if (strpos($line, ':') === false) continue;
        [$k, $v] = explode(':', $line, 2);
        $k = strtolower(trim($k));
        if (!isset($out[$k])) $out[$k] = trim($v);
    }
    return $out;
}

function mime_param(string $v, string $name): string {
    if (preg_match('/(?:^|;)\s*' . preg_quote($name, '/') . '\*\s*=\s*([^;]+)/i', $v, $m)) { // RFC 2231: utf-8''name
        $x = trim($m[1], " \"");
        if (preg_match("/^([^']*)'[^']*'(.*)$/", $x, $mm)) return mime_to_utf8(rawurldecode($mm[2]), $mm[1] ?: 'utf-8');
        return rawurldecode($x);
    }
    if (preg_match('/(?:^|;)\s*' . preg_quote($name, '/') . '\s*=\s*(?:"((?:[^"\\\\]|\\\\.)*)"|([^;\s]+))/i', $v, $m))
        return stripslashes($m[1] !== '' ? $m[1] : ($m[2] ?? ''));
    return '';
}

function mime_decode_header(string $v): string {
    if (strpos($v, '=?') === false) return mb_check_encoding($v, 'UTF-8') ? $v : mb_convert_encoding($v, 'UTF-8', 'ISO-8859-1');
    $r = function_exists('iconv_mime_decode') ? @iconv_mime_decode($v, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') : false;
    return $r !== false ? $r : mb_decode_mimeheader($v);
}

function mime_to_utf8(string $s, string $cs): string {
    $cs = strtolower(trim($cs, " \"'"));
    if ($cs === '' || in_array($cs, ['utf-8', 'utf8', 'us-ascii', 'ascii'], true))
        return mb_check_encoding($s, 'UTF-8') ? $s : mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
    $r = @mb_convert_encoding($s, 'UTF-8', $cs);
    if ((!is_string($r) || $r === '') && function_exists('iconv')) $r = @iconv($cs, 'UTF-8//IGNORE', $s);
    return is_string($r) && $r !== '' ? $r : mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
}

function mime_walk(string $raw, array &$acc, int $depth = 0): void {
    [$hs, $body] = mime_split($raw);
    $h = mime_headers($hs);
    $ctFull = $h['content-type'] ?? 'text/plain';
    $ct = strtolower(trim(explode(';', $ctFull)[0]));
    if (strpos($ct, 'multipart/') === 0 && $depth < 8 && ($b = mime_param($ctFull, 'boundary')) !== '') {
        $segs = explode('--' . $b, $body);
        array_shift($segs);
        foreach ($segs as $seg) {
            if (strncmp($seg, '--', 2) === 0) break;
            $seg = preg_replace('/^[ \t]*\r?\n/', '', $seg) ?? $seg;
            $seg = preg_replace('/\r?\n$/', '', $seg) ?? $seg;
            mime_walk($seg, $acc, $depth + 1);
        }
        return;
    }
    $enc = strtolower(trim($h['content-transfer-encoding'] ?? ''));
    $data = $enc === 'base64' ? (string)base64_decode(preg_replace('/\s+/', '', $body) ?? '') : ($enc === 'quoted-printable' ? quoted_printable_decode($body) : $body);
    $disp = strtolower($h['content-disposition'] ?? '');
    $fname = mime_param($h['content-disposition'] ?? '', 'filename') ?: mime_param($ctFull, 'name');
    if ($fname !== '' || strpos($disp, 'attachment') === 0 || !in_array($ct, ['text/plain', 'text/html'], true)) {
        $acc['attachments'][] = ['name' => mime_decode_header($fname ?: ($ct === 'message/rfc822' ? 'message.eml' : 'attachment')), 'type' => $ct, 'size' => strlen($data)];
        return;
    }
    $text = mime_to_utf8($data, mime_param($ctFull, 'charset'));
    if ($ct === 'text/html') { if ($acc['html'] === '') $acc['html'] = $text; }
    elseif ($acc['text'] === '') $acc['text'] = $text;
}

function html_to_text(string $html): string {
    $h = preg_replace('~<(script|style|head|title)\b[^>]*>.*?</\1>~is', '', $html) ?? $html;
    $h = preg_replace('~<br\s*/?>~i', "\n", $h) ?? $h;
    $h = preg_replace('~</(p|div|tr|li|h[1-6]|table|blockquote)>~i', "\n", $h) ?? $h;
    $h = preg_replace('~<li\b[^>]*>~i', '• ', $h) ?? $h;
    $t = html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = preg_replace("/[ \t\x{00A0}]+/u", ' ', $t) ?? $t;
    $t = preg_replace("/ *\n */", "\n", $t) ?? $t;
    return trim(preg_replace("/\n{3,}/", "\n\n", $t) ?? $t);
}

function mail_addrs(string $v): array {
    $v = mime_decode_header($v);
    $out = [];
    preg_match_all('/(?:"?([^"<,;]*?)"?\s*<([^<>\s]+@[^<>\s]+)>|([^\s<>,;"]+@[^\s<>,;"]+))/', $v, $m, PREG_SET_ORDER);
    foreach ($m as $x) {
        $email = strtolower(trim(($x[2] ?? '') !== '' ? $x[2] : ($x[3] ?? '')));
        if ($email !== '') $out[] = ['name' => trim($x[1] ?? ''), 'email' => $email];
    }
    return $out;
}

function mail_ids(string $v): array {
    preg_match_all('/<[^<>\s]+>/', $v, $m);
    return $m[0];
}

/** Raw RFC 822 → message record. */
function mail_parse(string $raw, bool $headersOnly = false): array {
    [$hs] = mime_split($raw);
    $h = mime_headers($hs);
    $acc = ['text' => '', 'html' => '', 'attachments' => []];
    if (!$headersOnly) mime_walk($raw, $acc);
    $text = $acc['text'] !== '' ? $acc['text'] : ($acc['html'] !== '' ? html_to_text($acc['html']) : '');
    if ($headersOnly) $text = '(Large message – open it in webmail to read it.)';
    $from = mail_addrs($h['from'] ?? '')[0] ?? ['name' => '', 'email' => ''];
    $auto = isset($h['list-unsubscribe']) || isset($h['list-id']) || preg_match('/^(auto-generated|auto-replied)/i', $h['auto-submitted'] ?? '')
        || preg_match('/bulk|list|junk/i', $h['precedence'] ?? '') || preg_match('/^(no-?reply|noreply|mailer-daemon|postmaster|notifications?|bounce)/i', $from['email']);
    return [
        'messageId' => mail_ids($h['message-id'] ?? '')[0] ?? '',
        'inReplyTo' => mail_ids($h['in-reply-to'] ?? '')[0] ?? '',
        'refs' => array_slice(mail_ids($h['references'] ?? ''), -30),
        'from' => $from, 'to' => mail_addrs($h['to'] ?? ''), 'cc' => mail_addrs($h['cc'] ?? ''), 'replyTo' => mail_addrs($h['reply-to'] ?? ''),
        'subject' => mime_decode_header($h['subject'] ?? ''), 'date' => strtotime($h['date'] ?? '') ?: 0,
        'text' => mb_substr($text, 0, 60000), 'html' => strlen($acc['html']) <= 600000 ? $acc['html'] : '', 'attachments' => $acc['attachments'], 'auto' => (bool)$auto,
    ];
}

// ---------- mailbox ----------

function inbox_login(): ?array {
    $c = smtp_config();
    if (!$c || empty($c['user']) || empty($c['pass'])) return null;
    return ['host' => (string)config('imap_host', preg_replace('/^smtp\./', 'imap.', (string)$c['host'])), 'port' => (int)config('imap_port', 993),
        'user' => (string)$c['user'], 'pass' => (string)$c['pass'], 'verify' => (bool)config('imap_verify', true)];
}

function inbox_imap(): ImapClient {
    $l = inbox_login();
    if (!$l) throw new RuntimeException('mail_not_configured');
    return new ImapClient($l['host'], $l['port'], $l['user'], $l['pass'], $l['verify']);
}

function inbox_our_address(): string {
    return strtolower((string)(inbox_login()['user'] ?? FROM_EMAIL));
}

function folder_key(string $name): string {
    return substr(preg_replace('/[^A-Za-z0-9]+/', '_', $name), 0, 40) . '-' . substr(md5($name), 0, 6);
}

/** LIST → [{name, label, role}], cached. */
function inbox_folders(?ImapClient $im = null, bool $refresh = false): array {
    $cached = store_get('mailsys', 'folders');
    if (!$refresh && $cached && ($cached['at'] ?? 0) > time() - 86400) return $cached['list'];
    $im = $im ?? inbox_imap();
    [, $raw] = $im->cmd('LIST "" "*"');
    $list = [];
    foreach (ImapClient::parse($raw) as $line) {
        if (($line[1] ?? '') !== 'LIST' || !is_array($line[2] ?? null)) continue;
        $flags = array_map('strtolower', array_map('strval', $line[2]));
        if (in_array('\noselect', $flags, true)) continue;
        $delim = (string)($line[3] ?? '.');
        $name = mb_convert_encoding((string)$line[4], 'UTF-8', 'UTF7-IMAP');
        $low = strtolower($name);
        $short = $delim !== '' && stripos($name, 'INBOX' . $delim) === 0 ? substr($name, 6) : $name;
        $role = strtoupper($name) === 'INBOX' ? 'inbox' : (in_array('\sent', $flags, true) || preg_match('/(^|\W)sent/', $low) ? 'sent'
            : (in_array('\drafts', $flags, true) || preg_match('/(^|\W)drafts?$/', $low) ? 'drafts' : (in_array('\trash', $flags, true) || preg_match('/(^|\W)(trash|deleted)/', $low) ? 'trash'
            : (in_array('\junk', $flags, true) || preg_match('/(^|\W)(junk|spam)/', $low) ? 'spam' : (in_array('\archive', $flags, true) || preg_match('/(^|\W)archive/', $low) ? 'archive' : 'other')))));
        $label = ['inbox' => 'Inbox', 'sent' => 'Sent', 'drafts' => 'Drafts', 'trash' => 'Trash', 'spam' => 'Spam', 'archive' => 'Archive'][$role] ?? $short;
        $list[] = ['name' => $name, 'label' => $role === 'other' ? $short : $label, 'role' => $role];
    }
    $order = ['inbox' => 0, 'drafts' => 1, 'sent' => 2, 'archive' => 3, 'other' => 4, 'spam' => 5, 'trash' => 6];
    usort($list, fn($a, $b) => [$order[$a['role']], $a['label']] <=> [$order[$b['role']], $b['label']]);
    store_put('mailsys', 'folders', ['at' => time(), 'list' => $list]);
    return $list;
}

function inbox_folder(string $role): ?string {
    foreach (inbox_folders() as $f) if ($f['role'] === $role) return $f['name'];
    return null;
}

/** Thread key: first known ancestor, else subject + other party. */
function inbox_thread(array $rec, string $folderRole): string {
    $threads = store_get('mailsys', 'threads') ?? ['byMsg' => [], 'bySubj' => []];
    $mine = inbox_our_address();
    $party = $folderRole === 'sent' || $rec['from']['email'] === $mine ? ($rec['to'][0]['email'] ?? '') : $rec['from']['email'];
    $subj = strtolower(trim(preg_replace('/^((re|aw|fw|fwd|wg|sv|antw)\s*(\[\d+\])?:\s*)+/i', '', $rec['subject']) ?? ''));
    $sk = md5($subj . '|' . $party);
    $key = '';
    foreach (array_reverse(array_merge($rec['refs'], $rec['inReplyTo'] ? [$rec['inReplyTo']] : [])) as $r) if (isset($threads['byMsg'][$r])) { $key = $threads['byMsg'][$r]; break; }
    if ($key === '' && $rec['refs']) $key = $rec['refs'][0];
    if ($key === '' && $rec['inReplyTo']) $key = $rec['inReplyTo'];
    if ($key === '' && $subj !== '' && isset($threads['bySubj'][$sk])) $key = $threads['bySubj'][$sk];
    if ($key === '') $key = $rec['messageId'] ?: ('local-' . md5($rec['subject'] . $rec['date'] . $rec['from']['email']));
    store_update('mailsys', 'threads', function (?array $t) use ($rec, $key, $sk, $subj) {
        $t = $t ?? ['byMsg' => [], 'bySubj' => []];
        if ($rec['messageId']) $t['byMsg'][$rec['messageId']] = $key;
        foreach ($rec['refs'] as $r) $t['byMsg'][$r] = $t['byMsg'][$r] ?? $key;
        if ($subj !== '') $t['bySubj'][$sk] = $key;
        if (count($t['byMsg']) > 20000) $t['byMsg'] = array_slice($t['byMsg'], -15000, null, true);
        if (count($t['bySubj']) > 10000) $t['bySubj'] = array_slice($t['bySubj'], -8000, null, true);
        return $t;
    });
    return $key;
}

function inbox_summary(array $rec): array {
    $s = $rec;
    unset($s['text'], $s['html']);
    $s['snippet'] = mb_substr(trim(preg_replace('/\s+/', ' ', preg_replace('/^>.*$/m', '', $rec['text']) ?? '') ?? ''), 0, 160);
    $s['attachments'] = count($rec['attachments']);
    return $s;
}

/** Mirrors one folder: new messages, deletions, flags. */
function inbox_sync_folder(ImapClient $im, array $folder): void {
    [$ok, $raw] = $im->cmd('SELECT ' . ImapClient::folderArg($folder['name']));
    if (!$ok) return;
    $uv = preg_match('/UIDVALIDITY (\d+)/', $raw, $m) ? (int)$m[1] : 0;
    $fk = folder_key($folder['name']);
    $idx = store_get('mailsys', 'idx-' . $fk) ?? ['uidvalidity' => $uv, 'items' => []];
    if ($idx['uidvalidity'] !== $uv) {
        foreach (glob(data_dir('mail/' . $fk) . '/*.json') ?: [] as $f) @unlink($f);
        $idx = ['uidvalidity' => $uv, 'items' => []];
    }
    [, $sr] = $im->cmd('UID SEARCH ALL');
    $uids = [];
    foreach (ImapClient::parse($sr) as $line) if (($line[1] ?? '') === 'SEARCH') foreach (array_slice($line, 2) as $u) $uids[] = (int)$u;
    rsort($uids);
    $uids = array_slice($uids, 0, INBOX_KEEP);
    $keep = array_flip($uids);
    foreach (array_keys($idx['items']) as $u) if (!isset($keep[$u])) { unset($idx['items'][$u]); @unlink(store_path('mail/' . $fk, (string)$u)); }
    $new = array_slice(array_values(array_filter($uids, fn($u) => !isset($idx['items'][$u]))), 0, INBOX_FETCH_PER_SYNC);
    if ($new) {
        [, $szr] = $im->cmd('UID FETCH ' . implode(',', $new) . ' (UID RFC822.SIZE)');
        $sizes = array_map(fn($x) => (int)($x['RFC822.SIZE'] ?? 0), ImapClient::fetchItems($szr));
        foreach (array_chunk($new, 10) as $chunk) {
            $big = array_values(array_filter($chunk, fn($u) => ($sizes[$u] ?? 0) > INBOX_MAX_BODY));
            $small = array_values(array_diff($chunk, $big));
            $items = [];
            if ($small) { [, $fr] = $im->cmd('UID FETCH ' . implode(',', $small) . ' (UID FLAGS INTERNALDATE BODY.PEEK[])'); $items += ImapClient::fetchItems($fr); }
            if ($big) { [, $fr] = $im->cmd('UID FETCH ' . implode(',', $big) . ' (UID FLAGS INTERNALDATE BODY.PEEK[HEADER])'); $items += ImapClient::fetchItems($fr); }
            foreach ($items as $uid => $it) {
                $body = (string)($it['BODY[]'] ?? $it['BODY[HEADER]'] ?? '');
                if ($body === '') continue;
                $rec = mail_parse($body, !isset($it['BODY[]']));
                if (!$rec['date']) $rec['date'] = strtotime((string)($it['INTERNALDATE'] ?? '')) ?: time();
                $flags = array_map('strtolower', array_map('strval', (array)($it['FLAGS'] ?? [])));
                $rec += ['folder' => $folder['name'], 'role' => $folder['role'], 'uid' => $uid, 'size' => $sizes[$uid] ?? strlen($body)];
                $rec['seen'] = in_array('\seen', $flags, true); $rec['answered'] = in_array('\answered', $flags, true); $rec['flagged'] = in_array('\flagged', $flags, true);
                $rec['thread'] = inbox_thread($rec, $folder['role']);
                store_put('mail/' . $fk, (string)$uid, $rec);
                $idx['items'][$uid] = inbox_summary($rec);
            }
        }
    }
    // flags of everything we keep
    if ($uids) {
        [, $flr] = $im->cmd('UID FETCH ' . min($uids) . ':' . max($uids) . ' (UID FLAGS)');
        foreach (ImapClient::fetchItems($flr) as $uid => $it) {
            if (!isset($idx['items'][$uid])) continue;
            $flags = array_map('strtolower', array_map('strval', (array)($it['FLAGS'] ?? [])));
            $idx['items'][$uid]['seen'] = in_array('\seen', $flags, true);
            $idx['items'][$uid]['answered'] = in_array('\answered', $flags, true);
            $idx['items'][$uid]['flagged'] = in_array('\flagged', $flags, true);
        }
    }
    $idx['syncedAt'] = time();
    store_put('mailsys', 'idx-' . $fk, $idx);
}

/** Syncs the given folder roles/names (default: inbox + sent). Never throws; returns an error code or ''. */
function inbox_sync(array $which = ['inbox', 'sent']): string {
    try {
        $im = inbox_imap();
        $folders = inbox_folders($im, true);
        foreach ($folders as $f) if (in_array($f['role'], $which, true) || in_array($f['name'], $which, true)) inbox_sync_folder($im, $f);
        store_put('mailsys', 'state', ['at' => time(), 'error' => '']);
        return '';
    } catch (Throwable $e) {
        $err = $e instanceof RuntimeException ? $e->getMessage() : 'imap_error';
        log_event('inbox sync failed: ' . $e->getMessage());
        store_put('mailsys', 'state', ['at' => time(), 'error' => $err]);
        return $err;
    }
}

function inbox_index(string $folder): array {
    return store_get('mailsys', 'idx-' . folder_key($folder))['items'] ?? [];
}

function inbox_get(string $folder, int $uid): ?array {
    return store_get('mail/' . folder_key($folder), (string)$uid);
}

/** email → order ids (customer email), cached per request. */
function inbox_orders_by_email(): array {
    static $map = null;
    if ($map !== null) return $map;
    $map = [];
    foreach (store_list('order') as $o) {
        $e = strtolower((string)($o['customer']['email'] ?? ''));
        if ($e !== '') $map[$e][] = ['id' => $o['id'], 'company' => $o['business']['name'] ?? ($o['customer']['company'] ?? ''), 'createdAt' => $o['createdAt'] ?? ''];
    }
    return $map;
}

function inbox_party(array $m): string {
    $mine = inbox_our_address();
    return ($m['role'] ?? '') === 'sent' || $m['from']['email'] === $mine ? ($m['to'][0]['email'] ?? '') : ($m['replyTo'][0]['email'] ?? $m['from']['email']);
}

function inbox_orders_for(array $m): array {
    $ids = [];
    foreach (inbox_orders_by_email()[inbox_party($m)] ?? [] as $o) $ids[$o['id']] = $o;
    if (preg_match_all('/BR-\d{5}/', $m['subject'] . ' ' . ($m['snippet'] ?? $m['text'] ?? ''), $mm))
        foreach ($mm[0] as $id) if (!isset($ids[$id]) && ($o = store_get('order', $id))) $ids[$id] = ['id' => $id, 'company' => $o['business']['name'] ?? '', 'createdAt' => $o['createdAt'] ?? ''];
    return array_values($ids);
}

// ---------- drafts (Anthropic API) ----------

function inbox_ai(): array {
    $s = store_get('settings', 'ai') ?? [];
    return ['key' => (string)($s['key'] ?? config('anthropic_api_key', '')), 'model' => (string)($s['model'] ?? 'claude-sonnet-5-5'),
        'tone' => (string)($s['tone'] ?? ''), 'signature' => (string)($s['signature'] ?? "Best regards,\nMaximilian\nbyereviews · byereviews.com"), 'auto' => (bool)($s['auto'] ?? true)];
}

function draft_key(string $thread): string { return md5($thread); }

/** All messages of a thread, oldest first (full records). */
function inbox_thread_messages(string $thread): array {
    $out = [];
    foreach (inbox_folders() as $f) {
        if (in_array($f['role'], ['trash', 'spam'], true)) continue;
        foreach (inbox_index($f['name']) as $uid => $s) if (($s['thread'] ?? '') === $thread && ($m = inbox_get($f['name'], (int)$uid))) $out[] = $m;
    }
    usort($out, fn($a, $b) => $a['date'] <=> $b['date']);
    // the same mail can sit in two folders (e.g. copies) – keep one
    $seen = [];
    return array_values(array_filter($out, function ($m) use (&$seen) { $k = $m['messageId'] ?: ($m['folder'] . $m['uid']); if (isset($seen[$k])) return false; $seen[$k] = 1; return true; }));
}

function inbox_order_context(array $orders): string {
    $lines = [];
    foreach (array_slice($orders, 0, 3) as $ref) {
        $o = store_get('order', $ref['id']);
        if (!$o) continue;
        $t = invoice($o);
        $rev = array_map(fn($r) => '- ' . ($r['author'] ?: 'review') . ' (' . (int)$r['stars'] . '★, ' . $r['tier'] . '): ' . $r['status'], $o['reviews']);
        $lines[] = "Order {$o['id']} for {$o['business']['name']} (created " . substr((string)$o['createdAt'], 0, 10) . ", currency {$o['currency']}, payment: " . ($o['payment']['status'] ?? 'unpaid')
            . ")\nReviews:\n" . implode("\n", $rev) . "\nAmount due for removed reviews: " . money($t['total'] ?? 0, $o['currency']);
    }
    return $lines ? implode("\n\n", $lines) : 'No order found for this sender.';
}

/** Asks Claude for a reply draft. Returns the text or throws. */
function inbox_generate_draft(string $thread, bool $allowSkip = false): string {
    $ai = inbox_ai();
    if ($ai['key'] === '') throw new RuntimeException('ai_not_configured');
    $msgs = inbox_thread_messages($thread);
    if (!$msgs) throw new RuntimeException('thread_not_found');
    $mine = inbox_our_address();
    $conv = [];
    foreach (array_slice($msgs, -6) as $m) {
        $who = $m['from']['email'] === $mine ? 'byereviews (us)' : (($m['from']['name'] ?: $m['from']['email']) . ' <' . $m['from']['email'] . '>');
        $body = preg_replace('/\n(On .{5,200} wrote:|Am .{5,200} schrieb .{0,200}:)\n.*$/s', '', $m['text']) ?? $m['text'];
        $conv[] = "--- " . date('Y-m-d H:i', $m['date']) . " · from $who\nSubject: {$m['subject']}\n\n" . mb_substr(trim($body), 0, 6000);
    }
    $last = end($msgs);
    // how the owner writes: his latest own mails from "Sent" (every reply sent from the admin becomes an example)
    $examples = [];
    if ($sentF = inbox_folder('sent')) {
        $idx = inbox_index($sentF);
        uasort($idx, fn($a, $b) => $b['date'] <=> $a['date']);
        foreach ($idx as $uid => $s) {
            if (($s['thread'] ?? '') === $thread || !empty($s['auto'])) continue;
            $x = inbox_get($sentF, (int)$uid);
            if (!$x) continue;
            $body = trim(preg_replace('/\n(On .{5,200} wrote:|Am .{5,200} schrieb .{0,200}:)\n.*$/s', '', $x['text']) ?? '');
            $body = trim(preg_replace('/^>.*$/m', '', $body) ?? '');
            if (mb_strlen($body) < 20) continue;
            $examples[] = mb_substr($body, 0, 1500);
            if (count($examples) >= 6) break;
        }
    }
    if (config('mock_ai')) return "Hi " . (explode(' ', $last['from']['name'] ?: 'there')[0]) . ",\n\nthanks for your message – [mock draft, " . count($examples) . " style examples]\n\n" . $ai['signature'];
    $system = "You write email replies for byereviews.com, a service that gets individual false, unfair or policy-violating Google reviews removed (the Google profile stays intact).\n"
        . "Facts you may use: No cure, no pay – nothing is charged upfront, customers pay only for reviews that were actually removed. Price per review: 90 if the review is at most 4 weeks old, 125 if older (same number in USD and EUR; EU customers pay EUR, everyone else USD). "
        . "Volume discount on the whole order: 2 reviews 5%, 3–5 reviews 10%, 6+ reviews 15%. Star-only ratings without text cannot be removed (cancelled for free). Orders are placed at https://byereviews.com/#order, customers log in at https://byereviews.com/#login to see status, pay and chat. "
        . "We never promise that a specific review will definitely be removed and never give legal advice.\n"
        . "Write only the email body (no subject line, no placeholders like [Name]). Reply in the language of the customer's last email. Be short, friendly, concrete and human – no marketing fluff, no AI phrases. "
        . "If something can't be answered from the facts and the order data, say we'll check and get back shortly. End with this signature:\n" . $ai['signature']
        . ($ai['tone'] !== '' ? "\nAdditional instructions from the owner:\n" . $ai['tone'] : '')
        . ($allowSkip ? "\nIf the last message needs no answer at all (e.g. only 'thanks', an out-of-office or a confirmation), reply with exactly NO_REPLY." : '')
        . ($examples ? "\n\nEmails the owner wrote himself recently. Match his tone, length, greeting and wording style closely (don't copy their content):\n\n" . implode("\n\n-----\n\n", $examples) : '');
    $user = "Order data:\n" . inbox_order_context(inbox_orders_for($last)) . "\n\nConversation (oldest first):\n" . implode("\n\n", $conv) . "\n\nWrite the reply to the last message.";
    $r = http_json('POST', 'https://api.anthropic.com/v1/messages', ['Content-Type: application/json', 'x-api-key: ' . $ai['key'], 'anthropic-version: 2023-06-01'],
        json_encode(['model' => $ai['model'], 'max_tokens' => 1200, 'system' => $system, 'messages' => [['role' => 'user', 'content' => $user]]]), 60);
    if ($r['code'] !== 200) {
        log_event('inbox draft failed ' . $r['code'] . ' ' . substr((string)($r['data']['error']['message'] ?? $r['error']), 0, 200));
        throw new RuntimeException($r['code'] === 401 ? 'ai_bad_key' : 'ai_failed');
    }
    $text = '';
    foreach ($r['data']['content'] ?? [] as $c) if (($c['type'] ?? '') === 'text') $text .= $c['text'];
    return trim($text);
}

/** Threads whose last mail is an inbound, human message in the inbox without a reply or draft. */
function inbox_needs_reply(): array {
    $mine = inbox_our_address();
    $last = [];
    foreach (inbox_folders() as $f) {
        if (!in_array($f['role'], ['inbox', 'sent'], true)) continue;
        foreach (inbox_index($f['name']) as $s) {
            $t = $s['thread'] ?? '';
            if ($t !== '' && (!isset($last[$t]) || $s['date'] > $last[$t]['date'])) $last[$t] = $s;
        }
    }
    $out = [];
    foreach ($last as $t => $s) {
        if ($s['role'] !== 'inbox' || $s['auto'] || $s['answered'] || $s['from']['email'] === $mine || $s['date'] < time() - 14 * 86400) continue;
        if (store_get('maildrafts', draft_key($t))) continue;
        $out[$t] = $s;
    }
    uasort($out, fn($a, $b) => $b['date'] <=> $a['date']);
    return $out;
}

function inbox_make_drafts(int $max = 5): int {
    $ai = inbox_ai();
    if ($ai['key'] === '' || !$ai['auto']) return 0;
    $n = 0;
    foreach (array_slice(inbox_needs_reply(), 0, $max, true) as $t => $s) {
        try {
            $text = inbox_generate_draft($t, true);
            $skip = trim($text) === 'NO_REPLY';
            store_put('maildrafts', draft_key($t), ['thread' => $t, 'text' => $skip ? '' : $text, 'source' => $skip ? 'skip' : 'ai', 'createdAt' => time(), 'forUid' => $s['uid']]);
            $n += $skip ? 0 : 1;
        } catch (Throwable $e) {
            if (in_array($e->getMessage(), ['ai_bad_key', 'ai_not_configured'], true)) break;
        }
    }
    return $n;
}

/** Runs after the response of a normal site request (php-fpm only), at most every INBOX_BG_INTERVAL. */
function inbox_background(): void {
    if (!function_exists('fastcgi_finish_request') || !inbox_login()) return;
    $st = store_get('mailsys', 'state');
    if ($st && ($st['at'] ?? 0) > time() - INBOX_BG_INTERVAL) return;
    $lock = @fopen(data_dir('mailsys') . '/bg.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return;
    fastcgi_finish_request();
    ignore_user_abort(true);
    @set_time_limit(180);
    if (inbox_sync() === '') inbox_make_drafts(5);
    flock($lock, LOCK_UN);
}

// ---------- sending ----------

function mail_send_raw(array $rcpt, string $raw): bool {
    if (config('mail_log_only')) {
        @file_put_contents(data_dir('mails') . '/' . date('His') . '-reply.eml', $raw);
        return true;
    }
    $c = smtp_config();
    if (!$c) return false;
    $port = (int)($c['port'] ?? 465);
    $fp = @stream_socket_client(($port === 465 ? 'ssl://' : 'tcp://') . $c['host'] . ':' . $port, $en, $es, 15);
    if (!$fp) return false;
    stream_set_timeout($fp, 20);
    $read = function () use ($fp) { $r = ''; while (($l = fgets($fp, 515)) !== false) { $r .= $l; if (isset($l[3]) && $l[3] === ' ') break; } return $r; };
    $cmd = function (string $line, string $expect) use ($fp, $read) { fwrite($fp, $line . "\r\n"); return strncmp($read(), $expect, 3) === 0; };
    $ok = strncmp($read(), '220', 3) === 0 && $cmd('EHLO byereviews.com', '250');
    if ($ok && $port !== 465) $ok = $cmd('STARTTLS', '220') && stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) && $cmd('EHLO byereviews.com', '250');
    $ok = $ok && $cmd('AUTH LOGIN', '334') && $cmd(base64_encode($c['user']), '334') && $cmd(base64_encode($c['pass']), '235') && $cmd('MAIL FROM:<' . $c['user'] . '>', '250');
    foreach ($rcpt as $r) $ok = $ok && $cmd('RCPT TO:<' . $r . '>', '250');
    $ok = $ok && $cmd('DATA', '354') && $cmd(preg_replace('/^\./m', '..', $raw) . "\r\n.", '250');
    @fwrite($fp, "QUIT\r\n"); fclose($fp);
    return $ok;
}

function mail_addr_header(array $list): string {
    return implode(', ', array_map(fn($a) => ($a['name'] !== '' ? '=?UTF-8?B?' . base64_encode($a['name']) . '?= ' : '') . '<' . $a['email'] . '>', $list));
}

function mail_quote(array $m): string {
    $who = $m['from']['name'] ?: $m['from']['email'];
    $body = implode("\n", array_map(fn($l) => '> ' . $l, array_slice(explode("\n", trim($m['text'])), 0, 60)));
    return "\n\nOn " . date('D, j M Y \a\t H:i', $m['date']) . ", $who wrote:\n" . $body;
}

// ---------- actions ----------

function inbox_admin(): void {
    admin_required();
    session_write_close();
    @set_time_limit(90);
}

function inbox_state(): array {
    $st = store_get('mailsys', 'state') ?? ['at' => 0, 'error' => ''];
    $ai = inbox_ai();
    return ['configured' => (bool)inbox_login(), 'syncedAt' => $st['at'] ?? 0, 'error' => $st['error'] ?? '', 'address' => inbox_our_address(),
        'ai' => ['hasKey' => $ai['key'] !== '', 'model' => $ai['model'], 'tone' => $ai['tone'], 'signature' => $ai['signature'], 'auto' => $ai['auto']]];
}

function inbox_unread(): int {
    $f = null;
    foreach (store_get('mailsys', 'folders')['list'] ?? [] as $x) if ($x['role'] === 'inbox') $f = $x['name'];
    if (!$f) return 0;
    return count(array_filter(inbox_index($f), fn($s) => !$s['seen']));
}

/** Folder list with unread counts + messages of one folder (summaries). ?folder=&q=&sync=1 */
function action_admin_inbox(): void {
    inbox_admin();
    if (!inbox_login()) json_out(['ok' => true] + inbox_state() + ['folders' => [], 'messages' => []]);
    $folder = (string)($_GET['folder'] ?? '');
    $folders = store_get('mailsys', 'folders')['list'] ?? [];
    if (!empty($_GET['sync']) || !$folders) {
        $err = inbox_sync(array_values(array_unique(['inbox', 'sent', $folder ?: 'inbox'])));
        if ($err === '') inbox_make_drafts(3);
        $folders = store_get('mailsys', 'folders')['list'] ?? [];
    }
    if ($folder === '' || !in_array($folder, array_column($folders, 'name'), true)) $folder = $folders[0]['name'] ?? 'INBOX';
    $q = mb_strtolower(trim((string)($_GET['q'] ?? '')));
    $drafts = [];
    foreach (glob(data_dir('maildrafts') . '/*.json') ?: [] as $f) { $d = json_decode((string)file_get_contents($f), true); if (is_array($d) && ($d['source'] ?? '') !== 'skip') $drafts[$d['thread']] = $d['source'] ?? 'ai'; }
    $items = [];
    foreach (inbox_index($folder) as $uid => $s) {
        if ($q !== '' && mb_strpos(mb_strtolower($s['subject'] . ' ' . $s['from']['name'] . ' ' . $s['from']['email'] . ' ' . implode(' ', array_column($s['to'], 'email')) . ' ' . $s['snippet']), $q) === false) continue;
        $items[] = ['uid' => (int)$uid, 'folder' => $folder, 'from' => $s['from'], 'to' => $s['to'], 'subject' => $s['subject'], 'date' => $s['date'], 'snippet' => $s['snippet'],
            'seen' => $s['seen'], 'answered' => $s['answered'], 'flagged' => $s['flagged'], 'attachments' => $s['attachments'], 'auto' => $s['auto'], 'thread' => $s['thread'],
            'draft' => isset($drafts[$s['thread']]), 'orders' => inbox_orders_for($s)];
    }
    usort($items, fn($a, $b) => $b['date'] <=> $a['date']);
    $fl = array_map(fn($f) => $f + ['unread' => count(array_filter(inbox_index($f['name']), fn($s) => !$s['seen'])), 'synced' => (bool)store_get('mailsys', 'idx-' . folder_key($f['name']))], $folders);
    json_out(['ok' => true] + inbox_state() + ['folders' => $fl, 'folder' => $folder, 'messages' => array_slice($items, 0, 300)]);
}

/** Cheap unread count for the nav badge (no IMAP). */
function action_admin_inbox_unread(): void {
    admin_required();
    session_write_close();
    json_out(['ok' => true, 'unread' => inbox_unread()]);
}

/** One conversation: all messages of the thread, linked orders, draft. Marks the opened mail as read. */
function action_admin_inbox_thread(): void {
    inbox_admin();
    $folder = (string)($_GET['folder'] ?? ''); $uid = (int)($_GET['uid'] ?? 0);
    $m = inbox_get($folder, $uid);
    if (!$m) fail(404, 'message_not_found');
    if (!$m['seen']) {
        try { $im = inbox_imap(); $im->cmd('SELECT ' . ImapClient::folderArg($folder)); $im->cmd("UID STORE $uid +FLAGS.SILENT (\\Seen)"); } catch (Throwable $e) {}
        $m['seen'] = true; store_put('mail/' . folder_key($folder), (string)$uid, $m);
        $fk = folder_key($folder);
        store_update('mailsys', 'idx-' . $fk, function (?array $i) use ($uid) { if ($i && isset($i['items'][$uid])) $i['items'][$uid]['seen'] = true; return $i; });
    }
    $mine = inbox_our_address();
    $msgs = inbox_thread_messages($m['thread']) ?: [$m];
    $orders = inbox_orders_for($m);
    $orderInfo = [];
    foreach ($orders as $o) if ($oo = store_get('order', $o['id'])) $orderInfo[] = ['id' => $oo['id'], 'company' => $oo['business']['name'] ?? '', 'currency' => $oo['currency'] ?? '',
        'payment' => $oo['payment']['status'] ?? 'unpaid', 'reviews' => array_count_values(array_map(fn($r) => $r['status'], $oo['reviews'])), 'createdAt' => $oo['createdAt'] ?? ''];
    $lastIn = null;
    foreach ($msgs as $x) if ($x['from']['email'] !== $mine) $lastIn = $x;
    $replyTo = $lastIn ? ($lastIn['replyTo'][0] ?? $lastIn['from']) : ($m['to'][0] ?? $m['from']);
    $d = store_get('maildrafts', draft_key($m['thread']));
    json_out(['ok' => true, 'thread' => $m['thread'], 'subject' => $m['subject'], 'orders' => $orderInfo,
        'messages' => array_map(fn($x) => ['folder' => $x['folder'], 'uid' => $x['uid'], 'mine' => $x['from']['email'] === $mine, 'from' => $x['from'], 'to' => $x['to'], 'cc' => $x['cc'],
            'date' => $x['date'], 'text' => $x['text'], 'html' => $x['html'], 'attachments' => $x['attachments'], 'auto' => $x['auto']], $msgs),
        'reply' => ['to' => $replyTo, 'subject' => preg_match('/^re:/i', $m['subject']) ? $m['subject'] : 'Re: ' . $m['subject'], 'folder' => ($lastIn ?? $m)['folder'], 'uid' => ($lastIn ?? $m)['uid']],
        'draft' => $d && ($d['source'] ?? '') !== 'skip' ? ['text' => $d['text'], 'source' => $d['source'] ?? 'ai', 'createdAt' => $d['createdAt'] ?? 0] : null]);
}

/** Save / regenerate / discard the draft of a thread. {thread, text?|regenerate?|discard?} */
function action_admin_inbox_draft(): void {
    inbox_admin();
    $d = json_body();
    $t = (string)($d['thread'] ?? '');
    if ($t === '') fail(400, 'no_thread');
    if (!empty($d['discard'])) { @unlink(store_path('maildrafts', draft_key($t))); json_out(['ok' => true, 'draft' => null]); }
    if (!empty($d['regenerate'])) {
        try { $text = inbox_generate_draft($t); } catch (Throwable $e) { fail(502, $e->getMessage()); }
        $rec = ['thread' => $t, 'text' => $text, 'source' => 'ai', 'createdAt' => time()];
    } else {
        $rec = ['thread' => $t, 'text' => clean($d['text'] ?? '', 50000), 'source' => 'edited', 'createdAt' => time()];
    }
    store_put('maildrafts', draft_key($t), $rec);
    json_out(['ok' => true, 'draft' => ['text' => $rec['text'], 'source' => $rec['source'], 'createdAt' => $rec['createdAt']]]);
}

/** Send a reply or a new mail. {to, cc?, subject, text, folder?, uid? (reply to)} */
function action_admin_inbox_send(): void {
    inbox_admin();
    $d = json_body();
    $to = mail_addrs(clean($d['to'] ?? '', 1000)); $cc = mail_addrs(clean($d['cc'] ?? '', 1000));
    $subject = clean($d['subject'] ?? '', 300); $text = str_replace("\r\n", "\n", clean($d['text'] ?? '', 100000));
    if (!$to || !array_filter($to, fn($a) => is_email($a['email']))) fail(400, 'bad_recipient');
    if (trim($text) === '') fail(400, 'empty_text');
    if (!rate_ok('inbox_send', 120, 3600)) fail(429, 'too_many_requests');
    $orig = !empty($d['uid']) ? inbox_get((string)($d['folder'] ?? ''), (int)$d['uid']) : null;
    $from = inbox_our_address();
    $mid = '<' . bin2hex(random_bytes(12)) . '@byereviews.com>';
    $body = $text . ($orig && !empty($d['quote']) ? mail_quote($orig) : '');
    $h = ['From: =?UTF-8?B?' . base64_encode(FROM_NAME) . "?= <$from>", 'To: ' . mail_addr_header($to)];
    if ($cc) $h[] = 'Cc: ' . mail_addr_header($cc);
    $h[] = 'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=';
    $h[] = 'Date: ' . date('r');
    $h[] = 'Message-ID: ' . $mid;
    if ($orig && $orig['messageId']) { $h[] = 'In-Reply-To: ' . $orig['messageId']; $h[] = 'References: ' . implode(' ', array_slice(array_merge($orig['refs'], [$orig['messageId']]), -20)); }
    $h[] = 'MIME-Version: 1.0';
    $h[] = 'Content-Type: text/plain; charset=UTF-8';
    $h[] = 'Content-Transfer-Encoding: quoted-printable';
    $raw = implode("\r\n", $h) . "\r\n\r\n" . quoted_printable_encode(str_replace("\n", "\r\n", $body));
    if (!mail_send_raw(array_merge(array_column($to, 'email'), array_column($cc, 'email')), $raw)) fail(502, 'send_failed');
    log_event('inbox sent to=' . implode(',', array_column($to, 'email')) . ' subject=' . $subject);
    $thread = $orig['thread'] ?? '';
    if ($thread !== '') @unlink(store_path('maildrafts', draft_key($thread)));
    try { // copy in "Sent", original marked as answered, then re-sync so the conversation shows it
        $im = inbox_imap();
        if ($sent = inbox_folder('sent')) $im->cmd('APPEND ' . ImapClient::folderArg($sent) . ' (\\Seen)', $raw);
        if ($orig) { $im->cmd('SELECT ' . ImapClient::folderArg($orig['folder'])); $im->cmd('UID STORE ' . (int)$orig['uid'] . ' +FLAGS.SILENT (\\Answered \\Seen)'); }
        foreach (inbox_folders($im) as $f) if ($f['role'] === 'sent' || ($orig && $f['name'] === $orig['folder'])) inbox_sync_folder($im, $f);
    } catch (Throwable $e) { log_event('inbox post-send: ' . $e->getMessage()); }
    json_out(['ok' => true]);
}

/** Read/unread, flag, move (archive/trash/spam/inbox). {folder, uid, seen?|flagged?|move?} */
function action_admin_inbox_update(): void {
    inbox_admin();
    $d = json_body();
    $folder = (string)($d['folder'] ?? ''); $uid = (int)($d['uid'] ?? 0);
    $m = inbox_get($folder, $uid);
    if (!$m) fail(404, 'message_not_found');
    try {
        $im = inbox_imap();
        [$ok] = $im->cmd('SELECT ' . ImapClient::folderArg($folder));
        if (!$ok) fail(502, 'imap_error');
        foreach (['seen' => '\\Seen', 'flagged' => '\\Flagged'] as $k => $flag) if (isset($d[$k])) $im->cmd("UID STORE $uid " . ($d[$k] ? '+' : '-') . "FLAGS.SILENT ($flag)");
        if (!empty($d['move'])) {
            $target = inbox_folder((string)$d['move']);
            if (!$target) fail(400, 'no_' . $d['move'] . '_folder');
            [$ok] = $im->cmd("UID MOVE $uid " . ImapClient::folderArg($target));
            if (!$ok) { [$ok] = $im->cmd("UID COPY $uid " . ImapClient::folderArg($target)); if ($ok) { $im->cmd("UID STORE $uid +FLAGS.SILENT (\\Deleted)"); $im->cmd("UID EXPUNGE $uid"); } }
            if (!$ok) fail(502, 'move_failed');
        }
        foreach (inbox_folders($im) as $f) if ($f['name'] === $folder || (!empty($d['move']) && $f['role'] === $d['move'])) inbox_sync_folder($im, $f);
    } catch (RuntimeException $e) { fail(502, $e->getMessage()); }
    json_out(['ok' => true]);
}

/** AI settings: {key?, model?, tone?, signature?, auto?}. The key is never sent back. */
function action_admin_inbox_ai(): void {
    inbox_admin();
    $d = json_body();
    store_update('settings', 'ai', function (?array $s) use ($d) {
        $s = $s ?? [];
        if (isset($d['key'])) { $k = trim((string)$d['key']); if ($k === '') unset($s['key']); elseif (preg_match('/^sk-ant-[A-Za-z0-9_\-]{20,}$/', $k)) $s['key'] = $k; }
        if (isset($d['model']) && in_array($d['model'], ['claude-sonnet-5-5', 'claude-opus-5-5', 'claude-haiku-4-5-20251001'], true)) $s['model'] = $d['model'];
        if (isset($d['tone'])) $s['tone'] = clean($d['tone'], 3000);
        if (isset($d['signature'])) $s['signature'] = clean($d['signature'], 500);
        if (isset($d['auto'])) $s['auto'] = (bool)$d['auto'];
        return $s;
    });
    @chmod(store_path('settings', 'ai'), 0640);
    if (isset($d['key']) && trim((string)$d['key']) !== '' && !preg_match('/^sk-ant-/', trim((string)$d['key']))) fail(400, 'bad_key');
    json_out(['ok' => true] + inbox_state());
}
