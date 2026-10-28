'use strict';

const {fakerEN: modern} = require('@faker-js/faker');

const image = {...modern.image, imageUrl: (width=640,height=480,category) => modern.image.urlLoremFlickr({width,height,category})};
for (const category of ['abstract','animals','business','cats','city','fashion','food','nature','nightlife','people','sports','transport']) {
  image[category] = (width=640,height=480) => modern.image.urlLoremFlickr({width,height,category});
}
function mask(length=4,parens,ellipsis) {
  const digits=modern.finance.accountNumber({length:length||4});
  const value=(ellipsis===null||ellipsis?'...':'')+digits;
  return parens===null||parens?'('+value+')':value;
}

// This is an API-name adapter, not a copied or patched legacy Faker engine.
const compatibility = {
  ...modern,
  address: {...modern.location, streetName: modern.location.street},
  name: {...modern.person, findName: modern.person.fullName},
  datatype: {...modern.datatype, number: modern.number.int, uuid: modern.string.uuid},
  random: {arrayElement: modern.helpers.arrayElement, word: modern.word.sample, alphaNumeric: modern.string.alphanumeric},
  commerce: {...modern.commerce, color: modern.color.human},
  company: {...modern.company, companyName: modern.company.name,
    companySuffix: () => modern.helpers.arrayElement(modern.definitions.company.legal_entity_type),
    bs: modern.company.buzzPhrase, bsAdjective: modern.company.buzzAdjective,
    bsBuzz: modern.company.buzzVerb, bsNoun: modern.company.buzzNoun},
  finance: {...modern.finance, account: (length=8) => modern.finance.accountNumber({length}), mask},
  internet: {...modern.internet, userName: modern.internet.username, color: modern.color.rgb},
  phone: {...modern.phone, phoneNumber: modern.phone.number, phoneNumberFormat: modern.phone.number},
  image,
  fake: modern.helpers.fake,
  seed: modern.seed.bind(modern),
};

module.exports = compatibility;
