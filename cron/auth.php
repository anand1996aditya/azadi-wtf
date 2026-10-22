<?php
/**
 * azadi.wtf — shared cron authentication.
 *
 * The cron endpoints and the full health report must never take the secret from
 * the URL. A secret in a query string is written to the web server access log,
 * proxy logs and browser history, where anyone who can read the log can replay
 * it. Send the secret in a request header instead:
 *
 *   curl -H "Authorization: Bearer $AZADI_CRON_SECRET" https://azadi.wtf/cron/health.php
 *   curl -H "X-Cron-Secret: $AZADI_CRON_SECRET" https://azadi.wtf/cron/health.php
 *
 * Hostinger cron runs through the CLI (php cron/run.php). A CLI process is
 * already trusted and needs no secret, so CLI behaviour is unchanged.
 *
 * Rotation (do this after any suspected leak):
 *   1. Generate a new value:  php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
 *   2. Put it in config.php as CRON_SECRET.
 *   3. Update the GitHub repository secret AZADI_CRON_SECRET.
 *   4. Update cron/keys.json on the operator machine.
 *   5. Re-run the next deploy or cron call and confirm it still authenticates.
 */

// This file only defines helpers. A direct web hit must not run anything.
if (PHP_SAPI !== 'cli' && basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    die('Forbidden');
}

/**
 * Return one request header value, or null when the header is absent.
 *
 * Apache, PHP-FPM and the PHP built-in server expose request headers through
 * different server variables, so check all of the known routes.
 */
function azadi_cron_header_value($name) {
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
        return $_SERVER[$key];
    }
    if (isset($_SERVER['REDIRECT_' . $key]) && $_SERVER['REDIRECT_' . $key] !== '') {
        return $_SERVER['REDIRECT_' . $key];
    }
    $allHeaders = null;
    if (function_exists('getallheaders')) {
        $allHeaders = getallheaders();
    } elseif (function_exists('apache_request_headers')) {
        $allHeaders = apache_request_headers();
    }
    if (is_array($allHeaders)) {
        foreach ($allHeaders as $headerName => $headerValue) {
            if (strcasecmp((string)$headerName, (string)$name) === 0) {
                return (string)$headerValue;
            }
        }
    }
    return null;
}

/**
 * Secret presented by this request, or '' when none was supplied.
 *
 * @param bool $allowLegacyPost Accept the old $_POST['secret'] field. This is a
 *                              one-release transition fallback for cron/push.php
 *                              so a deploy keeps working while older callers
 *                              catch up. It is never accepted from the URL.
 */
function azadi_cron_provided_secret($allowLegacyPost = false) {
    $authorization = azadi_cron_header_value('Authorization');
    if (is_string($authorization) && preg_match('/^\s*Bearer\s+(.+?)\s*$/i', $authorization, $matches)) {
        if ($matches[1] !== '') {
            return $matches[1];
        }
    }

    $cronHeader = azadi_cron_header_value('X-Cron-Secret');
    if (is_string($cronHeader) && trim($cronHeader) !== '') {
        return trim($cronHeader);
    }

    if ($allowLegacyPost && isset($_POST['secret']) && is_string($_POST['secret']) && $_POST['secret'] !== '') {
        return $_POST['secret'];
    }

    return '';
}

/**
 * True when the request carries the configured CRON_SECRET.
 *
 * The comparison is constant time. A missing, empty or undefined CRON_SECRET
 * always fails closed.
 */
function azadi_cron_is_authorized($allowLegacyPost = false) {
    if (!defined('CRON_SECRET') || !is_string(CRON_SECRET) || CRON_SECRET === '') {
        return false;
    }
    $provided = azadi_cron_provided_secret($allowLegacyPost);
    if ($provided === '') {
        return false;
    }
    return hash_equals(CRON_SECRET, $provided);
}

/**
 * Stop the request with 403 unless it carries the configured CRON_SECRET.
 */
function azadi_cron_require_auth($allowLegacyPost = false) {
    if (!azadi_cron_is_authorized($allowLegacyPost)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        die('Unauthorized');
    }
}
