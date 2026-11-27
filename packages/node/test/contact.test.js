// The contact-extraction cases from tests/smoke/php-harness.php, plus the
// plain-object shape Node sites post.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { extractContact, normaliseFields } from '../src/contact.js';

const f = (id, label, type, value) => ({ id, label, type, value });

test('name skips Company Name; email by type; phone by label; message from textarea', () => {
  const x = extractContact(
    normaliseFields([
      f('1', 'Company Name', 'text', 'Acme Ltd'),
      f('2', 'Full Name', 'text', 'Jane Doe'),
      f('3', 'Hotel Preference', 'text', '5 star'),
      f('4', 'Email', 'email', 'jane@example.com'),
      f('5', 'Mobile', 'text', '+44 7700 900123'),
      f('6', 'How can we help?', 'textarea', 'Need a <b>quote</b>\nplease'),
    ]),
  );
  assert.equal(x.name, 'Jane Doe');
  assert.equal(x.email, 'jane@example.com');
  assert.equal(x.phone, '+44 7700 900123');
  assert.equal(x.message, 'Need a quote\nplease');
});

test('full name preferred; short phone and invalid email rejected', () => {
  const x = extractContact(
    normaliseFields([
      f('3.3', 'Name First', 'name', 'John'),
      f('3.6', 'Name Last', 'name', 'Smith'),
      f('3', 'Name', 'name', 'John Smith'),
      f('4', 'Phone', 'phone', '555'),
      f('5', 'Your Email', 'text', 'not-an-email'),
      f('6', 'Comments', 'text', 'hi john@smith.com'),
    ]),
  );
  assert.equal(x.name, 'John Smith');
  assert.equal(x.phone, undefined);
  assert.equal(x.email, undefined);
});

test('a field map joins name parts; heuristics fill the rest', () => {
  const x = extractContact(
    normaliseFields([
      f('your-name', 'your-name', 'text', 'Ann'),
      f('your-surname', 'your-surname', 'text', 'Lee'),
      f('your-email', 'your-email', 'email', 'ann@lee.io'),
      f('your-tel', 'your-tel', 'tel', '0123456789'),
    ]),
    { name: 'your-name|your-surname', email: 'your-email' },
  );
  assert.equal(x.name, 'Ann Lee');
  assert.equal(x.email, 'ann@lee.io');
  assert.equal(x.phone, '0123456789');
});

test('id-only field falls back by id', () => {
  const x = extractContact(normaliseFields([f('name', '', 'text', 'Only Name')]));
  assert.equal(x.email, undefined);
  assert.equal(x.name, 'Only Name');
});

test('plain JSON body: The Social Scene booking form', () => {
  const x = extractContact(
    normaliseFields({
      form: 'booking',
      first_name: 'Kristy',
      last_name: 'Smith',
      email: 'Kristy@Example.com',
      phone: '(503) 555-0142',
      organization: 'Acme Offices',
      venue_type: 'Workplace',
      city: 'Portland',
      message: 'Lunch for 80 people',
    }),
  );
  assert.deepEqual(x, { name: 'Kristy Smith', email: 'kristy@example.com', phone: '(503) 555-0142', message: 'Lunch for 80 people' });
});

test('plain JSON body: The Social Scene cart form ignores the cart name', () => {
  const x = extractContact(
    normaliseFields({
      form: 'cart',
      cart_name: 'Taco Town',
      food_type: 'Mexican',
      website: 'https://tacotown.example',
      first_name: 'Luis',
      phone: '503-555-0199',
      email: 'luis@tacotown.example',
      areas: ['Portland', 'Beaverton'],
    }),
  );
  assert.equal(x.name, 'Luis');
  assert.equal(x.email, 'luis@tacotown.example');
  assert.equal(x.phone, '503-555-0199');
});
