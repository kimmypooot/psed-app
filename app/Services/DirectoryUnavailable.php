<?php

namespace App\Services;

use RuntimeException;

/** IMIS's people directory cannot be read (not set up, or not reachable); the message is shown as it is. */
class DirectoryUnavailable extends RuntimeException {}
