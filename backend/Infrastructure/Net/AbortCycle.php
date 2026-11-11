<?php

declare(strict_types=1);

namespace App\Infrastructure\Net;

/** Interrupção do ciclo por timeout/unreachable/teto de requisições/incoerência do agente. */
final class AbortCycle extends \RuntimeException
{
}
