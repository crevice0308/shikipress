/** Separate local plugin-page Lighthouse measurements; no field INP claim. */
import fs from'node:fs';import path from'node:path';import{execFileSync,spawn}from'node:child_process';import{pathToFileURL}from'node:url';
const root=path.resolve(path.dirname(new URL(import.meta.url).pathname),'..');const state=process.env.SHIKI_DEV_STATE;const wp=(...args)=>execFileSync('bash',['scripts/wp.sh',...args],{cwd:root,env:process.env,encoding:'utf8'}).trim();
wp('eval','if(!defined("SHIKI_TEST_ENV")||!SHIKI_TEST_ENV||DB_NAME!=="shikipress_audit"){WP_CLI::error("Dedicated audit required");}');
const{default:lighthouse}=await import(pathToFileURL(process.env.SHIKI_LIGHTHOUSE_MODULE||path.join(state,'perf/node_modules/lighthouse/core/index.js')));const original=wp('option','get','shikipress_settings','--format=json');const data=JSON.parse(wp('option','get','shiki_plugin_audit_fixtures','--format=json'));const out=path.join(root,'tests/results/audit-1.0.1/lighthouse');fs.mkdirSync(out,{recursive:true});
const chrome=spawn('/tmp/shiki-headless/chrome-headless-shell-linux64/chrome-headless-shell',['--headless','--no-sandbox','--no-zygote','--single-process','--disable-dev-shm-usage','--disable-background-networking','--remote-debugging-port=9224','about:blank'],{stdio:'ignore'});const rows=[];
try{
 for(let i=0;i<100;i++){try{await fetch('http://127.0.0.1:9224/json/version');break;}catch{await new Promise(r=>setTimeout(r,100));}}
 wp('eval',"$s=get_option('shikipress_settings');$s['ads_mode']='off';update_option('shikipress_settings',$s);");
 for(const[name,id]of[['contact',data.contact],['table',data.table_page]])for(let run=1;run<=3;run++){
  const url=wp('eval',`echo get_permalink(${id});`);const result=await lighthouse(url,{port:9224,output:'json',logLevel:'error',onlyCategories:['performance'],formFactor:'mobile',screenEmulation:{mobile:true,width:390,height:844,deviceScaleFactor:1,disabled:false},blockedUrlPatterns:['*googlesyndication.com*','*doubleclick.net*','*googleadservices.com*','*admax.ninja.co.jp*','*a8.net*','*moshimo.com*','*valuecommerce.com*','*afb.jp*','*gravatar.com*']});
  fs.writeFileSync(path.join(out,`${name}-${run}.json`),result.report);const l=result.lhr,a=l.audits;const row={page:name,run,performance:Math.round(l.categories.performance.score*100),LCP_ms:a['largest-contentful-paint'].numericValue,CLS:a['cumulative-layout-shift'].numericValue,TBT_ms:a['total-blocking-time'].numericValue,warnings:l.runWarnings};rows.push(row);console.log(JSON.stringify(row));
 }
}finally{
 chrome.kill('SIGTERM');wp('eval',`update_option('shikipress_settings',json_decode(base64_decode('${Buffer.from(original).toString('base64')}'),true));shiki_invalidate_cache();`);
 const groups=['contact','table'].map(page=>{const subset=rows.filter(r=>r.page===page);const stats=key=>{const values=subset.map(r=>r[key]).sort((a,b)=>a-b);return{min:values[0],median:values[Math.floor(values.length/2)],max:values.at(-1)};};return{page,runs:subset.length,performance:stats('performance'),LCP_ms:stats('LCP_ms'),CLS:stats('CLS'),TBT_ms:stats('TBT_ms')};});
 fs.writeFileSync(path.join(root,'tests/results/plugin-performance.json'),JSON.stringify({date:new Date().toISOString(),conditions:{server:'loopback PHP8.3 / WP Super Cache simple mode',viewport:'390x844',throttling:'Lighthouse default simulated mobile',plugins:'CF7 6.2.2 / Yoast 28.7 / WP Super Cache 3.1.4 / TablePress 3.4.1',realMail:false,realAds:false,realDevices:false,fieldINP:'unmeasured'},rows,groups},null,2));
 if(rows.length!==6)process.exitCode=1;
}
