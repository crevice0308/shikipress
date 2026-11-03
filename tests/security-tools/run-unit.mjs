/** Real tools.php on PHP-WASM with synthetic WP APIs. No external requests. */
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {PHP} from '../wordpress-layout/node_modules/@php-wasm/universal/index.js';
import {loadNodeRuntime} from '../wordpress-layout/node_modules/@php-wasm/node/index.js';
const root=path.dirname(fileURLToPath(import.meta.url));
for (const version of ['8.2','8.3','8.4','8.5']) {
  const php=new PHP(await loadNodeRuntime(version,{emscriptenOptions:{processId:Number(version.replace('.',''))+900}}));
  try {
    php.writeFile('/tools.php',fs.readFileSync(path.join(root,'../../shikipress/inc/tools.php')));
    php.writeFile('/unit.php',fs.readFileSync(path.join(root,'unit.php')));
    const result=await php.run({code:"<?php define('SHIKI_TOOLS_FILE','/tools.php'); require '/unit.php';"});
    console.log(result.text);
    if(result.errors)console.error(result.errors);
    if(result.exitCode!==0||result.errors||!result.text.includes('"status":"PASS"'))process.exitCode=1;
  } finally {php.exit();}
}
