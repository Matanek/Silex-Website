import { copyFile, mkdir, rm } from "node:fs/promises";
import { resolve } from "node:path";

const outputRoot = resolve(process.argv[2] ?? "dist");

await rm(outputRoot, { recursive: true, force: true });
await mkdir(outputRoot, { recursive: true });

for (const file of ["index.html", "styles.css", ".nojekyll"]) {
  await copyFile(resolve(file), resolve(outputRoot, file));
}

console.log(`Built website in ${outputRoot}`);
