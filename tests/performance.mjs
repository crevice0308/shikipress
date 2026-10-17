/** Local Lighthouse navigation measurements; INP/field CWV remain unmeasured. */
import fs from 'node:fs';import path from 'node:path';import{execFileSync,spawn}from'node:child_process';import{pathToFileURL}from'node:url';
const root=path.resolve(path.dirname(new URL(import.meta.url).pathname),'..');const state=process.env.SHIKI_DEV_STATE;if(!state)throw new Error('Set SHIKI_DEV_STATE');
const lighthousePath=process.env.SHIKI_LIGHTHOUSE_MODULE||path.join(state,'perf/node_modules/lighthouse/core/index.js');const{default:lighthouse}=await import(pathToFileURL(lighthousePath));
const wp=(...args)=>execFileSync('bash',['scripts/wp.sh',...args],{cwd:root,encoding:'utf8',env:process.env}).trim();
const original=wp('option','get','shikipress_settings','--format=json');const base='http://127.0.0.1:8080';const fixture=JSON.parse(wp('option','get','shiki_fixture_state','--format=json'));const article=wp('eval',`echo get_permalink(${fixture.post_ids[0]});`);
const encode=s=>Buffer.from(s).toString('base64');const change=p=>wp('eval',`$s=get_option('shikipress_settings',[]);$p=json_decode(base64_decode('${encode(JSON.stringify(p))}'),true);update_option('shikipress_settings',array_merge($s,$p));shiki_invalidate_cache();`);
const out=path.join(root,'tests/results/lighthouse');fs.mkdirSync(out,{recursive:true});const chromeLog=fs.openSync(path.join(state,'lighthouse-chrome.log'),'a');
const chrome=spawn(process.env.SHIKI_CHROMIUM||'/tmp/shiki-headless/chrome-headless-shell-linux64/chrome-headless-shell',['--headless','--no-sandbox','--no-zygote','--single-process','--disable-dev-shm-usage','--disable-background-networking','--remote-debugging-port=9222','about:blank'],{stdio:['ignore',chromeLog,chromeLog]});
const records=[];
try{
 for(let i=0;i<100;i++){try{await fetch('http://127.0.0.1:9222/json/version');break;}catch{await new Promise(r=>setTimeout(r,100));}}
 for(const mode of ['off','dummy']){
  change({ads_mode:mode==='off'?'off':'test',ads_slots:mode==='off'?[]:[{id:'perf-footer',service:'generic',state:'test',position:'footer',device:'all',post_types:['post','page'],exclude_ids:[],exclude_categories:[],interval:3,limit:1,height:120,dummy:'ready'}]});
  for(const[page,url]of[['home',base+'/'],['article',article]])for(let run=1;run<=3;run++){
   const result=await lighthouse(url,{port:9222,output:'json',logLevel:'error',onlyCategories:['performance'],formFactor:'mobile',disableStorageReset:false,screenEmulation:{mobile:true,width:390,height:844,deviceScaleFactor:1,disabled:false},blockedUrlPatterns:['*googlesyndication.com*','*doubleclick.net*','*googleadservices.com*','*admax.ninja.co.jp*','*a8.net*','*moshimo.com*','*valuecommerce.com*','*afb.jp*']});
   fs.writeFileSync(path.join(out,`${page}-${mode}-${run}.json`),result.report);const lhr=result.lhr;const a=lhr.audits;
   const row={page,adState:mode,run,performance:Math.round(lhr.categories.performance.score*100),LCP_ms:a['largest-contentful-paint'].numericValue,CLS:a['cumulative-layout-shift'].numericValue,TBT_ms:a['total-blocking-time'].numericValue,FCP_ms:a['first-contentful-paint'].numericValue,speedIndex_ms:a['speed-index'].numericValue,INP:'unmeasured (requires field or separate interaction evidence)',warnings:lhr.runWarnings};records.push(row);console.log(JSON.stringify(row));
  }
 }
}finally{
 chrome.kill('SIGTERM');fs.closeSync(chromeLog);wp('eval',`update_option('shikipress_settings',json_decode(base64_decode('${encode(original)}'),true));shiki_invalidate_cache();`);
 const groups=[];for(const page of ['home','article'])for(const adState of ['off','dummy']){const rows=records.filter(r=>r.page===page&&r.adState===adState);const stats=k=>{const v=rows.map(r=>r[k]).sort((a,b)=>a-b);return{median:v[Math.floor(v.length/2)],min:v[0],max:v.at(-1)};};if(rows.length)groups.push({page,adState,runs:rows.length,performance:stats('performance'),LCP_ms:stats('LCP_ms'),CLS:stats('CLS'),TBT_ms:stats('TBT_ms')});}
 fs.writeFileSync(path.join(root,'tests/results/performance-summary.json'),JSON.stringify({date:new Date().toISOString(),conditions:{tool:'Lighthouse',browser:'Chrome headless shell155.0.8059.84',viewport:'390x844',formFactor:'mobile',throttling:'Lighthouse default simulated mobile CPU/network',server:'loopback PHP development server',realAds:false,fieldINP:'unmeasured',realDevices:false},records,groups},null,2));
}
