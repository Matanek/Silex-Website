<?php

declare(strict_types=1);

namespace Silex\Web;

use Silex\Web\Documentation\DocumentRepository;
use Silex\Web\Ecosystem\ReleaseNotesRepository;
use Silex\Web\Ecosystem\SilexVersionResolver;
use Silex\Web\Http\Action\DocumentationAction;
use Silex\Web\Http\Action\HomeAction;
use Silex\Web\Http\Action\LocaleRedirectAction;
use Silex\Web\Http\Action\PackagesAction;
use Silex\Web\Http\Action\RegistryAction;
use Silex\Web\Http\Action\ReleaseNotesAction;
use Silex\Web\Http\LanguageNegotiator;
use Silex\Web\Http\Middleware\LocaleMiddleware;
use Silex\Web\Rendering\MarkdownRenderer;
use Slim\App;
use Slim\Factory\AppFactory as SlimAppFactory;
use Slim\Routing\RouteCollectorProxy;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class ApplicationFactory
{
    private const SUPPORTED_LOCALES = ['en', 'fr'];

    public static function create(string $root): App
    {
        $workspaceRoot = dirname($root);
        $twig = new Environment(
            new FilesystemLoader($root . '/templates'),
            [
                'autoescape' => 'html',
                'cache' => false,
                'strict_variables' => true,
            ],
        );
        $twig->addGlobal('release', self::releaseIdentifier($root));
        $twig->addGlobal('silex_version', SilexVersionResolver::resolve($root, $workspaceRoot));

        $releaseSourcesRoot = $root . '/var/content/sources';
        $documentationRoot = self::sourceRoot(
            'SILEX_DOCUMENTATION_ROOT',
            $workspaceRoot . '/Silex-Documentation',
            $releaseSourcesRoot . '/Silex-Documentation',
        );
        $registryRoot = self::sourceRoot('SILEX_REGISTRY_ROOT', $workspaceRoot . '/Silex-Registry', $releaseSourcesRoot . '/Silex-Registry');
        $packagesRoot = self::sourceRoot('SILEX_PACKAGES_ROOT', $workspaceRoot . '/Packages', $releaseSourcesRoot . '/Packages');
        $silexRoot = self::sourceRoot('SILEX_SOURCE_ROOT', $workspaceRoot . '/Silex', $releaseSourcesRoot . '/Silex');
        $usesReleaseSnapshot = $documentationRoot === $releaseSourcesRoot . '/Silex-Documentation'
            && $registryRoot === $releaseSourcesRoot . '/Silex-Registry'
            && $packagesRoot === $releaseSourcesRoot . '/Packages'
            && $silexRoot === $releaseSourcesRoot . '/Silex';

        $documents = new DocumentRepository(
            $documentationRoot,
            $registryRoot,
            $packagesRoot,
            $usesReleaseSnapshot ? $releaseSourcesRoot . '/snapshot.json' : null,
        );
        $markdown = new MarkdownRenderer();
        $releaseNotes = new ReleaseNotesRepository($silexRoot);
        $languages = new LanguageNegotiator(self::SUPPORTED_LOCALES, 'en');

        $app = SlimAppFactory::create();
        $app->get('/', new LocaleRedirectAction($languages));
        $app->group('/{locale:en|fr}', function (RouteCollectorProxy $group) use ($twig, $documents, $markdown, $releaseNotes): void {
            $home = new HomeAction($twig, $documents);
            $documentation = new DocumentationAction($twig, $documents, $markdown);
            $packages = new PackagesAction($twig, $documents);
            $registry = new RegistryAction($twig);
            $releases = new ReleaseNotesAction($twig, $releaseNotes, $markdown);

            $group->get('', $home);
            $group->get('/', $home);
            $group->get('/docs', $documentation);
            $group->get('/docs/', $documentation);
            $group->get('/docs/{document:.+}', $documentation);
            $group->get('/packages', $packages);
            $group->get('/packages/', $packages);
            $group->get('/registry', $registry);
            $group->get('/registry/', $registry);
            $group->get('/releases', $releases);
            $group->get('/releases/', $releases);
        })->add(new LocaleMiddleware(self::SUPPORTED_LOCALES));

        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(self::debugEnabled(), true, true);

        return $app;
    }

    private static function debugEnabled(): bool
    {
        return filter_var(getenv('SILEX_WEB_DEBUG') ?: false, FILTER_VALIDATE_BOOL);
    }

    private static function releaseIdentifier(string $root): string
    {
        $path = $root . '/release.txt';

        if (!is_file($path)) {
            return 'development';
        }

        $release = file_get_contents($path);

        if ($release === false || trim($release) === '') {
            return 'development';
        }

        return trim($release);
    }

    private static function sourceRoot(string $environmentName, string $workspaceSource, string $releaseSource): string
    {
        $configured = trim((string) getenv($environmentName));
        if ($configured !== '') {
            return $configured;
        }

        return is_dir($workspaceSource) ? $workspaceSource : $releaseSource;
    }
}
