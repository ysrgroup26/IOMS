/**
 * v2.88.0 -- THE FAVICON LOSES ITS BLUE SQUARE.
 *
 * The favicon was the official mark in cyan on a SOLID NAVY square. That was
 * a deliberate v2.76.0 decision, and its reasoning is in config/branding.php:
 * Google renders favicons on a light surface, and a ground was chosen so the
 * mark could not float. The brand direction is now the transparent symbol, so
 * this script rebuilds the set without the square. The decision is reversed,
 * not lost: the old reasoning stays in the config beside the new answer.
 *
 * WHAT THE SUPPLIED SOURCE ACTUALLY IS. `ioms-favicon-transparent.svg` is a
 * 679 KB SVG whose entire content is one 4096x4096 PNG embedded as base64. It
 * is a raster in an SVG wrapper rather than vector art, and the mark inside it
 * is not centred: trimmed, it occupies 2246x1991 of the 4096 square.
 *
 * So the two formats come from two places, deliberately:
 *
 *   RASTERS (ico, png) are rasterised from that supplied file, because it is
 *   the file the brand owner designated, and a raster source is exactly what
 *   a raster output wants. Trimmed and re-centred first, so the mark sits
 *   square with even padding at 16px.
 *
 *   THE SVG favicon is the repository's own `ioms-icon.svg`: real vector,
 *   1.8 KB, the same mark, the same single colour (#01c1ed), and already
 *   transparent with no background rect. Shipping the supplied file as the
 *   SVG favicon would make every modern browser download 679 KB for a 16px
 *   tab icon, which is roughly three hundred times the vector.
 *
 * Both are the same official mark in the same official colour. Nothing was
 * redrawn and no path was edited.
 *
 * Run with: npm run favicons
 */
import sharp from 'sharp';
import { readFileSync, writeFileSync, existsSync } from 'node:fs';

const SUPPLIED = 'public/branding/ioms-favicon-transparent.svg';
const VECTOR = 'public/branding/ioms-icon.svg';
const OUT = 'public/branding';

if (!existsSync(SUPPLIED)) {
    console.error(`Missing ${SUPPLIED}`);
    process.exit(1);
}

/** Pull the embedded PNG out of the SVG wrapper. */
function extractEmbeddedRaster(svgPath) {
    const svg = readFileSync(svgPath, 'utf8');
    const match = svg.match(/href="data:image\/png;base64,([^"]+)"/);

    if (!match) {
        throw new Error('No embedded PNG found in the supplied favicon SVG.');
    }

    return Buffer.from(match[1], 'base64');
}

/**
 * Trim the transparent margin, then centre the mark on a square canvas with
 * even padding. Without this the icon sits off-centre in every size, because
 * the supplied artwork is not centred in its own canvas.
 */
async function squareMark(raster, size, padding = 0.06) {
    const trimmed = await sharp(raster).trim().toBuffer();
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

/**
 * A minimal ICO containing PNG payloads.
 *
 * ICO has carried PNG entries since Windows Vista, and every browser that
 * still asks for /favicon.ico understands them. Writing the container by hand
 * avoids a dependency whose only job is forty bytes of header.
 */
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

// The sizes the markup and crawlers actually ask for.
const png32 = await squareMark(raster, 32);
const png48 = await squareMark(raster, 48);
const png96 = await squareMark(raster, 96);

writeFileSync(`${OUT}/ioms-favicon-32.png`, png32);
writeFileSync(`${OUT}/ioms-favicon-48.png`, png48);
writeFileSync(`${OUT}/ioms-favicon-96.png`, png96);

const ico = buildIco([
    { size: 16, data: await squareMark(raster, 16) },
    { size: 32, data: png32 },
    { size: 48, data: png48 },
]);

// Served from the web root: crawlers and older clients request this path
// directly without reading any <link>.
writeFileSync('public/favicon.ico', ico);

// The SVG favicon is the real vector, copied rather than rewritten.
writeFileSync(`${OUT}/ioms-favicon.svg`, readFileSync(VECTOR));

console.log('favicon.ico            16 + 32 + 48, transparent');
console.log('ioms-favicon-32.png    transparent');
console.log('ioms-favicon-48.png    transparent');
console.log('ioms-favicon-96.png    transparent');
console.log('ioms-favicon.svg       vector, transparent, copied from ioms-icon.svg');
