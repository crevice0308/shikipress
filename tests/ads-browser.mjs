/** Browser tests with synthetic ad code and intercepted fixture-only URLs. No provider is contacted. */
import {createRequire} from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
const require=createRequire(import.meta.url); const {chromium}=require('playwright');
const root=path.resolve(path.dirname(new URL(import.meta.url).pathname),'..');
const js=fs.readFileSync(path.join(root,'shikipress/assets/ads.js'),'utf8');
const css=fs.readFileSync(path.join(root,'shikipress/assets/ads.css'),'utf8');
const browser=await chromium.launch({headless:true,executablePath:process.env.SHIKI_CHROME_BIN||process.env.SHIKI_CHROMIUM||chromium.executablePath(),args:['--no-sandbox','--no-zygote','--single-process']});
const context=await browser.newContext();
// Keep one browser context alive: single-process headless shell cannot recreate disposed contexts reliably.
await context.newPage();
const results=[];const assert=(condition,name)=>{results.push({name,pass:Boolean(condition)});if(!condition)throw new Error(name);};
const payload=(overrides={})=>Object.assign({mode:'production',service:'generic',device:'all',vendors:[123],purposes:[1,3,4],code:'<span data-fixture="ad">SYNTHETIC TEST AD</span>',sharedLoaders:[]},overrides);
const markup=(items)=>items.map((item,index)=>`<aside id="ad${index}" class="shiki-ad" data-shiki-ad="${Buffer.from(JSON.stringify(item)).toString('base64')}"><span class="shiki-ad__label">広告 / PR</span><div class="shiki-ad__body"><span class="shiki-ad__status">TEST</span></div></aside>`).join('');
const granted={cmpStatus:'loaded',cmpId:999,gdprApplies:true,eventStatus:'tcloaded',tcString:'SYNTHETIC-NOT-A-REAL-TC-STRING',purpose:{consents:{1:true,3:true,4:true}},vendor:{consents:{123:true}}};
let totalRequests=0;let providerRequests=0;
async function setup(items,{width=390,live=true,cmp=null,failLoader=false}={}){
  const page=await context.newPage();await page.setViewportSize({width,height:800});const requests=[];const errors=[];
  page.on('pageerror',error=>errors.push(error.message));
  await page.route('**/*',async route=>{
    const url=route.request().url();requests.push(url);totalRequests++;
    if(!url.startsWith('https://fixture.invalid/')){providerRequests++;await route.abort();return;}
    if(failLoader){await route.abort();return;}
    await route.fulfill({status:200,contentType:'application/javascript',body:'window.fixtureSharedLoads=(window.fixtureSharedLoads||0)+1;'});
  });
  await page.setContent(markup(items));await page.addStyleTag({content:css});
  await page.evaluate(({live,cmp})=>{
    window.shikiAdsConfig={live,cmpId:999,auto:false};
    if(cmp){window.__tcfapi=(command,version,callback)=>{window.fixtureConsentCallback=callback;callback(cmp,true);};}
  },{live,cmp});
  await page.addScriptTag({content:js});
  return {page,requests,errors};
}
try{
  let test=await setup([payload({mode:'test',code:'<img src="https://never.invalid/pixel"><script>throw new Error("SHOULD NOT RUN")</script>'}),payload({mode:'test',dummy:'failure'}),payload({mode:'test',dummy:'no-fill'}),payload({mode:'test',dummy:'delay'}),payload({mode:'test',device:'desktop'})],{live:false});
  assert(await test.page.locator('#ad0').getAttribute('data-state')==='test','test mode renders only a local dummy');
  assert(await test.page.locator('#ad1').getAttribute('data-state')==='failure','dummy failure is reproducible without a request');
  assert(await test.page.locator('#ad2').getAttribute('data-state')==='no-fill','dummy no-fill keeps reserved area');
  assert(await test.page.locator('#ad4').getAttribute('data-state')==='device-excluded','desktop tag excluded before request on mobile');
  assert(await test.page.locator('#ad2 .shiki-ad__body').evaluate(el=>el.getBoundingClientRect().height)>=250,'dummy reserved area is at least 250px');
  await test.page.evaluate(()=>{document.documentElement.style.setProperty('--ink','#edeee6');document.documentElement.style.setProperty('--surface','#292d27');});
  assert(await test.page.locator('#ad0').evaluate(el=>getComputedStyle(el).color)==='rgb(237, 238, 230)','manual dark theme inherits readable theme foreground');
  await test.page.waitForTimeout(2100);assert(await test.page.locator('#ad3').getAttribute('data-state')==='test','dummy delay completes locally');
  assert(test.requests.length===0&&test.errors.length===0,'test modes produce zero external requests and JavaScript errors');await test.page.close();
  test=await setup([payload({code:'<script src="https://fixture.invalid/no-cmp.js"></script>'})]);
  assert(await test.page.locator('#ad0').getAttribute('data-state')==='consent-wait'&&test.requests.length===0,'missing CMP fails closed');await test.page.close();
  test=await setup([payload({code:'<script src="https://fixture.invalid/denied.js"></script>'})],{cmp:{...granted,purpose:{consents:{1:false,3:false,4:false}}}});
  assert(test.requests.length===0&&await test.page.locator('#ad0').getAttribute('data-state')==='consent-wait','denied purpose consent fails closed');await test.page.close();
  test=await setup([payload()],{cmp:{...granted,cmpId:1000}});
  assert(await test.page.locator('#ad0').getAttribute('data-state')==='consent-wait','CMP identifier mismatch fails closed');await test.page.close();
  test=await setup([payload()],{cmp:{...granted,gdprApplies:undefined}});
  assert(await test.page.locator('#ad0').getAttribute('data-state')==='consent-wait','undetermined region fails closed');await test.page.close();
  const shared='https://fixture.invalid/shared.js';
  const synthetic=payload({code:`<span data-fixture="ad">TEST slot</span><script src="${shared}"></script><script>window.fixtureSlotInits=(window.fixtureSlotInits||0)+1;</script>`,sharedLoaders:[shared]});
  test=await setup([synthetic,synthetic],{cmp:granted});
  await test.page.waitForFunction(()=>window.fixtureSlotInits===2);
  assert(test.requests.filter(url=>url===shared).length===1&&await test.page.evaluate(()=>window.fixtureSharedLoads)===1,'shared loader executes once for two eligible slots');
  assert(await test.page.locator('[data-fixture="ad"]').count()===2&&await test.page.evaluate(()=>window.fixtureSlotInits)===2,'two legitimate same-service slots remain independent');
  assert(test.errors.length===0,'synthetic production mount has no JavaScript errors');await test.page.close();
  test=await setup([payload({device:'mobile',code:'<script src="https://fixture.invalid/mobile.js"></script>'}),payload({device:'desktop',code:'<script src="https://fixture.invalid/desktop.js"></script>'})],{cmp:granted});
  await test.page.waitForFunction(()=>window.fixtureSharedLoads===1);
  assert(test.requests.length===1&&test.requests[0].endsWith('/mobile.js'),'device gating requests only the selected mobile tag');await test.page.close();
  test=await setup([synthetic],{cmp:granted,failLoader:true});await test.page.waitForFunction(()=>document.querySelector('#ad0').dataset.state==='failure');
  assert(await test.page.locator('#ad0').getAttribute('data-state')==='failure','loader network failure is reported without repeated requests');await test.page.close();
  test=await setup([payload({code:'<script>document.write("TEST")</script>'})],{cmp:granted});
  await test.page.waitForFunction(()=>document.querySelector('#ad0').dataset.state==='failure');
  assert(await test.page.locator('#ad0').getAttribute('data-state')==='failure','unsupported document.write tags fail clearly');await test.page.close();
  assert(providerRequests===0,'no real advertising, CMP, or affiliate service was contacted');
}catch(error){results.push({name:'exception',pass:false,error:error.message});}
finally{await browser.close();}
fs.mkdirSync(path.join(root,'tests/results'),{recursive:true});
fs.writeFileSync(path.join(root,'tests/results/ads-browser.json'),JSON.stringify({environment:{browser:'Chromium headless',realProvidersContacted:providerRequests,totalFixtureRequests:totalRequests,consent:'mock TCF only'},results},null,2));
for(const result of results)console.log(`${result.pass?'PASS':'FAIL'} ${result.name}${result.error?' '+result.error:''}`);
if(results.some(result=>!result.pass))process.exitCode=1;
