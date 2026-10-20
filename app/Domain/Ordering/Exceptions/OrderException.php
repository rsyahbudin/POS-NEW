<?php

namespace App\Domain\Ordering\Exceptions;

use DomainException;

/** Business rule violation that is safe to show to staff / customers. */
class OrderException extends DomainException {}
