/** Actual WordPress browser suite. Test ads only; all nonlocal requests are blocked. */
import {createRequire} from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
import {execFileSync} from 'node:child_process';
const require=createRequire(import.meta.url); const {chromium}=require('playwright');
const root=path.resolve(path.dirname(new URL(import.meta.url).pathname),'..');
const state=process.env.SHIKI_DEV_STATE; if(!state)throw new Error('Set SHIKI_DEV_STATE to the isolated development state.');
const base=process.env.SHIKI_DEV_URL||'http://127.0.0.1:8080';
const results=[]; const errors=[]; const external=[]; const counts=[];
const check=(name,pass,detail=null)=>{results.push({name,pass:Boolean(pass),detail});};
const wp=(...args)=>execFileSync('bash',['scripts/wp.sh',...args],{cwd:root,encoding:'utf8',env:process.env}).trim();
const settings=wp('option','get','shikipress_settings','--format=json');
const fixture=(count)=>execFileSync('bash',['scripts/fixtures.sh',String(count)],{cwd:root,encoding:'utf8',env:process.env});
const encode=s=>Buffer.from(s).toString('base64');
const change=(patch)=>wp('eval',`$s=get_option('shikipress_settings',[]);$p=json_decode(base64_decode('${encode(JSON.stringify(patch))}'),true);update_option('shikipress_settings',array_merge($s,$p));shiki_invalidate_cache();`);
const screen=(name)=>path.join(root,'screenshots',name+'.png');
fs.mkdirSync(path.join(root,'screenshots'),{recursive:true});fs.mkdirSync(path.join(root,'tests/results'),{recursive:true});
const browsers=[];
async function context(options={}){
 const browser=await chromium.launch({headless:true,executablePath:process.env.SHIKI_CHROMIUM||'/tmp/shiki-headless/chrome-headless-shell-linux64/chrome-headless-shell',args:['--no-sandbox','--no-zygote','--single-process']});browsers.push(browser);
 const ctx=await browser.newContext(options); await ctx.route('**/*',async route=>{
  const url=route.request().url();
  if(!/^https?:/.test(url)||url.startsWith(base+'/'))return route.continue();
  external.push(url);return route.abort();
 });return ctx;
}
async function pageIn(ctx){const p=await ctx.newPage();p.on('pageerror',e=>errors.push({url:p.url(),message:e.message}));return p;}
async function fits(p,name){const box=await p.evaluate(()=>({viewport:innerWidth,scroll:document.documentElement.scrollWidth,body:document.body.scrollWidth}));check(name,box.scroll<=box.viewport+1,box);}
let admin;
try {
 change({ads_mode:'off',season_mode:'fixed',season_fixed:'autumn'});
 const ctx=await context({viewport:{width:390,height:844},hasTouch:true,isMobile:true});const p=await pageIn(ctx);
 for(const count of [0,1,5,20,100,500]){
  fixture(count);const response=await p.goto(base+'/',{waitUntil:'networkidle'});const cards=await p.locator('.post-card').count();
  const links=await p.locator('.post-card .card-title a').evaluateAll(nodes=>nodes.map(n=>n.href));
  check(`count-${count}-http`,response.status()===200);check(`count-${count}-no-hidden-leak`,!(await p.locator('body').innerText()).includes('SECRET_TEST'));
  check(`count-${count}-unique-cards`,new Set(links).size===links.length,{cards});
  check(`count-${count}-cards`,count<=5?cards===count:cards>0&&cards<=9,{actual:cards});
  if(count===0){check('zero-no-empty-section',await p.locator('#latest-posts,#season-feature,.shiki-ad').count()===0);check('zero-opening-note',await p.locator('#opening-note').count()===1);}
  await fits(p,`count-${count}-no-overflow`);await p.screenshot({path:screen(`count-${count}-mobile`),fullPage:true});
  counts.push({count,cards,htmlBytes:(await p.content()).length});
 }
 fixture(20);
 for(const width of [320,375,390,430,768,1024,1440,1920]){
  await p.setViewportSize({width,height:width<768?844:1000});await p.goto(base+'/',{waitUntil:'networkidle'});await fits(p,`viewport-${width}-home`);
  if(width<768){const sizes=await p.locator('.mobile-tabs a').evaluateAll(nodes=>nodes.map(n=>{let r=n.getBoundingClientRect();return {w:r.width,h:r.height};}));check(`viewport-${width}-four-tabs`,sizes.length===4&&sizes.every(r=>r.w>=44&&r.h>=44),sizes);}
 }
 for(const season of ['spring','summer','autumn','winter']){
  change({season_mode:'fixed',season_fixed:season});
  for(const width of [390,1440]){await p.setViewportSize({width,height:width===390?844:1000});await p.goto(base+'/',{waitUntil:'networkidle'});check(`season-${season}-${width}`,await p.locator('.hero-art').getAttribute('src').then(v=>v.includes('season-'+season+'.svg')));await p.screenshot({path:screen(`${season}-${width}`),fullPage:true});}
  await p.setViewportSize({width:390,height:844});await p.emulateMedia({colorScheme:'dark',reducedMotion:'reduce'});await p.goto(base+'/',{waitUntil:'networkidle'});await fits(p,`season-${season}-dark-overflow`);await p.screenshot({path:screen(`${season}-dark-mobile`),fullPage:true});await p.emulateMedia({colorScheme:'light',reducedMotion:'no-preference'});
 }
 change({season_mode:'fixed',season_fixed:'autumn'});await p.setViewportSize({width:390,height:844});await p.goto(base+'/',{waitUntil:'networkidle'});
 for(const id of ['nav-categories','nav-pages','nav-search']){
  const trigger=p.locator(`.mobile-tabs [data-panel="${id}"]`);await trigger.click();check(`${id}-opens`,await p.locator('#'+id).isVisible());check(`${id}-aria`,await trigger.getAttribute('aria-expanded')==='true');await p.keyboard.press('Tab');check(`${id}-focus-contained`,await p.evaluate(id=>document.getElementById(id).contains(document.activeElement),id));await p.keyboard.press('Escape');check(`${id}-closes`,await p.locator('#'+id).isHidden());check(`${id}-focus-return`,await trigger.evaluate(el=>document.activeElement===el));
 }
 await p.locator('.theme-toggle').click();check('dark-explicit-toggle',await p.locator('html').getAttribute('data-theme')==='dark');await p.reload({waitUntil:'networkidle'});check('dark-persisted',await p.locator('html').getAttribute('data-theme')==='dark');await p.locator('.theme-toggle').click();
 await p.locator('.mobile-tabs [data-panel="nav-search"]').click();await p.locator('#nav-search input[type=search]').fill('SHIKI_NO_SUCH_RESULT_123');await Promise.all([p.waitForNavigation({waitUntil:'networkidle'}),p.locator('#nav-search form').evaluate(form=>form.requestSubmit())]);check('search-zero-results',await p.locator('.post-card').count()===0);await fits(p,'search-zero-no-overflow');
 const fixtureData=JSON.parse(wp('option','get','shiki_fixture_state','--format=json'));const articleId=fixtureData.post_ids[0];const article=wp('eval',`echo get_permalink(${articleId});`);
 for(const width of [320,375,390,430,768,1024,1440,1920]){await p.setViewportSize({width,height:width<768?844:1000});await p.goto(article,{waitUntil:'networkidle'});await fits(p,`viewport-${width}-article-table-code`);}
 await p.setViewportSize({width:390,height:844});await p.goto(article,{waitUntil:'networkidle'});
 const ids=await p.locator('.entry-content h2,.entry-content h3').evaluateAll(nodes=>nodes.map(n=>n.id));check('toc-unique-heading-ids',ids.length===new Set(ids).size,ids);check('toc-preserves-existing-id',ids.includes('existing-anchor'));check('toc-generated',await p.locator('.article-toc a').count()>=4);
 await p.locator('.article-toc a').first().click();check('toc-moves-focus',await p.evaluate(()=>['H2','H3'].includes(document.activeElement.tagName)));await p.screenshot({path:screen('article-mobile'),fullPage:true});
 await p.locator('.article-toc summary').click();check('toc-collapse',await p.locator('.article-toc').evaluate(el=>!el.open));await p.locator('.article-toc summary').click();
 await p.setViewportSize({width:1440,height:1000});await p.screenshot({path:screen('article-desktop'),fullPage:true});
 const response404=await p.goto(base+'/test-nonexistent-404-abc/',{waitUntil:'networkidle'});check('404-status',response404.status()===404);check('404-home-link',await p.locator(`a[href="${base}/"]`).count()>0);
 await p.setViewportSize({width:844,height:390});await p.goto(article,{waitUntil:'networkidle'});await fits(p,'mobile-landscape');
 await p.addStyleTag({content:'html {font-size:200% !important;}'});await fits(p,'font-scale-200-percent');
 // WordPress-generated public maps and search must not expose sentinel titles.
 const mapUrl=wp('eval',`echo get_permalink(${fixtureData.page_ids.sitemap});`);await p.goto(mapUrl,{waitUntil:'networkidle'});check('html-sitemap-no-hidden-leak',!(await p.locator('body').innerText()).includes('SECRET_TEST'));
 await p.goto(base+'/?s=SECRET_TEST',{waitUntil:'networkidle'});check('search-no-hidden-leak',await p.locator('.post-card').count()===0);
 // A deterministic dummy footer ad tests layout without any service request.
 change({ads_mode:'test',ads_slots:[{id:'browser-footer',service:'generic',state:'test',position:'footer',device:'all',post_types:['post','page'],exclude_ids:[],exclude_categories:[],interval:3,limit:1,height:120,dummy:'ready'}]});
 await p.setViewportSize({width:390,height:844});await p.goto(article,{waitUntil:'networkidle'});check('dummy-ad-rendered',await p.locator('.shiki-ad').count()>0);await fits(p,'dummy-ad-no-overflow');await p.screenshot({path:screen('dummy-ad-mobile'),fullPage:true});
 check('no-ad-service-network',!external.some(u=>/doubleclick|googlesyndication|nend|admax|a8\.net|moshimo|valuecommerce|afb\.jp/.test(u)),external);
 await ctx.close();
 const nojs=await context({viewport:{width:390,height:844},javaScriptEnabled:false});const np=await nojs.newPage();await np.goto(base+'/');check('no-js-navigation',await np.locator('#nav-search input[type=search]').isVisible()&&await np.locator('#nav-pages a').count()>0);await nojs.close();
 admin=await context({viewport:{width:1440,height:1000}});const ap=await pageIn(admin);const credentials=JSON.parse(fs.readFileSync(path.join(state,'credentials.json'),'utf8'));
 await ap.goto(base+'/wp-login.php');await ap.locator('#user_login').fill(credentials.admin_user);await ap.locator('#user_pass').fill(credentials.admin_password);await Promise.all([ap.waitForNavigation(),ap.locator('#wp-submit').click()]);
 for(const width of [390,1440]){await ap.setViewportSize({width,height:width===390?844:1000});for(const tab of ['dashboard','design','automation','navigation','ads','seo','diagnostics','backup']){await ap.goto(base+'/wp-admin/admin.php?page=shikipress&tab='+tab,{waitUntil:'networkidle'});check(`admin-${tab}-${width}-loaded`,await ap.locator('.shiki-admin h1').count()===1);const b=await ap.evaluate(()=>({width:innerWidth,scroll:document.documentElement.scrollWidth}));check(`admin-${tab}-${width}-overflow`,b.scroll<=b.width+1,b);if(tab==='dashboard'||tab==='ads')await ap.screenshot({path:screen(`admin-${tab}-${width}`),fullPage:true});}}
 await admin.close();admin=null;
 check('no-javascript-errors',errors.length===0,errors);
}catch(error){errors.push({fatal:error.stack});check('suite-completed',false,error.message);}
finally{
 try{wp('eval',`update_option('shikipress_settings',json_decode(base64_decode('${encode(settings)}'),true));shiki_invalidate_cache();`);}catch(e){errors.push({restore:e.message});}
 for(const browser of browsers){try{await browser.close();}catch{}}
 const report={date:new Date().toISOString(),engine:'Chrome headless shell 155.0.8059.84',wordpress:wp('core','version'),base,emulation:true,realDevices:false,results,counts,errors,blockedExternalRequests:[...new Set(external)]};
 fs.writeFileSync(path.join(root,'tests/results/browser-integration.json'),JSON.stringify(report,null,2));
 console.log(JSON.stringify({total:results.length,passed:results.filter(r=>r.pass).length,failed:results.filter(r=>!r.pass),errors,external:[...new Set(external)]},null,2));
 if(results.some(r=>!r.pass))process.exitCode=1;
}
