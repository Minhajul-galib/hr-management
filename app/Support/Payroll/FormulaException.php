<?php

namespace App\Support\Payroll;

use RuntimeException;

/** A formula that cannot be read; the message says what is wrong, in words an HR user follows. */
class FormulaException extends RuntimeException {}
