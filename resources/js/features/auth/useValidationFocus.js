import {useEffect, useRef} from 'react';

const fieldError = '[aria-invalid="true"], .has-error, [data-invalid="true"]';
const control = 'input:not([type="hidden"]):not(:disabled), select:not(:disabled), textarea:not(:disabled), button:not(:disabled)';

function reveal(target) {
  const field = target.closest('label, fieldset, .has-error, [data-invalid="true"]') || target;
  const input = target.matches(control) ? target : field.querySelector(control);
  input?.focus({preventScroll: true});
  field.scrollIntoView({block: 'center', inline: 'nearest'});
}

export function revealNativeError(event) {
  const form = event.currentTarget;
  if (form.querySelector(':invalid:not(fieldset)') !== event.target) return;
  const target = event.target;
  requestAnimationFrame(() => reveal(target));
}

export function useValidationFocus(errors, message) {
  const ref = useRef(null);
  useEffect(() => {
    // Editing a field clears its error without moving focus to another field.
    if (Object.values(errors || {}).includes(undefined)) return;
    if (!message && !Object.values(errors || {}).some(Boolean)) return;
    const root = ref.current;
    const target = root?.querySelector(fieldError)
      || root?.querySelector('.reg-error, .field-error, [role="alert"]');
    if (target) reveal(target);
  }, [errors, message]);
  return ref;
}
