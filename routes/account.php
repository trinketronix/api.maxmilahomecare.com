<?php

use Api\Controllers\AccountController;

if (isset($app)) {
    $account = new AccountController();
    /**
     * GET method request: Retrieve all user accounts (managers and admins only)
     */
    $app->get('/accounts', [$account, 'getAll']);
    // New route for getting a single account
    $app->get('/account/{id:[0-9]*}', [$account, 'getById']);
}