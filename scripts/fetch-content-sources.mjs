import { mkdir, readFile, rm, writeFile } from "node:fs/promises";
import { join, resolve } from "node:path";
import { spawnSync } from "node:child_process";
import { normalizePackageDescription } from "./package-description.mjs";
import { releasedChangelog } from "./released-changelog.mjs";

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
    const latest = versions.filter((version) => version.prerelease === null).at(-1);
    if (latest === undefined) throw new Error(`Canonical repository ${repository} has no published semantic version`);

    return { ...latest, publishedVersions: new Set(versions.filter((version) => version.prerelease === null).map((version) => version.tag.slice(1))) };
}

async function fetchReleaseNotes(silexRoot, repository, latestVersion) {
    const fetch = spawnSync("git", ["-C", silexRoot, "fetch", "--quiet", "--depth=1", "origin", "refs/heads/main"]);
    if (fetch.status !== 0) throw new Error(`Unable to fetch release notes from ${repository}`);
    const revision = spawnSync("git", ["-C", silexRoot, "rev-parse", "FETCH_HEAD"], { encoding: "utf8" });
    if (revision.status !== 0 || !/^[a-f0-9]{40}$/.test(revision.stdout.trim())) throw new Error("Invalid release-note source commit");
    const commit = revision.stdout.trim();
    const notesRoot = resolve(outputRoot, "Silex-Release-Notes");
    await mkdir(notesRoot, { recursive: true });
    for (const filename of ["CHANGELOG.fr.md", "CHANGELOG.md"]) {
        const result = spawnSync("git", ["-C", silexRoot, "show", `${commit}:${filename}`], { encoding: "utf8" });
        if (result.status !== 0) throw new Error(`Unable to read ${filename} from ${repository} main`);
        const baseline = await readFile(join(silexRoot, filename), "utf8").catch((error) => {
            if (error.code === "ENOENT") return null; // Historical tags can predate the changelog.
            throw error;
        });
        await writeFile(
            join(notesRoot, filename),
            releasedChangelog(result.stdout, latestVersion.publishedVersions, latestVersion.tag.slice(1), filename, baseline),
        );
    }
    await writeFile(join(notesRoot, "source.json"), `${JSON.stringify({ repository, commit, reference: "refs/heads/main", published_tag: latestVersion.tag })}\n`);
    console.log(`Fetched published release notes from Silex commit ${commit}`);
}

await rm(outputRoot, { recursive: true, force: true });
await mkdir(outputRoot, { recursive: true });

const silexRepository = "https://github.com/Matanek/Silex.git";
const silexVersion = latestVersionTag(silexRepository);
const documentationReference = process.env.SILEX_DOCUMENTATION_REF ?? `release/${silexVersion.major}.${silexVersion.minor}`;

const silexRoot = resolve(outputRoot, "Silex");
clone(silexRepository, silexRoot, silexVersion.tag);
await fetchReleaseNotes(silexRoot, silexRepository, silexVersion);
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
