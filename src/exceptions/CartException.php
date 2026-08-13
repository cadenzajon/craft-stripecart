<?php

namespace cadenzajon\stripecart\exceptions;

use RuntimeException;

/**
 * A customer-facing cart error (ineligible product, cart limits, invalid input).
 * Extends RuntimeException so existing checkout error handling still catches it,
 * and its message is safe to show to the shopper.
 */
class CartException extends RuntimeException
{
}
