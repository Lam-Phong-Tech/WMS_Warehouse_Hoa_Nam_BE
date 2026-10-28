'use strict';

const modern = require('csv-parse');

function options(value) {
  if (!value || typeof value !== 'object' || !Object.hasOwn(value, 'relax')) return value;
  const {relax, ...rest} = value;
  return {...rest, relax_quotes: rest.relax_quotes ?? relax};
}

function parse(...args) {
  return modern.parse(...args.map((arg) => options(arg)));
}

module.exports = Object.assign(parse, modern);
