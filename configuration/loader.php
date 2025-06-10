<?php
declare(strict_types=1);
use Phalcon\Autoload\Loader;
$rootPath = dirname(__DIR__);
$utilsPath = $rootPath . '/utils';
$loader = new Loader();
$loader->setNamespaces([
    'Api\Encoding'   => $utilsPath . '/encoding/',
    'Api\Email'      => $utilsPath . '/email/',
    'Api\Http'       => $utilsPath . '/http/',
    'Api\Constants'  => $rootPath . '/constants/',
    'Api\Models'     => $rootPath . '/models/',
    'Api\Controllers'=> $rootPath . '/controllers/',
    'Api\Middleware' => $rootPath . '/middleware/',
    'Api\Services'   => $rootPath . '/services/',
    'Api\Repositories' => $rootPath . '/repositories/',
])->register();
return $loader;