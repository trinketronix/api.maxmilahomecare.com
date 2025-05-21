<?php

// Add a Not-Found handler
use Api\Constants\Api;

if (isset($app)) {
    $app->get(
        '/',
        function () {
            $appName = Api::getAppName();
            $appVersion = Api::getVersion();
            $appCopyright = Api::getCopyright();
            $response = new Phalcon\Http\Response();
            $response->setStatusCode(200, 'OK');
            $response->setJsonContent([
                'status' => 'success',
                'code' => 200,
                'info' => [
                    'name' => $appName,
                    'version' => $appVersion,
                    'copyright' => $appCopyright,
                    'baseUrl' => BASE_URL,
                    'environment' => APP_ENV,
                    'database' => DB_DATABASE,
                    'message' => 'rest api is online'
                ]
            ]);
            return $response;
        }
    );
}