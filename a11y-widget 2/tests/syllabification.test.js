const fs = require('fs');
const path = require('path');
const vm = require('vm');
const assert = require('assert');

const widgetPath = path.resolve(__dirname, '../assets/widget.js');
const widgetSource = fs.readFileSync(widgetPath, 'utf8');
const startMarker = 'const FRENCH_HYPHENATION_DATA';
const endMarker = 'function insertReadingGuideMiddots';
const startIndex = widgetSource.indexOf(startMarker);
const endIndex = widgetSource.indexOf(endMarker);

if(startIndex === -1 || endIndex === -1 || endIndex <= startIndex){
  throw new Error('Unable to locate French hyphenation block in widget.js');
}

const snippet = widgetSource.slice(startIndex, endIndex);
const sandbox = {};
vm.createContext(sandbox);
vm.runInContext(snippet, sandbox);

const syllabify = sandbox.syllabifyReadingGuideWord;
if(typeof syllabify !== 'function'){
  throw new Error('Failed to load syllabification routine from widget.js');
}

const expectedPairs = new Map([
  ['bonjour', 'bon·jour'],
  ['famille', 'fa·mille'],
  ['travailleur', 'tra·vailleur'],
  ['communication', 'com·mu·ni·ca·tion'],
  ['accentuation', 'ac·cen·tua·tion'],
  ['aération', 'aé·ra·tion'],
  ['éducation', 'édu·ca·tion'],
  ['coïncidence', 'coïn·ci·dence'],
  ['psychiatre', 'psy·chiatre'],
  ['parler', 'par·ler'],
  ['Éléphant', 'Élé·phant'],
]);

for(const [word, expected] of expectedPairs.entries()){
  const actual = syllabify(word);
  assert.strictEqual(actual, expected, `Unexpected syllabification for ${word}: ${actual}`);
}

const monosyllables = ['paille', 'brouille', 'mœurs', 'SOUFFLE'];
monosyllables.forEach(word => {
  const actual = syllabify(word);
  assert(!actual.includes('·'), `Monosyllabic word ${word} should not contain a middot: ${actual}`);
});

const normalizeDescriptor = Object.getOwnPropertyDescriptor(String.prototype, 'normalize');
const originalNormalize = normalizeDescriptor ? normalizeDescriptor.value : undefined;
if(normalizeDescriptor){
  Object.defineProperty(String.prototype, 'normalize', {
    value: undefined,
    writable: true,
    configurable: true,
    enumerable: !!normalizeDescriptor.enumerable,
  });
}

try {
  const sandboxWithoutNormalize = {};
  vm.createContext(sandboxWithoutNormalize);
  vm.runInContext(snippet, sandboxWithoutNormalize);
  const fallbackSyllabify = sandboxWithoutNormalize.syllabifyReadingGuideWord;
  if(typeof fallbackSyllabify !== 'function'){
    throw new Error('Failed to load syllabification routine without normalize support');
  }
  const result = fallbackSyllabify('bonjour');
  assert.strictEqual(result, 'bon·jour', `Unexpected syllabification without normalize: ${result}`);
} finally {
  if(normalizeDescriptor){
    Object.defineProperty(String.prototype, 'normalize', normalizeDescriptor);
  } else if(originalNormalize !== undefined){
    String.prototype.normalize = originalNormalize;
  } else {
    delete String.prototype.normalize;
  }
}

console.log('All syllabification checks passed.');
