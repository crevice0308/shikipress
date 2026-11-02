/** Dev-server parser regression; synthetic streams only, no HTTP listener. */
const assert = require('node:assert/strict');
const {Readable} = require('node:stream');
const zlib = require('node:zlib');
const express = require('express');
const bodyParser = require('body-parser');
const uri = require('fast-uri');
const app = express(); app.set('query parser', 'extended');
const parse = app.get('query parser fn');
assert.deepEqual(parse('title=hello%20world&tags%5B%5D=a&tags%5B%5D=b'), {title:'hello world', tags:['a','b']});
const pollution = parse('__proto__[shikiPolluted]=yes&constructor[prototype][shikiPolluted]=yes');
assert.equal({}.shikiPolluted, undefined);
assert.equal(Object.prototype.hasOwnProperty.call(pollution, '__proto__'), false);
assert.equal(parse('literal=100%25').literal, '100%');
assert.equal(parse('nested[a][b]=ok').nested.a.b, 'ok');
assert.equal(uri.parse('https://example.invalid/a?b=c').scheme, 'https');
assert.notEqual(uri.parse('java%73cript:alert(1)').scheme, 'javascript');
async function json(payload, encoding) {
  const bytes=Buffer.from(payload); const req=Readable.from([bytes]);
  req.headers={'content-type':'application/json','content-length':String(bytes.length)};
  if(encoding)req.headers['content-encoding']=encoding;
  await new Promise((resolve,reject)=>bodyParser.json({limit:'8kb'})(req,{},error=>error?reject(error):resolve()));
  return req.body;
}
(async()=>{
  assert.deepEqual(await json('{"title":"synthetic"}'),{title:'synthetic'});
  assert.deepEqual(await json(zlib.gzipSync('{"title":"compressed"}'),'gzip'),{title:'compressed'});
  await assert.rejects(json('{broken'), error=>error.status===400);
  await assert.rejects(json(JSON.stringify({x:'a'.repeat(9000)})), error=>error.status===413);
  console.log(JSON.stringify({status:'PASS',checks:11,scope:'Express query parser, URI scheme handling, JSON/gzip/invalid/size-limit streams; no live server'}));
})().catch(e=>{console.error(e);process.exitCode=1;});
