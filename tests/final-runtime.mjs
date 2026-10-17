/** Fresh local requests after integration setup; retain historical logs privately. */
import fs from'node:fs';import path from'node:path';import{execFileSync}from'node:child_process';
const root=path.resolve(path.dirname(new URL(import.meta.url).pathname),'..');const primary=process.env.SHIKI_DEV_STATE;const clean=process.env.SHIKI_CLEAN_STATE||path.join(path.dirname(primary),'clean-state');const results=[];
for(const[state,base]of[[primary,'http://127.0.0.1:8080'],[clean,'http://127.0.0.1:8081']]){
 const log=path.join(state,'php-errors.log');if(fs.existsSync(log))fs.copyFileSync(log,log+'.before-final-check');fs.writeFileSync(log,'');
 const article=execFileSync('bash',['scripts/wp.sh','eval',`$s=get_option('shiki_fixture_state');echo get_permalink($s['post_ids'][0]);`],{cwd:root,encoding:'utf8',env:{...process.env,SHIKI_DEV_STATE:state}}).trim();
 const statuses=[];for(const url of[base+'/',article]){const r=await fetch(url);const html=await r.text();statuses.push({url,status:r.status,htmlBytes:html.length,hasMain:html.includes('id="main-content"')});}
 const php=fs.readFileSync(log,'utf8');results.push({installation:state===primary?'source-development':'actual-delivery-zip',statuses,phpLogBytes:Buffer.byteLength(php),phpLogClean:php.length===0,pass:statuses.every(r=>r.status===200&&r.hasMain)&&php.length===0});
}
const report={date:new Date().toISOString(),historicalSetupAndHarnessLogs:'Retained privately outside deliverables; WordPress CLI update checks had network warnings. This check starts a fresh log for real home/article HTTP requests.',results};fs.writeFileSync(path.join(root,'tests/results/final-runtime.json'),JSON.stringify(report,null,2));console.log(JSON.stringify(report,null,2));if(results.some(r=>!r.pass))process.exitCode=1;
