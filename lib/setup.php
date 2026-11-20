<?php
// One-time setup over SSH:  php lib/setup.php
// Creates config.php with your site address and upload password.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$configFile = $root . '/config.php';

function ask(string $q, string $default = ''): string
{
    echo $q . ($default !== '' ? " [$default]" : '') . ': ';
    $a = trim((string) fgets(STDIN));
    return $a === '' ? $default : $a;
}

function ask_secret(string $q): string
{
    echo $q . ': ';
    $tty = stream_isatty(STDIN);
    if ($tty) {
        shell_exec('stty -echo');
    }
    $a = trim((string) fgets(STDIN));
    if ($tty) {
        shell_exec('stty echo');
    }
    echo "\n";
    return $a;
}

echo "\nClipbin setup\n=============\n\n";

if (is_file($configFile)) {
    $again = strtolower(ask('config.php already exists. Replace it? (y/n)', 'n'));
    if ($again !== 'y') {
        echo "Nothing changed.\n";
        exit(0);
    }
}

$folder = basename($root);
$guess = preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $folder) ? "https://$folder" : '';
$url = rtrim(ask('Site address', $guess), '/');
if ($url !== '' && !preg_match('#^https?://#', $url)) {
    $url = 'https://' . $url;
}
$name = ask('Site name', 'Clipbin');

do {
    $pw = ask_secret('Choose an upload password (typing is hidden)');
    $pw2 = ask_secret('Type it again');
    if ($pw === '' || strlen($pw) < 8) {
        echo "Use at least 8 characters.\n";
        continue;
    }
    if ($pw !== $pw2) {
        echo "Those didn't match, try again.\n";
        continue;
    }
    break;
} while (true);

$config = "<?php\nreturn [\n"
    . "    'site_name' => " . var_export($name, true) . ",\n"
    . "    'base_url' => " . var_export($url, true) . ",\n"
    . "    'password' => " . var_export(password_hash($pw, PASSWORD_DEFAULT), true) . ",\n"
    . "    'max_upload_mb' => 2048,\n"
    . "    'chunk_mb' => 8,\n"
    . "    'max_dimension' => 1920,\n"
    . "];\n";

file_put_contents($configFile, $config);
@chmod($configFile, 0600);
foreach (['data', 'media'] as $d) {
    if (!is_dir("$root/$d")) {
        mkdir("$root/$d", 0755, true);
    }
}

echo "\nDone! Open " . ($url ?: 'your site') . " and sign in with that password.\n\n";
