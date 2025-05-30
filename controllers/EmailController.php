<?php

namespace Api\Controllers;

use Api\Constants\Email;
use Api\Constants\Message;
use Exception;

class EmailController extends BaseController{

    //TODO functions that can receive data to send emails with more than one TO email,
    // more than one CC and BCC and attachments

    public function send(): array{

        $data = $this->getRequestBody();
        // Validate the recipient "to"
        if (empty($data[Email::TO]))
            return $this->respondWithError(Message::CREDENTIALS_REQUIRED, 400);
        // Validate the recipient "to" is an email
        if (!filter_var($data[Email::TO], FILTER_VALIDATE_EMAIL))
            return $this->respondWithError(Message::INVALID_CREDENTIALS, 400);
        // Validate subject
        if (empty($data[Email::SUBJECT]))
            return $this->respondWithError(Message::EMAIL_SUBJECT_EMPTY, 400);
        // Validate body
        if (empty($data[Email::BODY]))
            return $this->respondWithError(Message::EMAIL_BODY_EMPTY, 400);

        $to = $data[Email::TO];
        $subject = $data[Email::SUBJECT];
        $body = $data[Email::BODY];

        return $this->process($to, $subject, $body);
    }

    public function process(string $to, string $subject, string $body): array {
        try {

            $result = $this->processEmail($to, $subject, $body);

            $success = $result['success'];
            $message = $result['message'];

            if ($success) {
                $data = [
                    'action' => 'email sent',
                    'to' => $to,
                    'message' => $message
                ];
                return $this->respondWithSuccess($data, 201, $message);
            } else {
                return $this->respondWithError($message, 417);
            }
        } catch (Exception $e) {
            $message = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . $e->getFile() . ' ' . $e->getLine();
            error_log('Exception: ' . $message);
            return $this->respondWithError('Exception: ' . $e->getMessage(), 417);
        }
    }
}