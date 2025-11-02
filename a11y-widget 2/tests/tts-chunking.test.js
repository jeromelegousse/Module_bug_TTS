const fs = require('fs');
const path = require('path');
const vm = require('vm');
const assert = require('assert');

const widgetPath = path.resolve(__dirname, '../assets/widget.js');
const widgetSource = fs.readFileSync(widgetPath, 'utf8');
const startMarker = 'const TTS_MAX_CHUNK_SIZE';
const endMarker = 'function resetTtsPlaybackState';
const startIndex = widgetSource.indexOf(startMarker);
const endIndex = widgetSource.indexOf(endMarker, startIndex);

if(startIndex === -1 || endIndex === -1 || endIndex <= startIndex){
  throw new Error('Unable to locate TTS chunk helpers in widget.js');
}

const snippet = widgetSource.slice(startIndex, endIndex);
const sandbox = {
  TTS_DEFAULTS: {},
  TTS_DEFAULT_TEXTS: {},
  localStorage: undefined,
  window: undefined,
  document: undefined,
  NodeFilter: undefined,
};
vm.createContext(sandbox);
vm.runInContext(snippet, sandbox);

const prepareChunks = sandbox.prepareTtsChunks;
const maxChunkMatch = widgetSource.match(/const\s+TTS_MAX_CHUNK_SIZE\s*=\s*(\d+)/);
const maxChunkSize = maxChunkMatch ? Number(maxChunkMatch[1]) : 1024;

if(typeof prepareChunks !== 'function'){
  throw new Error('prepareTtsChunks helper is not available for testing.');
}

const longParagraph = Array.from({ length: 200 }, (_, index) => `Paragraphe ${index + 1} avec un contenu riche.`).join(' ');
const longResult = prepareChunks(longParagraph);

assert(longResult && Array.isArray(longResult.chunks) && longResult.chunks.length > 1, 'Long text should be split into multiple chunks.');
longResult.chunks.forEach(chunk => {
  assert(chunk.length > 0, 'Chunks should never be empty.');
  assert(chunk.length <= maxChunkSize, `Chunk exceeds max size (${chunk.length} > ${maxChunkSize}).`);
});

assert(Array.isArray(longResult.offsets) && longResult.offsets.length === longResult.chunks.length, 'Offsets should align with chunks.');
longResult.chunks.forEach((chunk, index) => {
  const offset = longResult.offsets[index];
  assert.strictEqual(typeof offset, 'number', 'Offset must be numeric.');
  assert(offset >= 0 && offset < longResult.text.length, 'Offset must reference source text.');
  const recovered = longResult.text.slice(offset, offset + chunk.length);
  assert.strictEqual(recovered, chunk, 'Chunk text should match its slice in the normalized source.');
  if(index < longResult.offsets.length - 1){
    const nextOffset = longResult.offsets[index + 1];
    assert(nextOffset > offset, 'Offsets must increase monotonically.');
  }
});

const shortText = 'Bonjour tout le monde.';
const shortResult = prepareChunks(shortText);
assert.strictEqual(shortResult.chunks.length, 1, 'Short text should remain a single chunk.');
assert.strictEqual(shortResult.chunks[0], shortResult.text, 'Single chunk should match normalized text.');

const veryLongText = 'a'.repeat(120000);
const limitedResult = prepareChunks(veryLongText);
assert(limitedResult.text.length <= 60000, 'Normalized text should be capped at 60kB.');
assert(limitedResult.chunks.every(chunk => chunk.length <= maxChunkSize), 'All capped chunks should respect the max size.');

console.log('All TTS chunking checks passed.');
