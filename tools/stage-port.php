<?php
declare(strict_types=1);
// Produce a complete port tree without duplicating canonical library sources in Git.
$root = dirname(__DIR__);
$target = $argv[1] ?? $root . '/build/ports';
if (file_exists($target) || is_link($target)) throw new RuntimeException('Refusing to overwrite a staging destination');
$relative = 'sysutils/pfSense-pkg-Recovery_Guard';
$port = $target . '/' . $relative;
$copy = static function (string $from, string $to): void {
    if (!is_dir(dirname($to)) && !mkdir(dirname($to), 0755, true)) throw new RuntimeException('Cannot create staging directory');
    $text = file_get_contents($from);
    if ($text === false) throw new RuntimeException('Cannot read source file');
    // All package inputs are text. Windows checkouts must still build on FreeBSD.
    $text = str_replace("\r\n", "\n", $text);
    if (file_put_contents($to, $text) !== strlen($text)) throw new RuntimeException('Cannot stage source file');
};
$template = $root . '/ports/' . $relative;
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($template, FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->isLink() || !$file->isFile()) throw new RuntimeException('Unexpected port template entry');
    $copy($file->getPathname(), $port . '/' . substr($file->getPathname(), strlen($template) + 1));
}
foreach (glob($root . '/src/*.php') as $file) $copy($file, $port . '/files/usr/local/pkg/recovery_guard/' . basename($file));
$copy($root . '/LICENSE', $port . '/files/LICENSE');
$plist = [];
$prefix = $port . '/files/usr/local/';
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($prefix, FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->isFile()) $plist[] = str_replace('\\', '/', substr($file->getPathname(), strlen($prefix)));
}
sort($plist);
file_put_contents($port . '/pkg-plist', implode("\n", $plist) . "\n");
echo 'STAGED: ' . count($plist) . " installed files; complete port tree at " . $port . "\n";
