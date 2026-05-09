<?php
/**
 * SQLite only
 *
 * Schema is created on first call to db().
 * If config.php exists and the db has no links, contents are imported once
 */

declare(strict_types=1);

const DB_FILE       = __DIR__ . '/../db/data.sqlite';
const LEGACY_CONFIG = __DIR__ . '/../config/config.php';

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;

    $fresh = !is_file(DB_FILE);
    $pdo = new PDO('sqlite:' . DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA foreign_keys=ON');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settings (
            key   TEXT PRIMARY KEY,
            value TEXT
        );
        CREATE TABLE IF NOT EXISTS links (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            category   TEXT NOT NULL DEFAULT 'Other',
            name       TEXT NOT NULL,
            url        TEXT NOT NULL,
            icon       TEXT NOT NULL DEFAULT '',
            descr      TEXT NOT NULL DEFAULT '',
            sort_order INTEGER NOT NULL DEFAULT 0
        );
        CREATE INDEX IF NOT EXISTS idx_links_sort ON links(sort_order);
        CREATE TABLE IF NOT EXISTS health (
            host       TEXT PRIMARY KEY,
            status     TEXT NOT NULL,        -- ok | warn | down | unknown
            http_code  INTEGER,
            ms         INTEGER,
            checked_at INTEGER NOT NULL DEFAULT 0
        );
    ");

    if ($fresh) {
        seed_defaults($pdo);
        if (is_file(LEGACY_CONFIG)) {
            try { migrate_legacy_config($pdo); } catch (Throwable $e) { /* keep going */ }
        }
    }

    return $pdo;
}

function seed_defaults(PDO $pdo): void {
    $defaults = [
        'title'              => 'Homelab',
        'subtitle'           => 'Dashboard',
        // bcrypt for "changeme"
        'edit_password_hash' => '$2y$10$LmDWPgApM0IxLVTjJQ5Yrer9s1jxmfXJFSHiq7cG7KdfGBZnKSx8.',
        'edit_session_ttl'   => '3600',
        'theme_hue'          => '218',   // navy/steel
        'theme_sat'          => '35',    // %
        'theme_accent_shift' => '0',     // degrees offset for accent hue
        'theme_bg_bri'       => '0',     // ±, applied to whole bg ramp
        'theme_fg_bri'       => '0',     // ±, applied to text + accent L
        'theme_card_top'     => '9',     // L% — card gradient start
        'theme_card_bottom'  => '11',    // L% — card gradient end
    ];
    $stmt = $pdo->prepare('INSERT OR IGNORE INTO settings(key, value) VALUES(?, ?)');
    foreach ($defaults as $k => $v) $stmt->execute([$k, $v]);
}

function migrate_legacy_config(PDO $pdo): void {
    $cfg = require LEGACY_CONFIG;
    if (!is_array($cfg)) return;

    foreach (['title', 'subtitle', 'edit_password_hash', 'edit_session_ttl'] as $k) {
        if (!empty($cfg[$k])) set_setting($k, (string)$cfg[$k]);
    }
    $links = $cfg['links'] ?? [];
    if (count($links)) {
        save_links_replace($links);
    }
}

/* ---------- Settings ---------- */

function get_setting(string $key, ?string $default = null): ?string {
    $row = db()->prepare('SELECT value FROM settings WHERE key=?');
    $row->execute([$key]);
    $val = $row->fetchColumn();
    return $val !== false ? (string)$val : $default;
}

function set_setting(string $key, string $value): void {
    db()->prepare('INSERT INTO settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value')
        ->execute([$key, $value]);
}

function get_settings(): array {
    $rows = db()->query('SELECT key, value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    return $rows ?: [];
}

/* ---------- Links ---------- */

function get_links(): array {
    $rows = db()->query(
        'SELECT id, category, name, url, icon, descr AS "desc", sort_order
         FROM links ORDER BY category COLLATE NOCASE, sort_order, id'
    )->fetchAll(PDO::FETCH_ASSOC);
    return $rows ?: [];
}

/**
 * Replace the entire links table with the given array.
 * Preserves order via sort_order.
 */
function save_links_replace(array $links): void {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM links');
        $stmt = $pdo->prepare(
            'INSERT INTO links(category, name, url, icon, descr, sort_order)
             VALUES(?, ?, ?, ?, ?, ?)'
        );
        $i = 0;
        foreach ($links as $l) {
            $name = trim((string)($l['name'] ?? ''));
            $url  = trim((string)($l['url']  ?? ''));
            if ($name === '' || $url === '') continue;
            $stmt->execute([
                trim((string)($l['category'] ?? 'Other')) ?: 'Other',
                $name,
                $url,
                trim((string)($l['icon'] ?? '')),
                trim((string)($l['desc'] ?? '')),
                $i++,
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/* ---------- Health ---------- */

function get_health_map(): array {
    $rows = db()->query('SELECT host, status, http_code, ms, checked_at FROM health')->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) $out[$r['host']] = $r;
    return $out;
}

function set_health(string $host, string $status, ?int $code, ?int $ms): void {
    db()->prepare(
        'INSERT INTO health(host,status,http_code,ms,checked_at) VALUES(?,?,?,?,?)
         ON CONFLICT(host) DO UPDATE SET status=excluded.status, http_code=excluded.http_code, ms=excluded.ms, checked_at=excluded.checked_at'
    )->execute([$host, $status, $code, $ms, time()]);
}

function host_from(string $url): string {
    $h = parse_url($url, PHP_URL_HOST);
    $p = parse_url($url, PHP_URL_PORT);
    if (!$h) return $url;
    return $p ? "$h:$p" : $h;
}
