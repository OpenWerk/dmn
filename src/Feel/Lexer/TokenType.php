<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Lexer;

/**
 * @internal
 */
enum TokenType
{
    case Number;
    case String;
    case Name;
    case True;
    case False;
    case Null;
    case And;
    case Or;
    case Not;
    case Between;
    case In;
    case If;
    case Then;
    case Else;
    case For;
    case Return;
    case Some;
    case Every;
    case Satisfies;
    case Function;
    case External;
    case Instance;
    case Of;
    case Plus;
    case Minus;
    case Star;
    case Slash;
    case Power;
    case Eq;
    case Ne;
    case Lt;
    case Le;
    case Gt;
    case Ge;
    case LParen;
    case RParen;
    case LBracket;
    case RBracket;
    case LBrace;
    case RBrace;
    case Colon;
    case Comma;
    case DotDot;
    case Dot;
    case At;
    case Eof;
}
