(() => {
  const form = document.querySelector('#installation-form');
  if (!form) return;
  const controls = [...form.querySelectorAll('input:not([type=hidden]):not([type=radio]), select')];
  const password = form.elements.password;
  const confirmation = form.elements.password_confirmation;
  const databaseControls = controls.filter(control => control.name.startsWith('db_'));
  const checkButton = document.querySelector('#check-database');
  const result = document.querySelector('#database-result');
  let revision = 0;
  let checking = false;

  function checkPasswords() {
    const rules = {
      length: password.value.length >= 12,
      letters: /\p{L}/u.test(password.value) && /\p{N}/u.test(password.value),
      match: !!confirmation.value && password.value === confirmation.value
    };
    password.setCustomValidity(password.value && !rules.letters ? 'Incluye letras y números.' : '');
    confirmation.setCustomValidity(confirmation.value && !rules.match ? 'Las contraseñas no coinciden.' : '');
    for (const [name, valid] of Object.entries(rules)) {
      const item = form.querySelector('[data-password-rule="' + name + '"]');
      const touched = name === 'match' ? !!confirmation.value : !!password.value;
      item.classList.toggle('is-ok', valid);
      item.classList.toggle('is-error', touched && !valid);
      item.querySelector('span').textContent = valid ? '✓' : touched ? '×' : '○';
    }
  }

  function feedback(control) {
    const valid = control.validity.valid;
    control.setAttribute('aria-invalid', String(!valid));
    let message = control.parentElement.querySelector('.field-feedback');
    if (!message) {
      message = document.createElement('span');
      message.id = 'feedback-' + control.name;
      message.className = 'field-feedback';
      control.parentElement.append(message);
      control.setAttribute('aria-describedby', [control.getAttribute('aria-describedby'), message.id].filter(Boolean).join(' '));
    }
    message.className = 'field-feedback' + (valid ? '' : ' is-error');
    message.textContent = valid ? '' : '× ' + control.validationMessage;
  }

  for (const control of controls) {
    control.addEventListener('blur', () => { checkPasswords(); feedback(control); });
    control.addEventListener('input', () => {
      checkPasswords();
      if (control.hasAttribute('aria-invalid')) feedback(control);
      if (databaseControls.includes(control)) {
        revision++;
        result.className = 'install-feedback';
        if (!result.hidden) result.textContent = 'Los datos cambiaron. Vuelve a comprobar la conexión.';
      }
    });
    control.addEventListener('invalid', () => feedback(control));
  }
  document.querySelector('.database-check').hidden = false;
  checkButton.addEventListener('click', async () => {
    if (checking) return;
    const invalid = databaseControls.find(control => !control.checkValidity());
    if (invalid) { invalid.reportValidity(); return; }
    checking = true;
    checkButton.disabled = true;
    checkButton.textContent = 'Comprobando…';
    result.hidden = false;
    result.className = 'install-feedback';
    result.textContent = 'Conectando con la base de datos…';
    const currentRevision = revision;
    const data = new FormData();
    data.set('_token', form.elements._token.value);
    for (const control of databaseControls) data.set(control.name, control.value);
    try {
      const response = await fetch(form.dataset.databaseUrl, {method: 'POST', body: data, headers: {Accept: 'application/json'}, credentials: 'same-origin', signal: AbortSignal.timeout(15000)});
      if (revision !== currentRevision) return;
      const payload = await response.json().catch(() => ({}));
      if (revision !== currentRevision) return;
      if (response.status === 419) throw new Error('La sesión venció. Recarga la página y vuelve a intentarlo.');
      if (response.status === 429) throw new Error('Has realizado varias comprobaciones. Espera un minuto y vuelve a intentarlo.');
      if (response.status === 404) throw new Error('Este sitio ya se instaló. Recarga la página para entrar al panel.');
      if (!response.ok || !payload.ok) throw new Error(Object.values(payload.errors || {}).flat()[0] || 'No se pudo comprobar la conexión. Revisa los datos y vuelve a intentarlo.');
      result.className = 'install-feedback is-ok';
      result.textContent = '✓ ' + payload.message;
    } catch (error) {
      if (revision !== currentRevision) return;
      result.className = 'install-feedback is-error';
      result.textContent = '× ' + (error.name === 'TimeoutError' || error instanceof TypeError ? 'No se recibió respuesta. Revisa el servidor y vuelve a intentarlo.' : error.message);
    } finally {
      checking = false;
      checkButton.disabled = false;
      checkButton.textContent = 'Comprobar conexión';
    }
  });
  form.addEventListener('submit', event => {
    checkPasswords();
    if (!form.reportValidity()) { event.preventDefault(); return; }
    const button = form.querySelector('[type=submit]');
    button.disabled = true;
    button.textContent = 'Instalando tu blog…';
    form.setAttribute('aria-busy', 'true');
  });
  window.addEventListener('pageshow', () => {
    form.removeAttribute('aria-busy');
    const button = form.querySelector('[type=submit]');
    if (button.textContent.includes('Instalando')) { button.disabled = false; button.textContent = 'Instalar mi blog →'; }
  });
  document.querySelector('#install-errors')?.focus();
})();
