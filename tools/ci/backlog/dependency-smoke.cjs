'use strict';
// Disposable loopback HTTP only. No owner API, credentials or live collections.
const assert = require('node:assert/strict');
const http = require('node:http');
const quote = require('shell-quote');
const handlebars = require('handlebars');
const converter = require('openapi-to-postmanv2');
const newman = require('newman');
assert.equal(handlebars.VERSION, '4.7.10');
assert.equal(require('shell-quote/package.json').version, '1.11.0');
assert.equal(handlebars.compile('{{greeting}}, {{name}}!')({greeting: 'Hello', name: 'WMS'}), 'Hello, WMS!');
for (const newline of ['\n', '\r', '\u2028', '\u2029']) {
  assert.throws(() => quote.quote(['echo', {comment: 'isolated'}, 'value' + newline + 'blocked']), TypeError);
}
const spec = {openapi: '3.0.3', info: {title: 'Offline compatibility', version: '1.0.0'},
  paths: {'/health': {get: {responses: {'200': {description: 'ok'}}}}}};
converter.convert({type: 'json', data: spec}, {}, (err, converted) => {
  assert.ifError(err);
  assert.equal(converted.result, true);
  assert.ok(converted.output.some(x => x.type === 'collection'));
  const server = http.createServer((req, res) => {
    res.writeHead(200, {'Content-Type': 'application/json'});
    res.end(JSON.stringify({ok: true}));
  });
  server.listen(0, '127.0.0.1', () => {
    const collection = {info: {name: 'Isolated dependency compatibility',
      schema: 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json'},
      item: [{name: 'loopback only', request: {method: 'GET', url: `http://127.0.0.1:${server.address().port}/health`},
        event: [{listen: 'test', script: {type: 'text/javascript', exec: [
          'pm.test("HTTP status", () => pm.response.to.have.status(200));',
          'pm.test("JSON payload", () => pm.expect(pm.response.json().ok).to.equal(true));'
        ]}}]}]};
    newman.run({collection, timeoutRequest: 3000, timeoutScript: 3000, reporters: []}, (error, summary) => {
      server.close();
      assert.ifError(error);
      assert.equal(summary.run.failures.length, 0);
      assert.equal(summary.run.stats.assertions.total, 2);
      console.log('PASS: fixed dependency versions, quoting boundary, templates, OpenAPI conversion and Newman loopback');
    });
  });
});
