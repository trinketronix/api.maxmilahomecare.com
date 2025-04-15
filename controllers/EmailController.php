<?php

namespace Api\Controllers;

use Api\Constants\Email;
use Api\Constants\Message;
use Api\Email\Sender;
use Api\Email\SMTP;
use Phalcon\Http\Response;

class EmailController extends BaseController{

    //TODO functions that can receive data to send emails with more than one TO email,
    // more than one CC and BCC and attachments

    public function send(): Response{
        // Validate Header -> Content-Type: application/json
        $contentTypeCheck = $this->validateJsonContentType();
        if ($contentTypeCheck instanceof Response) return $contentTypeCheck;
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

    private function process(string $to, string $subject, string $body): Response {
        $mail = new Sender(true);
        try {
            $mail->isSMTP();
            $mail->Host =  getenv('EMAIL_HOSTPATH') ?: '127.0.0.1';
            $mail->Port = getenv('EMAIL_SERVPORT') ?: 25;
            $mail->SMTPAuth = getenv('EMAIL_SMTPAUTH') ?: false;
            $mail->Username = getenv('EMAIL_USERNAME') ?: '';
            $mail->Password = getenv('EMAIL_PASSWORD') ?: '';
            $mail->SMTPSecure = Sender::ENCRYPTION_SMTPS;
            $mail->SMTPDebug = SMTP::DEBUG_OFF;

            $mail->setFrom(getenv('EMAIL_REP_ADDR') ?: 'no-reply@maxmilahomecare.com', getenv('EMAIL_REP_NAME') ?: 'Maxmila Homecare Test System');
            $mail->addAddress($to);

            $mail->Subject = $subject;
            $mail->Body = $body;

            $result = $mail->send();

            $success = $result['success'];
            $message = $result['message'];

            if ($success) {
                $data = [
                    'email' => $to,
                    'action' => 'email sent',
                    'message' => $message
                ];
                return $this->respondWithSuccess($data, 201);
            } else {
                return $this->respondWithError($message, 417);
            }
        } catch (\Exception $e) {
            return $this->respondWithError('Exception: ' . $e->getMessage() . ' ' . $mail->ErrorInfo, 417);
        }
    }
}