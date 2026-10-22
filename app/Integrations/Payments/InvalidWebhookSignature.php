<?php

declare(strict_types=1);

namespace App\Integrations\Payments;

use RuntimeException;

final class InvalidWebhookSignature extends RuntimeException {}
