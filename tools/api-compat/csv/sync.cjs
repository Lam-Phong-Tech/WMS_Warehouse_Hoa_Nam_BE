'use strict';

const {parse} = require('csv-parse/sync');
module.exports = Object.assign(parse, {parse});
