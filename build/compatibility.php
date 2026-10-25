<?php
// Development checker, run with a modern PHP. This is a token audit, not a PHP 5.6 execution claim.
$root = dirname(__DIR__);
$forbidden = array('T_COALESCE','T_COALESCE_EQUAL','T_SPACESHIP','T_FN','T_MATCH','T_NULLSAFE_OBJECT_OPERATOR','T_ATTRIBUTE','T_ENUM','T_READONLY');
$tokens = array(); foreach ($forbidden as $name) { if (defined($name)) { $tokens[] = constant($name); } }
$count = 0;
foreach (array('src','modules','includes') as $dir) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') { continue; }
        foreach (token_get_all(file_get_contents($file->getPathname())) as $token) {
            if (is_array($token) && in_array($token[0], $tokens, true)) { fwrite(STDERR, 'Unsupported legacy token in ' . $file->getPathname() . ':' . $token[2] . PHP_EOL); exit(1); }
        }
        ++$count;
    }
}
echo 'Legacy token audit passed for ' . $count . ' runtime files. Actual PHP 5.6 execution: not run.' . PHP_EOL;
