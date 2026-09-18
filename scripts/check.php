<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface;
use Silex\Web\ApplicationFactory;
use Silex\Web\Documentation\DocumentRepository;
use Silex\Web\Ecosystem\ReleaseNotesRepository;
use Silex\Web\Ecosystem\SilexVersionResolver;
use Silex\Web\Rendering\MarkdownRenderer;
use Slim\Psr7\Factory\ServerRequestFactory;

$root = dirname(__DIR__);
putenv('SILEX_DOCUMENTATION_ROOT=' . $root . '/tests/fixtures/Silex-Documentation');
putenv('SILEX_REGISTRY_ROOT=' . $root . '/tests/fixtures/Silex-Registry');
putenv('SILEX_PACKAGES_ROOT=' . $root . '/tests/fixtures/Packages');
putenv('SILEX_SOURCE_ROOT=' . $root . '/tests/fixtures/Silex');
putenv('SILEX_VERSION=9.8.7');

$phpFiles = [];
foreach ([$root . '/public', $root . '/scripts', $root . '/src'] as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $phpFiles[] = $file->getPathname();
        }
    }
}
sort($phpFiles);
foreach ($phpFiles as $file) {
    passthru(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file), $status);
    if ($status !== 0) {
        exit($status);
    }
}

require $root . '/vendor/autoload.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
};

$requestFactory = new ServerRequestFactory();
$handle = static function (string $uri, array $headers = [], array $cookies = []) use ($root, $requestFactory): ResponseInterface {
    $request = $requestFactory->createServerRequest('GET', $uri);
    foreach ($headers as $name => $value) {
        $request = $request->withHeader($name, $value);
    }
    if ($cookies !== []) {
        $request = $request->withCookieParams($cookies);
    }

    return ApplicationFactory::create($root)->handle($request);
};

$frenchRedirect = $handle('https://silex.test/', ['Accept-Language' => 'fr-FR, en;q=0.8']);
$assert($frenchRedirect->getStatusCode() === 302, 'The root must redirect to the negotiated language.');
$assert($frenchRedirect->getHeaderLine('Location') === '/fr/', 'French browser preferences must select /fr/.');

$cookieRedirect = $handle(
    'https://silex.test/',
    ['Accept-Language' => 'fr-FR'],
    ['silex_locale' => 'en'],
);
$assert($cookieRedirect->getHeaderLine('Location') === '/en/', 'The saved language must override browser preferences.');

$home = $handle('https://silex.test/fr/');
$homeBody = (string) $home->getBody();
$releaseFile = $root . '/release.txt';
$expectedRelease = is_file($releaseFile) ? trim((string) file_get_contents($releaseFile)) : 'development';
$assert($home->getStatusCode() === 200, 'The French home page must respond with HTTP 200.');
$assert(str_contains($homeBody, '<html lang="fr">'), 'The French page language is missing.');
$assert(str_contains($homeBody, '<body class="home-page">'), 'The home page section layout is missing.');
$assert(str_contains($homeBody, '<div class="site-header-inner">'), 'The full-width header container is missing.');
$assert(str_contains($homeBody, 'data-navigation-toggle'), 'The mobile navigation toggle is missing.');
$assert(str_contains($homeBody, 'id="mobile-navigation" role="dialog" aria-modal="true"'), 'The mobile navigation panel must expose its modal dialog semantics.');
$assert(str_contains($homeBody, 'aria-hidden="true" inert data-navigation-panel'), 'The closed mobile navigation panel must be hidden from assistive technology and keyboard focus.');
$assert(str_contains($homeBody, '<script src="/assets/navigation.js" defer></script>'), 'The mobile navigation interaction script is missing.');
$assert(substr_count($homeBody, 'class="section-container') >= 5, 'The home page full-width sections are missing their containers.');
$assert(str_contains($homeBody, 'Code moderne'), 'The French home page content is missing.');
$assert(str_contains($homeBody, 'Concentrez-vous sur votre jeu, pas sur sa configuration.'), 'The French audience-focused hero introduction is missing.');
$assert(preg_match('/>Simplicité<.*>Efficacité<.*>Sécurité</s', $homeBody) === 1, 'The French homepage benefits are missing or ordered incorrectly.');
$assert(str_contains($homeBody, 'Compilation cross-platform pour Windows, macOS et Linux. Arm64 et x64.'), 'The French cross-platform benefit must include both desktop architectures.');
$assert(str_contains($homeBody, 'Le typage et l’ownership rendent la circulation des données prévisible.'), 'The French safety benefit is missing.');
$assert(str_contains($homeBody, '<p class="eyebrow section-tag" id="install-title">Installer Silex</p>'), 'The tagged French installation heading is missing.');
$assert(!str_contains($homeBody, 'Le compilateur autonome ne demande ni Zig, ni Git'), 'The removed French installation introduction is still rendered.');
$assert(str_contains($homeBody, 'href="/en/"'), 'The home page language switch must preserve the page.');
$assert(str_contains($home->getHeaderLine('Set-Cookie'), 'silex_locale=fr'), 'Localized routes must save the selected language.');
$assert(str_contains($home->getHeaderLine('Set-Cookie'), 'Secure'), 'HTTPS locale cookies must be secure.');
$assert(str_contains($homeBody, 'silex run Main.sx'), 'The canonical quickstart command is missing.');
$assert(str_contains($homeBody, 'struct</span> <span class="type">Greeter</span>'), 'The typed hero example is missing.');
$assert(str_contains($homeBody, 'Hello from $(self.name)!'), 'The hero string interpolation is missing.');
$assert(str_contains($homeBody, 'Hello from Silex!'), 'The hero example output is missing.');
$assert(str_contains($homeBody, 'id="zed"'), 'The Zed installation section is missing.');
$assert(str_contains($homeBody, '<p class="eyebrow section-tag" id="zed-title">Installer l’extension Zed</p>'), 'The tagged French Zed section heading is missing.');
$assert(!str_contains($homeBody, 'Installer l’extension Silex pour Zed.'), 'The duplicated French Zed installation heading is still rendered.');
$assert(str_contains($homeBody, 'Installation rapide') && str_contains($homeBody, 'En attente de validation'), 'The unavailable French Zed gallery path is missing.');
$assert(str_contains($homeBody, 'Installation manuelle') && str_contains($homeBody, 'Disponible maintenant'), 'The available French Zed manual path is missing.');
$assert(str_contains($homeBody, '<strong class="zed-titlebar-availability">Disponible maintenant</strong>'), 'The French Zed title bar must contain one readable availability label.');
$assert(str_contains($homeBody, '<a href="https://zed.dev/">Zed</a> est un éditeur de code moderne.'), 'The French Zed introduction must identify and link to the code editor.');
$assert(str_contains($homeBody, 'rustup target add wasm32-wasip2'), 'The Zed WebAssembly target setup is missing.');
$assert(str_contains($homeBody, '<a href="https://rust-lang.org/tools/install/">Installez-le avec rustup</a>'), 'The French Zed setup must link to the official Rust installer.');
$assert(str_contains($homeBody, 'git clone https://github.com/Matanek/Silex-Extension-Zed.git'), 'The Zed extension clone command is missing.');
$assert(str_contains($homeBody, 'zed: install dev extension'), 'The Zed development installation command is missing.');
$assert(str_contains($homeBody, 'href="https://github.com/zed-industries/extensions/pull/7190"'), 'The Zed catalog pull request is missing.');
$assert(str_contains($homeBody, 'v9.8.7'), 'The configured Silex version is missing.');
$assert(str_contains($homeBody, 'data-package-count="2"'), 'The home page package catalog is missing.');
$assert(str_contains($homeBody, 'href="/fr/#packages">Packages</a>'), 'The French navigation must link to the home page package catalog.');
$assert(str_contains($homeBody, 'href="https://github.com/Matanek/Silex-Lib-Example"'), 'Package cards must link to their canonical repository.');
$assert(str_contains($homeBody, 'class="package-card-repository"'), 'The package repository link must own the full-card interaction.');
$assert(str_contains($homeBody, '<span class="package-card-name">Legacy</span>'), 'A published package without a source repository must remain visible.');
$assert(str_contains($homeBody, 'Présente des métadonnées réutilisables pour un package Silex.'), 'French package cards must display their localized manifest description.');
$assert(str_contains($homeBody, 'Uses one package description for every locale.'), 'Plain package descriptions must remain valid.');
$assert(!str_contains($homeBody, 'v1.0.0'), 'Package cards must not expose a package version.');
$assert(!str_contains($homeBody, '<span>GitHub</span>'), 'Package cards must not repeat their repository host.');
$assert(!str_contains($homeBody, 'Consultez le code, les versions'), 'Package cards must not repeat generic repository guidance.');
$assert(!str_contains($homeBody, '>Dépôt <span'), 'Package cards must not repeat a repository action inside the card.');
$assert(!str_contains($homeBody, '/fr/packages/Example'), 'Package cards must not link to local package documentation.');
$assert(substr_count($homeBody, 'class="eyebrow section-tag"') === 3, 'Installation and package sections must share the same title tag.');
$assert(str_contains($homeBody, '<ul class="hero-principles">'), 'The homepage benefits must be integrated into the hero.');
$assert(substr_count($homeBody, 'class="hero-principle"') === 3, 'The hero must render exactly three benefits.');
$assert(!str_contains($homeBody, 'Pourquoi Silex ?'), 'The redundant French principles heading must not be rendered.');
$assert(!str_contains($homeBody, 'class="principles"'), 'The standalone principles section must not be rendered.');
$assert(str_contains($homeBody, '<h2 id="showcase-title">Exemples</h2>'), 'The French Silex showcase heading is missing.');
$assert(str_contains($homeBody, 'href="https://github.com/Matanek/Silex-Examples">Silex-Examples</a>'), 'The showcase must link to the Silex examples repository.');
$assert(str_contains($homeBody, 'exemples fournis avec Silex'), 'The French showcase must identify the images as distributed examples.');
$assert(substr_count($homeBody, 'data-showcase-item') === 16, 'The home page must render all sixteen Silex showcase images.');
$assert(substr_count($homeBody, 'class="showcase-thumbnail"') === 16, 'The showcase must render image-only thumbnails.');
$assert(substr_count($homeBody, '<picture>') === 16, 'Every showcase item must provide responsive image sources.');
$assert(substr_count($homeBody, '<source media="(max-width: 650px)"') === 16, 'Every mobile showcase item must use its full image.');
$assert(substr_count($homeBody, 'data-full-src=') === 16, 'Every showcase item must expose its full image to the desktop lightbox.');
$assert(substr_count($homeBody, '<figcaption') === 1, 'Only the lightbox may render a showcase caption.');
$assert(substr_count($homeBody, 'loading="lazy"') >= 16, 'Showcase thumbnails must load lazily.');
$assert(str_contains($homeBody, 'data-showcase-lightbox'), 'The showcase lightbox is missing.');
$assert(str_contains($homeBody, 'data-count-label="{current}/{total}"'), 'The showcase counter must use the compact current/total format.');
$assert(str_contains($homeBody, '<script src="/assets/showcase.js" defer></script>'), 'The showcase interaction script is missing.');
$showcaseSlugs = [
    'canvas-shapes',
    'shadow-debug',
    'gfx-world',
    'particle-system',
    'plot-overview',
    'plot-science',
    'plot-circular',
    'plot-areas',
    'material-showcase',
    'vector-font-typography',
    'vector-font-scene2d',
    'canvas-clock',
    'shadow-volumes',
    'minesweeper-board',
    'minesweeper-setup',
    'viewport-axis-3d',
];
foreach ($showcaseSlugs as $showcaseSlug) {
    $thumbnailPath = $root . '/public/assets/showcase/thumbs/' . $showcaseSlug . '.webp';
    $fullPath = $root . '/public/assets/showcase/full/' . $showcaseSlug . '.webp';
    $thumbnailInfo = getimagesize($thumbnailPath);
    $fullInfo = getimagesize($fullPath);
    $assert(
        $thumbnailInfo !== false
            && $thumbnailInfo[0] === 360
            && $thumbnailInfo[1] === 225
            && ($thumbnailInfo['mime'] ?? null) === 'image/webp'
            && filesize($thumbnailPath) <= 1024 * 1024,
        'Every showcase thumbnail must be a 360x225 WebP image no larger than 1 MiB.',
    );
    $assert(
        $fullInfo !== false
            && $fullInfo[0] <= 1440
            && ($fullInfo['mime'] ?? null) === 'image/webp'
            && filesize($fullPath) <= 1024 * 1024,
        'Every full showcase image must be a WebP image no wider than 1440 pixels or larger than 1 MiB.',
    );
}
$assert(str_contains($homeBody, 'Silex Web release: ' . $expectedRelease), 'The deployment marker does not match release.txt.');
$assert(str_contains($homeBody, '<link rel="icon" href="/icon.svg" type="image/svg+xml">'), 'The Silex icon is missing.');

$englishHome = $handle('https://silex.test/en/');
$englishHomeBody = (string) $englishHome->getBody();
$assert($englishHome->getStatusCode() === 200, 'The English home page must respond with HTTP 200.');
$assert(str_contains($englishHomeBody, '<html lang="en">') && str_contains($englishHomeBody, 'Modern Code'), 'The English home page is missing.');
$assert(str_contains($englishHomeBody, 'Focus on your game, not its setup.'), 'The English audience-focused hero introduction is missing.');
$assert(preg_match('/>Simplicity<.*>Efficiency<.*>Safety</s', $englishHomeBody) === 1, 'The English homepage benefits are missing or ordered incorrectly.');
$assert(str_contains($englishHomeBody, 'Cross-platform compilation for Windows, macOS, and Linux. ARM64 and x64.'), 'The English cross-platform benefit must include both desktop architectures.');
$assert(str_contains($englishHomeBody, 'Typing and ownership make data flow predictable.'), 'The English safety benefit is missing.');
$assert(str_contains($englishHomeBody, '<p class="eyebrow section-tag" id="install-title">Install Silex</p>'), 'The tagged English installation heading is missing.');
$assert(!str_contains($englishHomeBody, 'The standalone compiler does not require Zig or Git'), 'The removed English installation introduction is still rendered.');
$assert(str_contains($englishHomeBody, 'href="/fr/"'), 'The English language switch is missing.');
$assert(str_contains($englishHomeBody, '<p class="eyebrow section-tag" id="zed-title">Install the Zed extension</p>'), 'The tagged English Zed section heading is missing.');
$assert(!str_contains($englishHomeBody, 'Install the Silex extension for Zed.'), 'The duplicated English Zed installation heading is still rendered.');
$assert(str_contains($englishHomeBody, 'Quick installation') && str_contains($englishHomeBody, 'Awaiting approval'), 'The unavailable English Zed gallery path is missing.');
$assert(str_contains($englishHomeBody, 'Manual installation') && str_contains($englishHomeBody, 'Available now'), 'The available English Zed manual path is missing.');
$assert(str_contains($englishHomeBody, '<strong class="zed-titlebar-availability">Available now</strong>'), 'The English Zed title bar must contain one readable availability label.');
$assert(str_contains($englishHomeBody, '<a href="https://zed.dev/">Zed</a> is a modern code editor.'), 'The English Zed introduction must identify and link to the code editor.');
$assert(str_contains($englishHomeBody, '<a href="https://rust-lang.org/tools/install/">Install it with rustup</a>'), 'The English Zed setup must link to the official Rust installer.');
$assert(str_contains($englishHomeBody, 'Demonstrates reusable Silex package metadata.'), 'The English package card must display the canonical manifest description.');
$assert(str_contains($englishHomeBody, 'href="/en/#packages">Packages</a>'), 'The English navigation must link to the home page package catalog.');
$assert(!str_contains($englishHomeBody, 'Why Silex?'), 'The redundant English principles heading must not be rendered.');
$assert(str_contains($englishHomeBody, '<h2 id="showcase-title">Examples</h2>'), 'The English Silex showcase heading is missing.');
$assert(str_contains($englishHomeBody, 'Canvas shapes and paths') && str_contains($englishHomeBody, 'Minesweeper — Setup'), 'The English showcase captions are missing.');
$assert(!str_contains($englishHomeBody, 'Présente des métadonnées réutilisables'), 'The English package card must not display the French translation.');

$frenchReleases = $handle('https://silex.test/fr/releases');
$frenchReleasesBody = (string) $frenchReleases->getBody();
$assert($frenchReleases->getStatusCode() === 200, 'French release notes must respond with HTTP 200.');
$assert(str_contains($frenchReleasesBody, '<body class="release-notes-page">'), 'The release notes page theme is missing.');
$assert(str_contains($frenchReleasesBody, '<section class="release-notes-hero"') && str_contains($frenchReleasesBody, '<div class="release-notes-hero-inner">'), 'Release notes must open with the compact dark hero.');
$assert(str_contains($frenchReleasesBody, '<h1 id="release-notes-title">Ce qui change dans Silex</h1>'), 'The French release-notes heading is missing.');
$assert(str_contains($frenchReleasesBody, 'Silex 1.8.0') && !str_contains($frenchReleasesBody, 'Silex 1.0.0'), 'The first release page must contain only the newest eight versions.');
$assert(str_contains($frenchReleasesBody, 'Impact et migration'), 'Release notes must explain upgrade impact.');
$assert(str_contains($frenchReleasesBody, 'href="/fr/releases?page=2"'), 'Release notes must link to older versions.');
$assert(str_contains($frenchReleasesBody, 'href="/en/releases"'), 'The release-notes language switch must preserve the first page.');

$englishReleasePage = $handle('https://silex.test/en/releases?page=2');
$englishReleasePageBody = (string) $englishReleasePage->getBody();
$assert($englishReleasePage->getStatusCode() === 200, 'The second English release page must respond with HTTP 200.');
$assert(str_contains($englishReleasePageBody, 'Silex 1.0.0') && !str_contains($englishReleasePageBody, 'Silex 1.8.0'), 'The second release page must contain only older versions.');
$assert(str_contains($englishReleasePageBody, 'href="/en/releases"'), 'The second release page must link back to newer versions.');
$assert(str_contains($englishReleasePageBody, 'href="/fr/releases?page=2"'), 'The language switch must preserve release pagination.');
$assert($handle('https://silex.test/fr/releases?page=0')->getStatusCode() === 404, 'Invalid release pages must respond with HTTP 404.');
$assert($handle('https://silex.test/fr/releases?page=3')->getStatusCode() === 404, 'Release pages past the end must respond with HTTP 404.');

$releaseRepository = new ReleaseNotesRepository($root . '/tests/fixtures/Silex');
$assert(count($releaseRepository->releases('fr')) === 9, 'The complete French release inventory must be available to pagination.');
$assert(count($releaseRepository->releases('en')) === 9, 'The complete English release inventory must be available to pagination.');

$frenchDocumentation = $handle('https://silex.test/fr/docs');
$frenchDocumentationBody = (string) $frenchDocumentation->getBody();
$assert($frenchDocumentation->getStatusCode() === 200, 'French documentation must respond with HTTP 200.');
$assert(str_contains($frenchDocumentationBody, '<body class="docs-page">'), 'Documentation pages must expose their fixed-sidebar layout class.');
$sourceCss = (string) file_get_contents($root . '/assets/css/app.css');
$navigationScript = (string) file_get_contents($root . '/public/assets/navigation.js');
$navigationTemplate = (string) file_get_contents($root . '/templates/_site-navigation-links.twig');
$releaseNavigationPosition = strpos($navigationTemplate, '/releases');
$documentationNavigationPosition = strpos($navigationTemplate, '/docs');
$showcaseScript = (string) file_get_contents($root . '/public/assets/showcase.js');
$packageCardTemplate = (string) file_get_contents($root . '/templates/_package-card.twig');
$snapshotBuilder = (string) file_get_contents($root . '/scripts/build-content-snapshot.mjs');
$assert(
    str_contains($sourceCss, '.release-notes-hero { color-scheme: dark;')
        && str_contains($sourceCss, 'background: radial-gradient(circle at 78% 6%')
        && str_contains($sourceCss, '.release-notes-hero-inner { padding: 72px 0 68px; }')
        && str_contains($sourceCss, '.release-notes-index { padding: 72px 0 130px; }'),
    'Release notes must reuse the index hero atmosphere in a compact header before the white journal.',
);
$assert(
    $releaseNavigationPosition !== false
        && $documentationNavigationPosition !== false
        && $releaseNavigationPosition < $documentationNavigationPosition,
    'Release notes must remain the first link in the shared site navigation.',
);
$assert(
    !str_contains($packageCardTemplate, 'package.version')
        && !str_contains($snapshotBuilder, 'manifest.version')
        && str_contains($snapshotBuilder, 'packageMetadata.push({ name: registration.name, description });'),
    'Package rendering and content snapshots must remain independent of package versions.',
);
$assert(
    str_contains($packageCardTemplate, 'package.documentation is defined and package.documentation')
        && str_contains($packageCardTemplate, 'class="package-card-documentation"'),
    'Package cards must preserve an independent slot for future site documentation links.',
);
$assert(
    str_contains($sourceCss, 'row-gap: clamp(32px, 4vw, 48px); align-content: center;')
        && str_contains($sourceCss, '.hero-principles { grid-column: 1 / -1;')
        && str_contains($sourceCss, 'padding: 0; border: 0; list-style: none;')
        && str_contains($sourceCss, '.hero-principle { min-width: 0; padding: clamp(22px, 2.5vw, 32px); border: 1px solid var(--line); border-radius: 8px;')
        && str_contains($sourceCss, 'background: color-mix(in srgb, var(--color-gray-900) 42%, transparent); text-align: center;')
        && str_contains($sourceCss, '.hero-principles p { max-width: 340px; margin: 0 auto;')
        && str_contains($sourceCss, '.hero-principles h2 {')
        && str_contains($sourceCss, 'text-transform: uppercase;'),
    'The three uppercase benefits must remain centered in lightweight bordered cards.',
);
$assert(
    str_contains($sourceCss, '--navbar-height: 70px;')
        && str_contains($sourceCss, 'padding-top: var(--navbar-height);')
        && str_contains($sourceCss, 'body.home-page {')
        && str_contains($sourceCss, 'background: var(--color-white);')
        && str_contains($sourceCss, '.site-header {')
        && str_contains($sourceCss, "position: fixed;\n    inset: 0 0 auto;")
        && !str_contains($sourceCss, 'position: sticky;')
        && str_contains($sourceCss, '.home-page .hero {')
        && str_contains($sourceCss, 'background: radial-gradient(circle at 78% 6%, color-mix(in srgb, var(--color-sky-400) 8%, transparent), transparent 26rem), var(--background);')
        && str_contains($sourceCss, '.home-page .section { background: transparent; }'),
    'The fixed white navbar must stay outside the elastic page flow while preserving the dark hero.',
);
$assert(
    str_contains($sourceCss, '--color-silex-background: var(--color-gray-950);')
        && str_contains($sourceCss, '--color-silex-accent: var(--color-sky-400);')
        && str_contains($sourceCss, '--decorative-pink: var(--color-pink-400);')
        && str_contains($sourceCss, '--decorative-pistachio: var(--color-lime-200);')
        && preg_match('/#[0-9a-f]{3,8}\b|rgba?\(/i', $sourceCss) !== 1,
    'The website theme must use Tailwind palette tokens instead of custom color literals.',
);
$assert(
    str_contains($sourceCss, '.section { padding: 110px 0; border: 0;')
        && str_contains($sourceCss, '.registry-section { padding: 110px 0; border: 0; scroll-margin-top: calc(var(--navbar-height) + 10px); }')
        && !str_contains($sourceCss, '.home-page .section:has(+ .showcase)'),
    'Top-level site sections must remain free of decorative separators.',
);
$assert(
    str_contains($sourceCss, 'body.registry-page {')
        && str_contains($sourceCss, '.registry-page main {')
        && str_contains($sourceCss, '.registry-hero-section { color-scheme: dark;')
        && str_contains($sourceCss, '.registry-publish-section { background: var(--color-sky-50); }')
        && str_contains($sourceCss, '.contribution-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }')
        && str_contains($sourceCss, '.manifest-window { color-scheme: dark;')
        && str_contains($sourceCss, '.registry-review { display: flex; align-items: center; justify-content: space-between;')
        && str_contains($sourceCss, '.registry-review-copy > p:last-child { margin-bottom: 0; color: var(--muted); font-size: 0.88rem; text-wrap: balance; }'),
    'The registry must reuse the homepage rhythm with full-width alternating sections, contained content, and balanced tracking copy.',
);
$assert(
    !str_contains($sourceCss, '.home-page .section { min-height:')
        && str_contains($sourceCss, '.home-page .zed-extension { background: var(--color-sky-50); }')
        && str_contains($sourceCss, '.zed-setup { margin-top: 0; background: var(--color-white); }')
        && !str_contains($sourceCss, '.zed-extension .quickstart { background:')
        && !str_contains($sourceCss, '.zed-extension .quickstart::before { background:')
        && str_contains($sourceCss, '.section-tag { width: fit-content; padding: 5px 16px; border-radius: 5px; background: var(--decorative-pistachio); color: var(--color-gray-950); }')
        && str_contains($sourceCss, '.home-page .showcase { color-scheme: dark;')
        && str_contains($sourceCss, 'background: var(--color-gray-950); color: var(--text); }'),
    'The Zed installation section must use a light sky background while preserving the shared installation treatment.',
);
$assert(
    str_contains($sourceCss, '.showcase-heading > p:last-child { max-width: 720px; margin: 18px 0 0;')
        && str_contains($sourceCss, '.showcase-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px; }')
        && str_contains($sourceCss, '.showcase-card { width: 100%; aspect-ratio: 8 / 5;')
        && !str_contains($sourceCss, '.showcase-card { width: 100%; max-width: 360px;'),
    'The showcase subtitle must sit below its title and the desktop gallery must align four equal columns with the section container.',
);
$assert(
    str_contains($sourceCss, '.hero-copy { text-align: center; }')
        && str_contains($sourceCss, '.hero .actions { justify-content: center; }')
        && str_contains($sourceCss, '.hero .lede { margin-inline: auto; }')
        && str_contains($sourceCss, '.showcase-card { aspect-ratio: auto; }')
        && str_contains($sourceCss, '.showcase-thumbnail { height: auto; object-fit: contain; transition: none; }')
        && str_contains($sourceCss, '.showcase-lightbox { display: none; }'),
    'Mobile homepage content must be centered and full showcase images must replace the lightbox gallery.',
);
$assert(
    str_contains($sourceCss, '.registry-hero-copy { text-align: center; }')
        && str_contains($sourceCss, '.registry-hero-copy .actions { justify-content: center; }')
        && str_contains($sourceCss, '.registry-heading-split { gap: 24px; }')
        && str_contains($sourceCss, '.manifest-window pre { min-height: auto; padding: 28px 18px; overflow-x: hidden; overflow-wrap: anywhere; white-space: pre-wrap; }')
        && str_contains($sourceCss, '.registry-review { align-items: stretch; flex-direction: column; padding: 24px 20px; }'),
    'The registry page must use its dedicated centered, overflow-safe mobile layout.',
);
$assert(
    str_contains($showcaseScript, "window.matchMedia('(max-width: 650px)')")
        && str_contains($showcaseScript, "item.removeAttribute('href')")
        && str_contains($showcaseScript, 'item.dataset.fullSrc')
        && str_contains($showcaseScript, 'mobile.matches && dialog.open'),
    'The showcase interaction must disable links and close the lightbox in mobile mode.',
);
$assert(
    str_contains($sourceCss, '.package-card:hover { background: color-mix(in srgb, var(--surface) 86%, var(--accent) 14%); }')
        && !str_contains($sourceCss, '.package-card:hover { background: color-mix(in srgb, var(--surface) 92%, var(--text) 8%); }'),
    'Package hover states must use the sky accent instead of a gray text tint.',
);
$assert(
    str_contains($sourceCss, '.package-card { position: relative; min-width: 0; min-height: 190px;')
        && str_contains($sourceCss, '.package-card-repository::after { position: absolute; inset: 0; content: ""; }')
        && str_contains($sourceCss, '.package-card-documentation { position: relative; z-index: 1; }'),
    'Compact package cards must remain fully covered by their repository link while future documentation links stay independently interactive.',
);
$assert(
    str_contains($sourceCss, 'position: fixed; inset: 0; width: min(calc(100% - 36px), 1120px);')
        && str_contains($sourceCss, 'margin: auto;')
        && str_contains($sourceCss, '.showcase-lightbox figcaption > span {')
        && str_contains($sourceCss, 'font-size: 0.68rem; white-space: nowrap;'),
    'The showcase lightbox must remain centered in the viewport.',
);
$assert(
    str_contains($sourceCss, '.docs-page {')
        && str_contains($sourceCss, 'color-scheme: light;')
        && str_contains($sourceCss, '--docs-sidebar-width: clamp(230px, 18vw, 280px);')
        && str_contains($sourceCss, '.docs-sidebar { position: fixed;')
        && str_contains($sourceCss, 'height: calc(100dvh - var(--navbar-height));')
        && str_contains($sourceCss, 'overscroll-behavior-y: contain;')
        && str_contains($sourceCss, 'background: var(--color-gray-50);')
        && str_contains($sourceCss, '.docs-nav-group ul { margin: 0; padding: 0; border-left: 2px solid var(--color-gray-200);')
        && str_contains($sourceCss, '.docs-nav-group a[aria-current="page"]::before {')
        && str_contains($sourceCss, '.docs-toolbar > span { color: var(--color-sky-600); font-weight: 720; }')
        && str_contains($sourceCss, '.prose-silex { color: var(--color-gray-700); font-size: 0.96rem;'),
    'Documentation must use a compact light theme while keeping its fixed independently scrolling sidebar.',
);
$assert(
    str_contains($sourceCss, '.mobile-navigation-panel { color-scheme: light;')
        && str_contains($sourceCss, 'position: fixed; z-index: 45; top: var(--navbar-height); left: 0;')
        && str_contains($sourceCss, 'height: calc(100dvh - var(--navbar-height));')
        && str_contains($sourceCss, 'transform: translateX(-100%); visibility: hidden;')
        && str_contains($sourceCss, '.navigation-open .mobile-navigation-panel { transform: translateX(0);')
        && str_contains($sourceCss, '.docs-sidebar { display: none; }')
        && str_contains($sourceCss, '.docs-toolbar { flex-direction: column; gap: 8px;'),
    'Mobile navigation must slide in below the navbar while the documentation content keeps a compact single-column layout.',
);
$assert(
    str_contains($navigationScript, "window.matchMedia('(min-width: 651px)')")
        && str_contains($navigationScript, "panel.setAttribute('inert', '')")
        && str_contains($navigationScript, "event.key === 'Escape'")
        && str_contains($navigationScript, "event.key !== 'Tab'")
        && str_contains($navigationScript, "container.querySelector('[data-current-document]')")
        && str_contains($navigationScript, "container.scrollTo({ top: Math.max(0, centeredTop), behavior: 'auto' })")
        && str_contains($navigationScript, "focus({ preventScroll: true })")
        && str_contains($navigationScript, "document.querySelectorAll('.docs-sidebar').forEach(centerCurrentDocument)"),
    'Mobile navigation must close across desktop transitions and provide keyboard focus management.',
);
$assert(str_contains($frenchDocumentationBody, '<h1>Documentation Silex</h1>'), 'French Markdown must be rendered from FR/.');
$assert(str_contains($frenchDocumentationBody, '<div class="mobile-doc-navigation">'), 'Documentation navigation must be included in the mobile drawer.');
$assert(str_contains($frenchDocumentationBody, 'Bonjour depuis Silex'), 'The French code example is missing.');
$assert(str_contains($frenchDocumentationBody, 'href="/fr/docs/Language/README.md"'), 'French relative links must stay in the French route tree.');
$assert(str_contains($frenchDocumentationBody, 'href="/en/docs"'), 'The documentation switch must preserve the document path.');
$assert(str_contains($frenchDocumentationBody, '/Silex-Documentation/blob/main/FR/README.md'), 'The French canonical source URL is wrong.');
$assert(!str_contains($frenchDocumentationBody, 'docs-package-index'), 'Language navigation must not include package documentation.');
$assert(
    preg_match('/<aside class="docs-sidebar">(.*?)<\/aside>/s', $frenchDocumentationBody, $sidebarMatch) === 1
        && !str_contains($sidebarMatch[1], 'README.md')
        && !str_contains($sidebarMatch[1], 'href="/fr/docs"'),
    'README index pages must not appear in documentation navigation.',
);

$englishGuide = $handle('https://silex.test/en/docs/Language/README.md');
$englishGuideBody = (string) $englishGuide->getBody();
$assert($englishGuide->getStatusCode() === 200, 'An English nested guide must respond with HTTP 200.');
$assert(str_contains($englishGuideBody, '<h1>Understand the Silex language</h1>'), 'English Markdown must be rendered from EN/.');
$assert(str_contains($englishGuideBody, 'href="/fr/docs/Language/README.md"'), 'The nested documentation switch must preserve the path.');
$assert(str_contains($englishGuideBody, '<code class="language-sx">'), 'Silex Markdown fences must expose their language to the highlighter.');
$assert(str_contains($englishGuideBody, '<script src="/assets/documentation-syntax.js" defer></script>'), 'Documentation pages must load the local Silex syntax highlighter.');
$assert(!str_contains($englishHomeBody, '/assets/documentation-syntax.js'), 'The syntax highlighter must only load on documentation pages.');

$activeNavigationGuide = $handle('https://silex.test/en/docs/Language/Active-navigation.md');
$activeNavigationGuideBody = (string) $activeNavigationGuide->getBody();
$assert($activeNavigationGuide->getStatusCode() === 200, 'A documentation page represented in navigation must respond with HTTP 200.');
$assert(substr_count($activeNavigationGuideBody, 'aria-current="page" data-current-document') === 2, 'Desktop and mobile documentation navigation must identify the current document.');

$packages = $handle('https://silex.test/fr/packages');
$packagesBody = (string) $packages->getBody();
$assert($packages->getStatusCode() === 200, 'The French package catalog must respond with HTTP 200.');
$assert(str_contains($packagesBody, '<h1 id="packages-index-title">Packages publiés</h1>'), 'The French package catalog content is missing.');
$assert(str_contains($packagesBody, 'data-package-count="2"'), 'The package count is missing.');
$assert(str_contains($packagesBody, 'href="https://github.com/Matanek/Silex-Lib-Example"'), 'The package repository link is missing.');
$assert(str_contains($packagesBody, 'Présente des métadonnées réutilisables pour un package Silex.'), 'The localized package description is missing.');
$assert(!str_contains($packagesBody, 'v1.0.0'), 'The package catalog must not expose a package version.');
$assert(!str_contains($packagesBody, 'Docs <span'), 'The catalog must not advertise aggregated package documentation.');

$registry = $handle('https://silex.test/fr/registry');
$registryBody = (string) $registry->getBody();
$assert($registry->getStatusCode() === 200, 'The French registry page must respond with HTTP 200.');
$assert(str_contains($registryBody, '<h1 id="registry-title">Construisez<br><span>Publiez</span><br>Partagez</h1>'), 'The French registry content is missing.');
$assert(str_contains($registryBody, '<body class="registry-page">'), 'The registry page theme is missing.');
$assert(substr_count($registryBody, 'class="section-container') >= 3, 'The registry full-width sections are missing their containers.');
$assert(str_contains($registryBody, 'class="registry-section registry-publish-section"'), 'The registry publication section is missing.');
$assert(str_contains($registryBody, '<h2 id="publish-title">Publiez votre package</h2>'), 'The registry must explain direct publication.');
$assert(str_contains($registryBody, 'href="/fr/#packages"'), 'The registry contribution card must link to the complete package catalog.');
$assert(!str_contains($registryBody, 'registry-packages-section'), 'The registry page must not duplicate a partial package catalog.');
$assert(str_contains($registryBody, '<h3 class="eyebrow" id="registry-review-title">Versions publiées</h3>'), 'The registry must explain durable versions.');
$assert(str_contains($registryBody, 'silex login') && str_contains($registryBody, 'silex publish'), 'The registry must show the current login and publication commands.');
$assert(!str_contains($registryBody, 'silex register'), 'The obsolete Git registration flow must not appear.');
$assert(!str_contains($registryBody, 'registry-closing-section'), 'The registry must not end with an oversized generic repository call to action.');
$assert(str_contains($registryBody, 'href="/en/registry"'), 'The registry language switch must preserve the page.');
$assert(str_contains($registryBody, 'https://registry.silex-lang.org/v2/catalog'), 'The registry API link is missing.');

$assert($handle('https://silex.test/fr/docs/Missing.md')->getStatusCode() === 404, 'Missing documentation must respond with HTTP 404.');

$unsafeHtml = (new MarkdownRenderer())->toHtml('<script>alert("xss")</script>' . "\n\n" . '[unsafe](javascript:alert(1))');
$assert(!str_contains($unsafeHtml, '<script'), 'Raw HTML must not survive Markdown rendering.');
$assert(!str_contains($unsafeHtml, 'javascript:'), 'Unsafe Markdown links must be removed.');

$snapshotDocuments = new DocumentRepository(
    $root . '/tests/fixtures/Silex-Documentation',
    $root . '/tests/fixtures/Silex-Registry',
    $root . '/tests/fixtures/Packages',
    $root . '/tests/fixtures/snapshot.json',
);
$snapshotDocument = $snapshotDocuments->languageDocument('en', 'README.md');
$assert(
    $snapshotDocument !== null && str_contains($snapshotDocument['source_url'], '/blob/3333333333333333333333333333333333333333/EN/'),
    'Release documentation must link to the exact documentation commit.',
);
$canadianPackages = $snapshotDocuments->packages('fr-CA');
$assert(
    isset($canadianPackages[0]['description'])
        && str_contains(implode(' ', array_column($canadianPackages, 'description')), 'Présente des métadonnées réutilisables'),
    'A regional locale must fall back to its primary package-description language.',
);
$assert(
    array_filter($canadianPackages, static fn (array $package): bool => array_key_exists('version', $package)) === [],
    'Package metadata exposed to the site must not include release versions.',
);
$fallbackPackages = $snapshotDocuments->packages('de');
$assert(
    str_contains(implode(' ', array_column($fallbackPackages, 'description')), 'Demonstrates reusable Silex package metadata.'),
    'An unavailable package-description language must fall back to English.',
);

putenv('SILEX_VERSION');
$assert(
    SilexVersionResolver::resolve($root, $root . '/tests/fixtures/Workspace') === '1.2.3',
    'Local development must resolve the Silex version from the neighboring toolchain manifest.',
);

$cssPath = $root . '/public/assets/app.css';
$assert(is_file($cssPath) && filesize($cssPath) > 1_000, 'The compiled Tailwind stylesheet is missing.');
$syntaxScriptPath = $root . '/public/assets/documentation-syntax.js';
$syntaxScript = is_file($syntaxScriptPath) ? (string) file_get_contents($syntaxScriptPath) : '';
$assert(strlen($syntaxScript) > 10_000, 'The compiled Silex syntax-highlighting bundle is missing.');
$assert(str_contains($syntaxScript, 'silexHighlighted'), 'The syntax-highlighting bundle must mark highlighted code blocks.');
$assert(
    str_contains((string) file_get_contents($root . '/assets/js/documentation-syntax.js'), '.replace(/\\r?\\n$/, "")'),
    'The syntax highlighter must discard the Markdown fence terminator without removing intentional blank lines.',
);
$assert(
    str_contains($sourceCss, '--sx-token-keyword: var(--color-sky-400);')
        && str_contains($sourceCss, '--sx-token-string: var(--color-lime-200);')
        && str_contains($sourceCss, '.prose-silex pre.shiki code'),
    'The documentation theme must style Shiki tokens with the Silex palette.',
);
$iconPath = $root . '/public/icon.svg';
$assert(is_file($iconPath) && str_contains((string) file_get_contents($iconPath), '#38bdf8'), 'The Silex icon is missing or does not use Tailwind sky-400.');
$assert(str_contains($homeBody, '<meta name="theme-color" content="#030712">'), 'The browser theme color must use Tailwind gray-950.');

echo "Silex Web checks passed.\n";
