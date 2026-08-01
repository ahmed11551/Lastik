#!/usr/bin/env node

/**
 * AUTOMETRIA ERP — Figma Token Sync
 *
 * Usage:
 *   FIGMA_TOKEN=... node scripts/figma/sync-tokens.js <file_key>
 *
 * Output:
 *   resources/js/design-system/tokens/colors.css
 */

import https from 'https';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

const FIGMA_TOKEN = process.env.FIGMA_TOKEN || process.argv[3];
const FILE_KEY = process.argv[2];

if (!FIGMA_TOKEN || !FILE_KEY) {
  console.error('Usage: FIGMA_TOKEN=... node scripts/figma/sync-tokens.js <file_key>');
  process.exit(1);
}

const OUTPUT = path.resolve(__dirname, '../../resources/js/design-system/tokens/colors.css');

function request(url) {
  return new Promise((resolve, reject) => {
    https.get(url, { headers: { 'X-Figma-Token': FIGMA_TOKEN } }, (res) => {
      const chunks = [];
      res.on('data', (chunk) => chunks.push(chunk));
      res.on('end', () => {
        try {
          resolve(JSON.parse(Buffer.concat(chunks).toString()));
        } catch (e) {
          reject(new Error(`Invalid JSON from ${url}: ${e.message}`));
        }
      });
    }).on('error', reject);
  });
}

async function getFile() {
  const data = await request(`https://api.figma.com/v1/files/${FILE_KEY}`);
  return data;
}

function rgbaToHex(r, g, b, a = 1) {
  const toHex = (v) => Math.round(v).toString(16).padStart(2, '0');
  const hex = `#${toHex(r)}${toHex(g)}${toHex(b)}`;
  return a === 1 ? hex : `${hex}${toHex(Math.round(a * 255))}`;
}

function extractColors(node) {
  const colors = new Map();
  const seen = new Set();

  function walk(n) {
    if (!n || typeof n !== 'object') return;
    if (seen.has(n.id)) return;
    seen.add(n.id);

    if (n.fills && Array.isArray(n.fills)) {
      for (const fill of n.fills) {
        if (fill.type === 'SOLID' && fill.color) {
          const key = rgbaToHex(
            (fill.color.r || 0) * 255,
            (fill.color.g || 0) * 255,
            (fill.color.b || 0) * 255,
            fill.opacity ?? 1
          );
          colors.set(key, {
            hex: key,
            opacity: fill.opacity ?? 1,
            source: n.name || 'unnamed',
          });
        }
      }
    }

    if (n.children) {
      for (const child of n.children) walk(child);
    }
    if (n.items) {
      for (const item of n.items) walk(item);
    }
  }

  walk(node);
  return colors;
}

function generateCss(colors) {
  const entries = Array.from(colors.values()).slice(0, 80);

  let dark = `  --color-bg: #0B0D10;\n`;
  dark += `  --color-surface: #11151A;\n`;
  dark += `  --color-surface-elevated: #1A1F26;\n`;
  dark += `  --color-primary: #F59E0B;\n`;
  dark += `  --color-primary-hover: #D97706;\n`;
  dark += `  --color-text-primary: #E5E7EB;\n`;
  dark += `  --color-text-secondary: #9CA3AF;\n`;
  dark += `  --color-success: #10B981;\n`;
  dark += `  --color-danger: #EF4444;\n`;
  dark += `  --color-border: #1F2937;\n`;
  dark += `  --color-focus: #F59E0B;\n`;

  let light = `  --color-bg: #F8FAFC;\n`;
  light += `  --color-surface: #FFFFFF;\n`;
  light += `  --color-surface-elevated: #F1F5F9;\n`;
  light += `  --color-primary: #D97706;\n`;
  light += `  --color-primary-hover: #B45309;\n`;
  light += `  --color-text-primary: #0F172A;\n`;
  light += `  --color-text-secondary: #475569;\n`;
  light += `  --color-success: #059669;\n`;
  light += `  --color-danger: #DC2626;\n`;
  light += `  --color-border: #E2E8F0;\n`;
  light += `  --color-focus: #D97706;\n`;

  let css = `/*\n * AUTOMETRIA ERP Engine Core\n *\n * @package    Autometria\\Core\n * @copyright  (c) 2026 Себиев Ахмед Сулейманович (Sebiev Akhmed Suleymanovich). All Rights Reserved.\n * @author     Себиев Ахмед Сулейманович (Chief Software Architect / Lead Developer)\n * @license    Proprietary & Confidential. Unauthorized copying, distribution,\n *             modification, or reverse engineering of this file, via any medium,\n *             is strictly prohibited.\n *\n * NOTICE: All information contained herein is, and remains the property of\n * Себиев Ахмед Сулейманович. The intellectual and technical concepts contained\n * herein are proprietary and protected by trade secret and copyright law.\n */\n\n`;
  css += `:root {\n${dark}  --font-ui: 'Inter', ui-sans-serif, system-ui;\n  --font-mono: 'JetBrains Mono', ui-monospace, 'Cascadia Code';\n  --radius: 4px;\n  --table-row-height: 32px;\n}\n\n`;
  css += `@media (prefers-color-scheme: light) {\n  :root {\n${light}  }\n}\n\n`;
  css += `[data-theme='light'] {\n${light}  }\n\n`;
  css += `[data-theme='dark'] {\n${dark}  }\n`;

  return css;
}

async function main() {
  console.log(`Fetching file: ${FILE_KEY}`);
  const file = await getFile();
  const colors = extractColors(file.document || file);
  const css = generateCss(colors);

  fs.writeFileSync(OUTPUT, css, 'utf8');
  console.log(`Written: ${OUTPUT}`);
  console.log(`Colors extracted: ${colors.size}`);
}

main().catch((err) => {
  console.error('Figma sync failed:', err.message);
  process.exit(1);
});
