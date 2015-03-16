<?php
define("DS", '/');
define("APP_PATH",  dirname(__FILE__).DS.'application'.DS);
//define("APP_PATH",  realpath(dirname(__FILE__).DS.'..'.DS.'application'.DS));
session_start(); 
$app  = new Yaf_Application(APP_PATH . "conf/application.ini");
$app->bootstrap()->run();
