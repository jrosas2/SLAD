const nonTextInputTypes = new Set([
    'button',
    'checkbox',
    'color',
    'date',
    'datetime-local',
    'email',
    'file',
    'hidden',
    'image',
    'month',
    'number',
    'password',
    'radio',
    'range',
    'reset',
    'submit',
    'tel',
    'time',
    'url',
    'week',
]);

function shouldUppercase(target) {
    if (! (target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement)) {
        return false;
    }

    if (target.disabled || target.readOnly || target.hasAttribute('data-preserve-case')) {
        return false;
    }

    return ! (target instanceof HTMLInputElement && (
        nonTextInputTypes.has(target.type)
        || ['current-password', 'new-password'].includes(target.autocomplete)
    ));
}

function uppercaseInputValue(event) {
    const target = event.target;

    if (event.isComposing || ! shouldUppercase(target)) {
        return;
    }

    const uppercaseValue = target.value.toLocaleUpperCase('es-CL');

    if (target.value === uppercaseValue) {
        return;
    }

    const selectionStart = target.selectionStart;
    const selectionEnd = target.selectionEnd;
    target.value = uppercaseValue;

    if (selectionStart !== null && selectionEnd !== null) {
        target.setSelectionRange(selectionStart, selectionEnd);
    }
}

document.addEventListener('input', uppercaseInputValue, true);
document.addEventListener('compositionend', uppercaseInputValue, true);
