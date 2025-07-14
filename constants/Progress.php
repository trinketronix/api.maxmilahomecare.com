<?php

namespace Api\Constants;
class Progress {
    public const int CANCELED = -1;
    public const int SCHEDULED = 0;
    public const int IN_PROGRESS = 1;  // Check-in
    public const int COMPLETED = 2;    // Check-out
    public const int PAID = 3;         // Approved

    // Aliases for clarity
    public const int CHECKIN = self::IN_PROGRESS;
    public const int CHECKOUT = self::COMPLETED;
    public const int APPROVED = self::PAID;
}