<?php

declare(strict_types=1);

namespace Silex\Web\Documentation;

use JsonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final readonly class DocumentRepository
{
    private string $documentationReference;

    public function __construct(
        private string $documentationRoot,
        private string $registryRoot,
        private string $packagesRoot,
        ?string $snapshotManifest = null,
    ) {
        $this->documentationReference = $this->readDocumentationReference($snapshotManifest);
    }

    /** @return array{path: string, title: string, markdown: string, source_url: string}|null */
    public function languageDocument(string $locale, string $path): ?array
    {
        $language = $this->languageDirectory($locale);
        if ($language === null) {
            return null;
        }

        $document = $this->readMarkdown($this->documentationRoot . '/' . $language, $path);
        if ($document === null) {
            return null;
        }

        return $document + [
            'source_url' => 'https://github.com/Matanek/Silex-Documentation/blob/'
                . $this->encodedReference($this->documentationReference)
                . '/' . $language . '/' . $document['path'],
        ];
    }

    /** @return list<array{label: string, items: list<array{path: string, label: string}>}> */
    public function languageNavigation(string $locale): array
    {
        $language = $this->languageDirectory($locale);
        if ($language === null) {
            return [];
        }

        $root = $this->documentationRoot . '/' . $language;
        $groups = [];
        foreach ($this->markdownFiles($root) as $path) {
            if (basename($path) === 'README.md') {
                continue;
            }

            $key = $this->navigationGroup($path);
            $markdown = $this->readFile($root . '/' . $path);
            $groups[$key][] = [
                'path' => $path,
                'label' => $this->extractTitle($markdown, $this->humanize(pathinfo($path, PATHINFO_FILENAME))),
            ];
        }

        $priority = [
            'Overview',
            'Learn',
            'Language',
            'Language/Values',
            'Language/Control-flow',
            'Language/Functions',
            'Language/Data-types',
            'Language/Collections',
            'Language/Ownership',
            'Language/Modules',
            'Language/Interop',
            'Tools',
            'Reference',
        ];
        uksort($groups, static function (string $left, string $right) use ($priority): int {
            $leftIndex = array_search($left, $priority, true);
            $rightIndex = array_search($right, $priority, true);

            return ($leftIndex === false ? PHP_INT_MAX : $leftIndex)
                <=> ($rightIndex === false ? PHP_INT_MAX : $rightIndex)
                ?: strcasecmp($left, $right);
        });

        $navigation = [];
        foreach ($groups as $key => $items) {
            $order = $this->linkedDocumentOrder($root, $this->groupOverviewPath($key));
            usort($items, static function (array $left, array $right) use ($order): int {
                $leftRank = $order[$left['path']] ?? PHP_INT_MAX;
                $rightRank = $order[$right['path']] ?? PHP_INT_MAX;

                return $leftRank <=> $rightRank ?: strcasecmp($left['label'], $right['label']);
            });
            $navigation[] = [
                'label' => $this->groupLabel($key, $locale),
                'items' => $items,
            ];
        }

        return $navigation;
    }

    /** @return list<array{name: string, repository: string, description: string}> */
    public function packages(string $locale): array
    {
        $root = $this->registryRoot . '/registry/v1/packages';
        if (!is_dir($root)) {
            return [];
        }

        $entries = glob($root . '/*.json');
        if ($entries === false) {
            return [];
        }

        $packages = [];
        foreach ($entries as $path) {
            $registration = $this->decodeJson($path, 'Registry entry');
            $name = $registration['name'] ?? null;
            $repository = $registration['repository'] ?? null;
            if (!is_string($name) || !$this->validPackageName($name) || basename($path) !== $name . '.json') {
                throw new RuntimeException(sprintf('Registry entry "%s" has an invalid package name.', $path));
            }
            if ($repository !== null && (!is_string($repository) || preg_match('#^https://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+(?:\.git)?$#', $repository) !== 1)) {
                throw new RuntimeException(sprintf('Registry entry "%s" has an invalid repository.', $path));
            }

            $metadata = $this->packageMetadata($name, $locale);
            $packages[] = $metadata + [
                'name' => $name,
                'repository' => $repository === null ? null : (preg_replace('/\.git$/', '', $repository) ?? $repository),
            ];
        }

        usort($packages, static fn (array $left, array $right): int => strcasecmp($left['name'], $right['name']));

        return $packages;
    }

    /** @return array{description: string} */
    private function packageMetadata(string $name, string $locale): array
    {
        $path = $this->packagesRoot . '/' . $name . '/Package.json';
        if (!is_file($path)) {
            throw new RuntimeException(sprintf('Package "%s" has no manifest available.', $name));
        }

        $manifest = $this->decodeJson($path, 'Package manifest');
        if (($manifest['name'] ?? null) !== $name) {
            throw new RuntimeException(sprintf('Package manifest "%s" has an invalid name.', $path));
        }

        $description = $this->packageDescription($manifest['description'] ?? null, $locale, $path);

        return ['description' => $description];
    }

    private function packageDescription(mixed $value, string $locale, string $path): string
    {
        if (is_string($value) && $this->validDescriptionLine($value)) {
            return $value;
        }
        if (!is_array($value) || $value === []) {
            throw new RuntimeException(sprintf('Package manifest "%s" has no description.', $path));
        }

        $translations = [];
        foreach ($value as $language => $description) {
            if (!is_string($language)
                || preg_match('/^[A-Za-z]{2,8}(?:-[A-Za-z0-9]{1,8})*$/', $language) !== 1
                || !is_string($description)
                || !$this->validDescriptionLine($description)
            ) {
                throw new RuntimeException(sprintf('Package manifest "%s" has an invalid localized description.', $path));
            }
            $normalizedLanguage = strtolower($language);
            if (isset($translations[$normalizedLanguage])) {
                throw new RuntimeException(sprintf('Package manifest "%s" repeats a description language.', $path));
            }
            $translations[$normalizedLanguage] = $description;
        }
        if (!isset($translations['en'])) {
            throw new RuntimeException(sprintf('Package manifest "%s" has no English description fallback.', $path));
        }

        $requested = strtolower(str_replace('_', '-', $locale));
        if (isset($translations[$requested])) {
            return $translations[$requested];
        }
        $separator = strpos($requested, '-');
        if ($separator !== false) {
            $primary = substr($requested, 0, $separator);
            if (isset($translations[$primary])) {
                return $translations[$primary];
            }
        }

        return $translations['en'];
    }

    private function validDescriptionLine(string $description): bool
    {
        return $description !== ''
            && trim($description) === $description
            && !str_contains($description, "\r")
            && !str_contains($description, "\n");
    }

    /** @return array{path: string, title: string, markdown: string}|null */
    private function readMarkdown(string $root, string $path): ?array
    {
        $path = $path === '' ? 'README.md' : rawurldecode($path);
        if (!str_ends_with(strtolower($path), '.md')) {
            $path .= '.md';
        }
        if (!$this->validDocumentPath($path)) {
            return null;
        }

        $rootPath = realpath($root);
        $filePath = realpath($root . '/' . $path);
        if ($rootPath === false || $filePath === false || !str_starts_with($filePath, $rootPath . DIRECTORY_SEPARATOR)) {
            return null;
        }
        if (!is_file($filePath)) {
            return null;
        }

        $markdown = $this->readFile($filePath);

        return [
            'path' => str_replace(DIRECTORY_SEPARATOR, '/', substr($filePath, strlen($rootPath) + 1)),
            'title' => $this->extractTitle($markdown, $this->humanize(pathinfo($path, PATHINFO_FILENAME))),
            'markdown' => $markdown,
        ];
    }

    /** @return list<string> */
    private function markdownFiles(string $root): array
    {
        $rootPath = realpath($root);
        if ($rootPath === false) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rootPath, RecursiveDirectoryIterator::SKIP_DOTS));
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'md') {
                $files[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($rootPath) + 1));
            }
        }
        sort($files, SORT_NATURAL | SORT_FLAG_CASE);

        return $files;
    }

    private function navigationGroup(string $path): string
    {
        $parts = explode('/', $path);
        if (count($parts) === 1) {
            return 'Overview';
        }
        if ($parts[0] === 'Language' && count($parts) >= 3) {
            return 'Language/' . $parts[1];
        }

        return $parts[0];
    }

    private function groupOverviewPath(string $key): string
    {
        return match ($key) {
            'Overview' => 'README.md',
            default => $key . '/README.md',
        };
    }

    /** @return array<string, int> */
    private function linkedDocumentOrder(string $root, string $overviewPath): array
    {
        $path = $root . '/' . $overviewPath;
        if (!is_file($path)) {
            return [];
        }

        preg_match_all('/\[[^]]+\]\(([^)#]+\.md)(?:#[^)]*)?\)/', $this->readFile($path), $matches);
        $base = dirname($overviewPath);
        $order = [];
        foreach ($matches[1] as $index => $linkedPath) {
            $candidate = ($base === '.' ? '' : $base . '/') . rawurldecode($linkedPath);
            $normalized = [];
            foreach (explode('/', $candidate) as $segment) {
                if ($segment === '' || $segment === '.') {
                    continue;
                }
                if ($segment === '..') {
                    array_pop($normalized);
                    continue;
                }
                $normalized[] = $segment;
            }
            $order[implode('/', $normalized)] = $index;
        }

        return $order;
    }

    private function groupLabel(string $key, string $locale): string
    {
        $labels = $locale === 'fr' ? [
            'Overview' => 'Accueil',
            'Learn' => 'Apprendre',
            'Language' => 'Langage',
            'Language/Values' => 'Valeurs',
            'Language/Control-flow' => 'Contrôle de l’exécution',
            'Language/Functions' => 'Fonctions',
            'Language/Data-types' => 'Types de données',
            'Language/Collections' => 'Collections',
            'Language/Ownership' => 'Possession',
            'Language/Modules' => 'Modules',
            'Language/Interop' => 'Interopérabilité',
            'Tools' => 'Outils',
            'Reference' => 'Référence',
        ] : [
            'Overview' => 'Overview',
            'Learn' => 'Learn',
            'Language' => 'Language',
            'Language/Values' => 'Values',
            'Language/Control-flow' => 'Control flow',
            'Language/Functions' => 'Functions',
            'Language/Data-types' => 'Data types',
            'Language/Collections' => 'Collections',
            'Language/Ownership' => 'Ownership',
            'Language/Modules' => 'Modules',
            'Language/Interop' => 'Interoperability',
            'Tools' => 'Tools',
            'Reference' => 'Reference',
        ];

        return $labels[$key] ?? $this->humanize(basename($key));
    }

    private function languageDirectory(string $locale): ?string
    {
        return match ($locale) {
            'en' => 'EN',
            'fr' => 'FR',
            default => null,
        };
    }

    private function readDocumentationReference(?string $snapshotManifest): string
    {
        if ($snapshotManifest === null || !is_file($snapshotManifest)) {
            return $this->localDocumentationReference() ?? 'main';
        }

        $snapshot = $this->decodeJson($snapshotManifest, 'Content snapshot');
        $documentation = $snapshot['documentation'] ?? null;
        $reference = is_array($documentation) ? ($documentation['commit'] ?? $documentation['reference'] ?? null) : null;
        if (!is_string($reference) || preg_match('/^[0-9A-Za-z._\/-]+$/', $reference) !== 1 || str_contains($reference, '..')) {
            throw new RuntimeException(sprintf('Content snapshot "%s" has an invalid documentation reference.', $snapshotManifest));
        }

        return $reference;
    }

    private function localDocumentationReference(): ?string
    {
        $gitPath = $this->documentationRoot . '/.git';
        if (is_file($gitPath)) {
            $pointer = trim($this->readFile($gitPath));
            if (!str_starts_with($pointer, 'gitdir: ')) {
                return null;
            }
            $directory = substr($pointer, strlen('gitdir: '));
            $gitPath = str_starts_with($directory, '/')
                ? $directory
                : $this->documentationRoot . '/' . $directory;
        }
        $headPath = $gitPath . '/HEAD';
        if (!is_file($headPath)) {
            return null;
        }

        $head = trim($this->readFile($headPath));
        if (str_starts_with($head, 'ref: refs/heads/')) {
            return substr($head, strlen('ref: refs/heads/'));
        }

        return preg_match('/^[0-9a-f]{40}$/', $head) === 1 ? $head : null;
    }

    /** @return array<string, mixed> */
    private function decodeJson(string $path, string $label): array
    {
        try {
            $value = json_decode($this->readFile($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException(sprintf('%s "%s" is invalid: %s', $label, $path, $error->getMessage()), 0, $error);
        }
        if (!is_array($value)) {
            throw new RuntimeException(sprintf('%s "%s" must contain an object.', $label, $path));
        }

        return $value;
    }

    private function encodedReference(string $reference): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $reference)));
    }

    private function readFile(string $path): string
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException(sprintf('Unable to read documentation file "%s".', $path));
        }

        return $contents;
    }

    private function extractTitle(string $markdown, string $fallback): string
    {
        return preg_match('/^#\s+(.+)$/m', $markdown, $match) === 1 ? trim($match[1]) : $fallback;
    }

    private function humanize(string $value): string
    {
        return ucfirst(str_replace(['-', '_'], ' ', $value));
    }

    private function validPackageName(string $name): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*$/', $name) === 1;
    }

    private function validDocumentPath(string $path): bool
    {
        return !str_contains($path, '..')
            && !str_starts_with($path, '/')
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]*\.md$/i', $path) === 1;
    }
}
