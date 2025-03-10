<?php

// Add a Not-Found handler
use Api\Constants\Api;

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
                    'message' => 'rest api is online'
                ]
            ]);
            return $response;
        }
    );
}