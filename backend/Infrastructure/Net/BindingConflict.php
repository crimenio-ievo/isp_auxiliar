<?php

declare(strict_types=1);

namespace App\Infrastructure\Net;

/** Vinculo recusado pelas regras de unicidade (porta, ONU ou cliente ja ocupados). A mensagem e segura para o usuario. */
final class BindingConflict extends \RuntimeException
{
}
