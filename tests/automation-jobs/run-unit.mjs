// Optional local PHP-WASM runner. Native PHP runs the same cases in CI.
import fs from 'node:fs';
import path from 'node:path';
import {PHP} from '../wordpress-layout/node_modules/@php-wasm/universal/index.js';
import {loadNodeRuntime} from '../wordpress-layout/node_modules/@php-wasm/node/index.js';
const root=path.dirname(new URL(import.meta.url).pathname);
const source=process.argv[2] || path.join(root,'../../shikipress/inc/automation.php');
let failed=0;
for (const version of ['8.2','8.3','8.4','8.5']) {
  const php=new PHP(await loadNodeRuntime(version,{emscriptenOptions:{processId:Number(version.replace('.',''))+700}}));
  try {
    php.writeFile('/automation.php',fs.readFileSync(source));
    php.writeFile('/unit.php',fs.readFileSync(path.join(root,'unit.php')));
    const list=await php.run({code:"<?php define('SHIKI_JOB_CASE','--list'); require '/unit.php';"});
    for (const scenario of list.text.trim().split('\n')) {
      try {
        const result=await php.run({code:`<?php define('SHIKI_AUTOMATION_FILE','/automation.php'); define('SHIKI_JOB_CASE',${JSON.stringify(scenario)}); require '/unit.php';`});
        console.log(result.text.trim());
        if(result.errors)console.error(result.errors);
        if(result.exitCode!==0 || !result.text.includes('"status":"PASS"') || result.errors)++failed;
      } catch (error) { console.error(error.message); ++failed; }
    }
  } finally {php.exit();}
}
console.log(JSON.stringify({failed})); if(failed)process.exitCode=1;
