import { mkdir, readFile, rm, writeFile } from "node:fs/promises";
import { join, resolve } from "node:path";
import { spawnSync } from "node:child_process";
import { normalizePackageDescription } from "./package-description.mjs";

const outputRoot = resolve(process.argv[2] ?? ".content");
const packagePattern = /^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*$/;
const repositoryPattern = /^https:\/\/github\.com\/[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+(?:\.git)?$/;

function clone(repository, destination, reference = null, sparse = false) {
    const arguments_ = ["-c", "advice.detachedHead=false", "clone", "--quiet", "--depth=1", "--filter=blob:none", "--single-branch"];
    if (sparse) arguments_.push("--sparse");
    if (reference === null) {
        arguments_.push("--no-tags");
    } else {
        arguments_.push("--branch", reference);
    }
    arguments_.push(repository, destination);
    const result = spawnSync("git", arguments_, { stdio: "inherit" });
    if (result.status !== 0) {
        throw new Error(`Unable to fetch canonical content from ${repository}`);
    }
}

function latestVersionTag(repository) {
    const result = spawnSync("git", ["ls-remote", "--tags", "--refs", repository, "refs/tags/v*"], { encoding: "utf8" });
    if (result.status !== 0) throw new Error(`Unable to list published versions from ${repository}`);

    const versions = result.stdout
        .split("\n")
        .map((line) => line.match(/refs\/tags\/(v(\d+)\.(\d+)\.(\d+)(?:-([0-9A-Za-z.-]+))?)$/))
        .filter((match) => match !== null)
        .map((match) => ({ tag: match[1], major: Number(match[2]), minor: Number(match[3]), patch: Number(match[4]), prerelease: match[5] ?? null }));
    versions.sort((left, right) =>
        left.major - right.major
        || left.minor - right.minor
        || left.patch - right.patch
        || (left.prerelease === null ? 1 : right.prerelease === null ? -1 : left.prerelease.localeCompare(right.prerelease, "en")),
    );
    const latest = versions.at(-1);
    if (latest === undefined) throw new Error(`Canonical repository ${repository} has no published semantic version`);

    return latest;
}

function compareVersion(left, right) {
    return left.major - right.major || left.minor - right.minor || left.patch - right.patch;
}

function releasedChangelog(source, latestVersion, label) {
    const headings = [...source.matchAll(/^## \[(\d+)\.(\d+)\.(\d+)\] - \d{4}-\d{2}-\d{2}[ \t]*$/gm)];
    const selected = headings.filter((heading) => compareVersion({
        major: Number(heading[1]),
        minor: Number(heading[2]),
        patch: Number(heading[3]),
    }, latestVersion) <= 0);
    if (!selected.some((heading) => Number(heading[1]) === latestVersion.major
        && Number(heading[2]) === latestVersion.minor
        && Number(heading[3]) === latestVersion.patch)) {
        throw new Error(`${label} does not describe published ${latestVersion.tag}`);
    }

    const prefix = source.slice(0, headings[0]?.index ?? source.length).trimEnd();
    const sections = selected.map((heading) => {
        const index = headings.indexOf(heading);
        const end = headings[index + 1]?.index ?? source.length;
        return source.slice(heading.index, end).trim();
    });

    return `${prefix}\n\n${sections.join("\n\n")}\n`;
}

async function ensureReleaseNotes(silexRoot, repository, latestVersion) {
    try {
        await Promise.all([
            readFile(join(silexRoot, "CHANGELOG.fr.md")),
            readFile(join(silexRoot, "CHANGELOG.md")),
        ]);
        return;
    } catch {
        const fetch = spawnSync("git", ["-C", silexRoot, "fetch", "--quiet", "--depth=1", "origin", "refs/heads/main"]);
        if (fetch.status !== 0) throw new Error(`Unable to bootstrap release notes from ${repository}`);
    }

    for (const filename of ["CHANGELOG.fr.md", "CHANGELOG.md"]) {
        const result = spawnSync("git", ["-C", silexRoot, "show", `FETCH_HEAD:${filename}`], { encoding: "utf8" });
        if (result.status !== 0) throw new Error(`Unable to read ${filename} from ${repository} main`);
        await writeFile(
            join(silexRoot, filename),
            releasedChangelog(result.stdout, latestVersion, filename),
        );
    }
    console.log(`Bootstrapped release notes for ${latestVersion.tag} from Silex main`);
}

await rm(outputRoot, { recursive: true, force: true });
await mkdir(outputRoot, { recursive: true });

const silexRepository = "https://github.com/Matanek/Silex.git";
const silexVersion = latestVersionTag(silexRepository);
const documentationReference = process.env.SILEX_DOCUMENTATION_REF ?? `release/${silexVersion.major}.${silexVersion.minor}`;

const silexRoot = resolve(outputRoot, "Silex");
clone(silexRepository, silexRoot, silexVersion.tag);
await ensureReleaseNotes(silexRoot, silexRepository, silexVersion);
clone("https://github.com/Matanek/Silex-Documentation.git", resolve(outputRoot, "Silex-Documentation"), documentationReference);
clone("https://github.com/Matanek/Silex-Extension-VSCode.git", resolve(outputRoot, "Silex-Extension-VSCode"));
clone("https://github.com/Matanek/Silex-Registry.git", resolve(outputRoot, "Silex-Registry"));

const registryPackagesRoot = resolve(outputRoot, "Silex-Registry/registry/v1/packages");
const response = await fetch("https://registry.silex-lang.org/v2/catalog");
if (!response.ok) throw new Error(`Unable to fetch published package catalog: HTTP ${response.status}`);
const catalog = await response.json();
if (catalog.schema !== 1 || !Array.isArray(catalog.packages)) throw new Error("Invalid published package catalog");
await rm(registryPackagesRoot, { recursive: true, force: true });
await mkdir(registryPackagesRoot, { recursive: true });
const seen = new Set();
for (const item of catalog.packages) {
    if (!item || !packagePattern.test(item.name) || seen.has(item.name)
        || normalizePackageDescription(item.description) === null
        || (item.repository !== undefined && !repositoryPattern.test(item.repository))) {
        throw new Error("Invalid published package metadata");
    }
    seen.add(item.name);
    await writeFile(join(registryPackagesRoot, `${item.name}.json`), `${JSON.stringify({
        schema: 1, name: item.name, ...(item.repository ? { repository: item.repository } : {}),
    })}\n`);
    const destination = resolve(outputRoot, "Packages", item.name);
    await mkdir(destination, { recursive: true });
    await writeFile(join(destination, "Package.json"), `${JSON.stringify({ name: item.name, description: item.description })}\n`);
}

console.log(`Fetched Silex ${silexVersion.tag}, documentation ${documentationReference}, the TextMate grammar, and ${seen.size} published package descriptions`);
