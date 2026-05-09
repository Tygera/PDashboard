<?php
/**
 * Health check engine.
 *
 * page reads stale entries (>60s old or never checked) and triggers
 * a background fetch via /?action=health_refresh. That endpoint runs
 * curl_multi over the stale URLs and writes results back to the db.
 *
 * Status semantics:
 *   ok      — 2xx/3xx response
 *   warn    — 4xx response or slow (>2s)
 *   down    — 5xx, timeout, DNS failure, connection refused
 *   unknown — not checked yet
 */

declare(strict_types=1);

const HEALTH_TTL          = 60;     // seconds; consider checks stale after this
const HEALTH_TIMEOUT      = 4;
const HEALTH_SLOW_MS      = 2000;
const HEALTH_MAX_PARALLEL = 16;

function find_stale_links(): array {
    $links  = get_links();
    $health = get_health_map();
    $now    = time();
    $stale  = [];

    foreach ($links as $l) {
        $host = host_from($l['url']);
        $h    = $health[$host] ?? null;
        if (!$h || ($now - (int)$h['checked_at']) > HEALTH_TTL) {
            $stale[$host] = $l['url'];   // de-dupe by host
        }
    }
    return $stale;
}

/**
 * Run health checks for the given host=>url map.
 * Writes results to the db. Returns the same map keyed by host.
 */
function run_health_checks(array $hostUrls): array {
    if (!$hostUrls) return [];

    $multi = curl_multi_init();
    $handles = [];

    $i = 0;
    foreach ($hostUrls as $host => $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY          => true,           // HEAD first
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_MAXREDIRS       => 3,
            CURLOPT_CONNECTTIMEOUT  => HEALTH_TIMEOUT,
            CURLOPT_TIMEOUT         => HEALTH_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER  => false,
            CURLOPT_SSL_VERIFYHOST  => 0,
            CURLOPT_USERAGENT       => 'HomelabDashboard-HealthCheck/1.0',
        ]);
        $handles[$host] = $ch;
        curl_multi_add_handle($multi, $ch);

        // Throttle to avoid stampedes if you have a *lot* of links
        if (++$i % HEALTH_MAX_PARALLEL === 0) {
            drain_multi($multi);
        }
    }

    drain_multi($multi);

    $results = [];
    foreach ($handles as $host => $ch) {
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ms   = (int)round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
        $err  = curl_errno($ch);

        // Some servers reject HEAD with 405; retry once with GET if so
        if ($code === 405) {
            curl_setopt($ch, CURLOPT_NOBODY, false);
            curl_setopt($ch, CURLOPT_HTTPGET, true);
            curl_setopt($ch, CURLOPT_RANGE, '0-0');   // ask for one byte
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $ms   = (int)round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
            $err  = curl_errno($ch);
        }

        $status = classify_status($code, $ms, $err);
        set_health($host, $status, $code ?: null, $ms ?: null);
        $results[$host] = ['status' => $status, 'http_code' => $code, 'ms' => $ms];

        curl_multi_remove_handle($multi, $ch);
        curl_close($ch);
    }

    curl_multi_close($multi);
    return $results;
}

function classify_status(int $code, int $ms, int $err): string {
    if ($err !== 0)      return 'down';
    if ($code === 0)     return 'down';
    if ($code >= 500)    return 'down';
    if ($code >= 400)    return 'warn';
    if ($ms > HEALTH_SLOW_MS) return 'warn';
    return 'ok';
}

function drain_multi($multi): void {
    do {
        $status = curl_multi_exec($multi, $running);
        if ($running) curl_multi_select($multi, 1.0);
    } while ($running > 0 && $status === CURLM_OK);
}
