import { access, readFile, readdir } from "node:fs/promises";
import { dirname, extname, join, relative, resolve, sep } from "node:path";

const outputRoot = resolve(process.argv[2] ?? "dist");

async function listFiles(root) {
  const entries = await readdir(root, { withFileTypes: true });
  const paths = [];
  for (const entry of entries) {
    const path = join(root, entry.name);
    if (entry.isDirectory()) paths.push(...await listFiles(path));
    if (entry.isFile()) paths.push(path);
  }
  return paths;
}

async function exists(path) {
  try {
    await access(path);
    return true;
  } catch {
    return false;
  }
}

for (const artifact of [
  "index.html",
  "docs/index.html",
  "docs.css",
  "llms.txt",
  "llms-full.txt",
  "sitemap.xml",
  "robots.txt",
]) {
  if (!await exists(join(outputRoot, artifact))) throw new Error(`missing generated artifact: ${artifact}`);
}

const files = await listFiles(outputRoot);
const htmlFiles = files.filter((path) => extname(path) === ".html");
const rawMarkdownFiles = files.filter((path) => path.includes(`${sep}docs${sep}raw${sep}`) && extname(path) === ".md");
if (htmlFiles.length !== rawMarkdownFiles.length + 1) {
  throw new Error(`expected one HTML page per Markdown source, found ${htmlFiles.length - 1} pages for ${rawMarkdownFiles.length} sources`);
}

const homepage = await readFile(join(outputRoot, "index.html"), "utf8");
if (homepage.includes("{{SILEX_VERSION}}")) {
  throw new Error("homepage contains an unresolved Silex version placeholder");
}
const version = /data-silex-version="(\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?)"/.exec(homepage)?.[1];
if (!version || !homepage.includes(`https://github.com/Matanek/Silex/releases/tag/v${version}`)) {
  throw new Error("homepage is missing the current Silex release link");
}
for (const expected of [
  'id="zed"',
  "https://registry.silex-lang.org/",
  "https://github.com/zed-industries/extensions/pull/7190",
  "git clone https://github.com/Matanek/Silex-Extension-Zed.git",
  "zed: install dev extension",
]) {
  if (!homepage.includes(expected)) throw new Error(`homepage is missing Zed installation content: ${expected}`);
}

const brokenLinks = [];
for (const htmlPath of htmlFiles) {
  const html = await readFile(htmlPath, "utf8");
  const links = [...html.matchAll(/\shref="([^"]+)"/g)].map((match) => match[1]);
  for (const href of links) {
    if (/^(?:https?:|mailto:)/.test(href)) continue;
    const hashIndex = href.indexOf("#");
    const cleanHref = (hashIndex >= 0 ? href.slice(0, hashIndex) : href).split("?", 1)[0];
    const fragment = hashIndex >= 0 ? decodeURIComponent(href.slice(hashIndex + 1)) : "";
    const target = cleanHref === ""
      ? htmlPath
      : cleanHref.startsWith("/")
        ? join(outputRoot, cleanHref)
        : resolve(dirname(htmlPath), cleanHref);
    const resolvedTarget = extname(target) ? target : join(target, "index.html");
    if (!await exists(resolvedTarget)) {
      brokenLinks.push(`${relative(outputRoot, htmlPath)} -> ${href}`);
      continue;
    }
    if (fragment && extname(resolvedTarget) === ".html") {
      const targetHtml = resolvedTarget === htmlPath ? html : await readFile(resolvedTarget, "utf8");
      if (!targetHtml.includes(` id="${fragment}"`)) {
        brokenLinks.push(`${relative(outputRoot, htmlPath)} -> ${href} (missing anchor)`);
      }
    }
  }
}

if (brokenLinks.length > 0) {
  throw new Error(`broken internal links:\n${brokenLinks.join("\n")}`);
}

const llmsIndex = await readFile(join(outputRoot, "llms.txt"), "utf8");
if (!llmsIndex.includes("/docs/raw/Language/README.md")) {
  throw new Error("llms.txt does not expose the language guide Markdown source");
}

console.log(`Checked ${htmlFiles.length} HTML pages, ${rawMarkdownFiles.length} Markdown sources, and all internal links`);
