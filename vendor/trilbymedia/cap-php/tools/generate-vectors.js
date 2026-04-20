#!/usr/bin/env node
// Generates tests/fixtures/prng-vectors.json by running the upstream
// cap.js PRNG over a curated set of (seed, length) inputs. Run with:
//
//   node tools/generate-vectors.js > tests/fixtures/prng-vectors.json
//
// The PRNG implementation below is copied verbatim from cap upstream
// (github.com/tiagozip/cap, server/index.js). Keep it in sync if upstream
// ever changes the algorithm.

function prng(seed, length) {
  function fnv1a(str) {
    let hash = 2166136261;
    for (let i = 0; i < str.length; i++) {
      hash ^= str.charCodeAt(i);
      hash += (hash << 1) + (hash << 4) + (hash << 7) + (hash << 8) + (hash << 24);
    }
    return hash >>> 0;
  }

  let state = fnv1a(seed);
  let result = "";

  function next() {
    state ^= state << 13;
    state ^= state >>> 17;
    state ^= state << 5;
    return state >>> 0;
  }

  while (result.length < length) {
    const rnd = next();
    result += rnd.toString(16).padStart(8, "0");
  }

  return result.substring(0, length);
}

const seeds = [
  '',
  'a',
  '0',
  '1',
  '9',
  'abcdef0123456789',
  'A',
  'z',
  '~!@#$%^&*()',
  ' ',
  '\n',
  '\t',
  'The quick brown fox jumps over the lazy dog',
  '0'.repeat(50),
  'f'.repeat(50),
  // Realistic cap token shapes (50 hex chars = 25 random bytes):
  '6b1f9c2d7a4e5f8b3c9d0e1a2b4c5d6e7f8a9b0c1d2e3f4a5b',
  '6b1f9c2d7a4e5f8b3c9d0e1a2b4c5d6e7f8a9b0c1d2e3f4a5b1',  // token + "1"
  '6b1f9c2d7a4e5f8b3c9d0e1a2b4c5d6e7f8a9b0c1d2e3f4a5b1d', // token + "1" + "d"
  'deadbeefcafebabe',
  '00000000000000000000000000000000',
  'ffffffffffffffffffffffffffffffff',
];

const lengths = [1, 2, 4, 7, 8, 9, 16, 31, 32, 33, 64, 128, 256];

const vectors = [];
for (const seed of seeds) {
  for (const length of lengths) {
    vectors.push({
      seed,
      length,
      output: prng(seed, length),
    });
  }
}

process.stdout.write(JSON.stringify(vectors, null, 2) + '\n');
