<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Tests\Parser;

use Brick\Math\BigDecimal;
use OpenWerk\DecisionModelAndNotation\Dmn;
use OpenWerk\DecisionModelAndNotation\Parser\DirectoryImportResolver;
use OpenWerk\DecisionModelAndNotation\Validation\ValidationMessage;
use PHPUnit\Framework\TestCase;

/**
 * The directory resolver probes sibling files for their namespace cheaply
 * and fully parses only the namespaces a model actually imports.
 */
final class DirectoryImportResolverTest extends TestCase
{
    private const string DIR = __DIR__ . '/../fixtures/imports-lazy';

    public function testUnrelatedSiblingsAreNotParsedAndLeakNoMessages(): void
    {
        // aaa-broken.dmn sorts before lib.dmn and contains a FEEL syntax
        // error; resolving urn:lazy-lib must not touch it.
        $model = Dmn::fromFile(self::DIR . '/main.dmn');

        self::assertSame([], $model->validationMessages());

        $result = $model->evaluateDecision('Scaled Level', [], ['urn:lazy-lib' => ['points' => 4]]);

        self::assertFalse($result->hasErrors());

        $value = $result->value();
        self::assertInstanceOf(BigDecimal::class, $value);
        self::assertSame('41', (string) $value);
    }

    public function testRequestedNamespaceStillReportsItsOwnFindings(): void
    {
        $resolver = new DirectoryImportResolver(self::DIR);

        self::assertNull($resolver->resolve('urn:does-not-exist'));
        self::assertSame([], $resolver->drainMessages());

        $broken = $resolver->resolve('urn:lazy-broken');

        self::assertNotNull($broken);
        self::assertSame('urn:lazy-broken', $broken->namespace);

        $messages = implode("\n", array_map(
            static fn(ValidationMessage $message): string => (string) $message,
            $resolver->drainMessages(),
        ));

        self::assertStringContainsString('aaa-broken.dmn', $messages);
    }
}
