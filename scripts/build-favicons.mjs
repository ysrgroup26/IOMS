/**
 * v2.90.0 -- every active IOMS icon is derived from the designated official
 * transparent artwork at public/branding/ioms-favicon-transparent.svg.
 *
 * The supplied file is a 4096px PNG embedded in an SVG wrapper. Its transparent
 * canvas is 4096 square, while the visible mark occupies 2246 x 1991 and is
 * off-centre. The source is kept byte-for-byte intact. This build extracts the
 * PNG, trims only transparent margins, centres it where square raster formats
 * require one, and writes transparent output for browser, Apple and PWA icons.
 *
 * The SVG favicon is a compact SVG wrapper around a trimmed 512px derivative of
 * that same source. It avoids both a tiny mark in the source's oversized canvas
 * and a 679 KB download for a browser tab. No background, path or colour is
 * added or redrawn.
 *
 * Maskable icons keep the entire mark within the Web App Manifest's guaranteed
 * 40%-radius safe circle. Their transparent pixels are composited by the user
 * agent onto its own solid fill, as specified by W3C; IOMS does not bake in a
 * ground colour.
 *
 * Run with: npm run favicons
 */
import sharp from 'sharp';
import { readFileSync, writeFileSync, existsSync } from 'node:fs';

const SUPPLIED = 'public/branding/ioms-favicon-transparent.svg';
const OUT = 'public/branding';

if (!existsSync(SUPPLIED)) {
    console.error(`Missing ${SUPPLIED}`);
    process.exit(1);
}

/** Pull the embedded PNG out of the official SVG wrapper. */
function extractEmbeddedRaster(svgPath) {
    const svg = readFileSync(svgPath, 'utf8');
    const match = svg.match(/href="data:image\/png;base64,([^"]+)"/);

    if (!match) {
        throw new Error('No embedded PNG found in the supplied favicon SVG.');
    }

    return Buffer.from(match[1], 'base64');
}

/** Trim transparent edges, then centre on a transparent square. */
async function squareMark(trimmed, size, padding = 0.06) {
    const inner = Math.round(size * (1 - padding * 2));

    return sharp({
        create: {
            width: size,
            height: size,
            channels: 4,
            background: { r: 0, g: 0, b: 0, alpha: 0 },
        },
    })
        .composite([
            {
                input: await sharp(trimmed)
                    .resize(inner, inner, { fit: 'contain', background: { r: 0, g: 0, b: 0, alpha: 0 } })
                    .toBuffer(),
                gravity: 'center',
            },
        ])
        .png()
        .toBuffer();
}

/** Create a tightly-cropped SVG favicon from the official transparent artwork. */
async function croppedSvgFavicon(trimmed) {
    const png = await sharp(trimmed)
        .resize(512, 512, { fit: 'inside' })
        .png({ compressionLevel: 9, adaptiveFiltering: true })
        .toBuffer();
    const { width, height } = await sharp(png).metadata();
    const encoded = png.toString('base64');

    return Buffer.from(
        `<?xml version="1.0" encoding="UTF-8"?>\n`
        + `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}" viewBox="0 0 ${width} ${height}">`
        + `<image width="${width}" height="${height}" href="data:image/png;base64,${encoded}"/>`
        + `</svg>\n`,
    );
}

/** A minimal ICO containing PNG payloads. */
function buildIco(pngs) {
    const header = Buffer.alloc(6);
    header.writeUInt16LE(0, 0); // reserved
    header.writeUInt16LE(1, 2); // 1 = icon
    header.writeUInt16LE(pngs.length, 4);

    const entries = [];
    let offset = 6 + pngs.length * 16;

    for (const { size, data } of pngs) {
        const entry = Buffer.alloc(16);
        // 0 means 256 in this field, which is why it is one byte.
        entry.writeUInt8(size >= 256 ? 0 : size, 0);
        entry.writeUInt8(size >= 256 ? 0 : size, 1);
        entry.writeUInt8(0, 2); // palette size
        entry.writeUInt8(0, 3); // reserved
        entry.writeUInt16LE(1, 4); // colour planes
        entry.writeUInt16LE(32, 6); // bits per pixel
        entry.writeUInt32LE(data.length, 8);
        entry.writeUInt32LE(offset, 12);
        entries.push(entry);
        offset += data.length;
    }

    return Buffer.concat([header, ...entries, ...pngs.map((p) => p.data)]);
}

const raster = extractEmbeddedRaster(SUPPLIED);
const trimmed = await sharp(raster).trim().png().toBuffer();

// Browser favicons: tight SVG plus transparent raster fallbacks.
const png32 = await squareMark(trimmed, 32);
const png48 = await squareMark(trimmed, 48);
const png96 = await squareMark(trimmed, 96);
writeFileSync(`${OUT}/ioms-favicon.svg`, await croppedSvgFavicon(trimmed));
writeFileSync(`${OUT}/ioms-favicon-32.png`, png32);
writeFileSync(`${OUT}/ioms-favicon-48.png`, png48);
writeFileSync(`${OUT}/ioms-favicon-96.png`, png96);
writeFileSync('public/favicon.ico', buildIco([
    { size: 16, data: await squareMark(trimmed, 16) },
    { size: 32, data: png32 },
    { size: 48, data: png48 },
]));

// App icons: same transparent mark, with extra padding only for the safe zone.
writeFileSync(`${OUT}/ioms-apple-touch-icon.png`, await squareMark(trimmed, 180));
writeFileSync(`${OUT}/ioms-icon-192.png`, await squareMark(trimmed, 192));
writeFileSync(`${OUT}/ioms-icon-512.png`, await squareMark(trimmed, 512));
writeFileSync(`${OUT}/ioms-maskable-192.png`, await squareMark(trimmed, 192, 0.12));
writeFileSync(`${OUT}/ioms-maskable-512.png`, await squareMark(trimmed, 512, 0.12));

console.log('Official transparent mark regenerated:');
console.log('  browser: favicon.ico (16/32/48), SVG, PNG (32/48/96)');
console.log('  Apple:   touch icon (180)');
console.log('  PWA:     any + maskable (192/512), all transparent');
