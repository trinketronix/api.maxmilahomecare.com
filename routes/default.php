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
                    'apiBaseUrl' => API_BASE_URL,
                    'appBaseUrl' => APP_BASE_URL,
                    'environment' => APP_ENV,
                    'database' => DB_DATABASE,
                    'email' => getenv('EMAIL_REP_ADDR') ?: 'failsafe@maxmilahomecare.com',
                    'message' => 'rest api is online'
                ]
            ]);
            return $response;
        }
    );
}