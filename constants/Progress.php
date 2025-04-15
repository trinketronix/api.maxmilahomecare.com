<?php

namespace Api\Constants;

class Progress {
    public const int CANCELED = -1;
    public const int SCHEDULED = 0;
    public const int IN_PROGRESS = 1;
    public const int COMPLETED = 2;
    public const int PAID = 3;
    public const int CHECKIN = 1;
    public const int CHECKOUT = 2;
    public const int APPROVED = 3;
}