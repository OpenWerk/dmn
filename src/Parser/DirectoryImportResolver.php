<?php

declare(strict_types=1);

namespace OpenWerk\DecisionModelAndNotation\Parser;

use OpenWerk\DecisionModelAndNotation\Diagnostics\Severity;
use OpenWerk\DecisionModelAndNotation\Model\Definitions;
use OpenWerk\DecisionModelAndNotation\Validation\ValidationMessage;

/**
 * Resolves model imports against the `.dmn` files of one directory — the
 * convention used by the DMN TCK and by most modeling tools: a model's
 * imports sit next to it.
 *
 * Sibling files are probed once for their declared namespace (a cheap read
 * of the root element); a file parses fully only when its namespace is
 * actually imported, and at most once. The resolver guards against import
 * cycles by answering null for a namespace that is already being resolved
 * further up the stack.
 */
final class DirectoryImportResolver implements ImportResolver
{
    /** @var array<string, Definitions> */
    private array $byNamespace = [];

    /** @var array<string, list<string>>|null candidate files per declared namespace; null until probed */
    private ?array $filesByNamespace = null;

    /** @var array<string, true> */
    private array $inProgress = [];

    /** @var list<ValidationMessage> */
    private array $messages = [];

    public function __construct(private readonly string $directory)
    {
    }

    public function resolve(string $namespace): ?Definitions
    {
        if (isset($this->byNamespace[$namespace])) {
            return $this->byNamespace[$namespace];
        }

        if (isset($this->inProgress[$namespace])) {
            $this->messages[] = new ValidationMessage(
                Severity::Warning,
                sprintf('import cycle detected while resolving namespace %s', var_export($namespace, true)),
            );

            return null;
        }

        // Only the namespace probe touches every sibling file; a full parse
        // runs on demand, for the namespaces actually imported.
        $this->filesByNamespace ??= $this->probeCandidateFiles();

        foreach ($this->filesByNamespace[$namespace] ?? [] as $file) {
            $this->inProgress[$namespace] = true;

            try {
                $result = (new DmnParser($this))->parse((string) file_get_contents($file));
            } catch (\Throwable $exception) {
                $this->messages[] = new ValidationMessage(
                    Severity::Warning,
                    sprintf('imported model %s cannot be parsed: %s', basename($file), $exception->getMessage()),
                );

                continue;
            } finally {
                unset($this->inProgress[$namespace]);
            }

            foreach ($result->messages as $message) {
                $this->messages[] = new ValidationMessage(
                    $message->severity,
                    sprintf('in imported model %s: %s', basename($file), $message->message),
                    $message->elementId,
                    $message->line,
                );
            }

            return $this->byNamespace[$namespace] = $result->definitions;
        }

        return null;
    }

    public function drainMessages(): array
    {
        $messages = $this->messages;
        $this->messages = [];

        return $messages;
    }

    /**
     * Probes every sibling `.dmn` file for its declared namespace, in glob
     * order (so the first file declaring a namespace wins, and further files
     * remain fallbacks if it turns out unparseable).
     *
     * @return array<string, list<string>>
     */
    private function probeCandidateFiles(): array
    {
        $files = glob(rtrim($this->directory, '/') . '/*.dmn');
        $byNamespace = [];

        foreach ($files === false ? [] : $files as $file) {
            $declared = self::declaredNamespace($file);

            if ($declared !== null) {
                $byNamespace[$declared][] = $file;
            }
        }

        return $byNamespace;
    }

    /**
     * Reads just the root element's namespace attribute — cheap enough to
     * probe every sibling file without fully parsing it.
     */
    private static function declaredNamespace(string $file): ?string
    {
        $xml = @file_get_contents($file);

        if ($xml === false) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $reader = \XMLReader::XML($xml, null, LIBXML_NONET);

            if (!$reader instanceof \XMLReader) {
                return null;
            }

            while (@$reader->read()) {
                if ($reader->nodeType === \XMLReader::ELEMENT) {
                    $namespace = $reader->getAttribute('namespace');
                    $reader->close();

                    return $namespace === '' || $namespace === null ? null : $namespace;
                }
            }

            $reader->close();
        } finally {
            libxml_use_internal_errors($previous);
        }

        return null;
    }
}
