
<?php

use Api\Controllers\AddressController;

if (isset($app)) {
    $address = new AddressController();

    // POST Routes - Create
    $app->post('/address', [$address, 'create']);

    // GET Routes - Read
    $app->get('/address/{id}', [$address, 'getById']);
    $app->get('/address/person/{personId}/{personType}', [$address, 'getByPerson']);
    $app->get('/address/nearby', [$address, 'findNearby']);

    // PUT Routes - Update
    $app->put('/address/{id}', [$address, 'update']);

    // DELETE Routes - Delete
    $app->delete('/address/{id}', [$address, 'delete']);
}