<?php
/**
 * Daily commit: create date branch with file, open PR to main, merge previous day's open PRs.
 * Loads GITHUB_REPO and GITHUB_TOKEN from .env. Run from CLI or cron.
 * PHP 7.x / 8.x compatible.
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "Run from CLI only.\n");
    exit(1);
}

$envPath = getenv('ENV_PATH');
if ($envPath === false || $envPath === '') {
    $envPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
}
if (!is_readable($envPath)) {
    fwrite(STDERR, "Missing or unreadable .env at: " . $envPath . "\n");
    exit(1);
}

$env = load_env($envPath);
$repo = isset($env['GITHUB_REPO']) ? trim($env['GITHUB_REPO']) : '';
$token = isset($env['GITHUB_TOKEN']) ? trim($env['GITHUB_TOKEN']) : '';
if ($repo === '' || $token === '') {
    fwrite(STDERR, "GITHUB_REPO and GITHUB_TOKEN must be set in .env\n");
    exit(1);
}

$tz = isset($env['TZ']) ? trim($env['TZ']) : 'UTC';
date_default_timezone_set($tz);
$today = date('Y-m-d');
$yesterdayStart = date('Y-m-d 00:00:00', strtotime('-1 day'));
$yesterdayEnd = date('Y-m-d 00:00:00');

$cloneDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'daily-commit-' . $today . '-' . getmypid();
$cloneUrl = 'https://' . $token . '@github.com/' . $repo . '.git';

if (!clone_repo($cloneUrl, $cloneDir)) {
    fwrite(STDERR, "Clone failed.\n");
    exit(1);
}

$branchName = 'daily/' . $today;
$random = (string) mt_rand(100000, 999999);
$filePath = 'daily' . DIRECTORY_SEPARATOR . $today . '.txt';
$fileContent = $today . ' ' . $random . "\n";
$commitMsg = 'Daily commit ' . $today . ' ' . $random;

if (!do_commit_and_push($cloneDir, $branchName, $filePath, $fileContent, $commitMsg)) {
    cleanup($cloneDir);
    fwrite(STDERR, "Commit or push failed.\n");
    exit(1);
}

$api = function ($method, $path, $body = null) use ($repo, $token) {
    return github_api($repo, $token, $method, $path, $body);
};

if ($api('POST', '/pulls', [
    'title' => 'Daily merge ' . $today,
    'head'  => $branchName,
    'base'  => 'main',
    'body'  => 'Auto PR for ' . $today,
]) === null) {
    cleanup($cloneDir);
    fwrite(STDERR, "Create PR failed.\n");
    exit(1);
}

$openPrs = $api('GET', '/pulls?state=open');
if (!is_array($openPrs)) {
    cleanup($cloneDir);
    fwrite(STDERR, "List PRs failed.\n");
    exit(1);
}

$yesterdayPrs = filter_prs_by_date($openPrs, $yesterdayStart, $yesterdayEnd);
foreach ($yesterdayPrs as $pr) {
    $num = isset($pr['number']) ? (int) $pr['number'] : 0;
    if ($num > 0) {
        $api('PUT', '/pulls/' . $num . '/merge', ['commit_title' => 'Merge PR #' . $num]);
    }
}

cleanup($cloneDir);
exit(0);

/**
 * Parse .env into associative array. No putenv.
 *
 * @param string $path
 * @return array<string, string>
 */
function load_env($path)
{
    $env = [];
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return $env;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $eq = strpos($line, '=');
        if ($eq === false) {
            continue;
        }
        $key = trim(substr($line, 0, $eq));
        $val = trim(substr($line, $eq + 1));
        if (strlen($val) > 1 && ($val[0] === '"' || $val[0] === "'")) {
            $val = substr($val, 1, -1);
        }
        if ($key !== '') {
            $env[$key] = $val;
        }
    }
    return $env;
}

/**
 * @param string $url
 * @param string $dir
 * @return bool
 */
function clone_repo($url, $dir)
{
    $dirEsc = escapeshellarg($dir);
    $urlEsc = escapeshellarg($url);
    $cmd = 'git clone --depth 1 ' . $urlEsc . ' ' . $dirEsc . ' 2>&1';
    $out = shell_exec($cmd);
    return is_dir($dir . DIRECTORY_SEPARATOR . '.git');
}

/**
 * Create branch, write file, commit, push.
 *
 * @param string $dir
 * @param string $branch
 * @param string $filePath
 * @param string $content
 * @param string $commitMsg
 * @return bool
 */
function do_commit_and_push($dir, $branch, $filePath, $content, $commitMsg)
{
    $dirEsc = escapeshellarg($dir);
    $branchEsc = escapeshellarg($branch);
    $fullPath = $dir . DIRECTORY_SEPARATOR . $filePath;
    $pathDir = dirname($fullPath);
    if (!is_dir($pathDir)) {
        if (!@mkdir($pathDir, 0755, true)) {
            return false;
        }
    }
    if (@file_put_contents($fullPath, $content) === false) {
        return false;
    }
    $cmd = 'git -C ' . $dirEsc . ' config user.email "daily@localhost" 2>&1';
    shell_exec($cmd);
    $cmd = 'git -C ' . $dirEsc . ' config user.name "daily-commit" 2>&1';
    shell_exec($cmd);
    $cmd = 'git -C ' . $dirEsc . ' checkout -b ' . $branchEsc . ' 2>&1';
    shell_exec($cmd);
    $fileEsc = escapeshellarg($filePath);
    $cmd = 'git -C ' . $dirEsc . ' add ' . $fileEsc . ' 2>&1';
    shell_exec($cmd);
    $msgEsc = escapeshellarg($commitMsg);
    $cmd = 'git -C ' . $dirEsc . ' commit -m ' . $msgEsc . ' 2>&1';
    shell_exec($cmd);
    $cmd = 'git -C ' . $dirEsc . ' push -u origin ' . $branchEsc . ' 2>&1';
    $lines = [];
    exec($cmd, $lines, $pushCode);
    return $pushCode === 0;
}

/**
 * GitHub REST API call. Returns decoded JSON or null on failure.
 *
 * @param string $repo owner/repo
 * @param string $token
 * @param string $method GET|POST|PUT
 * @param string $path e.g. /pulls or /pulls/123/merge
 * @param mixed $body for POST/PUT
 * @return array|null
 */
function github_api($repo, $token, $method, $path, $body = null)
{
    $url = 'https://api.github.com/repos/' . $repo . $path;
    $opts = [
        'http' => [
            'method'  => $method,
            'header'  => "Accept: application/vnd.github+json\r\nAuthorization: Bearer " . $token . "\r\nUser-Agent: daily-commit-php\r\n",
            'ignore_errors' => true,
        ],
    ];
    if ($body !== null && ($method === 'POST' || $method === 'PUT')) {
        $opts['http']['header'] .= "Content-Type: application/json\r\n";
        $opts['http']['content'] = json_encode($body);
    }
    $ctx = stream_context_create($opts);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        return null;
    }
    $code = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('#^HTTP/\d\.\d\s+(\d+)#', $h, $m)) {
                $code = (int) $m[1];
                break;
            }
        }
    }
    if ($code >= 400) {
        return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : (is_object($decoded) ? (array) $decoded : null);
}

/**
 * Filter PRs by created_at in [start, end).
 *
 * @param array $prs
 * @param string $start Y-m-d H:i:s
 * @param string $end   Y-m-d H:i:s
 * @return array
 */
function filter_prs_by_date($prs, $start, $end)
{
    $result = [];
    $tsStart = strtotime($start);
    $tsEnd = strtotime($end);
    foreach ($prs as $pr) {
        $created = isset($pr['created_at']) ? $pr['created_at'] : '';
        if ($created === '') {
            continue;
        }
        $ts = strtotime($created);
        if ($ts >= $tsStart && $ts < $tsEnd) {
            $result[] = $pr;
        }
    }
    return $result;
}

/**
 * @param string $dir
 */
function cleanup($dir)
{
    if (!is_dir($dir)) {
        return;
    }
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $f) {
        if ($f->isDir()) {
            @rmdir($f->getRealPath());
        } else {
            @unlink($f->getRealPath());
        }
    }
    @rmdir($dir);
}
