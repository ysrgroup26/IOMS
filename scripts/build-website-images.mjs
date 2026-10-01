/**
 * v2.88.0 -- WEBSITE PHOTOGRAPHY, PREPARED FOR THE WEB.
 *
 * The photography arrived as eight PNGs totalling about 14.6 MB. PNG is a
 * lossless format built for graphics with flat colour and hard edges; for a
 * photograph it stores an enormous amount of data no viewer can see. Shipping
 * them unchanged would have made the landing page heavier than every other
 * asset on it combined, which is the opposite of what adding atmosphere is
 * supposed to buy.
 *
 * This script is the build step for that. It reads the masters from
 * `resources/images/website-source` and writes derivatives into
 * `public/images/website`:
 *
 *   <name>-640.webp    phones
 *   <name>-1024.webp   tablets and story panels
 *   <name>-1600.webp   full-bleed bands on a desktop
 *   <name>-1024.jpg    the fallback `src`, for anything that cannot read WebP
 *
 * NEVER UPSCALED. Three of the photographs are only 787px wide, so they
 * simply do not get a 1024 or 1600 derivative. Generating one would invent
 * detail that is not in the original and cost bytes to do it.
 *
 * THE MASTERS LIVE OUTSIDE public/ ON PURPOSE. They are 14 MB of PNG, and
 * anything under public/ is fetchable: leaving them there would publish a
 * 3 MB original beside the 121 KB derivative built to replace it.
 *
 * Run with: npm run images
 *
 * The derivatives are committed, like `public/build`, because the deployment
 * target serves files rather than running a pipeline. Re-run this after
 * adding or replacing a photograph.
 */
import sharp from 'sharp';
import { readdirSync, statSync, mkdirSync, existsSync } from 'node:fs';
import { join, parse } from 'node:path';

const SOURCE = 'resources/images/website-source';
const OUT = 'public/images/website';

/** Widths we actually lay out at. Anything wider than the source is skipped. */
const WIDTHS = [640, 1024, 1600];

/** Quality chosen by eye against these photographs, not copied from a blog. */
const WEBP_QUALITY = 74;
const JPEG_QUALITY = 78;

if (!existsSync(SOURCE)) {
    console.error(`No source directory at ${SOURCE}. Put the original photographs there.`);
    process.exit(1);
}

mkdirSync(OUT, { recursive: true });

const sources = readdirSync(SOURCE).filter((f) => /\.(png|jpe?g|webp)$/i.test(f));

if (sources.length === 0) {
    console.error(`No images found in ${SOURCE}.`);
    process.exit(1);
}

let written = 0;
let bytesIn = 0;
let bytesOut = 0;

for (const file of sources) {
    const inPath = join(SOURCE, file);
    // Lowercased, so a file called `Construction.png` and one called
    // `construction.png` cannot become two different URLs on a
    // case-sensitive server.
    const name = parse(file).name.toLowerCase();
    const meta = await sharp(inPath).metadata();

    bytesIn += statSync(inPath).size;

    for (const width of WIDTHS) {
        if (width > meta.width) continue;

        const out = join(OUT, `${name}-${width}.webp`);
        await sharp(inPath).resize({ width }).webp({ quality: WEBP_QUALITY }).toFile(out);
        bytesOut += statSync(out).size;
        written++;
    }

    // One JPEG fallback, at the width most layouts request.
    const fallbackWidth = Math.min(1024, meta.width);
    const fallback = join(OUT, `${name}-${fallbackWidth}.jpg`);
    await sharp(inPath).resize({ width: fallbackWidth }).jpeg({ quality: JPEG_QUALITY, mozjpeg: true }).toFile(fallback);
    bytesOut += statSync(fallback).size;
    written++;

    console.log(`${file.padEnd(22)} ${meta.width}x${meta.height} -> ${name}-*`);
}

const mb = (n) => (n / 1024 / 1024).toFixed(2);
console.log(`\n${written} files written. Sources ${mb(bytesIn)} MB, derivatives ${mb(bytesOut)} MB.`);
