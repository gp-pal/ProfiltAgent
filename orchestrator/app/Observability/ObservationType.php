<?php

namespace App\Observability;

/**
 * Discriminates the two kinds of Langfuse observations a {@see TraceHandle} can
 * represent, replacing the previously loosely-typed 'span'|'generation' string.
 */
enum ObservationType: string
{
    case Span = 'span';
    case Generation = 'generation';
}
