<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Feel\Parser;

use OpenWerk\DecisionModelAndNotation\Exception\FeelParseException;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\AnyTest;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\AtLiteral;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\Between;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\Binary;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\BinaryOp;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\BooleanLiteral;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\ComparisonRange;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\UnaryComparison;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\ComparisonTest;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\ContextLiteral;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\ExpressionTest;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\Filter;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\ForExpression;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\FunctionCall;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\FunctionLiteral;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\IfExpression;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\InExpression;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\InstanceOfExpression;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\IntervalTest;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\IterationContext;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\ListLiteral;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\Name;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\Negation;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\Node;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\NullLiteral;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\NumberLiteral;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\PathExpression;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\QuantifiedExpression;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\RangeLiteral;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\StringLiteral;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\TypeNode;
use OpenWerk\DecisionModelAndNotation\Feel\Ast\UnaryTests;
use OpenWerk\DecisionModelAndNotation\Feel\Lexer\Lexer;
use OpenWerk\DecisionModelAndNotation\Feel\Lexer\Token;
use OpenWerk\DecisionModelAndNotation\Feel\Lexer\TokenType;
use OpenWerk\DecisionModelAndNotation\Feel\Value\FeelNumber;

/**
 * Recursive-descent parser for FEEL expressions and unary tests.
 *
 * Covers literals (including list, context, range and function literals),
 * arithmetic, comparisons, `between`, `in`, `instance of`, conjunction and
 * disjunction, `if`/`for`/`some`/`every`, filters, paths and invocations
 * with positional or named arguments.
 *
 * Precedence, loosest to tightest: if/for/quantified < or < and <
 * comparison (incl. between/in/instance of) < additive < multiplicative <
 * exponentiation < unary minus < postfix (path, filter, invocation).
 *
 * @internal
 */
final class FeelParser
{
    /** Token types that may continue a multi-word name. */
    private const array NAME_CONTINUATION = [
        TokenType::Name,
        TokenType::And,
        TokenType::Or,
        TokenType::Not,
        TokenType::Between,
        TokenType::In,
        TokenType::Of,
        TokenType::For,
        TokenType::Return,
        TokenType::If,
        TokenType::Then,
        TokenType::Else,
    ];

    /** Two-word property names recognized after a dot. */
    private const array COMPOUND_PROPERTIES = [
        'time offset',
        'start included',
        'end included',
    ];

    private const array MULTIWORD_TYPE_NAMES = [
        'date and time',
        'days and time duration',
        'years and months duration',
    ];

    /** @var list<Token> */
    private array $tokens = [];

    private int $position = 0;

    private string $source = '';

    public function __construct(private readonly NameRegistry $names)
    {
    }

    public function parseExpression(string $source): Node
    {
        $this->init($source);
        $node = $this->expression();
        $this->expect(TokenType::Eof);

        return $node;
    }

    public function parseUnaryTests(string $source): UnaryTests
    {
        $this->init($source);

        if ($this->check(TokenType::Minus) && $this->peek(1)->type === TokenType::Eof) {
            return new UnaryTests(false, [new AnyTest()]);
        }

        $negated = false;

        if ($this->check(TokenType::Not) && $this->peek(1)->type === TokenType::LParen) {
            $this->advance();
            $this->advance();
            $tests = $this->positiveTests();
            $this->expect(TokenType::RParen);
            $negated = true;
        } else {
            $tests = $this->positiveTests();
        }

        $this->expect(TokenType::Eof);

        return new UnaryTests($negated, $tests);
    }

    private function init(string $source): void
    {
        $this->source = $source;
        $this->tokens = (new Lexer())->tokenize($source);
        $this->position = 0;
    }

    /**
     * @return list<Node>
     */
    private function positiveTests(): array
    {
        $tests = [$this->positiveTest()];

        while ($this->check(TokenType::Comma)) {
            $this->advance();
            $tests[] = $this->positiveTest();
        }

        return $tests;
    }

    private function positiveTest(): Node
    {
        $comparisonOp = match ($this->current()->type) {
            TokenType::Lt => BinaryOp::Lt,
            TokenType::Le => BinaryOp::Le,
            TokenType::Gt => BinaryOp::Gt,
            TokenType::Ge => BinaryOp::Ge,
            TokenType::Eq => BinaryOp::Eq,
            TokenType::Ne => BinaryOp::Ne,
            default => null,
        };

        if ($comparisonOp !== null) {
            $this->advance();

            return new ComparisonTest($comparisonOp, $this->additive());
        }

        if ($this->check(TokenType::LBracket)) {
            $this->advance();

            if ($this->check(TokenType::RBracket)) {
                $this->advance();

                return new ExpressionTest(new ListLiteral([]));
            }

            $first = $this->expression();

            if ($this->check(TokenType::DotDot)) {
                $this->advance();

                return $this->intervalEnd(true, $first);
            }

            $elements = [$first];

            while ($this->check(TokenType::Comma)) {
                $this->advance();
                $elements[] = $this->expression();
            }

            $this->expect(TokenType::RBracket);

            return new ExpressionTest(new ListLiteral($elements));
        }

        if ($this->check(TokenType::RBracket)) {
            $this->advance();

            return $this->intervalTail(false);
        }

        if ($this->check(TokenType::LParen)) {
            $this->advance();
            $inner = $this->expression();

            if ($this->check(TokenType::DotDot)) {
                $this->advance();

                return $this->intervalEnd(false, $inner);
            }

            $this->expect(TokenType::RParen);

            return new ExpressionTest($this->postfixOn($inner));
        }

        return new ExpressionTest($this->expression());
    }

    private function intervalTail(bool $startClosed): Node
    {
        $start = $this->expression();
        $this->expect(TokenType::DotDot);

        return $this->intervalEnd($startClosed, $start);
    }

    private function intervalEnd(bool $startClosed, Node $start): Node
    {
        $end = $this->expression();

        $endClosed = match ($this->current()->type) {
            TokenType::RBracket => true,
            TokenType::RParen, TokenType::LBracket => false,
            default => throw $this->parseError('expected an interval end (")", "]" or "[")'),
        };

        $this->advance();

        return new IntervalTest($startClosed, $start, $end, $endClosed);
    }

    private function expression(): Node
    {
        switch ($this->current()->type) {
            case TokenType::If:
                return $this->ifExpression();

            case TokenType::For:
                return $this->forExpression();

            case TokenType::Some:
            case TokenType::Every:
                return $this->quantifiedExpression();

            default:
                return $this->disjunction();
        }
    }

    private function ifExpression(): Node
    {
        $this->expect(TokenType::If);
        $condition = $this->expression();
        $this->expect(TokenType::Then);
        $then = $this->expression();
        $this->expect(TokenType::Else);

        return new IfExpression($condition, $then, $this->expression());
    }

    private function forExpression(): Node
    {
        $this->expect(TokenType::For);
        $contexts = $this->iterationContexts();
        $this->expect(TokenType::Return);

        return new ForExpression($contexts, $this->expression());
    }

    private function quantifiedExpression(): Node
    {
        $every = $this->current()->type === TokenType::Every;
        $this->advance();
        $contexts = $this->iterationContexts();
        $this->expect(TokenType::Satisfies);

        return new QuantifiedExpression($every, $contexts, $this->expression());
    }

    /**
     * @return list<IterationContext>
     */
    private function iterationContexts(): array
    {
        $contexts = [];

        while (true) {
            $name = $this->expect(TokenType::Name)->text;
            $this->expect(TokenType::In);
            $domain = $this->disjunction();
            $domainEnd = null;

            if ($this->check(TokenType::DotDot)) {
                $this->advance();
                $domainEnd = $this->disjunction();
            }

            $contexts[] = new IterationContext($name, $domain, $domainEnd);

            if (!$this->check(TokenType::Comma)) {
                return $contexts;
            }

            $this->advance();
        }
    }

    private function disjunction(): Node
    {
        $node = $this->conjunction();

        while ($this->check(TokenType::Or)) {
            $this->advance();
            $node = new Binary(BinaryOp::Or, $node, $this->conjunction());
        }

        return $node;
    }

    private function conjunction(): Node
    {
        $node = $this->comparison();

        while ($this->check(TokenType::And)) {
            $this->advance();
            $node = new Binary(BinaryOp::And, $node, $this->comparison());
        }

        return $node;
    }

    private function comparison(): Node
    {
        $node = $this->additive();

        $op = match ($this->current()->type) {
            TokenType::Eq => BinaryOp::Eq,
            TokenType::Ne => BinaryOp::Ne,
            TokenType::Lt => BinaryOp::Lt,
            TokenType::Le => BinaryOp::Le,
            TokenType::Gt => BinaryOp::Gt,
            TokenType::Ge => BinaryOp::Ge,
            default => null,
        };

        if ($op !== null) {
            $this->advance();

            return new Binary($op, $node, $this->additive());
        }

        if ($this->check(TokenType::Between)) {
            $this->advance();
            $lower = $this->additive();
            $this->expect(TokenType::And);
            $upper = $this->additive();

            return new Between($node, $lower, $upper);
        }

        if ($this->check(TokenType::In)) {
            $this->advance();

            return new InExpression($node, $this->inOperandTests());
        }

        if ($this->check(TokenType::Instance)) {
            $this->advance();
            $this->expect(TokenType::Of);

            return new InstanceOfExpression($node, $this->typeNode());
        }

        return $node;
    }

    /**
     * The right side of `in`: a single positive unary test, or a
     * parenthesized list of them.
     *
     * @return list<Node>
     */
    private function inOperandTests(): array
    {
        if ($this->check(TokenType::LParen)) {
            $saved = $this->position;

            try {
                $this->advance();
                $tests = $this->positiveTests();
                $this->expect(TokenType::RParen);

                return $tests;
            } catch (FeelParseException) {
                // fall through: re-parse as a single test, e.g. `(1..5)`
            }

            $this->position = $saved;
        }

        return [$this->positiveTest()];
    }

    private function typeNode(): TypeNode
    {
        $name = $this->typeName();

        if ($name === 'list' || $name === 'range') {
            $parameters = [];

            if ($this->check(TokenType::Lt)) {
                $this->advance();
                $parameters[] = $this->typeNode();
                $this->expect(TokenType::Gt);
            }

            return new TypeNode(
                $name === 'list' ? TypeNode::KIND_LIST : TypeNode::KIND_RANGE,
                $name,
                $parameters,
            );
        }

        if ($name === 'context' && $this->check(TokenType::Lt)) {
            $this->advance();
            $members = [];

            if (!$this->check(TokenType::Gt)) {
                while (true) {
                    $memberName = $this->parseName();
                    $this->expect(TokenType::Colon);
                    $members[$memberName] = $this->typeNode();

                    if (!$this->check(TokenType::Comma)) {
                        break;
                    }

                    $this->advance();
                }
            }

            $this->expect(TokenType::Gt);

            return new TypeNode(TypeNode::KIND_CONTEXT, $name, [], $members);
        }

        if ($name === 'function' && $this->check(TokenType::Lt)) {
            $this->advance();
            $parameters = [];

            if (!$this->check(TokenType::Gt)) {
                while (true) {
                    $parameters[] = $this->typeNode();

                    if (!$this->check(TokenType::Comma)) {
                        break;
                    }

                    $this->advance();
                }
            }

            $this->expect(TokenType::Gt);
            $this->expect(TokenType::Minus);
            $this->expect(TokenType::Gt);
            $parameters[] = $this->typeNode();

            return new TypeNode(TypeNode::KIND_FUNCTION, $name, $parameters);
        }

        return new TypeNode(TypeNode::KIND_SIMPLE, $name);
    }

    private function typeName(): string
    {
        if ($this->check(TokenType::Null)) {
            $this->advance();

            return 'null';
        }

        if ($this->check(TokenType::Function)) {
            $this->advance();

            return 'function';
        }

        $first = $this->expect(TokenType::Name)->text;

        foreach (self::MULTIWORD_TYPE_NAMES as $candidate) {
            $parts = explode(' ', $candidate);

            if ($parts[0] !== $first) {
                continue;
            }

            $matches = true;

            foreach (\array_slice($parts, 1) as $offset => $part) {
                if ($this->peek($offset)->text !== $part) {
                    $matches = false;
                    break;
                }
            }

            if ($matches) {
                for ($i = 1, $max = \count($parts); $i < $max; $i++) {
                    $this->advance();
                }

                return $candidate;
            }
        }

        return $first;
    }

    private function additive(): Node
    {
        $node = $this->multiplicative();

        while (true) {
            $op = match ($this->current()->type) {
                TokenType::Plus => BinaryOp::Add,
                TokenType::Minus => BinaryOp::Subtract,
                default => null,
            };

            if ($op === null) {
                return $node;
            }

            $this->advance();
            $node = new Binary($op, $node, $this->multiplicative());
        }
    }

    private function multiplicative(): Node
    {
        $node = $this->exponentiation();

        while (true) {
            $op = match ($this->current()->type) {
                TokenType::Star => BinaryOp::Multiply,
                TokenType::Slash => BinaryOp::Divide,
                default => null,
            };

            if ($op === null) {
                return $node;
            }

            $this->advance();
            $node = new Binary($op, $node, $this->exponentiation());
        }
    }

    /**
     * Left-associative, and unary minus binds tighter: `-3**2` is 9 and
     * `3**4**5` is (3**4)**5 (TCK 0075/0100).
     */
    private function exponentiation(): Node
    {
        $node = $this->unary();

        while ($this->check(TokenType::Power)) {
            $this->advance();
            $node = new Binary(BinaryOp::Power, $node, $this->unary());
        }

        return $node;
    }

    private function unary(): Node
    {
        if ($this->check(TokenType::Minus)) {
            $this->advance();

            return new Negation($this->unary());
        }

        return $this->postfix();
    }

    private function postfix(): Node
    {
        return $this->postfixOn($this->primary());
    }

    /**
     * Applies postfix operators (path access, filters, invocations) to an
     * already-parsed base expression.
     */
    private function postfixOn(Node $node): Node
    {
        while (true) {
            if ($this->check(TokenType::Dot)) {
                $this->advance();
                $node = new PathExpression($node, $this->pathProperty());
                continue;
            }

            if ($this->check(TokenType::LBracket) && self::canStartExpression($this->peek(1)->type)) {
                $this->advance();
                $condition = $this->expression();
                $this->expect(TokenType::RBracket);
                $node = new Filter($node, $condition);
                continue;
            }

            if ($this->check(TokenType::LParen)) {
                $this->advance();
                [$arguments, $namedArguments] = $this->arguments();
                $node = new FunctionCall($node, $arguments, $namedArguments);
                continue;
            }

            return $node;
        }
    }

    /**
     * Whether a token can begin an expression. Used to tell a filter's `[`
     * from the open-end closer of a range (`]0..1[`).
     */
    private static function canStartExpression(TokenType $type): bool
    {
        return match ($type) {
            TokenType::Number, TokenType::String, TokenType::Name, TokenType::True, TokenType::False,
            TokenType::Null, TokenType::Not, TokenType::LParen, TokenType::LBracket, TokenType::LBrace,
            TokenType::At, TokenType::Minus, TokenType::If, TokenType::For, TokenType::Some,
            TokenType::Every, TokenType::Function => true,
            default => false,
        };
    }

    private function pathProperty(): string
    {
        $name = $this->expect(TokenType::Name)->text;

        foreach (self::COMPOUND_PROPERTIES as $compound) {
            [$first, $second] = explode(' ', $compound);

            if ($name === $first && $this->check(TokenType::Name) && $this->current()->text === $second) {
                $this->advance();

                return $compound;
            }
        }

        // Registered multi-word names extend the property (imported element
        // names like `myimport.Say Hello`), longest match first.
        return $this->extendRegisteredName($name);
    }

    /**
     * @return array{list<Node>, array<string, Node>}
     */
    private function arguments(): array
    {
        $arguments = [];
        $namedArguments = [];

        if (!$this->check(TokenType::RParen)) {
            while (true) {
                $name = $this->namedArgumentName();

                if ($name !== null) {
                    $namedArguments[$name] = $this->expression();
                } else {
                    $arguments[] = $this->expression();
                }

                if (!$this->check(TokenType::Comma)) {
                    break;
                }

                $this->advance();
            }
        }

        $this->expect(TokenType::RParen);

        return [$arguments, $namedArguments];
    }

    /**
     * Detects `name :` at the current position (named argument syntax) and
     * consumes it; returns null (without consuming) otherwise.
     */
    private function namedArgumentName(): ?string
    {
        if (!$this->check(TokenType::Name)) {
            return null;
        }

        $saved = $this->position;
        $parts = [$this->current()->text];
        $this->advance();

        while (\in_array($this->current()->type, self::NAME_CONTINUATION, true)) {
            $parts[] = $this->current()->text;
            $this->advance();
        }

        if ($this->check(TokenType::Colon)) {
            $this->advance();

            return implode(' ', $parts);
        }

        $this->position = $saved;

        return null;
    }

    private function primary(): Node
    {
        $token = $this->current();

        switch ($token->type) {
            case TokenType::Number:
                $this->advance();
                $literal = str_starts_with($token->text, '.') ? '0' . $token->text : $token->text;

                return new NumberLiteral(FeelNumber::of($literal));

            case TokenType::String:
                $this->advance();

                return new StringLiteral($token->text);

            case TokenType::True:
            case TokenType::False:
                $this->advance();

                return new BooleanLiteral($token->type === TokenType::True);

            case TokenType::Null:
                $this->advance();

                return new NullLiteral();

            case TokenType::At:
                $this->advance();
                $text = $this->expect(TokenType::String);

                return new AtLiteral($text->text);

            case TokenType::LParen:
                $this->advance();
                $node = $this->expression();

                if ($this->check(TokenType::DotDot)) {
                    $this->advance();

                    return $this->rangeEnd(false, $node);
                }

                $this->expect(TokenType::RParen);

                return $node;

            case TokenType::LBracket:
                return $this->listOrRange();

            case TokenType::RBracket:
                $this->advance();
                $start = $this->expression();
                $this->expect(TokenType::DotDot);

                return $this->rangeEnd(false, $start);

            case TokenType::LBrace:
                return $this->contextLiteral();

            case TokenType::Function:
                return $this->functionLiteral();

            case TokenType::Lt:
            case TokenType::Le:
            case TokenType::Gt:
            case TokenType::Ge:
                $op = match ($token->type) {
                    TokenType::Lt => BinaryOp::Lt,
                    TokenType::Le => BinaryOp::Le,
                    TokenType::Gt => BinaryOp::Gt,
                    default => BinaryOp::Ge,
                };
                $this->advance();

                return new ComparisonRange($op, $this->additive());

            case TokenType::Eq:
            case TokenType::Ne:
                $this->advance();

                return new UnaryComparison($token->type === TokenType::Ne, $this->additive());

            case TokenType::Name:
            case TokenType::Not:
                return new Name($this->parseName());

            default:
                throw $this->parseError(sprintf('unexpected token %s', var_export($token->text, true)));
        }
    }

    private function listOrRange(): Node
    {
        $this->expect(TokenType::LBracket);

        if ($this->check(TokenType::RBracket)) {
            $this->advance();

            return new ListLiteral([]);
        }

        $first = $this->expression();

        if ($this->check(TokenType::DotDot)) {
            $this->advance();

            return $this->rangeEnd(true, $first);
        }

        $elements = [$first];

        while ($this->check(TokenType::Comma)) {
            $this->advance();
            $elements[] = $this->expression();
        }

        $this->expect(TokenType::RBracket);

        return new ListLiteral($elements);
    }

    private function rangeEnd(bool $startClosed, Node $start): Node
    {
        $end = $this->expression();

        $endClosed = match ($this->current()->type) {
            TokenType::RBracket => true,
            TokenType::RParen, TokenType::LBracket => false,
            default => throw $this->parseError('expected a range end (")", "]" or "[")'),
        };

        $this->advance();

        return new RangeLiteral($startClosed, $start, $end, $endClosed);
    }

    private function contextLiteral(): Node
    {
        $this->expect(TokenType::LBrace);
        $entries = [];

        if (!$this->check(TokenType::RBrace)) {
            while (true) {
                $entries[] = [$this->contextKey(), $this->contextValue()];

                if (!$this->check(TokenType::Comma)) {
                    break;
                }

                $this->advance();
            }
        }

        $this->expect(TokenType::RBrace);

        return new ContextLiteral($entries);
    }

    private function contextKey(): string
    {
        if ($this->check(TokenType::String)) {
            $key = $this->current()->text;
            $this->advance();

            return $key;
        }

        if (!$this->check(TokenType::Name) && !\in_array($this->current()->type, self::NAME_CONTINUATION, true)) {
            throw $this->parseError('expected a context key');
        }

        $key = $this->current()->text;
        $this->advance();
        $afterSymbol = false;

        // Keys are self-declaring names: word runs join with spaces, and the
        // FEEL additional name symbols (. / - + *) join without ("foo+bar").
        while (!$this->check(TokenType::Colon)) {
            $type = $this->current()->type;
            $symbol = match ($type) {
                TokenType::Dot => '.',
                TokenType::Slash => '/',
                TokenType::Minus => '-',
                TokenType::Plus => '+',
                TokenType::Star => '*',
                default => null,
            };

            if ($symbol !== null) {
                $key .= $symbol;
                $afterSymbol = true;
                $this->advance();
                continue;
            }

            if (!\in_array($type, self::NAME_CONTINUATION, true) && $type !== TokenType::Number) {
                break;
            }

            $key .= ($afterSymbol ? '' : ' ') . $this->current()->text;
            $afterSymbol = false;
            $this->advance();
        }

        return $key;
    }

    private function contextValue(): Node
    {
        $this->expect(TokenType::Colon);

        return $this->expression();
    }

    private function functionLiteral(): Node
    {
        $this->expect(TokenType::Function);
        $this->expect(TokenType::LParen);
        $parameters = [];
        $parameterTypes = [];

        if (!$this->check(TokenType::RParen)) {
            while (true) {
                $parameters[] = $this->expect(TokenType::Name)->text;

                if ($this->check(TokenType::Colon)) {
                    $this->advance();
                    $parameterTypes[\count($parameters) - 1] = $this->typeNode();
                }

                if (!$this->check(TokenType::Comma)) {
                    break;
                }

                $this->advance();
            }
        }

        $this->expect(TokenType::RParen);

        if ($this->check(TokenType::External)) {
            $this->advance();
            // The external mapping context is parsed and discarded; external
            // functions are a documented deviation (no Java/PMML bindings).
            $this->expression();

            return new FunctionLiteral($parameters, null, true);
        }

        return new FunctionLiteral($parameters, $this->expression(), false, $parameterTypes);
    }

    /**
     * Consumes one name, greedily extending over adjacent name-like tokens
     * when the extended name is known in the registry (longest match wins).
     */
    private function parseName(): string
    {
        $token = $this->current();

        if ($token->type !== TokenType::Name && $token->type !== TokenType::Not) {
            throw $this->parseError('expected a name');
        }

        $this->advance();

        return $this->extendRegisteredName($token->text);
    }

    /**
     * Greedily extends a name over the following tokens while the extended
     * name is known in the registry (longest match wins). Word tokens join
     * with a space; the FEEL additional name symbols (`. - + * /`, as in
     * "Pre-bureau risk category" or "Person.Gender") join without one, and
     * a symbol alone never completes a match.
     */
    private function extendRegisteredName(string $name): string
    {
        $candidate = $name;
        $matchedTokens = 0;
        $lookahead = 0;
        $afterSymbol = false;

        while ($lookahead < $this->names->maxParts() - 1) {
            $next = $this->peek($lookahead);
            $symbol = self::nameSymbol($next->type);

            if ($symbol !== null) {
                if ($afterSymbol) {
                    break;
                }

                $candidate .= $symbol;
                $afterSymbol = true;
                $lookahead++;
                continue;
            }

            // Number tokens may continue a name ("Extra days case 1").
            if (!\in_array($next->type, self::NAME_CONTINUATION, true) && $next->type !== TokenType::Number) {
                break;
            }

            $candidate .= $afterSymbol ? $next->text : ' ' . $next->text;
            $afterSymbol = false;
            $lookahead++;

            if ($this->names->has($candidate)) {
                $matchedTokens = $lookahead;
            }
        }

        $afterSymbol = false;

        for ($i = 0; $i < $matchedTokens; $i++) {
            $part = $this->current();
            $this->advance();
            $symbol = self::nameSymbol($part->type);

            if ($symbol !== null) {
                $name .= $symbol;
                $afterSymbol = true;
                continue;
            }

            $name .= $afterSymbol ? $part->text : ' ' . $part->text;
            $afterSymbol = false;
        }

        return $name;
    }

    private static function nameSymbol(TokenType $type): ?string
    {
        return match ($type) {
            TokenType::Dot => '.',
            TokenType::Minus => '-',
            TokenType::Plus => '+',
            TokenType::Star => '*',
            TokenType::Slash => '/',
            default => null,
        };
    }

    private function current(): Token
    {
        return $this->tokens[$this->position];
    }

    private function peek(int $offset): Token
    {
        return $this->tokens[min($this->position + $offset, \count($this->tokens) - 1)];
    }

    private function check(TokenType $type): bool
    {
        return $this->current()->type === $type;
    }

    private function advance(): void
    {
        if ($this->position < \count($this->tokens) - 1) {
            $this->position++;
        }
    }

    private function expect(TokenType $type): Token
    {
        $token = $this->current();

        if ($token->type !== $type) {
            throw $this->parseError(sprintf(
                'expected %s but found %s',
                $type->name,
                $token->type === TokenType::Eof ? 'end of input' : var_export($token->text, true),
            ));
        }

        $this->advance();

        return $token;
    }

    private function parseError(string $message): FeelParseException
    {
        return new FeelParseException($message, $this->source, $this->current()->position);
    }
}
