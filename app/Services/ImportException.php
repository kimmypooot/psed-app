<?php

namespace App\Services;

use RuntimeException;

/** A problem with the file as a whole (not one row); the message is shown to the user as it is. */
class ImportException extends RuntimeException {}
