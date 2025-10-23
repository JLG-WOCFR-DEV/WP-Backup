const jq = require('jquery');

const originalReady = jq.fn.ready;
jq.fn.ready = function(fn) {
  if (typeof fn === 'function') {
    fn(jq);
  }
  return originalReady.call(this, fn);
};

function patchedJQuery(arg) {
  if (typeof arg === 'function') {
    arg(jq);
    return jq;
  }

  return jq(arg);
}

Object.assign(patchedJQuery, jq);

global.$ = patchedJQuery;
global.jQuery = patchedJQuery;
if (typeof window !== 'undefined') {
  window.$ = patchedJQuery;
  window.jQuery = patchedJQuery;
}

global.wp = {
  i18n: {
    __: (text) => text,
    _n: (singular, plural, number) => (number === 1 ? singular : plural),
    sprintf: (...args) => {
      const [format, ...values] = args;
      let output = format;
      values.forEach((value) => {
        output = output.replace(/%s/, String(value));
      });
      return output;
    }
  },
  a11y: {
    speak: jest.fn()
  }
};
