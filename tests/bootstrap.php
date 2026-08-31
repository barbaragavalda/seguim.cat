<?php

// normally defined by public/index.php before anything else runs; tests
// bypass that entry point, so define harmless placeholders here instead.
define('DIR_ROOT', __DIR__ . '/../');
define('IS_DEV', true);

require __DIR__ . '/../vendor/autoload.php';
