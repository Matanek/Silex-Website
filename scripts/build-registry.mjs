import { readdir, readFile, writeFile } from "node:fs/promises";
import { join, resolve } from "node:path";

const registryRoot = resolve(process.argv[2] ?? "registry/v1");
const packagesRoot = join(registryRoot, "packages");
const versionPattern = /^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-([0-9A-Za-z.-]+))?(?:\+[0-9A-Za-z.-]+)?$/;

function parseVersion(value) {
  const match = versionPattern.exec(value);
  if (!match) {
    return null;
  }

  return {
    major: Number(match[1]),
    minor: Number(match[2]),
    patch: Number(match[3]),
    prerelease: match[4]?.split(".") ?? [],
  };
}

function comparePrerelease(left, right) {
  if (left.length === 0 || right.length === 0) {
    return left.length === right.length ? 0 : left.length === 0 ? 1 : -1;
  }

  const length = Math.max(left.length, right.length);
  for (let index = 0; index < length; index += 1) {
    if (left[index] === undefined || right[index] === undefined) {
      return left[index] === right[index] ? 0 : left[index] === undefined ? -1 : 1;
    }

    const leftNumber = /^\d+$/.test(left[index]) ? Number(left[index]) : null;
    const rightNumber = /^\d+$/.test(right[index]) ? Number(right[index]) : null;
    if (leftNumber !== null && rightNumber !== null && leftNumber !== rightNumber) {
      return leftNumber - rightNumber;
    }
    if (leftNumber !== null && rightNumber === null) {
      return -1;
    }
    if (leftNumber === null && rightNumber !== null) {
      return 1;
    }
    if (left[index] !== right[index]) {
      return left[index].localeCompare(right[index], "en");
    }
  }

  return 0;
}

function compareVersions(left, right) {
  for (const part of ["major", "minor", "patch"]) {
    if (left.parsed[part] !== right.parsed[part]) {
      return left.parsed[part] - right.parsed[part];
    }
  }

  return comparePrerelease(left.parsed.prerelease, right.parsed.prerelease);
}

const packageDirectories = (await readdir(packagesRoot, { withFileTypes: true }))
  .filter((entry) => entry.isDirectory())
  .sort((left, right) => left.name.localeCompare(right.name, "en"));

for (const packageDirectory of packageDirectories) {
  const packageRoot = join(packagesRoot, packageDirectory.name);
  const versionFiles = (await readdir(packageRoot, { withFileTypes: true }))
    .filter((entry) => entry.isFile() && entry.name.endsWith(".json") && entry.name !== "index.json")
    .map((entry) => ({
      file: entry.name,
      version: entry.name.slice(0, -".json".length),
    }))
    .map((entry) => ({ ...entry, parsed: parseVersion(entry.version) }));

  const invalidFile = versionFiles.find((entry) => entry.parsed === null);
  if (invalidFile) {
    throw new Error(`${packageDirectory.name}/${invalidFile.file} is not a semantic version manifest`);
  }
  if (versionFiles.length === 0) {
    throw new Error(`${packageDirectory.name} has no version manifest`);
  }

  const releases = [];
  for (const entry of versionFiles.sort((left, right) => compareVersions(right, left))) {
    const path = join(packageRoot, entry.file);
    const manifest = JSON.parse(await readFile(path, "utf8"));

    if (manifest.schema !== 1) {
      throw new Error(`${packageDirectory.name}/${entry.file} uses an unsupported schema`);
    }
    if (manifest.name !== packageDirectory.name) {
      throw new Error(`${packageDirectory.name}/${entry.file} has a mismatched package name`);
    }
    if (manifest.version !== entry.version) {
      throw new Error(`${packageDirectory.name}/${entry.file} has a mismatched version`);
    }
    if (typeof manifest.requires?.silex !== "string") {
      throw new Error(`${packageDirectory.name}/${entry.file} has no Silex compatibility range`);
    }

    releases.push({
      version: manifest.version,
      requires: manifest.requires,
      manifest: entry.file,
    });
  }

  const index = {
    schema: 1,
    name: packageDirectory.name,
    releases,
  };

  await writeFile(join(packageRoot, "index.json"), `${JSON.stringify(index, null, 2)}\n`);
}
