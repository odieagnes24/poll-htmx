<?php
declare(strict_types=1);

/**
 * Poll App
 *
 * Single-file PHP app backed by Postgres (Neon). Polls, options and votes all
 * live in the database; the only browser state is a random voter cookie used
 * to enforce one vote per visitor per poll.
 */

/* -------------------------------------------------------------------------
 * Configuration
 * ---------------------------------------------------------------------- */

/**
 * Read DATABASE_URL. An environment variable wins (useful for deployment),
 * otherwise .env.local, otherwise .env.
 */
function database_dsn(): string
{
    $fromEnv = getenv('DATABASE_URL');
    if (is_string($fromEnv) && trim($fromEnv) !== '') {
        return trim($fromEnv);
    }

    foreach ([__DIR__ . '/.env.local', __DIR__ . '/.env'] as $file) {
        if (!is_file($file) || !is_readable($file)) {
            continue;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            continue;
        }

        foreach ($lines as $line) {
            if (preg_match('/^\s*DATABASE_URL\s*=\s*(.*)$/', $line, $match) === 1) {
                $value = trim(trim($match[1]), "\"'");
                if ($value !== '') {
                    return $value;
                }
            }
        }
    }

    throw new RuntimeException('DATABASE_URL is not configured.');
}

/**
 * PDO accepts a pgsql: DSN directly. Also tolerate postgres:// style URLs.
 */
function connect_database(): PDO
{
    $dsn = database_dsn();
    if (preg_match('~^postgres(?:ql)?://~', $dsn) === 1) {
        $dsn = postgres_url_to_dsn($dsn);
    }

    return new PDO($dsn, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 15,
    ]);
}

function postgres_url_to_dsn(string $url): string
{
    $parts = parse_url($url);
    if ($parts === false || !isset($parts['host'])) {
        return $url;
    }

    $dsn = 'pgsql:host=' . $parts['host'];
    if (isset($parts['port'])) {
        $dsn .= ';port=' . $parts['port'];
    }
    if (isset($parts['path']) && $parts['path'] !== '/') {
        $dsn .= ';dbname=' . ltrim($parts['path'], '/');
    }
    if (isset($parts['user'])) {
        $dsn .= ';user=' . rawurldecode($parts['user']);
    }
    if (isset($parts['pass'])) {
        $dsn .= ';password=' . rawurldecode($parts['pass']);
    }
    if (isset($parts['query'])) {
        parse_str($parts['query'], $query);
        foreach (['sslmode', 'channel_binding', 'connect_timeout', 'application_name'] as $key) {
            if (isset($query[$key]) && is_string($query[$key])) {
                $dsn .= ';' . $key . '=' . $query[$key];
            }
        }
    }

    return $dsn;
}

/* -------------------------------------------------------------------------
 * Small helpers
 * ---------------------------------------------------------------------- */

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    $forwarded = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
    if (is_string($forwarded) && $forwarded !== '') {
        return strtolower(trim(explode(',', $forwarded)[0])) === 'https';
    }

    // Vercel terminates TLS at the edge and always serves the deployment
    // over HTTPS, even when the forwarded header is not visible.
    if (getenv('VERCEL') !== false) {
        return true;
    }

    return false;
}

function base_url(): string
{
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    if (!is_string($host) || preg_match('/\A[A-Za-z0-9.\-:\[\]]+\z/', $host) !== 1) {
        $host = 'localhost';
    }

    $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    if (!is_string($script) || $script === '') {
        $script = '/index.php';
    }
    $script = preg_replace('/[\x00-\x1F\x7F]/', '', $script) ?? '/index.php';

    return (is_https() ? 'https' : 'http') . '://' . $host . $script;
}

function poll_url(string $slug): string
{
    return base_url() . '?p=' . rawurlencode($slug);
}

function percentage(int $votes, int $total): float
{
    return $total > 0 ? round(($votes / $total) * 100, 2) : 0.0;
}

function format_percentage(float $percentage): string
{
    $value = number_format($percentage, 1, '.', '');
    return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
}

function tally_total(array $rows): int
{
    $total = 0;
    foreach ($rows as $row) {
        $total += (int) $row['votes'];
    }

    return $total;
}

function request_is_htmx(): bool
{
    return ($_SERVER['HTTP_HX_REQUEST'] ?? '') === 'true';
}

function uuid_v4(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);

    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-'
        . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
}

function normalize_uuid(string $value): ?string
{
    $value = strtolower(trim($value));

    if (preg_match('/\A[0-9a-f]{32}\z/', $value) === 1) {
        return substr($value, 0, 8) . '-' . substr($value, 8, 4) . '-' . substr($value, 12, 4) . '-'
            . substr($value, 16, 4) . '-' . substr($value, 20, 12);
    }

    if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $value) === 1) {
        return $value;
    }

    return null;
}

function voter_token(): string
{
    $current = $_COOKIE['poll_voter'] ?? null;
    if (is_string($current)) {
        $existing = normalize_uuid($current);
        if ($existing !== null) {
            return $existing;
        }
    }

    $token = uuid_v4();
    setcookie('poll_voter', $token, [
        'expires' => time() + 60 * 60 * 24 * 730,
        'path' => '/',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE['poll_voter'] = $token;

    return $token;
}

function respond_html(string $html, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo $html;
}

function respond_json(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
}

function redirect(string $url): never
{
    header('Location: ' . $url, true, 303);
    exit;
}

/* -------------------------------------------------------------------------
 * Database
 * ---------------------------------------------------------------------- */

final class PollStore
{
    public function __construct(private readonly PDO $pdo)
    {
        $this->migrate();
    }

    private function migrate(): void
    {
        static $ready = false;
        if ($ready) {
            return;
        }

        // On serverless platforms every request is a fresh process; skip the
        // DDL once the schema is in place instead of taking locks each time.
        try {
            $check = $this->pdo->query(<<<'SQL'
SELECT
    (EXISTS (
        SELECT 1 FROM information_schema.columns
         WHERE table_schema = current_schema()
           AND table_name = 'options'
           AND column_name = 'position'
    ))::int AS has_position,
    (EXISTS (
        SELECT 1 FROM pg_indexes
         WHERE schemaname = current_schema()
           AND indexname = 'votes_poll_visitor_key'
    ))::int AS has_vote_index
SQL);
            $state = $check->fetch();
            $check->closeCursor();

            if (
                is_array($state)
                && (int) ($state['has_position'] ?? 0) === 1
                && (int) ($state['has_vote_index'] ?? 0) === 1
            ) {
                $ready = true;
                return;
            }
        } catch (Throwable) {
            // Fall through to the idempotent migration below.
        }

        $this->pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS polls (
    id UUID PRIMARY KEY,
    question TEXT NOT NULL,
    link UUID NOT NULL UNIQUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS options (
    id UUID PRIMARY KEY,
    poll_id UUID NOT NULL REFERENCES polls(id) ON DELETE CASCADE,
    text TEXT NOT NULL,
    position INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS votes (
    id UUID PRIMARY KEY,
    poll_id UUID NOT NULL REFERENCES polls(id) ON DELETE CASCADE,
    option_id UUID NOT NULL REFERENCES options(id) ON DELETE CASCADE,
    visitor_id UUID NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

ALTER TABLE options ADD COLUMN IF NOT EXISTS position INTEGER NOT NULL DEFAULT 0;

CREATE INDEX IF NOT EXISTS options_poll_idx ON options (poll_id);
CREATE INDEX IF NOT EXISTS votes_tally_idx ON votes (poll_id, option_id);
CREATE UNIQUE INDEX IF NOT EXISTS votes_poll_visitor_key ON votes (poll_id, visitor_id);
SQL);

        $ready = true;
    }

    public function findBySlug(string $slug): ?array
    {
        $link = normalize_uuid($slug);
        if ($link === null) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT id, link, question FROM polls WHERE link = ?');
        $stmt->execute([$link]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return [
            'id' => (string) $row['id'],
            'slug' => (string) $row['link'],
            'question' => (string) $row['question'],
        ];
    }

    /**
     * @param list<string> $labels
     * @return array{id: string, slug: string}
     */
    public function createPoll(string $question, array $labels): array
    {
        $this->pdo->beginTransaction();

        try {
            $pollId = uuid_v4();
            $link = uuid_v4();

            $stmt = $this->pdo->prepare('INSERT INTO polls (id, question, link) VALUES (?, ?, ?)');
            $stmt->execute([$pollId, $question, $link]);

            $insert = $this->pdo->prepare(
                'INSERT INTO options (id, poll_id, text, position) VALUES (?, ?, ?, ?)'
            );
            foreach (array_values($labels) as $position => $label) {
                $insert->execute([uuid_v4(), $pollId, $label, $position]);
            }

            $this->pdo->commit();

            return ['id' => $pollId, 'slug' => $link];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /**
     * @return list<array{id: string, label: string, votes: int}>
     */
    public function tally(string $pollId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT o.id, o.text AS label, COUNT(v.id) AS votes
               FROM options o
               LEFT JOIN votes v ON v.option_id = o.id AND v.poll_id = o.poll_id
              WHERE o.poll_id = ?
              GROUP BY o.id, o.text, o.position
              ORDER BY o.position, o.id'
        );
        $stmt->execute([$pollId]);

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = [
                'id' => (string) $row['id'],
                'label' => (string) $row['label'],
                'votes' => (int) $row['votes'],
            ];
        }

        return $rows;
    }

    public function hasVoted(string $pollId, string $voter): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM votes WHERE poll_id = ? AND visitor_id = ? LIMIT 1');
        $stmt->execute([$pollId, $voter]);
        $found = $stmt->fetchColumn() !== false;
        $stmt->closeCursor();

        return $found;
    }

    /**
     * @return 'ok'|'duplicate'|'invalid'
     */
    public function castVote(string $pollId, string $optionId, string $voter): string
    {
        $owns = $this->pdo->prepare('SELECT 1 FROM options WHERE id = ? AND poll_id = ?');
        $owns->execute([$optionId, $pollId]);
        $belongs = $owns->fetchColumn() !== false;
        $owns->closeCursor();

        if (!$belongs) {
            return 'invalid';
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO votes (id, poll_id, option_id, visitor_id) VALUES (?, ?, ?, ?)
             ON CONFLICT (poll_id, visitor_id) DO NOTHING'
        );
        $stmt->execute([uuid_v4(), $pollId, $optionId, $voter]);

        return $stmt->rowCount() > 0 ? 'ok' : 'duplicate';
    }
}

/* -------------------------------------------------------------------------
 * Rendering
 * ---------------------------------------------------------------------- */

function render_page(string $title, string $body): string
{
    $titleEsc = e($title);

    return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<title>{$titleEsc}</title>
<script src="https://unpkg.com/htmx.org@2.0.4/dist/htmx.min.js" defer></script>
<style>
:root {
    --bg: #f4f6fb;
    --card: #ffffff;
    --ink: #101828;
    --muted: #667085;
    --line: #e4e7ec;
    --accent: #4f46e5;
    --accent-hover: #4338ca;
    --accent-soft: #eef2ff;
    --danger: #b42318;
    --radius: 18px;
    --shadow: 0 18px 50px -22px rgba(16, 24, 40, .28), 0 2px 6px rgba(16, 24, 40, .05);
}
*, *::before, *::after { box-sizing: border-box; }
html { -webkit-text-size-adjust: 100%; }
body {
    margin: 0;
    min-height: 100vh;
    background:
        radial-gradient(1200px 600px at 10% -10%, #e8edff 0%, transparent 60%),
        radial-gradient(900px 500px at 110% 10%, #eef7ff 0%, transparent 55%),
        var(--bg);
    color: var(--ink);
    font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    font-size: 16px;
    line-height: 1.5;
    -webkit-font-smoothing: antialiased;
}
.page { max-width: 680px; margin: 0 auto; padding: clamp(24px, 5vw, 56px) 16px 64px; }
.card {
    background: var(--card);
    border: 1px solid rgba(16, 24, 40, .06);
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    padding: clamp(20px, 4vw, 32px);
}
h1, h2 { line-height: 1.2; letter-spacing: -.02em; margin: 0; }
h1 { font-size: clamp(1.6rem, 4vw, 2.1rem); }
h2 { font-size: 1.15rem; }
.subtitle { margin: 8px 0 24px; color: var(--muted); font-size: .98rem; }
.field { margin: 0 0 22px; }
.field > label, .field legend {
    display: block;
    font-weight: 650;
    font-size: .92rem;
    margin-bottom: 8px;
    padding: 0;
}
fieldset.field { border: 0; padding: 0; margin: 0 0 22px; min-width: 0; }
input[type="text"] {
    width: 100%;
    padding: 12px 14px;
    border: 1px solid var(--line);
    border-radius: 12px;
    font: inherit;
    color: var(--ink);
    background: #fff;
    outline: none;
    transition: border-color .15s, box-shadow .15s;
}
input[type="text"]::placeholder { color: #98a2b3; }
input[type="text"]:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 4px rgba(79, 70, 229, .14);
}
input[type="text"][aria-invalid="true"] { border-color: var(--danger); }
.field-error {
    color: var(--danger);
    font-size: .85rem;
    margin: 6px 0 0;
    min-height: 1.2em;
}
.field-error:empty { display: none; }
#options-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px; }
.option-row { display: flex; gap: 10px; align-items: flex-start; }
.option-main { flex: 1; min-width: 0; }
.remove-option {
    flex: 0 0 auto;
    width: 44px;
    height: 44px;
    margin-top: 1px;
    border: 1px solid var(--line);
    border-radius: 12px;
    background: #fff;
    color: var(--muted);
    font-size: 1.35rem;
    line-height: 1;
    cursor: pointer;
    transition: background .15s, color .15s, border-color .15s;
}
.remove-option:hover:not(:disabled) { background: #fef3f2; color: var(--danger); border-color: #fecdca; }
.remove-option:disabled { opacity: .35; cursor: not-allowed; }
.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    border-radius: 12px;
    border: 1px solid transparent;
    font: inherit;
    font-weight: 650;
    cursor: pointer;
    padding: 12px 18px;
    transition: background .15s, color .15s, border-color .15s, box-shadow .15s, transform .05s;
}
.btn:active { transform: translateY(1px); }
.btn:disabled { opacity: .55; cursor: not-allowed; transform: none; }
.btn-primary {
    width: 100%;
    background: var(--accent);
    color: #fff;
    box-shadow: 0 8px 20px -8px rgba(79, 70, 229, .65);
}
.btn-primary:hover:not(:disabled) { background: var(--accent-hover); }
.btn-ghost {
    background: var(--accent-soft);
    color: var(--accent);
    border-color: #e0e7ff;
    padding: 9px 14px;
    font-size: .9rem;
}
.btn-ghost:hover:not(:disabled) { background: #e0e7ff; }
.btn-outline {
    background: #fff;
    border-color: var(--line);
    color: var(--ink);
    padding: 9px 14px;
    font-size: .9rem;
    white-space: nowrap;
}
.btn-outline:hover { background: #f9fafb; border-color: #d0d5dd; }
.poll-top {
    display: flex;
    gap: 16px;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 24px;
}
.eyebrow {
    text-transform: uppercase;
    letter-spacing: .12em;
    font-size: .7rem;
    font-weight: 700;
    color: var(--accent);
    margin: 0 0 6px;
}
.question { overflow-wrap: anywhere; }
.copy-wrap { display: flex; flex-direction: column; align-items: flex-end; gap: 6px; flex: 0 0 auto; }
.copied-note { font-size: .8rem; font-weight: 650; color: #067647; }
.vote-area { margin-top: 4px; }
.vote-hint { color: var(--muted); font-size: .9rem; margin: 0 0 10px; }
.vote-grid { display: flex; flex-direction: column; gap: 10px; }
.vote-btn {
    width: 100%;
    text-align: left;
    padding: 13px 16px;
    border: 1px solid var(--line);
    border-radius: 12px;
    background: #fff;
    font: inherit;
    font-weight: 600;
    color: var(--ink);
    cursor: pointer;
    overflow-wrap: anywhere;
    transition: background .15s, border-color .15s, box-shadow .15s;
}
.vote-btn:hover:not(:disabled) {
    background: var(--accent-soft);
    border-color: #c7d2fe;
    box-shadow: 0 4px 14px -8px rgba(79, 70, 229, .5);
}
.vote-btn:disabled { opacity: .6; cursor: progress; }
.notice {
    margin: 0;
    padding: 12px 14px;
    border-radius: 12px;
    background: #ecfdf3;
    border: 1px solid #d1fadf;
    color: #067647;
    font-size: .92rem;
    font-weight: 550;
}
.results-head {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    gap: 12px;
    margin: 28px 0 14px;
}
.results-head p { margin: 0; color: var(--muted); font-size: .9rem; }
.results-head strong { color: var(--ink); }
.results { display: flex; flex-direction: column; gap: 14px; }
.result-meta {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    align-items: baseline;
    font-size: .92rem;
    margin-bottom: 6px;
}
.result-label { font-weight: 600; overflow-wrap: anywhere; }
.result-numbers { color: var(--muted); font-variant-numeric: tabular-nums; white-space: nowrap; }
.bar-track { height: 10px; border-radius: 999px; background: #eef0f4; overflow: hidden; }
.bar-fill {
    height: 100%;
    width: 0;
    border-radius: inherit;
    background: linear-gradient(90deg, #6366f1, #4f46e5);
    transition: width .7s cubic-bezier(.22, .61, .36, 1);
}
.footer-link { margin: 28px 0 0; text-align: center; font-size: .85rem; }
.footer-link a { color: var(--muted); text-decoration: none; }
.footer-link a:hover { color: var(--accent); text-decoration: underline; }
.center-card { text-align: center; padding: 48px 28px; }
.center-card p { color: var(--muted); margin: 10px 0 22px; }
:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
@media (max-width: 520px) {
    .poll-top { flex-direction: column; }
    .copy-wrap { width: 100%; flex-direction: row; align-items: center; justify-content: space-between; }
    .results-head { flex-direction: column; align-items: flex-start; gap: 4px; }
}
@media (prefers-reduced-motion: reduce) {
    .bar-fill, .btn, .vote-btn, .remove-option { transition: none; }
}
</style>
</head>
<body>
{$body}
<script>
(function () {
    'use strict';

    var form = document.getElementById('create-form');
    if (form) {
        form.noValidate = true;

        var list = document.getElementById('options-list');
        var addButton = document.getElementById('add-option');
        var template = document.getElementById('option-template');
        var question = document.getElementById('question');
        var questionError = form.querySelector('[data-error-for="question"]');
        var countError = form.querySelector('[data-error-for="options-count"]');
        var submitButton = document.getElementById('create-btn');
        var MIN = 2;
        var MAX = 6;

        function rows() {
            return Array.prototype.slice.call(list.querySelectorAll('.option-row'));
        }

        function clearError(el) {
            if (el) el.textContent = '';
        }

        function sync() {
            var all = rows();
            if (addButton) addButton.disabled = all.length >= MAX;
            all.forEach(function (row, index) {
                var input = row.querySelector('input[name="options[]"]');
                var remove = row.querySelector('.remove-option');
                if (input) {
                    input.placeholder = 'Option ' + (index + 1);
                    input.setAttribute('aria-label', 'Option ' + (index + 1));
                }
                if (remove) {
                    remove.disabled = all.length <= MIN;
                    remove.setAttribute('aria-label', 'Remove option ' + (index + 1));
                }
            });
        }

        function bind(row) {
            var remove = row.querySelector('.remove-option');
            if (!remove) return;
            remove.addEventListener('click', function () {
                if (rows().length <= MIN) return;
                clearError(countError);
                row.remove();
                sync();
            });
        }

        function addRow(value) {
            if (!template || rows().length >= MAX) return;
            var node = template.content.firstElementChild.cloneNode(true);
            var input = node.querySelector('input');
            if (input) input.value = value || '';
            bind(node);
            list.appendChild(node);
            sync();
            if (input) input.focus();
        }

        rows().forEach(bind);
        sync();

        if (addButton) {
            addButton.addEventListener('click', function () {
                clearError(countError);
                addRow('');
            });
        }

        form.addEventListener('input', function (event) {
            var target = event.target;
            if (!target || !target.name) return;
            if (target.name === 'question') {
                clearError(questionError);
            } else if (target.name === 'options[]') {
                var row = target.closest('.option-row');
                if (row) clearError(row.querySelector('.field-error'));
            }
        });

        form.addEventListener('submit', function (event) {
            var valid = true;
            clearError(questionError);
            clearError(countError);

            var q = question ? question.value.trim() : '';
            if (q.length < 5 || q.length > 150) {
                if (questionError) questionError.textContent = 'Question must be 5 to 150 characters.';
                valid = false;
            }

            var all = rows();
            all.forEach(function (row) {
                var input = row.querySelector('input[name="options[]"]');
                var error = row.querySelector('.field-error');
                var value = input ? input.value.trim() : '';
                if (value.length < 1 || value.length > 80) {
                    if (error) error.textContent = 'Option must be 1 to 80 characters.';
                    valid = false;
                } else if (error) {
                    error.textContent = '';
                }
            });

            if (all.length < MIN || all.length > MAX) {
                if (countError) countError.textContent = 'Add between 2 and 6 options.';
                valid = false;
            }

            if (!valid) {
                event.preventDefault();
                var firstError = Array.prototype.slice.call(form.querySelectorAll('.field-error')).filter(function (el) {
                    return el.textContent.trim() !== '';
                })[0];
                if (firstError) {
                    var row = firstError.closest('.option-row');
                    var field = row ? row.querySelector('input') : question;
                    if (field) field.focus();
                }
                return;
            }

            if (submitButton) {
                submitButton.disabled = true;
                submitButton.textContent = 'Creating…';
            }
        });
    }

    var card = document.getElementById('poll-card');
    if (card) {
        var slug = card.getAttribute('data-slug') || '';
        var results = document.getElementById('results');
        var total = document.getElementById('total-votes');

        function formatPercent(value) {
            var number = Number(value);
            if (!isFinite(number)) number = 0;
            return String(Number(number.toFixed(1)));
        }

        function refresh() {
            if (!slug || document.hidden) return;
            fetch('?action=results&p=' + encodeURIComponent(slug), {
                headers: { 'Accept': 'application/json' },
                cache: 'no-store'
            }).then(function (response) {
                return response.ok ? response.json() : null;
            }).then(function (data) {
                if (!data) return;
                if (total) total.textContent = String(data.total);
                (data.options || []).forEach(function (option) {
                    if (!results) return;
                    var row = results.querySelector('[data-option-id="' + option.id + '"]');
                    if (!row) return;
                    var bar = row.querySelector('[data-bar]');
                    var count = row.querySelector('[data-count]');
                    var pct = row.querySelector('[data-pct]');
                    if (bar) bar.style.width = Number(option.pct).toFixed(2) + '%';
                    if (count) count.textContent = String(option.count);
                    if (pct) pct.textContent = formatPercent(option.pct) + '%';
                });
            }).catch(function () {});
        }

        window.setInterval(refresh, 2000);
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) refresh();
        });
        document.body.addEventListener('pollVoted', refresh);
    }

    var copyButton = document.getElementById('copy-btn');
    if (copyButton) {
        copyButton.addEventListener('click', function () {
            var url = copyButton.getAttribute('data-url') || '';
            var note = document.getElementById('copied-note');
            var done = function () {
                if (!note) return;
                note.hidden = false;
                window.clearTimeout(note.timer);
                note.timer = window.setTimeout(function () {
                    note.hidden = true;
                }, 1800);
            };
            var fallback = function () {
                var textarea = document.createElement('textarea');
                textarea.value = url;
                textarea.setAttribute('readonly', '');
                textarea.style.position = 'fixed';
                textarea.style.opacity = '0';
                document.body.appendChild(textarea);
                textarea.select();
                var ok = false;
                try {
                    ok = document.execCommand('copy');
                } catch (error) {
                    ok = false;
                }
                textarea.remove();
                if (ok) done();
            };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(url).then(done).catch(fallback);
            } else {
                fallback();
            }
        });
    }
})();
</script>
</body>
</html>
HTML;
}

function render_option_row(int $index, string $value, string $error, bool $canRemove): string
{
    $number = $index + 1;
    $valueEsc = e($value);
    $errorEsc = e($error);
    $disabled = $canRemove ? '' : ' disabled';

    return <<<HTML
<li class="option-row">
    <div class="option-main">
        <input type="text" name="options[]" value="{$valueEsc}" maxlength="80" placeholder="Option {$number}" aria-label="Option {$number}" autocomplete="off" required>
        <p class="field-error" aria-live="polite">{$errorEsc}</p>
    </div>
    <button type="button" class="remove-option" aria-label="Remove option {$number}"{$disabled}>&times;</button>
</li>
HTML;
}

function render_home(array $values, array $errors): string
{
    $question = is_string($values['question'] ?? null) ? $values['question'] : '';
    $options = $values['options'] ?? [];
    if (!is_array($options)) {
        $options = [];
    }
    $options = array_values($options);
    if (count($options) < 2) {
        $options = array_pad($options, 2, '');
    }

    $canRemove = count($options) > 2;
    $rows = '';
    foreach ($options as $index => $value) {
        $optionError = isset($errors['options'][$index]) ? (string) $errors['options'][$index] : '';
        $rows .= render_option_row($index, is_string($value) ? $value : '', $optionError, $canRemove);
    }

    $questionEsc = e($question);
    $questionError = e((string) ($errors['question'] ?? ''));
    $countError = e((string) ($errors['count'] ?? ''));
    $addDisabled = count($options) >= 6 ? ' disabled' : '';

    return <<<HTML
<main class="page">
    <section class="card">
        <h1>Create a poll</h1>
        <p class="subtitle">Ask one question, share the link, and watch the results update live.</p>

        <form id="create-form" method="post" action="?action=create">
            <div class="field">
                <label for="question">Question</label>
                <input id="question" name="question" type="text" value="{$questionEsc}" maxlength="150" minlength="5"
                       placeholder="What should we have for lunch?" autocomplete="off" required>
                <p class="field-error" data-error-for="question" aria-live="polite">{$questionError}</p>
            </div>

            <fieldset class="field">
                <legend>Options</legend>
                <ul id="options-list">{$rows}</ul>
                <p class="field-error" data-error-for="options-count" aria-live="polite">{$countError}</p>
                <button type="button" id="add-option" class="btn btn-ghost"{$addDisabled}>+ Add option</button>
            </fieldset>

            <button type="submit" id="create-btn" class="btn btn-primary">Create poll</button>
        </form>
    </section>

    <template id="option-template">
        <li class="option-row">
            <div class="option-main">
                <input type="text" name="options[]" maxlength="80" placeholder="Option" aria-label="Option" autocomplete="off" required>
                <p class="field-error" aria-live="polite"></p>
            </div>
            <button type="button" class="remove-option" aria-label="Remove option">&times;</button>
        </li>
    </template>
</main>
HTML;
}

function render_vote_area(string $slug, array $rows, bool $voted): string
{
    if ($voted) {
        return '<div id="vote-area"><p class="notice">You have voted. Results update automatically.</p></div>';
    }

    $url = '?action=vote&p=' . rawurlencode($slug);
    $urlEsc = e($url);
    $buttons = '';

    foreach ($rows as $row) {
        $optionId = (string) $row['id'];
        $optionValue = e($optionId);
        $optionUrl = rawurlencode($optionId);
        $label = e((string) $row['label']);
        $buttons .= <<<HTML
<button type="submit" name="option_id" value="{$optionValue}" class="vote-btn"
        hx-post="{$urlEsc}&amp;option_id={$optionUrl}"
        hx-target="#vote-area"
        hx-swap="outerHTML"
        hx-disabled-elt="this">{$label}</button>
HTML;
    }

    return <<<HTML
<div id="vote-area" class="vote-area">
    <p class="vote-hint">Choose one option:</p>
    <form method="post" action="{$urlEsc}">
        <div class="vote-grid">{$buttons}</div>
    </form>
</div>
HTML;
}

function render_results(array $rows, int $total): string
{
    $html = '';

    foreach ($rows as $row) {
        $optionId = e((string) $row['id']);
        $label = e((string) $row['label']);
        $votes = (int) $row['votes'];
        $percentage = percentage($votes, $total);
        $width = number_format($percentage, 2, '.', '');
        $display = format_percentage($percentage);

        $html .= <<<HTML
<div class="result" data-option-id="{$optionId}">
    <div class="result-meta">
        <span class="result-label">{$label}</span>
        <span class="result-numbers"><span data-count>{$votes}</span> &middot; <span data-pct>{$display}%</span></span>
    </div>
    <div class="bar-track"><div class="bar-fill" data-bar style="width:{$width}%"></div></div>
</div>
HTML;
    }

    return $html !== '' ? $html : '<p class="vote-hint">No options yet.</p>';
}

function render_poll(array $poll, array $rows, bool $voted): string
{
    $slugEsc = e((string) $poll['slug']);
    $question = e((string) $poll['question']);
    $total = tally_total($rows);
    $voteArea = render_vote_area((string) $poll['slug'], $rows, $voted);
    $results = render_results($rows, $total);
    $shareUrl = e(poll_url((string) $poll['slug']));
    $homeUrl = e(base_url());

    return <<<HTML
<main class="page">
    <section class="card" id="poll-card" data-slug="{$slugEsc}">
        <div class="poll-top">
            <div>
                <p class="eyebrow">Poll</p>
                <h1 class="question">{$question}</h1>
            </div>
            <div class="copy-wrap">
                <button type="button" class="btn btn-outline" id="copy-btn" data-url="{$shareUrl}">Copy link</button>
                <span id="copied-note" class="copied-note" hidden>Copied!</span>
            </div>
        </div>

        {$voteArea}

        <div class="results-head">
            <h2>Results</h2>
            <p>Total votes: <strong id="total-votes">{$total}</strong></p>
        </div>
        <div id="results" class="results">{$results}</div>

        <p class="footer-link"><a href="{$homeUrl}">Create your own poll</a></p>
    </section>
</main>
HTML;
}

function render_not_found(): string
{
    $homeUrl = e(base_url());

    return <<<HTML
<main class="page">
    <section class="card center-card">
        <h1>Poll not found</h1>
        <p>This link does not point to a poll. It may have been mistyped or never existed.</p>
        <a class="btn btn-outline" href="{$homeUrl}">Create a poll</a>
    </section>
</main>
HTML;
}

/* -------------------------------------------------------------------------
 * Request handling
 * ---------------------------------------------------------------------- */

/**
 * @return array<string, mixed>
 */
function validate_poll_input(array $values): array
{
    $errors = [];

    $question = trim((string) ($values['question'] ?? ''));
    $length = mb_strlen($question);
    if ($length < 5 || $length > 150) {
        $errors['question'] = 'Question must be 5 to 150 characters.';
    }

    /** @var list<mixed> $options */
    $options = is_array($values['options'] ?? null) ? array_values($values['options']) : [];
    $count = count($options);
    if ($count < 2 || $count > 6) {
        $errors['count'] = 'Add between 2 and 6 options.';
    }

    foreach ($options as $index => $option) {
        $label = trim(is_string($option) ? $option : '');
        $optionLength = mb_strlen($label);
        if ($optionLength < 1 || $optionLength > 80) {
            $errors['options'][$index] = 'Option must be 1 to 80 characters.';
        }
    }

    return $errors;
}

function handle_home(): void
{
    respond_html(render_page(
        'Create a poll · Poll App',
        render_home(['question' => '', 'options' => ['', '']], [])
    ));
}

function handle_create(PollStore $store): void
{
    $rawQuestion = $_POST['question'] ?? '';
    $rawOptions = $_POST['options'] ?? [];

    $values = [
        'question' => is_string($rawQuestion) ? $rawQuestion : '',
        'options' => [],
    ];

    if (is_array($rawOptions)) {
        foreach (array_values($rawOptions) as $option) {
            $values['options'][] = is_string($option) ? $option : '';
        }
    }

    $errors = validate_poll_input($values);
    if ($errors !== []) {
        respond_html(render_page(
            'Create a poll · Poll App',
            render_home($values, $errors)
        ), 422);
        return;
    }

    $labels = array_map(
        static fn (mixed $option): string => trim((string) $option),
        $values['options']
    );
    $poll = $store->createPoll(trim((string) $values['question']), $labels);

    redirect(poll_url($poll['slug']));
}

function handle_poll(PollStore $store, string $slug): void
{
    $poll = $store->findBySlug($slug);
    if ($poll === null) {
        respond_html(render_page('Poll not found · Poll App', render_not_found()), 404);
        return;
    }

    $voter = voter_token();
    $rows = $store->tally($poll['id']);
    $voted = $store->hasVoted($poll['id'], $voter);

    respond_html(render_page(
        $poll['question'] . ' · Poll App',
        render_poll($poll, $rows, $voted)
    ));
}

function handle_results(PollStore $store, string $slug): void
{
    $poll = $store->findBySlug($slug);
    if ($poll === null) {
        respond_json(['error' => 'not_found'], 404);
        return;
    }

    $rows = $store->tally($poll['id']);
    $total = tally_total($rows);
    $options = [];

    foreach ($rows as $row) {
        $options[] = [
            'id' => $row['id'],
            'count' => $row['votes'],
            'pct' => percentage((int) $row['votes'], $total),
        ];
    }

    respond_json([
        'total' => $total,
        'options' => $options,
    ]);
}

function handle_vote(PollStore $store, string $slug): void
{
    $isHtmx = request_is_htmx();
    $poll = $store->findBySlug($slug);

    if ($poll === null) {
        if ($isHtmx) {
            respond_html('<div id="vote-area"><p class="notice">Poll not found.</p></div>', 404);
        } else {
            respond_html(render_page('Poll not found · Poll App', render_not_found()), 404);
        }
        return;
    }

    $rawOption = $_POST['option_id'] ?? ($_GET['option_id'] ?? null);
    $optionId = is_string($rawOption) ? normalize_uuid($rawOption) : null;

    $voter = voter_token();
    $outcome = $optionId !== null ? $store->castVote($poll['id'], $optionId, $voter) : 'invalid';

    if ($outcome === 'invalid') {
        if ($isHtmx) {
            respond_html('<div id="vote-area"><p class="notice">That option is not part of this poll.</p></div>', 422);
            return;
        }
        redirect(poll_url($poll['slug']));
    }

    if ($isHtmx) {
        header('HX-Trigger: pollVoted');
        respond_html('<div id="vote-area"><p class="notice">You have voted. Results update automatically.</p></div>');
        return;
    }

    redirect(poll_url($poll['slug']));
}

function run(): void
{
    $store = new PollStore(connect_database());

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $action = $_GET['action'] ?? '';
    $action = is_string($action) ? $action : '';
    $slug = $_GET['p'] ?? '';
    $slug = is_string($slug) ? $slug : '';

    if ($method === 'POST' && $action === 'create') {
        handle_create($store);
        return;
    }

    if ($method === 'POST' && $action === 'vote') {
        handle_vote($store, $slug);
        return;
    }

    if ($action === 'results') {
        handle_results($store, $slug);
        return;
    }

    if ($slug !== '') {
        handle_poll($store, $slug);
        return;
    }

    handle_home();
}

/* -------------------------------------------------------------------------
 * Boot
 * ---------------------------------------------------------------------- */

try {
    run();
} catch (Throwable $error) {
    error_log('[poll-app] ' . $error->getMessage() . ' in ' . $error->getFile() . ':' . $error->getLine());
    if (!headers_sent()) {
        respond_html(render_page('Poll App', <<<'HTML'
<main class="page">
    <section class="card center-card">
        <h1>Something went wrong</h1>
        <p>The poll app is temporarily unavailable. Please try again in a moment.</p>
    </section>
</main>
HTML), 500);
    }
}
