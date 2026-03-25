#!/usr/bin/env php
<?php
/**
 * DoliModuleManager (DMM) — Preflight Diagnostic Script
 *
 * Run this script from the command line on your Dolibarr server to verify
 * that all prerequisites for DMM are met.
 *
 * Usage:
 *   php dmm_preflight.php [options]
 *
 * Options:
 *   --dolibarr-root=/path    Path to Dolibarr root (default: auto-detect)
 *   --token=ghp_xxxxx        GitHub token to test authenticated access
 *   --repo=owner/repo        GitHub repo to test (default: Dolibarr/dolibarr)
 *   --skip-download          Skip the tarball download/extract test
 *   --json                   Output results as JSON
 *
 * Examples:
 *   php dmm_preflight.php
 *   php dmm_preflight.php --dolibarr-root=/var/www/html
 *   php dmm_preflight.php --token=ghp_abc123 --repo=myorg/mymodule
 */

// ============================================================================
// Configuration
// ============================================================================

$options = getopt('', [
    'dolibarr-root:',
    'token:',
    'repo:',
    'skip-download',
    'json',
]);

$dolibarrRoot = $options['dolibarr-root'] ?? null;
$githubToken  = $options['token'] ?? null;
$testRepo     = $options['repo'] ?? 'Dolibarr/dolibarr';
$skipDownload = isset($options['skip-download']);
$jsonOutput   = isset($options['json']);

// Auto-detect: are we running in a browser or a terminal?
$isWeb = (php_sapi_name() !== 'cli' && php_sapi_name() !== 'phpdbg');
$isCli = !$isWeb;

// ============================================================================
// Helpers
// ============================================================================

$results = [];
$totalPass = 0;
$totalFail = 0;
$totalWarn = 0;
$currentSection = '';

function section(string $title): void
{
    global $currentSection, $jsonOutput;
    $currentSection = $title;
    if (!$jsonOutput) {
        echo "\n[$title]\n";
    }
}

function pass(string $label, string $detail = ''): void
{
    global $results, $totalPass, $currentSection, $jsonOutput;
    $totalPass++;
    $results[] = ['section' => $currentSection, 'status' => 'OK', 'label' => $label, 'detail' => $detail];
    if (!$jsonOutput) {
        $d = $detail ? " ($detail)" : '';
        echo " ✅ $label$d\n";
    }
}

function fail(string $label, string $detail = ''): void
{
    global $results, $totalFail, $currentSection, $jsonOutput;
    $totalFail++;
    $results[] = ['section' => $currentSection, 'status' => 'FAIL', 'label' => $label, 'detail' => $detail];
    if (!$jsonOutput) {
        $d = $detail ? " ($detail)" : '';
        echo " ❌ $label$d\n";
    }
}

function warn(string $label, string $detail = ''): void
{
    global $results, $totalWarn, $currentSection, $jsonOutput;
    $totalWarn++;
    $results[] = ['section' => $currentSection, 'status' => 'WARN', 'label' => $label, 'detail' => $detail];
    if (!$jsonOutput) {
        $d = $detail ? " ($detail)" : '';
        echo " ⚠️ $label$d\n";
    }
}

function info(string $label, string $detail = ''): void
{
    global $jsonOutput;
    if (!$jsonOutput) {
        $d = $detail ? " ($detail)" : '';
        echo " ℹ️ $label$d\n";
    }
}

function curlGet(string $url, ?string $token = null, array $extraHeaders = []): array
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HEADER, false);
    $headers = ['User-Agent: DMM-Preflight/1.0'];
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    $headers = array_merge($headers, $extraHeaders);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'body' => $body, 'error' => $error];
}

/**
 * Download a URL directly to a file (streaming, no memory bloat).
 */
function curlDownloadToFile(string $url, string $destPath, ?string $token = null): array
{
    $fp = fopen($destPath, 'wb');
    if (!$fp) {
        return ['code' => 0, 'error' => 'Cannot open destination file'];
    }
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    $headers = ['User-Agent: DMM-Preflight/1.0'];
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    fclose($fp);
    return ['code' => $code, 'error' => $error];
}

function autoDetectDolibarr(): ?string
{
    $candidates = [
        '/var/www/html',
        '/var/www/dolibarr',
        '/var/www/dolibarr/htdocs',
        '/var/www/html/dolibarr',
        '/var/www/html/dolibarr/htdocs',
        '/usr/share/dolibarr/htdocs',
        '/opt/dolibarr/htdocs',
    ];
    foreach ($candidates as $path) {
        if (file_exists($path . '/filefunc.inc.php')) {
            return $path;
        }
    }
    return null;
}

// ============================================================================
// Banner
// ============================================================================

if (!$jsonOutput) {
    if ($isWeb) echo '<pre style="font-family:monospace;font-size:13px;line-height:1.5;">';
    echo "DMM Preflight Diagnostic — " . date('Y-m-d H:i:s') . " — " . php_uname('n') . "\n";
}

// ============================================================================
// 1. PHP Environment
// ============================================================================

section('PHP Environment');

// PHP version
$phpVersion = PHP_VERSION;
if (version_compare($phpVersion, '8.0.0', '>=')) {
    pass('PHP version', $phpVersion);
} elseif (version_compare($phpVersion, '7.4.0', '>=')) {
    warn('PHP version', "$phpVersion — DMM supports 7.4+ but 8.0+ recommended");
} else {
    fail('PHP version', "$phpVersion — DMM requires PHP 7.4+");
}

// Required extensions
$requiredExt = ['curl', 'json', 'Phar', 'openssl', 'zlib'];
foreach ($requiredExt as $ext) {
    if (extension_loaded($ext) || (strtolower($ext) === 'phar' && extension_loaded('phar'))) {
        $ver = phpversion($ext);
        pass("ext-$ext", $ver ?: 'bundled');
    } else {
        fail("ext-$ext", "not loaded — required by DMM");
    }
}

// Optional but useful
$optionalExt = ['posix', 'mbstring'];
foreach ($optionalExt as $ext) {
    if (extension_loaded($ext)) {
        pass("ext-$ext", "optional");
    } else {
        warn("ext-$ext", "optional, not loaded");
    }
}

// Memory & limits
$memLimit = ini_get('memory_limit');
info("memory_limit", $memLimit);
$maxExec = ini_get('max_execution_time');
info("max_execution_time", $maxExec . "s");
$phpUser = function_exists('posix_geteuid') ? posix_getpwuid(posix_geteuid())['name'] : get_current_user();
info("PHP runs as", $phpUser);

// ============================================================================
// 2. Dolibarr Detection
// ============================================================================

section('Dolibarr Detection');

if (!$dolibarrRoot) {
    $dolibarrRoot = autoDetectDolibarr();
}

if ($dolibarrRoot && file_exists($dolibarrRoot . '/filefunc.inc.php')) {
    pass('Dolibarr root found', $dolibarrRoot);
} else {
    fail('Dolibarr root not found', 'Use --dolibarr-root=/path/to/htdocs');
    $dolibarrRoot = null;
}

$dolibarrVersion = null;
$dolEncryptAvailable = false;

if ($dolibarrRoot) {
    // Try to get Dolibarr version without full bootstrap
    $filefunc = $dolibarrRoot . '/filefunc.inc.php';
    $mainInc = $dolibarrRoot . '/master.inc.php';

    // Method 1: parse filefunc.inc.php for DOL_VERSION
    $content = @file_get_contents($filefunc);
    if ($content && preg_match("/define\s*\(\s*'DOL_VERSION'\s*,\s*'([^']+)'/", $content, $m)) {
        $dolibarrVersion = $m[1];
    }

    // Method 2: try to include filefunc.inc.php if method 1 failed
    if (!$dolibarrVersion) {
        // Suppress errors — Dolibarr bootstrap can be noisy
        $oldLevel = error_reporting(0);
        $oldDisplay = ini_get('display_errors');
        ini_set('display_errors', '0');
        try {
            if (!defined('DOL_DOCUMENT_ROOT')) {
                define('DOL_DOCUMENT_ROOT', $dolibarrRoot);
            }
            @include_once $filefunc;
            if (defined('DOL_VERSION')) {
                $dolibarrVersion = DOL_VERSION;
            }
        } catch (\Throwable $e) {
            // Ignore
        }
        error_reporting($oldLevel);
        ini_set('display_errors', $oldDisplay);
    }

    if ($dolibarrVersion) {
        if (version_compare($dolibarrVersion, '14.0.0', '>=')) {
            pass('Dolibarr version', $dolibarrVersion);
        } else {
            fail('Dolibarr version', "$dolibarrVersion — DMM requires 14.0+");
        }
    } else {
        warn('Dolibarr version', 'Could not detect version — run this script from the server with Dolibarr bootstrapped');
    }

    // dolEncrypt check
    if (function_exists('dolEncrypt') && function_exists('dolDecrypt')) {
        $dolEncryptAvailable = true;
        // Test roundtrip
        try {
            $testVal = 'dmm_test_' . uniqid();
            $enc = dolEncrypt($testVal);
            $dec = dolDecrypt($enc);
            if ($dec === $testVal) {
                pass('dolEncrypt/dolDecrypt', 'Roundtrip OK');
            } else {
                warn('dolEncrypt/dolDecrypt', 'Roundtrip mismatch — encryption may not work correctly');
            }
        } catch (\Throwable $e) {
            warn('dolEncrypt/dolDecrypt', 'Error: ' . $e->getMessage());
        }
    } else {
        warn('dolEncrypt/dolDecrypt', 'Functions not available — Dolibarr not fully bootstrapped or version < 13');
    }

    // /custom/ directory
    $customDir = $dolibarrRoot . '/custom';
    if (!is_dir($customDir)) {
        // Some setups use a different path
        $customDir = $dolibarrRoot . '/../custom';
        if (!is_dir($customDir)) {
            $customDir = null;
        }
    }

    if ($customDir) {
        $customDir = realpath($customDir);
        $perms = substr(sprintf('%o', fileperms($customDir)), -4);
        $dirOwner = function_exists('posix_getpwuid') ? posix_getpwuid(fileowner($customDir))['name'] : 'unknown';
        pass('/custom/ directory exists', "$customDir owner:$dirOwner perms:$perms");

        if (is_writable($customDir)) {
            pass('/custom/ is writable');
        } else {
            fail('/custom/ is writable', "PHP runs as $phpUser but cannot write to $customDir");
        }

        // Check disk space
        $freeBytes = @disk_free_space($customDir);
        if ($freeBytes !== false) {
            $freeMB = round($freeBytes / 1024 / 1024);
            if ($freeMB >= 50) {
                pass('Disk space', $freeMB . ' MB free');
            } else {
                warn('Disk space', $freeMB . ' MB free — DMM recommends 50 MB+');
            }
        }

        // Count existing modules
        $modules = array_filter(scandir($customDir), function ($d) use ($customDir) {
            return $d[0] !== '.' && is_dir($customDir . '/' . $d);
        });
        info('Modules in /custom/', count($modules) . ' found');
    } else {
        fail('/custom/ directory', 'Not found');
    }
}

// ============================================================================
// 3. Temp Directory
// ============================================================================

section('Temp Directory');

$tmpBase = sys_get_temp_dir();
$tmpTest = $tmpBase . '/dmm_preflight_' . uniqid();

if (@mkdir($tmpTest, 0755, true)) {
    $testFile = $tmpTest . '/test.txt';
    if (@file_put_contents($testFile, 'dmm_test') !== false) {
        pass('Temp dir writable', $tmpBase);
        @unlink($testFile);
    } else {
        fail('Temp dir writable', "Cannot write files in $tmpBase");
    }
    @rmdir($tmpTest);
} else {
    fail('Temp dir writable', "$tmpBase — cannot create directories");
}

// ============================================================================
// 4. PharData Extraction
// ============================================================================

section('PharData Extraction');

if (class_exists('PharData')) {
    pass('PharData class available');

    // Create a test tar.gz to verify extraction works
    $tmpPhar = sys_get_temp_dir() . '/dmm_phar_test_' . uniqid();
    @mkdir($tmpPhar, 0755, true);

    try {
        // We test the exact flow DMM will use: decompress a .tar.gz then extract.
        // To avoid PharData's internal registry conflicts, we build the test
        // archive using a different path than the extraction target.

        $sourceDir = $tmpPhar . '/source';
        @mkdir($sourceDir, 0755, true);
        file_put_contents($sourceDir . '/hello.txt', 'DMM test file');

        // Step 1: Create a .tar.gz directly (not via compress, which causes registry issues)
        $tarGzPath = $tmpPhar . '/test.tar.gz';
        $buildTar = new PharData($tmpPhar . '/build.tar');
        $buildTar->buildFromDirectory($sourceDir);
        $buildTar->compress(Phar::GZ); // creates build.tar.gz
        unset($buildTar);
        // Rename to our target name
        rename($tmpPhar . '/build.tar.gz', $tarGzPath);
        @unlink($tmpPhar . '/build.tar');

        if (file_exists($tarGzPath) && filesize($tarGzPath) > 0) {
            pass('PharData create .tar.gz', filesize($tarGzPath) . ' bytes');

            // Step 2: Decompress .tar.gz → .tar (exactly what DMM does)
            $gz = new PharData($tarGzPath);
            $gz->decompress(); // creates test.tar
            unset($gz);

            $tarPath = $tmpPhar . '/test.tar';
            if (file_exists($tarPath)) {
                pass('PharData decompress .tar.gz → .tar');

                // Step 3: Extract .tar
                $extractDir = $tmpPhar . '/extracted';
                @mkdir($extractDir, 0755, true);
                $tar = new PharData($tarPath);
                $tar->extractTo($extractDir);
                unset($tar);

                if (file_exists($extractDir . '/hello.txt')) {
                    pass('PharData extract from .tar');
                } else {
                    fail('PharData extract from .tar', 'Extracted file not found');
                }
            } else {
                fail('PharData decompress', '.tar not created');
            }
        } else {
            fail('PharData create .tar.gz');
        }
    } catch (\Throwable $e) {
        fail('PharData operations', $e->getMessage());
    }

    // Cleanup
    @exec('rm -rf ' . escapeshellarg($tmpPhar));
} else {
    fail('PharData class available', 'Phar extension is loaded but PharData class missing');
}

// ============================================================================
// 5. GitHub API Connectivity
// ============================================================================

section('GitHub API Connectivity');

// Unauthenticated
$res = curlGet('https://api.github.com/rate_limit');
if ($res['code'] === 200) {
    $data = json_decode($res['body'], true);
    $remaining = $data['rate']['remaining'] ?? '?';
    $limit = $data['rate']['limit'] ?? '?';
    pass('GitHub API reachable', "Rate limit: $remaining/$limit (unauthenticated)");
} elseif ($res['code'] === 0) {
    fail('GitHub API reachable', 'Connection failed: ' . $res['error']);
} else {
    fail('GitHub API reachable', 'HTTP ' . $res['code']);
}

// Test fetching releases (public repo, no token needed)
$res = curlGet("https://api.github.com/repos/$testRepo/releases?per_page=1");
if ($res['code'] === 200) {
    $releases = json_decode($res['body'], true);
    if (!empty($releases)) {
        $latestTag = $releases[0]['tag_name'] ?? 'unknown';
        pass('Fetch releases', "$testRepo latest: $latestTag");
    } else {
        warn('Fetch releases', 'No releases found — repo may be empty');
    }
} else {
    fail('Fetch releases', "HTTP {$res['code']} for $testRepo");
}

// ============================================================================
// 6. Authenticated Access (if token provided)
// ============================================================================

if ($githubToken) {
    section('Authenticated GitHub Access');

    // Validate token
    $res = curlGet('https://api.github.com/rate_limit', $githubToken);
    if ($res['code'] === 200) {
        $data = json_decode($res['body'], true);
        $remaining = $data['rate']['remaining'] ?? '?';
        $limit = $data['rate']['limit'] ?? '?';
        pass('Token is valid', "Rate limit: $remaining/$limit");
    } elseif ($res['code'] === 401) {
        fail('Token is valid', 'HTTP 401 — Token is invalid or expired');
    } else {
        fail('Token is valid', 'HTTP ' . $res['code']);
    }

    // List accessible repos
    $res = curlGet('https://api.github.com/user/repos?per_page=10&sort=updated', $githubToken);
    if ($res['code'] === 200) {
        $repos = json_decode($res['body'], true);
        $count = count($repos);
        pass("List repos", "$count repos returned (showing up to 10)");
        foreach ($repos as $r) {
            $visibility = $r['private'] ? 'private' : 'public';
            info('  → ' . $r['full_name'], $visibility);
        }
    } else {
        fail('List repos', 'HTTP ' . $res['code']);
    }

    // Test fetching dmm.json from the test repo
    $res = curlGet("https://api.github.com/repos/$testRepo/contents/dmm.json", $githubToken);
    if ($res['code'] === 200) {
        pass("dmm.json found in $testRepo");
    } elseif ($res['code'] === 404) {
        info("No dmm.json in $testRepo", "Normal if this repo is not DMM-configured");
    } else {
        warn("dmm.json fetch", "HTTP {$res['code']} for $testRepo");
    }

    // Test tarball download (stream to file, not memory)
    $res = curlGet("https://api.github.com/repos/$testRepo/releases?per_page=1", $githubToken);
    if ($res['code'] === 200) {
        $releases = json_decode($res['body'], true);
        if (!empty($releases)) {
            $tag = $releases[0]['tag_name'];
            info("Testing tarball download for tag $tag");

            $tmpTar = sys_get_temp_dir() . '/dmm_auth_test_' . uniqid() . '.tar.gz';
            $tarRes = curlDownloadToFile(
                "https://api.github.com/repos/$testRepo/tarball/$tag",
                $tmpTar,
                $githubToken
            );
            if ($tarRes['code'] === 200 && file_exists($tmpTar) && filesize($tmpTar) > 100) {
                $sizeMB = round(filesize($tmpTar) / 1024 / 1024, 2);
                pass("Tarball download", "$tag — {$sizeMB} MB");
            } else {
                fail("Tarball download", "HTTP {$tarRes['code']}");
            }
            @unlink($tmpTar);
        }
    }
} else {
    section('Authenticated GitHub Access');
    info('Skipped', 'No token provided. Use --token=ghp_xxxxx to test authenticated access.');
}

// ============================================================================
// 7. Full Integration Test (download + extract from GitHub)
// ============================================================================

if (!$skipDownload) {
    section('Integration Test (download + extract)');

    $integrationTmp = sys_get_temp_dir() . '/dmm_integration_' . uniqid();
    @mkdir($integrationTmp, 0755, true);

    try {
        // Use a tiny public repo for the integration test — NOT the user's test repo
        // which could be huge (e.g., Dolibarr itself at ~150 MB).
        // octocat/Hello-World is GitHub's official demo repo (~1 KB).
        $integrationRepo = 'octocat/Hello-World';
        $integrationTag = 'master';

        info("Using $integrationRepo @ $integrationTag (tiny test repo)");

        // Download tarball — stream directly to file, never to memory
        $tarballUrl = "https://api.github.com/repos/$integrationRepo/tarball/$integrationTag";
        $tarGzPath = $integrationTmp . '/module.tar.gz';

        $dlResult = curlDownloadToFile($tarballUrl, $tarGzPath);

        if ($dlResult['code'] === 200 && file_exists($tarGzPath) && filesize($tarGzPath) > 100) {
            $sizeKB = round(filesize($tarGzPath) / 1024, 1);
            pass("Download tarball", "{$sizeKB} KB streamed to disk");

            // Decompress
            $phar = new PharData($tarGzPath);
            $phar->decompress(); // creates .tar
            $tarPath = $integrationTmp . '/module.tar';
            if (file_exists($tarPath)) {
                pass('Decompress .tar.gz → .tar');

                // Extract
                $extractDir = $integrationTmp . '/extracted';
                @mkdir($extractDir, 0755, true);
                $tar = new PharData($tarPath);
                $tar->extractTo($extractDir);

                // Detect wrapper directory (GitHub adds owner-repo-hash/)
                $entries = array_diff(scandir($extractDir), ['.', '..']);
                if (count($entries) === 1 && is_dir($extractDir . '/' . reset($entries))) {
                    $wrapperDir = reset($entries);
                    $moduleRoot = $extractDir . '/' . $wrapperDir;
                    pass('Detect wrapper directory', $wrapperDir);

                    // Count files
                    $fileCount = 0;
                    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($moduleRoot));
                    foreach ($iterator as $file) {
                        if ($file->isFile()) $fileCount++;
                    }
                    pass("Extract module files", "$fileCount files extracted");
                } else {
                    pass("Extract to directory", count($entries) . " entries at root");
                }
            } else {
                fail('Decompress .tar.gz → .tar', '.tar file not created');
            }
        } else {
            fail("Download tarball", "HTTP {$dlResult['code']} — {$dlResult['error']}");
        }
    } catch (\Throwable $e) {
        fail('Integration test', $e->getMessage());
    }

    // Cleanup
    @exec('rm -rf ' . escapeshellarg($integrationTmp));
} else {
    section('Integration Test (download + extract)');
    info('Skipped', 'Use without --skip-download to run full integration test');
}

// ============================================================================
// Summary
// ============================================================================

if ($jsonOutput) {
    echo json_encode([
        'date' => date('c'),
        'host' => php_uname('n'),
        'php_version' => PHP_VERSION,
        'dolibarr_root' => $dolibarrRoot,
        'dolibarr_version' => $dolibarrVersion,
        'token_provided' => !empty($githubToken),
        'summary' => [
            'pass' => $totalPass,
            'fail' => $totalFail,
            'warn' => $totalWarn,
        ],
        'results' => $results,
    ], JSON_PRETTY_PRINT) . "\n";
} else {
    echo "\n---\n";
    if ($totalFail === 0) {
        echo "🚀 $totalPass passed, $totalWarn warnings — All clear, DMM is ready!\n";
    } else {
        echo "⛔ $totalPass passed, $totalFail failed, $totalWarn warnings — Fix issues above.\n";
    }
    if ($isWeb) echo '</pre>';
}

exit($totalFail > 0 ? 1 : 0);
