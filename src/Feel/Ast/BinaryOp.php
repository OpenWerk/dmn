<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Ast;

/**
 * @internal
 */
enum BinaryOp: string
{
    case Add = '+';
    case Subtract = '-';
    case Multiply = '*';
    case Divide = '/';
    case Power = '**';
    case Eq = '=';
    case Ne = '!=';
    case Lt = '<';
    case Le = '<=';
    case Gt = '>';
    case Ge = '>=';
    case And = 'and';
    case Or = 'or';
}
