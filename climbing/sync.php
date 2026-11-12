<?php
// Stores Training for Climbing progress so every device sees the same ticks.
// GET  -> {"done":[...],"log":{"<day>":{"<field>":"<text>"}},"updated":<ms>}
// POST {"done":[...],"log":{...}} -> saves and returns the same shape.
// A POST without "log" (an older copy of the page) keeps the saved log.
// Requests must send the X-Sync-Key header that matches the key in the HTML file.

const SYNC_KEY = '7c3eafdd3f8563d6ff263148738f00b9';
const DATA_FILE = __DIR__ . '/progress-v2.json';
// v1 progress counted days from Tue 3 Nov; v2 counts from Sun 1 Nov.
const OLD_FILE = __DIR__ . '/progress.json';
const OLD_OFFSET = 2;
// Sun 1 Nov and Tue 3 Nov were completed before the plan was re-dated.
const SEED_DAYS = [0, 2];
const TOTAL_DAYS = 84;
// Load log fields, matching the page. Values are short text, at most 1000 characters each.
// rr, sp, sn and fn are from earlier plan versions and are kept for days that already have them.
const LOG_FIELDS = ['hk','hs','hr','pk','pr','pp','fs','fr','ca','ss','cs','bw','no','rr','sp','sn','fn'];
// Week 1 Sunday and Tuesday as recorded in the plan's LOAD LOG sheet.
const SEED_LOG = [
    0 => ['no' => 'Completed redpoint day.'],
    2 => ['pk' => '0', 'pr' => '5 × 5 BW', 'pp' => '5.5', 'fs' => '5', 'fr' => '4.5',
          'no' => '10 mm 3-finger pockets 10 × 8+ sec at RPE 4-5 (not comparable to the 20 mm load). 3-angle lock-offs 3 sets × 2 rounds × 5 sec at RPE 4-5. Shoulder good.'],
];
// Version 5 of the plan records W2 Sunday (Nov 8) as done. Added once to saved progress.
const SEED5_DAY = 7;
const SEED5_NOTE = 'Nov 8 redpoint session completed. The old post workout felt too easy.';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!hash_equals(SYNC_KEY, $_SERVER['HTTP_X_SYNC_KEY'] ?? '')) {
    http_response_code(403);
    echo '{"error":"forbidden"}';
    exit;
}

function read_state() {
    $s = is_file(DATA_FILE) ? json_decode((string) file_get_contents(DATA_FILE), true) : migrate_old();
    if (!is_array($s)) $s = ['done' => [], 'updated' => 0];
    if (!array_key_exists('log', $s)) $s['log'] = empty($s['updated']) ? [] : SEED_LOG;
    $s['log'] = clean_log($s['log']);
    if (empty($s['v5']) && !empty($s['updated'])) {
        if (!in_array(SEED5_DAY, $s['done'] ?? [], true)) $s['done'][] = SEED5_DAY;
        sort($s['done']);
        if (!isset($s['log'][SEED5_DAY])) $s['log'][SEED5_DAY] = ['no' => SEED5_NOTE];
        ksort($s['log']);
        $s['v5'] = 1;
        write_state($s);
    }
    return $s;
}

function write_state($s) {
    $out = ['done' => array_values($s['done']), 'log' => (object) $s['log'], 'updated' => $s['updated'], 'v5' => 1];
    return file_put_contents(DATA_FILE, json_encode($out, JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
}

function clean_log($in) {
    $out = [];
    if (!is_array($in)) return $out;
    foreach ($in as $day => $entry) {
        if (!is_numeric($day) || (int) $day != $day || $day < 0 || $day >= TOTAL_DAYS || !is_array($entry)) continue;
        $e = [];
        foreach (LOG_FIELDS as $f) {
            if (isset($entry[$f]) && is_string($entry[$f]) && trim($entry[$f]) !== '' && strlen($entry[$f]) <= 4000) {
                $e[$f] = $entry[$f];
            }
        }
        if ($e) $out[(int) $day] = $e;
    }
    ksort($out);
    return $out;
}

function output($s) {
    unset($s['v5']);
    $s['done'] = array_values($s['done']);
    $s['log'] = (object) array_map(function ($e) { return (object) $e; }, $s['log']);
    echo json_encode($s, JSON_UNESCAPED_UNICODE);
}

function migrate_old() {
    if (!is_file(OLD_FILE)) return ['done' => [], 'updated' => 0];
    $old = json_decode((string) file_get_contents(OLD_FILE), true);
    $done = array_fill_keys(SEED_DAYS, true);
    foreach (($old['done'] ?? []) as $d) {
        if (is_int($d) && $d + OLD_OFFSET < TOTAL_DAYS) $done[$d + OLD_OFFSET] = true;
    }
    $done = array_keys($done);
    sort($done);
    $state = ['done' => $done, 'updated' => (int) round(microtime(true) * 1000)];
    file_put_contents(DATA_FILE, json_encode($state), LOCK_EX);
    return $state;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($in) || !isset($in['done']) || !is_array($in['done'])) {
        http_response_code(400);
        echo '{"error":"bad request"}';
        exit;
    }
    $done = [];
    foreach ($in['done'] as $d) {
        if (is_int($d) && $d >= 0 && $d < TOTAL_DAYS) $done[$d] = true;
    }
    $done = array_keys($done);
    sort($done);
    $log = array_key_exists('log', $in) ? clean_log($in['log']) : read_state()['log'];
    $state = ['done' => $done, 'log' => $log, 'updated' => (int) round(microtime(true) * 1000), 'v5' => 1];
    if (!write_state($state)) {
        http_response_code(500);
        echo '{"error":"could not write progress-v2.json; check folder permissions"}';
        exit;
    }
    output($state);
    exit;
}

output(read_state());
