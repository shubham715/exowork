import assert from 'node:assert/strict';
import {test} from 'node:test';
import {fallbackSlides, readHeroSlides} from '../../resources/js/components/hero-slide-data.js';

test('rejects missing slides and HTML responses before they enter component state', () => {
  for (const data of [undefined, null, {}, '<!doctype html><html></html>', {slides:null}, {slides:{}}, {slides:[null]}, {slides:[{}]}]) {
    assert.throws(() => readHeroSlides(data), /response is invalid/);
  }
  assert.equal(fallbackSlides.length, 1);
});

test('accepts API slides and preserves an intentionally empty list', () => {
  assert.deepEqual(readHeroSlides({slides:[]}), []);
  assert.equal(readHeroSlides({slides:fallbackSlides}), fallbackSlides);
});
