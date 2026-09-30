import test from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';
import { armenianValidationMessage, installArmenianNativeValidation } from '../../resources/js/services/armenianValidation.js';

test('native required-field validation is shown in Armenian', () => {
    const document = new JSDOM('<form><label class="form-field">Քանակ *<input required></label></form>').window.document;
    const field = document.querySelector('input');
    const uninstall = installArmenianNativeValidation(document);

    assert.equal(field.checkValidity(), false);
    assert.equal(field.validationMessage, 'Քանակ դաշտը պարտադիր է։');
    field.value = '3';
    field.dispatchEvent(new document.defaultView.Event('input', { bubbles: true }));
    assert.equal(field.validationMessage, '');
    uninstall();
});

test('native constraints produce specific Armenian messages', () => {
    const document = new JSDOM('<form><label class="form-field">Պահեստային տեղ<input type="text" minlength="3" maxlength="8"></label></form>').window.document;
    const field = document.querySelector('input');

    assert.equal(armenianValidationMessage(field, { valid: false, tooShort: true }), 'Պահեստային տեղ դաշտը պետք է պարունակի առնվազն 3 նիշ։');
    assert.equal(armenianValidationMessage(field, { valid: false, tooLong: true }), 'Պահեստային տեղ դաշտը կարող է պարունակել առավելագույնը 8 նիշ։');
});
