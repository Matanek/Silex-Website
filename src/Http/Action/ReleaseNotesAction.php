<?php

declare(strict_types=1);

namespace Silex\Web\Http\Action;

use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Silex\Web\Ecosystem\ReleaseNotesRepository;
use Silex\Web\Rendering\MarkdownRenderer;
use Twig\Environment;

final readonly class ReleaseNotesAction
{
    private const PER_PAGE = 8;

    public function __construct(
        private Environment $twig,
        private ReleaseNotesRepository $releaseNotes,
        private MarkdownRenderer $markdown,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $locale = (string) $request->getAttribute('locale');
        $page = $this->pageNumber($request->getQueryParams()['page'] ?? null);
        if ($page === null) {
            return $response->withStatus(404);
        }

        $releases = $this->releaseNotes->releases($locale);
        $totalPages = max(1, (int) ceil(count($releases) / self::PER_PAGE));
        if ($page > $totalPages) {
            return $response->withStatus(404);
        }

        $visibleReleases = array_slice($releases, ($page - 1) * self::PER_PAGE, self::PER_PAGE);
        foreach ($visibleReleases as &$release) {
            $release['html'] = $this->markdown->toHtml(
                $release['markdown'],
                $locale === 'fr' ? 'CHANGELOG.fr.md' : 'CHANGELOG.md',
                'https://github.com/Matanek/Silex/blob/v' . $release['version'],
                'https://github.com/Matanek/Silex/blob/v' . $release['version'],
            );
            $release['display_date'] = $this->displayDate($release['date'], $locale);
            $release['url'] = 'https://github.com/Matanek/Silex/releases/tag/v' . $release['version'];
        }
        unset($release);

        $alternateLocale = $locale === 'fr' ? 'en' : 'fr';
        $pageQuery = $page === 1 ? '' : '?page=' . $page;
        $response->getBody()->write($this->twig->render('release-notes.twig', [
            'locale' => $locale,
            'alternate_locale' => $alternateLocale,
            'alternate_label' => $locale === 'fr' ? 'English' : 'Français',
            'alternate_path' => '/' . $alternateLocale . '/releases' . $pageQuery,
            'releases' => $visibleReleases,
            'page' => $page,
            'total_pages' => $totalPages,
        ]));

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    private function pageNumber(mixed $value): ?int
    {
        if ($value === null) {
            return 1;
        }
        if (!is_string($value) || preg_match('/^[1-9]\d*$/', $value) !== 1) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
    }

    private function displayDate(string $date, string $locale): string
    {
        $value = new DateTimeImmutable($date);
        if ($locale === 'en') {
            return $value->format('F j, Y');
        }

        $months = [
            1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
            'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre',
        ];

        return sprintf('%d %s %d', (int) $value->format('j'), $months[(int) $value->format('n')], (int) $value->format('Y'));
    }
}
