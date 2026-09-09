<?php

declare(strict_types=1);

namespace Silex\Web\Ecosystem;

use DateTimeImmutable;
use RuntimeException;

final readonly class ReleaseNotesRepository
{
    /** @var array<string, list<string>> */
    private const REQUIRED_SECTIONS = [
        'fr' => ['Pourquoi mettre à jour ?', 'Changements', 'Impact et migration'],
        'en' => ['Why upgrade?', 'Changes', 'Impact and migration'],
    ];

    public function __construct(private string $silexRoot)
    {
    }

    /** @return list<array{version: string, date: string, markdown: string}> */
    public function releases(string $locale): array
    {
        $path = match ($locale) {
            'fr' => $this->silexRoot . '/CHANGELOG.fr.md',
            'en' => $this->silexRoot . '/CHANGELOG.md',
            default => null,
        };
        if ($path === null || !is_file($path)) {
            return [];
        }

        $source = file_get_contents($path);
        if ($source === false) {
            throw new RuntimeException(sprintf('Unable to read release notes "%s".', $path));
        }

        preg_match_all(
            '/^## \[(\d+\.\d+\.\d+)\] - (\d{4}-\d{2}-\d{2})[ \t]*$/m',
            $source,
            $matches,
            PREG_OFFSET_CAPTURE,
        );
        if ($matches[0] === []) {
            throw new RuntimeException(sprintf('Release notes "%s" contain no version.', $path));
        }

        $releases = [];
        $seen = [];
        $previous = null;
        foreach ($matches[0] as $index => $heading) {
            $version = $matches[1][$index][0];
            $date = $matches[2][$index][0];
            if (isset($seen[$version])) {
                throw new RuntimeException(sprintf('Release notes "%s" repeat version %s.', $path, $version));
            }
            $seen[$version] = true;
            if (DateTimeImmutable::createFromFormat('!Y-m-d', $date)?->format('Y-m-d') !== $date) {
                throw new RuntimeException(sprintf('Release notes "%s" contain an invalid date.', $path));
            }

            $semanticVersion = array_map('intval', explode('.', $version));
            if ($previous !== null && $semanticVersion >= $previous) {
                throw new RuntimeException(sprintf('Release notes "%s" are not in descending order.', $path));
            }
            $previous = $semanticVersion;

            $start = $heading[1] + strlen($heading[0]);
            $end = $matches[0][$index + 1][1] ?? strlen($source);
            $markdown = trim(substr($source, $start, $end - $start));
            $this->validateSections($path, $locale, $version, $markdown);
            $releases[] = [
                'version' => $version,
                'date' => $date,
                'markdown' => $markdown,
            ];
        }

        return $releases;
    }

    private function validateSections(string $path, string $locale, string $version, string $markdown): void
    {
        preg_match_all('/^### (.+?)[ \t]*$/m', $markdown, $matches, PREG_OFFSET_CAPTURE);
        $headings = array_map(static fn (array $match): string => $match[0], $matches[1] ?? []);
        if ($headings !== self::REQUIRED_SECTIONS[$locale]) {
            throw new RuntimeException(sprintf('Release notes "%s" have an invalid structure for %s.', $path, $version));
        }
        foreach ($matches[0] as $index => $heading) {
            $start = $heading[1] + strlen($heading[0]);
            $end = $matches[0][$index + 1][1] ?? strlen($markdown);
            if (trim(substr($markdown, $start, $end - $start)) === '') {
                throw new RuntimeException(sprintf('Release notes "%s" have an empty section for %s.', $path, $version));
            }
        }
    }
}
