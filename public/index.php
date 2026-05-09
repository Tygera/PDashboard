<?php
/**
 * Homelab Dashboard — single-entry router.
 *
 * Modes:
 *   ?           view
 *   ?edit=1     edit (password gated)
 *
 * Async endpoints:
 *   ?action=health_refresh
 *   ?action=refresh_favicon&host=…  (edit mode only)
 *
 * Storage: SQLite (data.sqlite). Imports legacy config.php on first run if present.
 */

declare(strict_types=1);

require __DIR__ . '/../app/db.php';
require __DIR__ . '/../app/favicons.php';
require __DIR__ . '/../app/health.php';

session_name('dashboard_home');
session_start();

/* -------------------------------------------------------------------------- */
/* Helpers                                                                     */
/* -------------------------------------------------------------------------- */

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function is_editing(): bool {
    if (empty($_SESSION['edit_until'])) return false;
    if ($_SESSION['edit_until'] < time()) {
        unset($_SESSION['edit_until'], $_SESSION['csrf']);
        return false;
    }
    return true;
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function csrf_check(string $t): bool {
    return !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t);
}

function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** Cache-busting suffix for an asset path. */
function av(string $relPath): string {
    $abs = __DIR__ . '/' . $relPath;
    $v = is_file($abs) ? filemtime($abs) : time();
    return $relPath . '?v=' . $v;
}

/** Determine icon source for a link: manual / favicon / initials. */
function icon_info(array $link): array {
    $host   = host_from($link['url']);
    $manual = trim((string)($link['icon'] ?? ''));
    if ($manual !== '') return ['type' => 'manual', 'value' => $manual, 'host' => $host];
    $rel = favicon_relpath($host);
    if ($rel) return ['type' => 'favicon', 'value' => $rel, 'host' => $host];

    $name  = (string)($link['name'] ?? '?');
    $parts = preg_split('/\s+/', trim($name));
    $initials = '';
    foreach ($parts as $p) {
        if ($p === '') continue;
        $first = function_exists('mb_substr') ? mb_substr($p, 0, 1) : substr($p, 0, 1);
        $initials .= $first;
        $len = function_exists('mb_strlen') ? mb_strlen($initials) : strlen($initials);
        if ($len >= 2) break;
    }
    if ($initials === '') $initials = '?';
    $upper = function_exists('mb_strtoupper') ? mb_strtoupper($initials) : strtoupper($initials);
    return ['type' => 'initials', 'value' => $upper, 'host' => $host];
}

/* -------------------------------------------------------------------------- */
/* Async actions                                                               */
/* -------------------------------------------------------------------------- */

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'health_refresh') {
    $stale = find_stale_links();
    run_health_checks($stale);
    json_out(['health' => get_health_map()]);
}

if ($action === 'refresh_favicon' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_editing() || !csrf_check((string)($_POST['csrf'] ?? ''))) json_out(['error' => 'forbidden'], 403);
    $url = trim((string)($_POST['url'] ?? ''));
    if ($url === '') json_out(['error' => 'no url'], 400);
    $rel = fetch_favicon_for($url);
    json_out(['favicon' => $rel]);
}

/* -------------------------------------------------------------------------- */
/* Login / logout / save                                                       */
/* -------------------------------------------------------------------------- */

$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'login') {
        $pw   = (string)($_POST['password'] ?? '');
        $hash = (string)get_setting('edit_password_hash', '');
        if ($hash !== '' && password_verify($pw, $hash)) {
            session_regenerate_id(true);
            $_SESSION['edit_until'] = time() + (int)get_setting('edit_session_ttl', '3600');
            csrf_token();
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?edit=1');
            exit;
        }
        $flash = ['type' => 'error', 'msg' => 'Wrong password.'];
    }
    elseif ($action === 'logout') {
        unset($_SESSION['edit_until'], $_SESSION['csrf']);
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
    elseif ($action === 'save' && is_editing() && csrf_check((string)($_POST['csrf'] ?? ''))) {
        $payload  = json_decode((string)($_POST['payload'] ?? ''), true);
        $themeRaw = json_decode((string)($_POST['theme']   ?? '{}'), true) ?: [];

        if (!is_array($payload)) {
            $flash = ['type' => 'error', 'msg' => 'Invalid payload.'];
        } else {
            $oldHosts = array_map(fn($l) => host_from($l['url']), get_links());
            save_links_replace($payload);
            $newHosts = array_map(fn($l) => host_from($l['url']), get_links());

            $gone = array_diff($oldHosts, $newHosts);
            foreach (array_unique($gone) as $g) delete_favicon($g);

            $added = array_unique(array_diff($newHosts, $oldHosts));
            foreach ($added as $host) {
                if (favicon_cache_path($host)) continue;
                foreach (get_links() as $l) {
                    if (host_from($l['url']) === $host) {
                        @fetch_favicon_for($l['url']);
                        break;
                    }
                }
            }

            foreach (['theme_hue', 'theme_sat', 'theme_accent_shift', 'theme_bg_bri', 'theme_fg_bri', 'theme_card_top', 'theme_card_bottom'] as $k) {
                if (isset($themeRaw[$k]) && is_numeric($themeRaw[$k])) {
                    set_setting($k, (string)(int)$themeRaw[$k]);
                }
            }
            if (isset($themeRaw['meme_interval']) && is_numeric($themeRaw['meme_interval'])) {
                set_setting('meme_interval', (string)max(5, (int)$themeRaw['meme_interval']));
            }

            $flash = ['type' => 'ok', 'msg' => 'Saved ' . count(get_links()) . ' links.'];
        }
    }
}

$editing   = is_editing();
$showLogin = isset($_GET['edit']) && !$editing;

/* -------------------------------------------------------------------------- */
/* Data load                                                                   */
/* -------------------------------------------------------------------------- */

$links    = get_links();
$settings = get_settings();
$health   = get_health_map();

$title    = $settings['title']    ?? 'Homelab';
$subtitle = $settings['subtitle'] ?? 'Dashboard';

$hue   = (int)($settings['theme_hue']          ?? 218);
$sat   = (int)($settings['theme_sat']          ?? 35);
$accSh = (int)($settings['theme_accent_shift'] ?? 0);
$bgBri = (int)($settings['theme_bg_bri']       ?? 0);
$fgBri = (int)($settings['theme_fg_bri']       ?? 0);
$cTop  = (int)($settings['theme_card_top']     ?? 9);
$cBot  = (int)($settings['theme_card_bottom']  ?? 11);

$memeInterval = max(5, (int)($settings['meme_interval'] ?? 30));
$memes = is_file(__DIR__ . '/../config/meme.php') ? (require __DIR__ . '/../config/meme.php') : [];
$initialMeme = (!empty($memes) && $subtitle === ($settings['subtitle'] ?? 'Dashboard'))
    ? $memes[array_rand($memes)]
    : $subtitle;

$grouped = [];
foreach ($links as $l) {
    $cat = $l['category'] ?? 'Other';
    $grouped[$cat][] = $l;
}
ksort($grouped);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> — <?= h($subtitle) ?></title>
<link rel="stylesheet" href="<?= h(av('assets/app.css')) ?>">
<style>
  /* Per-page theme overrides — these come from db settings, so they have to
     be rendered server-side to avoid a flash of default theme on load. */
  :root {
    --h:        <?= $hue ?>;
    --s:        <?= $sat ?>%;
    --acc-h:    <?= ($hue + $accSh + 360 * 2) % 360 ?>;
    --bg-bri:   <?= $bgBri ?>;
    --fg-bri:   <?= $fgBri ?>;
    --card-top: <?= $cTop ?>%;
    --card-bot: <?= $cBot ?>%;
  }
</style>
</head>
<body data-editing="<?= $editing ? '1' : '0' ?>" data-csrf="<?= $editing ? h(csrf_token()) : '' ?>">
<div class="wrap">

  <header>
    <div class="title">
      <h1><?= h($title) ?></h1>
      <span class="sub" id="subtitle"><?= h($initialMeme) ?></span>
      <?php if ($editing): ?><span class="badge">Editing</span><?php endif; ?>
    </div>
    <div class="header-right">
      <?php if (!$editing): ?>
        <div class="search">
          <svg class="icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
          <input id="q" type="text" placeholder="Search links…" autocomplete="off" spellcheck="false">
          <kbd>/</kbd>
        </div>
        <button class="compact-toggle" type="button" id="compactToggle" title="Toggle compact view" aria-label="Toggle compact view">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
        </button>
        <a class="btn btn-ghost btn-sm" href="?edit=1" title="Edit links">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 1 1 3 3L7 19l-4 1 1-4Z"/></svg>
          Edit
        </a>
      <?php else: ?>
        <button class="btn btn-ghost btn-sm" type="button" id="themeToggle" title="Toggle theme panel">🎨 Theme</button>
        <form method="post" style="display:inline">
          <input type="hidden" name="action" value="logout">
          <button class="btn btn-ghost btn-sm" type="submit">Exit edit mode</button>
        </form>
      <?php endif; ?>
    </div>
  </header>

  <?php if ($flash): ?>
    <div class="flash <?= h($flash['type']) ?>"><?= h($flash['msg']) ?></div>
  <?php endif; ?>

  <?php if ($editing): /* ------------------- EDIT MODE ------------------- */ ?>

    <div class="edit-toolbar" id="editToolbar">
      <span class="info"><span class="dirty-dot"></span><strong id="cardCount">0</strong> links · drag <span style="color:var(--text-dim)">⋮⋮</span> to reorder · empty rows are dropped on save</span>
      <div class="spacer"></div>
      <?php if (!empty($memes)): ?>
      <label class="toolbar-label" title="Seconds between subtitle changes">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        <input type="number" id="memeInterval" min="5" max="3600" value="<?= $memeInterval ?>" style="width:54px">s
      </label>
      <?php endif; ?>
      <button class="btn btn-sm" type="button" id="addCardBtn">+ Add card</button>
      <button class="btn btn-sm btn-primary" type="button" id="saveBtn">Save</button>
    </div>

    <div class="theme-panel" id="themePanel">
      <label>
        <div class="row"><span>Hue</span><span class="v" id="vHue"><?= $hue ?>°</span></div>
        <input type="range" id="rHue" min="0"    max="360" value="<?= $hue ?>">
        <div class="swatch" id="swatchHue"></div>
      </label>
      <label>
        <div class="row"><span>Saturation</span><span class="v" id="vSat"><?= $sat ?>%</span></div>
        <input type="range" id="rSat" min="0"    max="60"  value="<?= $sat ?>">
      </label>
      <label>
        <div class="row"><span>Accent shift</span><span class="v" id="vAcc"><?= $accSh ?>°</span></div>
        <input type="range" id="rAcc" min="-180" max="180" value="<?= $accSh ?>">
      </label>
      <label>
        <div class="row"><span>Background brightness</span><span class="v" id="vBgBri"><?= ($bgBri >= 0 ? '+' : '') . $bgBri ?></span></div>
        <input type="range" id="rBgBri" min="-8"  max="20"  value="<?= $bgBri ?>">
      </label>
      <label>
        <div class="row"><span>Foreground brightness</span><span class="v" id="vFgBri"><?= ($fgBri >= 0 ? '+' : '') . $fgBri ?></span></div>
        <input type="range" id="rFgBri" min="-15" max="15"  value="<?= $fgBri ?>">
      </label>
      <label>
        <div class="row"><span>Card gradient top</span><span class="v" id="vCTop"><?= $cTop ?>%</span></div>
        <input type="range" id="rCTop" min="0"   max="30"  value="<?= $cTop ?>">
      </label>
      <label>
        <div class="row"><span>Card gradient bottom</span><span class="v" id="vCBot"><?= $cBot ?>%</span></div>
        <input type="range" id="rCBot" min="0"   max="30"  value="<?= $cBot ?>">
      </label>
    </div>

    <div id="editList"></div>

    <form id="saveForm" method="post" style="display:none;">
      <input type="hidden" name="action"  value="save">
      <input type="hidden" name="csrf"    value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="payload" id="payloadField">
      <input type="hidden" name="theme"   id="themeField">
    </form>

    <script id="initial-data" type="application/json"><?= json_encode(array_values($links), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
    <script id="initial-favicons" type="application/json"><?php
      $favMap = [];
      foreach ($links as $l) {
          $host = host_from($l['url']);
          if (!isset($favMap[$host])) $favMap[$host] = favicon_relpath($host);
      }
      echo json_encode($favMap, JSON_UNESCAPED_SLASHES);
    ?></script>

  <?php else: /* ------------------- VIEW MODE ------------------- */ ?>

    <?php if (!$showLogin): /* hide dashboard while login modal is up */ ?>
    <main id="board">
    <?php $idx = 0; foreach ($grouped as $cat => $items): ?>
      <section class="category" data-category="<?= h($cat) ?>">
        <div class="category-head">
          <h2><?= h($cat) ?></h2>
          <span class="count"><?= count($items) ?></span>
          <span class="spacer"></span>
          <button class="cat-compact" type="button" title="Toggle compact for this category" aria-label="Toggle compact for this category">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
          </button>
          <button class="cat-lock" type="button" title="Lock this category" aria-label="Lock this category">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/></svg>
          </button>
        </div>
        <div class="grid">
          <?php foreach ($items as $item):
              $info = icon_info($item);
              $host = $info['host'];
              $h_st = $health[$host]['status'] ?? 'unknown';
              $first = '';
              $nameTrim = trim((string)($item['name'] ?? ''));
              if ($nameTrim !== '') {
                  $first = function_exists('mb_substr') ? mb_substr($nameTrim, 0, 1) : substr($nameTrim, 0, 1);
                  $first = function_exists('mb_strtoupper') ? mb_strtoupper($first) : strtoupper($first);
              }
          ?>
            <a class="card"
               href="<?= h($item['url']) ?>"
               data-idx="<?= $idx++ ?>"
               data-host="<?= h($host) ?>"
               data-h="<?= h($h_st) ?>"
               data-init="<?= h($first) ?>"
               data-search="<?= h(strtolower(($item['name'] ?? '') . ' ' . ($item['desc'] ?? '') . ' ' . ($item['category'] ?? '') . ' ' . $host)) ?>">
              <div class="ic <?= $info['type'] === 'initials' ? 'initials' : '' ?>">
                <?php if ($info['type'] === 'favicon'): ?>
                  <img src="<?= h($info['value']) ?>" alt="" loading="lazy">
                <?php else: ?>
                  <?= h($info['value']) ?>
                <?php endif; ?>
              </div>
              <div class="body">
                <div class="name">
                  <span class="dot <?= h($h_st) ?>" data-health title="status: <?= h($h_st) ?>"></span>
                  <span><?= h($item['name']) ?></span>
                </div>
                <?php if (!empty($item['desc'])): ?>
                  <div class="desc"><?= h($item['desc']) ?></div>
                <?php endif; ?>
                <div class="host-row">
                  <span class="host"><?= h($host) ?></span>
                  <button class="copy-btn" type="button" data-copy="<?= h($host) ?>" title="Copy hostname" aria-label="Copy hostname">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                  </button>
                </div>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endforeach; ?>
    </main>

    <div id="empty" class="empty" style="display:none;">No links match your search.</div>

    <div class="hint">
      <span><kbd>/</kbd> search</span>
      <span><kbd>Ctrl</kbd>+<kbd>K</kbd> launcher</span>
      <span><kbd>j</kbd> / <kbd>k</kbd> next / prev</span>
      <span><kbd>↵</kbd> open</span>
      <span><kbd>⇧</kbd>+<kbd>↵</kbd> new tab</span>
      <span><kbd>Esc</kbd> clear</span>
    </div>
    <?php endif; /* !$showLogin */ ?>

  <?php endif; ?>
</div>

<?php if ($showLogin): ?>
  <div class="modal-bg" id="loginModal">
    <form class="modal" method="post" autocomplete="off" id="loginForm">
      <h3>Edit mode</h3>
      <p>Enter the edit password.</p>
      <input type="hidden" name="action" value="login">
      <input type="password" name="password" id="loginPw" placeholder="Password" autofocus required>
      <div class="modal-actions">
        <a class="btn btn-ghost" href="<?= h(strtok($_SERVER['REQUEST_URI'], '?')) ?>">Cancel</a>
        <button class="btn btn-primary" type="submit">Unlock</button>
      </div>
    </form>
  </div>
  <script>
    // Trap focus in the modal — otherwise other focusable elements grab it.
    (() => {
      const pw = document.getElementById('loginPw');
      const form = document.getElementById('loginForm');
      if (!pw || !form) return;
      const focus = () => pw.focus({ preventScroll: true });
      focus();
      requestAnimationFrame(focus);
      setTimeout(focus, 50);
      setTimeout(focus, 250);
      document.addEventListener('focusin', (e) => {
        if (!form.contains(e.target)) pw.focus({ preventScroll: true });
      });
    })();
  </script>
<?php endif; ?>

<div class="toast" id="toast"></div>

<?php if (!$showLogin): ?>
<?php if (!empty($memes)): ?>
<script id="meme-data" type="application/json"><?= json_encode(['memes' => $memes, 'interval' => $memeInterval], JSON_UNESCAPED_UNICODE) ?></script>
<?php endif; ?>
<script type="module" src="<?= h(av('assets/app.js')) ?>"></script>
<?php endif; ?>
</body>
</html>
