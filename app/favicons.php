<?php
/**
 * Favicon fetcher with on-disk cache.
 *
 * Strategy:
 *   1. Try <scheme>://<host>/favicon.ico
 *   2. Fall back to fetching the homepage and looking for <link rel="icon">
 *   3. Give up
 *
 * Cached at icons/<sha1(host)>.<ext>. Returns the relative path or null.
 *
 * Designed to fail gracefully — internal hosts that aren't reachable just
 * end up with no favicon, which is fine because we fall back to letters.
 */

declare(strict_types=1);

const ICON_DIR     = __DIR__ . '/icons';
const FAVICON_TTL  = 86400 * 7;          // re-attempt after a week
const FETCH_TIMEOUT = 4;                 // seconds; keep snappy

function ensure_icon_dir(): void {
    if (!is_dir(ICON_DIR)) @mkdir(ICON_DIR, 0775, true);
}

function favicon_cache_path(string $host): ?string {
    $hash = sha1($host);
    foreach (['png', 'ico', 'svg', 'jpg', 'jpeg', 'gif', 'webp'] as $ext) {
        $p = ICON_DIR . "/$hash.$ext";
        if (is_file($p)) return $p;
    }
    return null;
}

function favicon_relpath(string $host): ?string {
    $p = favicon_cache_path($host);
    if (!$p) return null;
    return 'icons/' . basename($p);
}

function favicon_age(string $host): int {
    $p = favicon_cache_path($host);
    return $p ? (time() - filemtime($p)) : PHP_INT_MAX;
}

/**
 * @return string|null  relative path on success, null on failure
 */
function fetch_favicon_for(string $url): ?string {
    ensure_icon_dir();
    $parts = parse_url($url);
    if (empty($parts['host'])) return null;
    $host   = $parts['host'];
    $port   = $parts['port'] ?? null;
    $scheme = $parts['scheme'] ?? 'https';
    $base   = $scheme . '://' . $host . ($port ? ":$port" : '');
    $hash   = sha1($host . ($port ? ":$port" : ''));

    // Step 1: direct /favicon.ico
    [$body, $ctype, $ok] = http_get($base . '/favicon.ico');
    if ($ok && $body !== '' && looks_like_image($body, $ctype)) {
        $ext = ext_for($ctype, $body, 'ico');
        return write_icon($hash, $ext, $body);
    }

    // Step 2: parse homepage for <link rel="icon" href="…">
    [$html, $ctype2, $ok2] = http_get($base . '/');
    if ($ok2 && $html !== '' && stripos((string)$ctype2, 'html') !== false) {
        $iconUrl = parse_icon_link($html);
        if ($iconUrl) {
            $abs = absolutize($base, $iconUrl);
            [$ibody, $ictype, $iok] = http_get($abs);
            if ($iok && looks_like_image($ibody, $ictype)) {
                $ext = ext_for($ictype, $ibody, 'png');
                return write_icon($hash, $ext, $ibody);
            }
        }
    }

    return null;
}

function http_get(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_FOLLOWLOCATION  => true,
        CURLOPT_MAXREDIRS       => 4,
        CURLOPT_CONNECTTIMEOUT  => FETCH_TIMEOUT,
        CURLOPT_TIMEOUT         => FETCH_TIMEOUT + 2,
        CURLOPT_SSL_VERIFYPEER  => false,    // homelab certs frequently self-signed
        CURLOPT_SSL_VERIFYHOST  => 0,
        CURLOPT_USERAGENT       => 'HomelabDashboard/1.0',
        CURLOPT_HEADER          => false,
    ]);
    $body  = curl_exec($ch);
    $ctype = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $ok = is_string($body) && $code >= 200 && $code < 400;
    return [is_string($body) ? $body : '', (string)$ctype, $ok];
}

function looks_like_image(string $body, string $ctype): bool {
    if ($body === '') return false;
    if (stripos($ctype, 'image/') === 0) return true;
    // Magic bytes — some servers send wrong content-type
    if (str_starts_with($body, "\x89PNG"))                return true;
    if (str_starts_with($body, "\x00\x00\x01\x00"))       return true;  // ICO
    if (str_starts_with($body, "GIF8"))                    return true;
    if (str_starts_with($body, "\xFF\xD8\xFF"))           return true;  // JPEG
    if (str_starts_with($body, "RIFF") && substr($body, 8, 4) === 'WEBP') return true;
    if (str_starts_with(ltrim($body), '<svg'))            return true;
    return false;
}

function ext_for(string $ctype, string $body, string $fallback): string {
    if (stripos($ctype, 'png')  !== false) return 'png';
    if (stripos($ctype, 'svg')  !== false) return 'svg';
    if (stripos($ctype, 'jpeg') !== false || stripos($ctype, 'jpg') !== false) return 'jpg';
    if (stripos($ctype, 'gif')  !== false) return 'gif';
    if (stripos($ctype, 'webp') !== false) return 'webp';
    if (stripos($ctype, 'icon') !== false || stripos($ctype, 'ico') !== false) return 'ico';
    if (str_starts_with($body, "\x89PNG"))         return 'png';
    if (str_starts_with($body, "\x00\x00\x01\x00")) return 'ico';
    if (str_starts_with(ltrim($body), '<svg'))     return 'svg';
    return $fallback;
}

function parse_icon_link(string $html): ?string {
    // Lazy regex; good enough for typical <link rel="icon"> markup
    if (preg_match_all('#<link[^>]+rel=["\']?[^"\'>]*icon[^"\'>]*["\']?[^>]*>#i', $html, $m)) {
        // Prefer larger ones if multiple — naive sort by sizes attribute
        $best = null; $bestSize = -1;
        foreach ($m[0] as $tag) {
            if (preg_match('#href=["\']([^"\']+)["\']#i', $tag, $h)) {
                $size = 0;
                if (preg_match('#sizes=["\'](\d+)#i', $tag, $s)) $size = (int)$s[1];
                if ($size > $bestSize) { $best = $h[1]; $bestSize = $size; }
            }
        }
        return $best;
    }
    return null;
}

function absolutize(string $base, string $href): string {
    if (preg_match('#^https?://#i', $href)) return $href;
    if (str_starts_with($href, '//')) {
        $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';
        return "$scheme:$href";
    }
    if (str_starts_with($href, '/')) return rtrim($base, '/') . $href;
    return rtrim($base, '/') . '/' . ltrim($href, '/');
}

function write_icon(string $hash, string $ext, string $body): string {
    // Clean up any older variants for this host first
    foreach (glob(ICON_DIR . "/$hash.*") ?: [] as $old) @unlink($old);
    $path = ICON_DIR . "/$hash.$ext";
    file_put_contents($path, $body);
    return 'icons/' . basename($path);
}

function delete_favicon(string $host): void {
    $hash = sha1($host);
    foreach (glob(ICON_DIR . "/$hash.*") ?: [] as $old) @unlink($old);
}
