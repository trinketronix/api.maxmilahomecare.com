<?php

namespace Api\Email;

/**
 * OAuthTokenProvider - OAuth2 token provider interface.
 * Provides base64 encoded OAuth2 auth strings for SMTP authentication.
 *
 * @see     OAuth
 * @see     SMTP::authenticate()
 */
interface OAuthTokenProvider
{
    /**
     * Generate a base64-encoded OAuth token ensuring that the access token has not expired.
     * The string to be base 64 encoded should be in the form:
     * "user=<user_email_address>\001auth=Bearer <access_token>\001\001"
     *
     * @return string
     */
    public function getOauth64();
}
