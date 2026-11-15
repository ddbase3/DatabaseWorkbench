'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const root = path.resolve(__dirname, '../..');
const source = fs.readFileSync(path.join(root, 'assets/databaseworkbench/databaseworkbench.js'), 'utf8');
const marker = '\tglobal.DatabaseWorkbench = {';
assert.equal(source.split(marker).length, 2, 'The production error function must remain in the existing client script.');

// Expose the existing closure only inside this test VM. No production API is added.
const window = {};
vm.runInNewContext(source.replace(marker, '\tglobal.__errorForTest = localizeError;\n\tglobal.__appForTest = DatabaseWorkbenchApp;\n' + marker), {window});
const localizeError = window.__errorForTest;

function translations(language) {
	const contents = fs.readFileSync(path.join(root, 'lang/Display', language + '.ini'), 'utf8');
	const match = contents.match(/^error_database = "([^"]+)"$/m);
	assert.ok(match, 'Missing error_database translation for ' + language);
	return {error_database: match[1]};
}

test('database errors retain SQL server code and full message in German', () => {
	const config = {strings: translations('de')};
	const message = "Database error 1062: Duplicate entry '4-test' for key 'tag_unique'";
	assert.equal(localizeError(config, message), "Datenbankfehler 1062: Duplicate entry '4-test' for key 'tag_unique'");
});

test('SQL syntax errors retain full server message in English', () => {
	const config = {strings: translations('en')};
	const message = "Database error 1064: You have an error in your SQL syntax near '\\SELECT' at line 2";
	assert.equal(localizeError(config, message), "Database error 1064: You have an error in your SQL syntax near '\\SELECT' at line 2");
});

test('backend exceptions without the database error wrapper are not masked', () => {
	const config = {strings: {...translations('de'), request_failed: 'Die Anfrage ist fehlgeschlagen.'}};
	assert.equal(localizeError(config, 'SQLSTATE[42000]: Syntax error'), 'SQLSTATE[42000]: Syntax error');
	assert.equal(localizeError(config, ''), 'Die Anfrage ist fehlgeschlagen.');
	assert.equal(localizeError(config, 'DatabaseWorkbench request failed.'), 'Die Anfrage ist fehlgeschlagen.');
});

test('all shipped languages have complete localized database-error placeholders', () => {
	for (const language of ['ar', 'bg', 'de', 'en', 'es', 'fr', 'hi', 'it', 'pl', 'pt', 'ru', 'zh']) {
		const label = translations(language).error_database;
		assert.ok(label.includes('{code}'), language + ' needs {code}');
		assert.ok(label.includes('{message}'), language + ' needs {message}');
		const rendered = localizeError({strings: translations(language)}, 'Database error 1213: deadlock');
		assert.ok(rendered.includes('1213'), language);
		assert.ok(rendered.includes('deadlock'), language);
	}
});

test('SQL error details render as text and status updates are announced', () => {
	function elementStub(tagName) {
		return {
			tagName,
			attributes: {},
			dataset: {},
			children: [],
			classList: {add() {}, remove() {}, toggle() {}},
			append(...nodes) { this.children.push(...nodes); },
			appendChild(node) { this.children.push(node); },
			replaceChildren(...nodes) { this.children = nodes; },
			addEventListener() {},
			setAttribute(name, value) { this.attributes[name] = value; },
			querySelectorAll() { return []; }
		};
	}

	const rootElement = elementStub('div');
	const resultElement = elementStub('div');
	const document = {
		getElementById() { return rootElement; },
		createElement(tagName) { return elementStub(tagName); }
	};
	// Rendering is exercised in an isolated VM with a minimal DOM test double.
	const renderSource = source.replace(marker, '\tglobal.__appForTest = DatabaseWorkbenchApp;\n' + marker);
	const renderWindow = {};
	vm.runInNewContext(renderSource, {window: renderWindow, document});
	const renderedApp = new renderWindow.__appForTest({containerId: 'test', strings: {}});
	renderedApp.buildShell();
	assert.equal(renderedApp.status.attributes.role, 'status');
	renderedApp.content.querySelector = () => resultElement;
	const errorText = "Database error 1064: syntax near '<img src=x onerror=alert(1)>'";
	renderedApp.renderSqlResult(null, {error: errorText});
	assert.equal(resultElement.children[0].tagName, 'pre');
	assert.equal(resultElement.children[0].textContent, errorText);
	assert.equal(resultElement.children[0].innerHTML, undefined);
});
