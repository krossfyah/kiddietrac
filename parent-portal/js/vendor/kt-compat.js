/* KiddieTrac compatibility shims (2026-10-22, tickets #175/#176).
   Older Android WebViews/Chrome (< 92) lack Array/String/TypedArray .at() and (< 98)
   structuredClone. pdf.js 3.11 uses both, so opening a form's PDF crashed there with
   "t.at is not a function". Loaded on the page AND inside the pdf.js worker
   (pdf.worker.compat.js). Each shim only installs when the browser lacks the feature. */
(function (g) {
  'use strict';
  function at(n) {
    n = Math.trunc(n) || 0;
    if (n < 0) { n += this.length; }
    if (n < 0 || n >= this.length) { return undefined; }
    return this[n];
  }
  function def(proto) {
    if (proto && !proto.at) {
      try { Object.defineProperty(proto, 'at', { value: at, writable: true, configurable: true }); } catch (e) {}
    }
  }
  def(Array.prototype);
  def(String.prototype);
  ['Int8Array', 'Uint8Array', 'Uint8ClampedArray', 'Int16Array', 'Uint16Array', 'Int32Array',
    'Uint32Array', 'Float32Array', 'Float64Array', 'BigInt64Array', 'BigUint64Array'].forEach(function (n) {
    if (typeof g[n] === 'function') { def(Object.getPrototypeOf(g[n].prototype)); def(g[n].prototype); }
  });

  if (typeof g.structuredClone !== 'function') {
    var clone = function (v, seen) {
      if (v === null || typeof v !== 'object') { return v; }
      if (seen.has(v)) { return seen.get(v); }
      var out;
      if (v instanceof Date) { return new Date(v.getTime()); }
      if (v instanceof RegExp) { return new RegExp(v.source, v.flags); }
      if (typeof ArrayBuffer !== 'undefined') {
        if (v instanceof ArrayBuffer) { return v.slice(0); }
        if (ArrayBuffer.isView(v)) {
          return v instanceof DataView
            ? new DataView(v.buffer.slice(0), v.byteOffset, v.byteLength)
            : new v.constructor(v);
        }
      }
      if (typeof ImageBitmap !== 'undefined' && v instanceof ImageBitmap) { return v; }
      if (v instanceof Map) {
        out = new Map(); seen.set(v, out);
        v.forEach(function (val, key) { out.set(clone(key, seen), clone(val, seen)); });
        return out;
      }
      if (v instanceof Set) {
        out = new Set(); seen.set(v, out);
        v.forEach(function (val) { out.add(clone(val, seen)); });
        return out;
      }
      out = Array.isArray(v) ? [] : {};
      seen.set(v, out);
      Object.keys(v).forEach(function (k) { out[k] = clone(v[k], seen); });
      return out;
    };
    g.structuredClone = function (v) { return clone(v, new Map()); };
  }
})(typeof self !== 'undefined' ? self : this);
