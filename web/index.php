<?php

//defines
session_start();
error_reporting(0);
const DIR_ROOT = '../';

//auto include all classes
include DIR_ROOT . 'vendor/autoload.php';

//init app
$b = new Core\Bootstrap(false);
$b->run();
