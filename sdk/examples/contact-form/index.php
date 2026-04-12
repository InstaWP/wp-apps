<?php
/**
 * WP Apps Contact Form
 *
 * Demonstrates the data-first integration model:
 * - Block for frontend UI (cached, zero page-load cost after first render)
 * - Form submission via the app's own endpoint
 * - App-side storage (JSON file)
 * - Admin panel for viewing submissions (iframe surface)
 *
 * NO the_content filters. NO render-path HTTP calls.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use WPApps\SDK\App;
use WPApps\SDK\Request;
use WPApps\SDK\Response;

$app = new App(__DIR__ . '/wp-app.json');

// ─── Storage (JSON file) ────────────────────────────────────────
$dataFile = __DIR__ . '/data/submissions.json';

function loadSubmissions(string $file): array {
    if (!file_exists($file)) return [];
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function saveSubmissions(string $file, array $submissions): void {
    file_put_contents($file, json_encode($submissions, JSON_PRETTY_PRINT), LOCK_EX);
}

function addSubmission(string $file, array $entry): int {
    $submissions = loadSubmissions($file);
    $entry['id'] = count($submissions) > 0 ? max(array_column($submissions, 'id')) + 1 : 1;
    $entry['status'] = 'unread';
    $entry['submitted_at'] = gmdate('Y-m-d H:i:s');
    $submissions[] = $entry;
    saveSubmissions($file, $submissions);
    return $entry['id'];
}

// ─── Block: Contact Form (Tier 1 — cached, zero page-load cost) ─
$app->onBlock('wpapps/contact-form', function (Request $req): Response {
    $appEndpoint = 'https://contact-form-app.nbg1-2.instapods.app';

    $html = <<<HTML
<div id="wpapps-contact-form" style="max-width:560px;margin:2rem 0;">
    <form id="wpapps-cf" style="display:flex;flex-direction:column;gap:1rem;">
        <div>
            <label for="wpapps-cf-name" style="display:block;font-weight:600;margin-bottom:4px;font-size:14px;">Name</label>
            <input type="text" id="wpapps-cf-name" name="name" required
                   style="width:100%;padding:10px 12px;border:1px solid #ccc;border-radius:6px;font-size:15px;font-family:inherit;">
        </div>
        <div>
            <label for="wpapps-cf-email" style="display:block;font-weight:600;margin-bottom:4px;font-size:14px;">Email</label>
            <input type="email" id="wpapps-cf-email" name="email" required
                   style="width:100%;padding:10px 12px;border:1px solid #ccc;border-radius:6px;font-size:15px;font-family:inherit;">
        </div>
        <div>
            <label for="wpapps-cf-message" style="display:block;font-weight:600;margin-bottom:4px;font-size:14px;">Message</label>
            <textarea id="wpapps-cf-message" name="message" rows="5" required
                      style="width:100%;padding:10px 12px;border:1px solid #ccc;border-radius:6px;font-size:15px;font-family:inherit;resize:vertical;"></textarea>
        </div>
        <button type="submit" id="wpapps-cf-submit"
                style="align-self:flex-start;padding:10px 28px;background:#0073aa;color:#fff;border:none;border-radius:6px;font-size:15px;font-weight:600;cursor:pointer;font-family:inherit;">
            Send Message
        </button>
    </form>
    <div id="wpapps-cf-status" style="margin-top:1rem;display:none;padding:12px 16px;border-radius:6px;font-size:14px;"></div>
</div>
<script>
(function() {
    var form = document.getElementById('wpapps-cf');
    var statusEl = document.getElementById('wpapps-cf-status');
    var submitBtn = document.getElementById('wpapps-cf-submit');

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        submitBtn.disabled = true;
        submitBtn.textContent = 'Sending...';
        statusEl.style.display = 'none';

        var data = {
            name: document.getElementById('wpapps-cf-name').value,
            email: document.getElementById('wpapps-cf-email').value,
            message: document.getElementById('wpapps-cf-message').value,
            page_url: window.location.href
        };

        fetch('{$appEndpoint}/submit', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(data)
        })
        .then(function(r) { return r.json(); })
        .then(function(resp) {
            if (resp.status === 'ok') {
                statusEl.style.display = 'block';
                statusEl.style.background = '#edfaef';
                statusEl.style.color = '#0a5c1f';
                statusEl.style.border = '1px solid #b8e6c4';
                statusEl.textContent = 'Thank you! Your message has been sent.';
                form.reset();
            } else {
                throw new Error(resp.error || 'Submission failed');
            }
        })
        .catch(function(err) {
            statusEl.style.display = 'block';
            statusEl.style.background = '#fef0f0';
            statusEl.style.color = '#8a1f1f';
            statusEl.style.border = '1px solid #f0c4c4';
            statusEl.textContent = 'Error: ' + err.message;
        })
        .finally(function() {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Send Message';
        });
    });
})();
</script>
HTML;

    return Response::block($html);
});

// ─── Health check ───────────────────────────────────────────────
$app->onHealth(function () use ($dataFile): Response {
    $count = count(loadSubmissions($dataFile));
    return Response::json([
        'status' => 'healthy',
        'version' => '1.0.0',
        'app' => 'WP Apps Contact Form',
        'total_submissions' => $count,
    ]);
});

// ─── Custom Routes ──────────────────────────────────────────────

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

// Handle CORS preflight
if ($method === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(204);
    exit;
}

// Handle form submission
if ($path === '/submit' && $method === 'POST') {
    header('Access-Control-Allow-Origin: *');
    header('Content-Type: application/json');

    $body = json_decode(file_get_contents('php://input'), true);

    $errors = [];
    if (empty($body['name']) || strlen($body['name']) > 255) {
        $errors[] = 'Name is required (max 255 characters).';
    }
    if (empty($body['email']) || !filter_var($body['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid email address is required.';
    }
    if (empty($body['message']) || strlen($body['message']) > 5000) {
        $errors[] = 'Message is required (max 5000 characters).';
    }

    if ($errors) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'error' => implode(' ', $errors)]);
        exit;
    }

    $id = addSubmission($dataFile, [
        'name' => htmlspecialchars($body['name'], ENT_QUOTES, 'UTF-8'),
        'email' => $body['email'],
        'message' => htmlspecialchars($body['message'], ENT_QUOTES, 'UTF-8'),
        'page_url' => htmlspecialchars($body['page_url'] ?? '', ENT_QUOTES, 'UTF-8'),
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);

    echo json_encode(['status' => 'ok', 'id' => $id]);
    exit;
}

// Admin panel — submissions list
if ($path === '/admin' || $path === '/admin/') {
    header('Content-Type: text/html; charset=utf-8');

    $allSubmissions = loadSubmissions($dataFile);
    $total = count($allSubmissions);

    usort($allSubmissions, fn($a, $b) => ($b['submitted_at'] ?? '') <=> ($a['submitted_at'] ?? ''));

    $page = max(1, (int) ($_GET['pg'] ?? 1));
    $perPage = 20;
    $submissions = array_slice($allSubmissions, ($page - 1) * $perPage, $perPage);

    $unread = count(array_filter($allSubmissions, fn($s) => ($s['status'] ?? '') === 'unread'));

    // Mark viewed as read
    $changed = false;
    foreach ($allSubmissions as &$s) {
        if (($s['status'] ?? '') === 'unread') {
            $s['status'] = 'read';
            $changed = true;
        }
    }
    unset($s);
    if ($changed) saveSubmissions($dataFile, $allSubmissions);

    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Contact Submissions</title>';
    echo '<style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",system-ui,sans-serif; background:#f0f0f1; padding:20px; color:#1d2327; }
        h1 { font-size:20px; font-weight:600; margin-bottom:16px; }
        .stats { display:flex; gap:12px; margin-bottom:20px; }
        .stat { background:#fff; padding:16px 20px; border-radius:8px; border:1px solid #ddd; flex:1; }
        .stat-num { font-size:28px; font-weight:700; color:#0073aa; }
        .stat-label { font-size:12px; color:#666; text-transform:uppercase; letter-spacing:0.05em; margin-top:2px; }
        table { width:100%; border-collapse:collapse; background:#fff; border-radius:8px; overflow:hidden; border:1px solid #ddd; }
        th { text-align:left; padding:10px 16px; font-size:12px; text-transform:uppercase; letter-spacing:0.05em; color:#666; background:#f9f9f9; border-bottom:2px solid #ddd; }
        td { padding:12px 16px; border-bottom:1px solid #f0f0f0; font-size:14px; vertical-align:top; }
        tr:hover { background:#f9f9f9; }
        .msg { max-width:400px; white-space:pre-wrap; word-break:break-word; }
        .time { color:#999; font-size:12px; white-space:nowrap; }
        .empty { text-align:center; padding:3rem; color:#999; }
        .badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:600; }
        .badge-unread { background:#e5f5ff; color:#0073aa; }
    </style>';
    echo '</head><body>';

    echo '<h1>Contact Submissions</h1>';
    echo '<div class="stats">';
    echo '<div class="stat"><div class="stat-num">' . $total . '</div><div class="stat-label">Total</div></div>';
    echo '<div class="stat"><div class="stat-num">' . $unread . '</div><div class="stat-label">Unread</div></div>';
    echo '</div>';

    if (empty($submissions)) {
        echo '<div class="empty"><p>No submissions yet.</p></div>';
    } else {
        echo '<table><thead><tr><th>Name</th><th>Email</th><th>Message</th><th>Page</th><th>Date</th></tr></thead><tbody>';
        foreach ($submissions as $s) {
            $badge = ($s['status'] ?? '') === 'unread' ? '<span class="badge badge-unread">new</span> ' : '';
            echo '<tr>';
            echo '<td>' . $badge . htmlspecialchars($s['name']) . '</td>';
            echo '<td><a href="mailto:' . htmlspecialchars($s['email']) . '">' . htmlspecialchars($s['email']) . '</a></td>';
            echo '<td class="msg">' . htmlspecialchars($s['message']) . '</td>';
            echo '<td class="time">' . htmlspecialchars(parse_url($s['page_url'] ?? '', PHP_URL_PATH) ?: '-') . '</td>';
            echo '<td class="time">' . htmlspecialchars($s['submitted_at']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    echo '</body></html>';
    exit;
}

// Fall through to SDK router for /hooks, /health, /auth/callback, /surfaces/*
$app->run();
