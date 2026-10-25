<?php
/* Development loader; the release builder installs an equivalent private loader. */
spl_autoload_register(function ($class) {
    $prefix = 'Bachs\\Whmcs\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) { require_once $file; }
    }
});
