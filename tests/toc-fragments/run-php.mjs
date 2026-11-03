/** Isolated actual fragment-rendering statement, not the WordPress HTML parser. */
import fs from 'node:fs';
import path from 'node:path';
import {PHP} from '../wordpress-layout/node_modules/@php-wasm/universal/index.js';
import {loadNodeRuntime} from '../wordpress-layout/node_modules/@php-wasm/node/index.js';
const root = path.dirname(new URL(import.meta.url).pathname);
const source = fs.readFileSync(process.env.SHIKI_FRONTEND_PHP || path.join(root, '../../shikipress/inc/frontend.php'), 'utf8');
const statement = source.split('\n').find(line => line.includes('foreach ($headings as $heading)'));
if (!statement) throw new Error('TOC rendering statement not found');
for (const version of ['8.2', '8.3', '8.4', '8.5']) {
  const php = new PHP(await loadNodeRuntime(version, {emscriptenOptions: {processId: Number(version.replace('.', '')) + 800}}));
  try {
    php.writeFile('/frontend.php', source);
    const code = `<?php
    define('ABSPATH', '/'); function add_action(...$args) {} function add_filter(...$args) {}
    require '/frontend.php'; // Compile the complete production PHP asset.
    function esc_attr($value) { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8', false); }
    function esc_html($value) { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8', false); }
    $ids = ['ordinary', '100%', 'section%20literal', '日本語の見出し', 'a#b?c', 'quote"&anchor'];
    foreach ($ids as $id) {
      $toc = ''; $headings = [['level' => 2, 'id' => $id, 'text' => 'Heading']];
      ${statement}
      if (!preg_match('/href="#([^\"]*)"/', $toc, $match) || rawurldecode(html_entity_decode($match[1], ENT_QUOTES, 'UTF-8')) !== $id) {
        throw new RuntimeException('Fragment does not round-trip: ' . $id);
      }
      if ($match[1] !== rawurlencode($id)) { throw new RuntimeException('Fragment not encoded: ' . $id); }
    }
    echo json_encode(['status' => 'PASS', 'php' => PHP_VERSION, 'cases' => count($ids)]);`;
    const result = await php.run({code});
    console.log(result.text);
    if (result.errors) console.error(result.errors);
    if (result.exitCode !== 0 || result.errors || !result.text.includes('"status":"PASS"')) process.exitCode = 1;
  } finally { php.exit(); }
}
