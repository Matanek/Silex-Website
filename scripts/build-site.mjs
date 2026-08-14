import { cp, mkdir, readFile, readdir, rm, writeFile } from "node:fs/promises";
import { basename, dirname, extname, join, relative, resolve, sep } from "node:path";
import MarkdownIt from "markdown-it";

const outputRoot = resolve(process.argv[2] ?? "dist");
const docsRoot = resolve(process.env.SILEX_DOCS_ROOT ?? "../Silex/Docs");
const sourceRoot = resolve(process.env.SILEX_SOURCE_ROOT ?? "../Silex");
const siteUrl = (process.env.SILEX_SITE_URL ?? "https://silex-lang.org").replace(/\/$/, "");
const sourceUrl = "https://github.com/Matanek/Silex/blob/main/Docs";

async function resolveSilexVersion() {
  const configured = process.env.SILEX_VERSION?.trim();
  const manifest = configured
    ? null
    : await readFile(join(sourceRoot, "Toolchain", "build.zig.zon"), "utf8");
  const version = configured ?? /\.version\s*=\s*"([^"]+)"/.exec(manifest)?.[1];
  if (!version || !/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/.test(version)) {
    throw new Error("Silex version must be a semantic version");
  }
  return version;
}

const silexVersion = await resolveSilexVersion();

const categoryOrder = new Map([
  ["Getting-started", 10],
  ["Functions", 20],
  ["Modules", 30],
  ["Data-types", 40],
  ["Collections", 50],
  ["Ownership", 60],
  ["Reference", 70],
]);

function escapeHtml(value) {
  return value
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;");
}

function slugify(value) {
  return value
    .normalize("NFKD")
    .replace(/[\u0300-\u036f]/g, "")
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-|-$/g, "");
}

function displayName(value) {
  return value.replaceAll("-", " ").replace(/^\w/, (character) => character.toUpperCase());
}

function sourcePath(path) {
  return relative(docsRoot, path).split(sep).join("/");
}

function routeForSource(path) {
  const parts = sourcePath(path).split("/");
  const file = parts.pop();
  if (file === "README.md") {
    return `/docs/${parts.map(slugify).join("/")}${parts.length === 0 ? "" : "/"}`;
  }
  parts.push(basename(file, extname(file)));
  return `/docs/${parts.map(slugify).join("/")}/`;
}

function markdownUrlForSource(path) {
  return `/docs/raw/${sourcePath(path)}`;
}

async function listMarkdownFiles(root) {
  const entries = await readdir(root, { withFileTypes: true });
  const paths = [];
  for (const entry of entries.sort((left, right) => left.name.localeCompare(right.name, "en"))) {
    const path = join(root, entry.name);
    if (entry.isDirectory()) paths.push(...await listMarkdownFiles(path));
    if (entry.isFile() && entry.name.endsWith(".md")) paths.push(path);
  }
  return paths;
}

function titleFromMarkdown(markdown, path) {
  const match = /^#\s+(.+)$/m.exec(markdown);
  return match?.[1].replaceAll("`", "") ?? displayName(basename(path, ".md"));
}

function descriptionFromMarkdown(markdown, title) {
  const withoutCode = markdown.replace(/```[\s\S]*?```/g, "");
  const paragraph = withoutCode
    .split(/\n\s*\n/)
    .map((block) => block.replace(/^#+\s+.*$/gm, "").replace(/^[-*]\s+/gm, "").trim())
    .find((block) => block && !block.startsWith("|"));
  const plain = (paragraph ?? `Silex documentation: ${title}`)
    .replace(/\[([^\]]+)\]\([^)]+\)/g, "$1")
    .replace(/[*_`]/g, "")
    .replace(/\s+/g, " ");
  return plain.length > 180 ? `${plain.slice(0, 177)}…` : plain;
}

const markdownFiles = await listMarkdownFiles(docsRoot);
const documents = await Promise.all(markdownFiles.map(async (path) => {
  const markdown = await readFile(path, "utf8");
  return {
    path,
    sourcePath: sourcePath(path),
    route: routeForSource(path),
    markdownRoute: markdownUrlForSource(path),
    markdown,
    title: titleFromMarkdown(markdown, path),
  };
}));
const documentBySource = new Map(documents.map((document) => [document.sourcePath, document]));

function navigationRank(document) {
  if (document.sourcePath === "README.md") return 0;
  if (document.sourcePath === "Installation.md") return 1;
  if (document.sourcePath === "PackageRegistry.md") return 2;
  if (document.sourcePath === "Architecture.md") return 3;
  if (document.sourcePath === "Language/README.md") return 4;
  if (document.sourcePath === "Language/Interop.md") return 5;
  const category = document.sourcePath.split("/")[1];
  return 100 + (categoryOrder.get(category) ?? 90);
}

const orderedDocuments = [...documents].sort((left, right) => {
  const rank = navigationRank(left) - navigationRank(right);
  return rank || left.sourcePath.localeCompare(right.sourcePath, "en");
});

function navigationGroup(document) {
  const parts = document.sourcePath.split("/");
  if (parts.length === 1) return "Documentation";
  if (parts.length === 2) return "Language";
  return displayName(parts[1]);
}

function renderNavigation(current) {
  const groups = new Map();
  for (const document of orderedDocuments) {
    const group = navigationGroup(document);
    if (!groups.has(group)) groups.set(group, []);
    groups.get(group).push(document);
  }

  return [...groups.entries()].map(([group, entries]) => `
    <section class="docs-nav-group">
      <h2>${escapeHtml(group)}</h2>
      <ul>${entries.map((entry) => `
        <li><a href="${entry.route}"${entry === current ? ' aria-current="page"' : ""}>${escapeHtml(entry.title)}</a></li>`).join("")}
      </ul>
    </section>`).join("");
}

function resolveMarkdownLink(href, current) {
  if (/^(?:[a-z]+:|#|\/)/i.test(href)) return href;
  const hashIndex = href.indexOf("#");
  const target = hashIndex >= 0 ? href.slice(0, hashIndex) : href;
  const fragment = hashIndex >= 0 ? href.slice(hashIndex + 1) : "";
  if (!target.endsWith(".md")) {
    if (target === "../install.sh" || target === "../install.ps1") {
      return `https://github.com/Matanek/Silex/blob/main/${target.slice(3)}`;
    }
    return href;
  }

  const resolved = join(dirname(current.sourcePath), target).split(sep).join("/");
  const document = documentBySource.get(resolved);
  return document ? `${document.route}${fragment ? `#${fragment}` : ""}` : href;
}

function createMarkdownRenderer(current) {
  const markdown = new MarkdownIt({ html: false, linkify: true, typographer: false });
  const headingIds = new Map();
  const defaultHeadingOpen = markdown.renderer.rules.heading_open;
  markdown.renderer.rules.heading_open = (tokens, index, options, environment, self) => {
    const inline = tokens[index + 1];
    const base = slugify(inline?.content ?? "section") || "section";
    const count = headingIds.get(base) ?? 0;
    headingIds.set(base, count + 1);
    tokens[index].attrSet("id", count === 0 ? base : `${base}-${count + 1}`);
    return defaultHeadingOpen
      ? defaultHeadingOpen(tokens, index, options, environment, self)
      : self.renderToken(tokens, index, options);
  };

  const defaultLinkOpen = markdown.renderer.rules.link_open;
  markdown.renderer.rules.link_open = (tokens, index, options, environment, self) => {
    const hrefIndex = tokens[index].attrIndex("href");
    if (hrefIndex >= 0) {
      const href = tokens[index].attrs[hrefIndex][1];
      tokens[index].attrs[hrefIndex][1] = resolveMarkdownLink(href, current);
      if (/^https?:\/\//.test(href)) tokens[index].attrSet("rel", "noreferrer");
    }
    return defaultLinkOpen
      ? defaultLinkOpen(tokens, index, options, environment, self)
      : self.renderToken(tokens, index, options);
  };
  return markdown;
}

function renderDocument(document, index) {
  const description = descriptionFromMarkdown(document.markdown, document.title);
  const previous = orderedDocuments[index - 1];
  const next = orderedDocuments[index + 1];
  const source = `${sourceUrl}/${document.sourcePath}`;
  const canonical = `${siteUrl}${document.route}`;
  const content = createMarkdownRenderer(document).render(document.markdown);

  return `<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="${escapeHtml(description)}">
    <meta name="theme-color" content="#0c0c0d">
    <link rel="canonical" href="${canonical}">
    <title>${escapeHtml(document.title)} — Silex documentation</title>
    <link rel="stylesheet" href="/styles.css?v=6">
    <link rel="stylesheet" href="/docs.css?v=1">
  </head>
  <body class="docs-body">
    <header class="site-header docs-header">
      <a class="brand" href="/" aria-label="Silex home">
        <span class="brand-mark" aria-hidden="true">S</span><span>Silex</span><span class="docs-brand-context">Docs</span>
      </a>
      <nav aria-label="Primary navigation">
        <a href="/docs/" aria-current="page">Docs</a>
        <a href="https://registry.silex-lang.org/">Packages</a>
        <a href="https://github.com/Matanek/Silex">GitHub</a>
      </nav>
    </header>
    <div class="docs-shell">
      <aside class="docs-sidebar" aria-label="Documentation navigation">
        <div class="docs-sidebar-heading">
          <span>Documentation</span>
          <a href="/llms.txt">For AI</a>
        </div>
        <nav>${renderNavigation(document)}</nav>
      </aside>
      <main class="docs-main">
        <div class="docs-toolbar">
          <span>${escapeHtml(document.sourcePath)}</span>
          <div><a href="${document.markdownRoute}">Markdown</a><a href="${source}">Edit on GitHub</a></div>
        </div>
        <article class="docs-content">${content}</article>
        <nav class="docs-pagination" aria-label="Previous and next pages">
          ${previous ? `<a href="${previous.route}"><span>Previous</span><strong>${escapeHtml(previous.title)}</strong></a>` : "<span></span>"}
          ${next ? `<a class="next" href="${next.route}"><span>Next</span><strong>${escapeHtml(next.title)}</strong></a>` : ""}
        </nav>
      </main>
    </div>
    <footer class="docs-footer"><span>Silex is experimental software.</span><a href="${document.markdownRoute}">Read this page as Markdown</a></footer>
  </body>
</html>\n`;
}

await rm(outputRoot, { recursive: true, force: true });
await mkdir(outputRoot, { recursive: true });
for (const file of ["styles.css", "docs.css", ".nojekyll"]) {
  await cp(resolve(file), resolve(outputRoot, file));
}
const homepageTemplate = await readFile(resolve("index.html"), "utf8");
if (!homepageTemplate.includes("{{SILEX_VERSION}}")) {
  throw new Error("homepage is missing the Silex version placeholder");
}
await writeFile(
  join(outputRoot, "index.html"),
  homepageTemplate.replaceAll("{{SILEX_VERSION}}", silexVersion),
);

await cp(docsRoot, join(outputRoot, "docs", "raw"), { recursive: true });
for (const [index, document] of orderedDocuments.entries()) {
  const destination = join(outputRoot, document.route.replace(/^\//, ""), "index.html");
  await mkdir(dirname(destination), { recursive: true });
  await writeFile(destination, renderDocument(document, index));
}

const llmsIndex = [
  "# Silex",
  "",
  "> Silex is a modern native language for games and applications. These pages describe only behavior implemented by the current compiler.",
  "",
  "## Documentation",
  "",
  ...orderedDocuments.map((document) => `- [${document.title}](${siteUrl}${document.markdownRoute}): ${descriptionFromMarkdown(document.markdown, document.title)}`),
  "",
  "## Source and packages",
  "",
  "- [Silex source](https://github.com/Matanek/Silex)",
  "- [Package registry](https://registry.silex-lang.org/v1/index.json)",
  "",
].join("\n");
await writeFile(join(outputRoot, "llms.txt"), llmsIndex);

const llmsFull = orderedDocuments.map((document) => [
  `# ${document.title}`,
  "",
  `Source: ${siteUrl}${document.markdownRoute}`,
  "",
  document.markdown.replace(/^#\s+.*\n+/, ""),
].join("\n")).join("\n\n---\n\n");
await writeFile(join(outputRoot, "llms-full.txt"), `${llmsFull}\n`);

const sitemapUrls = ["/", ...orderedDocuments.map((document) => document.route)];
const sitemap = `<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n${sitemapUrls.map((route) => `  <url><loc>${siteUrl}${route}</loc></url>`).join("\n")}\n</urlset>\n`;
await writeFile(join(outputRoot, "sitemap.xml"), sitemap);
await writeFile(join(outputRoot, "robots.txt"), `User-agent: *\nAllow: /\n\nSitemap: ${siteUrl}/sitemap.xml\n`);

console.log(`Built website and ${documents.length} documentation pages in ${outputRoot}`);
