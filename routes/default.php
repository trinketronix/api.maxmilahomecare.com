<?php

// Add a Not-Found handler
use Api\Constants\Api;


// Define application environment
define('APP_ENV', getenv('APP_ENV') ?: 'dev');

if (isset($app)) {
    $app->get(
        '/',
        function () {
            $appName = Api::NAME;
            $appVersion = Api::VERSION;
            $appCopyright = Api::COPYRIGHT;
            $response = new Phalcon\Http\Response();
            $response->setStatusCode(200, 'OK');
            $response->setJsonContent([
                'status' => 'success',
                'code' => 200,
                'info' => [
                    'name' => $appName,
                    'version' => $appVersion,
                    'copyright' => $appCopyright,
                    'environment' => APP_ENV,
                    'message' => 'rest api is online'
                ]
            ]);
            return $response;
        }
    );
}