const labelFor = (field) => {
    const label = field.closest('label.form-field, label');
    if (label) {
        const copy = label.cloneNode(true);
        copy.querySelectorAll('input, select, textarea, button, small').forEach((node) => node.remove());
        const text = copy.textContent.replace(/\*/g, '').replace(/\s+/g, ' ').trim();
        if (text) return text;
    }

    return field.getAttribute('aria-label') || field.getAttribute('placeholder') || 'Այս դաշտը';
};

export function armenianValidationMessage(field, validity = field.validity) {
    if (!validity || validity.valid || validity.customError) return '';

    const label = labelFor(field);
    const min = field.getAttribute('minlength') || field.getAttribute('min');
    const max = field.getAttribute('maxlength') || field.getAttribute('max');

    if (validity.valueMissing) return `${label} դաշտը պարտադիր է։`;
    if (validity.typeMismatch && field.type === 'email') return 'Մուտքագրեք էլեկտրոնային փոստի վավեր հասցե։';
    if (validity.typeMismatch && field.type === 'url') return 'Մուտքագրեք վավեր հղում։';
    if (validity.tooShort) return `${label} դաշտը պետք է պարունակի առնվազն ${min} նիշ։`;
    if (validity.tooLong) return `${label} դաշտը կարող է պարունակել առավելագույնը ${max} նիշ։`;
    if (validity.rangeUnderflow) return `${label} դաշտի արժեքը պետք է լինի առնվազն ${min}։`;
    if (validity.rangeOverflow) return `${label} դաշտի արժեքը չպետք է գերազանցի ${max}‑ը։`;
    if (validity.stepMismatch) return 'Մուտքագրեք թույլատրելի քայլին համապատասխան արժեք։';
    if (validity.patternMismatch) return field.title || `${label} դաշտի ձևաչափը սխալ է։`;
    if (validity.badInput) return 'Մուտքագրեք ճիշտ ձևաչափի արժեք։';

    return `${label} դաշտի արժեքը վավեր չէ։`;
}

export function installArmenianNativeValidation(document = globalThis.document) {
    if (!document) return () => {};

    const onInvalid = (event) => {
        const field = event.target;
        if (!(field instanceof document.defaultView.HTMLInputElement)
            && !(field instanceof document.defaultView.HTMLSelectElement)
            && !(field instanceof document.defaultView.HTMLTextAreaElement)) return;

        const message = armenianValidationMessage(field);
        if (message) field.setCustomValidity(message);
    };
    const clearMessage = (event) => {
        const field = event.target;
        if (field instanceof document.defaultView.HTMLInputElement
            || field instanceof document.defaultView.HTMLSelectElement
            || field instanceof document.defaultView.HTMLTextAreaElement) field.setCustomValidity('');
    };

    document.addEventListener('invalid', onInvalid, true);
    document.addEventListener('input', clearMessage, true);
    document.addEventListener('change', clearMessage, true);

    return () => {
        document.removeEventListener('invalid', onInvalid, true);
        document.removeEventListener('input', clearMessage, true);
        document.removeEventListener('change', clearMessage, true);
    };
}
