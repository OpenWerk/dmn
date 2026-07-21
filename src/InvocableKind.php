<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation;

/**
 * The kind of an invocable model element.
 */
enum InvocableKind: string
{
    case Decision = 'decision';
    case BusinessKnowledgeModel = 'businessKnowledgeModel';
    case DecisionService = 'decisionService';
}
