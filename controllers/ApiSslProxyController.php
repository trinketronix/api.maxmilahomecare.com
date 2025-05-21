<?php

namespace Api\Controllers;

use Exception;
use Phalcon\Mvc\Controller;
use Phalcon\Http\Response;

/**
 * Minimal SSL Proxy for Maxmila API
 * This proxy only bypasses SSL certificate validation
 * It assumes CORS is already properly handled by middleware.php
 */
class ApiSslProxyController extends Controller {

    /**
     * Target API base URL
     * @var string
     */
    private $apiBaseUrl = 'https://api-test.maxmilahomecare.com';

    /**
     * Forward any request to the API
     * This method handles all HTTP methods (GET, POST, PUT, DELETE, etc.)
     */
    public function forwardAction() {
        // Create a new response object
        $response = new Response();

        try {
            // Get the path after /ssl-proxy/
            $path = $this->dispatcher->getParams();
            $endpoint = implode('/', $path);

            // Build target URL
            $targetUrl = $this->apiBaseUrl . '/' . $endpoint;

            // Create a new cURL resource
            $ch = curl_init();

            // Set URL
            curl_setopt($ch, CURLOPT_URL, $targetUrl);

            // Return the transfer as a string
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            // Include header in response
            curl_setopt($ch, CURLOPT_HEADER, true);

            // Don't verify SSL certificate (only for development/testing)
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

            // Set request method
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $this->request->getMethod());

            // Set headers from request
            $headers = [];
            foreach ($this->request->getHeaders() as $name => $value) {
                // Skip headers that would conflict with cURL
                if (!in_array(strtolower($name), ['host', 'content-length'])) {
                    $headers[] = "$name: $value";
                }
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            // Handle request body for POST, PUT etc.
            if (in_array($this->request->getMethod(), ['POST', 'PUT', 'PATCH'])) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $this->request->getRawBody());
            }

            // Execute cURL request
            $curlResponse = curl_exec($ch);

            // Check for errors
            if ($curlResponse === false) {
                throw new Exception(curl_error($ch));
            }

            // Get status code
            $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            // Get header size
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);

            // Close cURL resource
            curl_close($ch);

            // Split header and body
            $responseHeaders = substr($curlResponse, 0, $headerSize);
            $responseBody = substr($curlResponse, $headerSize);

            // Parse headers to get content type
            $contentType = 'application/json'; // Default
            if (preg_match('/Content-Type: (.*?)(?:\r\n|\r|\n)/i', $responseHeaders, $matches)) {
                $contentType = trim($matches[1]);
            }

            // Set response content type
            $response->setContentType($contentType);

            // Set response status code
            $response->setStatusCode($statusCode);

            // Set response body
            $response->setContent($responseBody);

        } catch (Exception $e) {
            error_log('Exception: ' . $e->getMessage());
            // Handle exceptions
            $response->setStatusCode(500, 'Internal Server Error');
            $response->setJsonContent([
                'status' => 'error',
                'message' => 'Proxy error: ' . $e->getMessage()
            ]);
        }

        // Send the response
        $response->send();
    }
}