<?php

namespace Api\Encoding;

class Base64 {
    // Constants for salt and pepper (you might want to store these in a secure configuration)
    private const SALT_PREFIX = "S@1t3dPr3f1x";  // Salt prefix
    private const PEPPER_SUFFIX = "P3pp3rSuf1x"; // Pepper suffix

    public static function encode(string $data): string {
        return base64_encode($data);
    }

    public static function decode(string $data): string {
        return base64_decode($data);
    }

    public static function urlSafeEncode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function urlSafeDecode(string $data): string {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', 3 - (3 + strlen($data)) % 4));
    }

    /**
     * Encodes data with added salt prefix and pepper suffix
     * @param string $data Original data to encode
     * @return string Encoded string with salt and pepper
     */
    public static function encodingSaltedPeppered(string $data): string {
        // Add salt and pepper to the original data
        $saltedPeppered = self::SALT_PREFIX . $data . self::PEPPER_SUFFIX;
        // Encode the combined string
        return base64_encode($saltedPeppered);
    }

    /**
     * Decodes and removes salt and pepper from encoded string
     * @param string $data Encoded string with salt and pepper
     * @return string Original data without salt and pepper
     */
    public static function decodingSaltedPeppered(string $data): string {
        // Decode the string
        $decoded = base64_decode($data);

        // Remove salt prefix and pepper suffix
        $withoutSalt = substr($decoded, strlen(self::SALT_PREFIX));
        return substr($withoutSalt, 0, -strlen(self::PEPPER_SUFFIX));
    }
}