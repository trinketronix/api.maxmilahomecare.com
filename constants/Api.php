<?php

namespace Api\Constants;
class Api {
    public const string NAME = 'Maxmila Homecare Rest API';
    public const string VERSION = '1.0.0';
    public const string COPYRIGHT = 'Maxmila Homecare LLC & Trinketronix LLC ®️Copyright ';
    public static function getAppName(): string{
        return self::NAME;
    }
    public static function getVersion(): string{
        return self::VERSION;
    }
    public static function getCopyright(): string{
        return self::COPYRIGHT . self::getCurrentYear();
    }
    public static function getCurrentYear(): string {
        return date("Y");
    }
}