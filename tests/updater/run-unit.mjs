// Uses the existing isolated PHP-WASM test runtime; no requests leave this test.
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import {execFileSync} from 'node:child_process';
import {PHP} from '../wordpress-layout/node_modules/@php-wasm/universal/index.js';
import {loadNodeRuntime} from '../wordpress-layout/node_modules/@php-wasm/node/index.js';
const root=path.dirname(new URL(import.meta.url).pathname);
const fixtures=fs.mkdtempSync(path.join(os.tmpdir(),'shiki-updater-fixtures-'));
try {
  console.log(execFileSync('python3',[path.join(root,'fixtures.py'),fixtures],{encoding:'utf8'}).trim());
  for (const version of ['8.2','8.3','8.4','8.5']) {
    const php=new PHP(await loadNodeRuntime(version,{emscriptenOptions:{processId:Number(version.replace('.',''))+200}}));
    try {
      php.mkdir('/fixtures');
      for (const name of fs.readdirSync(fixtures)) php.writeFile('/fixtures/'+name,fs.readFileSync(path.join(fixtures,name)));
      php.writeFile('/updater.php',fs.readFileSync(path.join(root,'../../shikipress/inc/updater.php')));
      php.writeFile('/unit.php',fs.readFileSync(path.join(root,'unit.php')));
      const result=await php.run({code:"<?php define('SHIKI_TEST_FIXTURES','/fixtures'); define('SHIKI_UPDATER_FILE','/updater.php'); require '/unit.php';"});
      console.log(result.text); if(result.errors)console.error(result.errors);
      if(result.exitCode!==0||!result.text.includes('"status":"PASS"')||result.errors)process.exitCode=1;
    } finally {php.exit();}
  }
} finally {fs.rmSync(fixtures,{recursive:true,force:true});}
