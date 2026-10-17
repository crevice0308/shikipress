/** Actual computed CSS palette, WCAG sRGB contrast. All content/assets are local. */
import fs from 'node:fs';import path from 'node:path';import {createRequire} from 'node:module';
const require=createRequire(import.meta.url);const{chromium}=require('playwright');
const root=path.resolve(path.dirname(new URL(import.meta.url).pathname),'..');
const css=fs.readFileSync(path.join(root,'shikipress/assets/style.css'),'utf8');
const browser=await chromium.launch({headless:true,executablePath:process.env.SHIKI_CHROME_BIN||process.env.SHIKI_CHROMIUM||chromium.executablePath(),args:['--no-sandbox','--no-zygote','--single-process']});
const context=await browser.newContext();const page=await context.newPage();const rows=[];const requests=[];
await page.route('**/*',route=>{requests.push(route.request().url());return route.abort();});
const rgb=s=>{const m=s.match(/[\d.]+/g);if(!m||m.length<3)throw new Error('Unsupported color '+s);return m.slice(0,3).map(Number);};
const luminance=c=>c.map(v=>{const s=v/255;return s<=.04045?s/12.92:((s+.055)/1.055)**2.4;}).reduce((sum,v,i)=>sum+v*[.2126,.7152,.0722][i],0);
const contrast=(a,b)=>{const x=luminance(rgb(a)),y=luminance(rgb(b));return(Math.max(x,y)+.05)/(Math.min(x,y)+.05);};
for(const mode of ['light','dark'])for(const season of ['spring','summer','autumn','winter']){
 await page.setContent(`<!doctype html><html data-theme="${mode}"><head></head><body class="season-${season}"><main><p id="body">本文</p><p id="muted" class="muted">補助文</p><div class="entry-content"><p><a id="link" href="#">本文リンク</a></p></div><a id="text-link" class="text-link" href="#">一覧リンク</a><a id="button" class="button button-primary" href="#">ボタン</a><p id="accent" class="eyebrow">アクセント文字</p></main></body></html>`);
 await page.addStyleTag({content:css});
 // The actual button animates background/color over .2s. Read its settled visible state.
 await page.waitForTimeout(350);
 const colors=await page.evaluate(()=>{
  const body=getComputedStyle(document.body);const out={background:body.backgroundColor};
  for(const id of ['body','muted','link','text-link','button','accent']){const style=getComputedStyle(document.getElementById(id));out[id]={foreground:style.color,background:id==='button'?style.backgroundColor:body.backgroundColor};}return out;
 });
 for(const [name,pair]of Object.entries(colors)){if(name==='background')continue;const ratio=contrast(pair.foreground,pair.background);rows.push({mode,season,target:name,...pair,ratio:Number(ratio.toFixed(3)),minimum:4.5,pass:ratio>=4.5});}
}
await browser.close();fs.mkdirSync(path.join(root,'tests/results'),{recursive:true});
const result={browser:'Chrome headless-shell 155.0.8059.84',method:'Actual getComputedStyle; WCAG2 sRGB relative luminance; 4.5:1 normal text threshold',externalRequests:requests.length,rows};
fs.writeFileSync(path.join(root,'tests/results/contrast.json'),JSON.stringify(result,null,2));
const failed=rows.filter(row=>!row.pass);console.log(`${rows.length-failed.length}/${rows.length} computed contrast checks PASS; external requests ${requests.length}`);for(const row of failed)console.log(`FAIL ${row.mode}/${row.season}/${row.target} ${row.ratio}:1 fg=${row.foreground} bg=${row.background}`);if(failed.length||requests.length)process.exitCode=1;
