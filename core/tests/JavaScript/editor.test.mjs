import test from 'node:test';
import assert from 'node:assert/strict';
import { slashQuery, matchingCommands, documentStats, createFrameScheduler, createAutosave, createUI } from '../../../site/editor/solqubit-editor.js';

function autosaveFixture(options={}) {
  const timers=new Map(), states=[], writes=[]; let id=0;
  const autosave=createAutosave({
    read:async()=>({version:0,base_revision:'original',draft:null}),
    write:async payload=>{writes.push(payload);return {version:payload.version+1};},
    snapshot:()=>({document:'texto'}),onState:(...state)=>states.push(state),
    schedule:callback=>{timers.set(++id,callback);return id;},cancel:key=>timers.delete(key),...options,
  });
  return {autosave,timers,states,writes};
}

test('El autoguardado agrupa cambios y serializa peticiones sin perder la edición más reciente', async()=>{
  let finish, text='primero'; const writes=[];
  const {autosave,timers}=autosaveFixture({snapshot:()=>({document:text}),write:payload=>{writes.push(payload);return new Promise(resolve=>{finish=resolve;});}});
  await autosave.start(); assert.equal(timers.size,0);
  for(let i=0;i<20;i++) autosave.changed();
  assert.equal(timers.size,1);
  const first=autosave.flush();
  text='segundo'; autosave.changed(); await autosave.flush();
  assert.equal(writes.length,1);
  finish({version:1}); await first;
  const second=autosave.flush();
  assert.equal(writes.length,2);
  assert.deepEqual(writes[1],{document:'segundo',version:1,base_revision:'original'});
  finish({version:2}); await second; autosave.destroy(); assert.equal(timers.size,0);
});

test('Un borrador recuperable espera la decisión del autor antes de sobrescribirlo', async()=>{
  const {autosave,timers,states,writes}=autosaveFixture({read:async()=>({version:3,base_revision:'actual',draft:{payload:{document:'recuperable'}}})});
  await autosave.start(); autosave.changed(); await autosave.flush();
  assert.equal(states.at(-1)[0],'recover'); assert.equal(timers.size,0); assert.equal(writes.length,0);
  autosave.recovered(); await autosave.flush();
  assert.equal(writes[0].version,3); autosave.destroy();
});

test('Un fallo de conexión no crea un bucle y un conflicto detiene escrituras posteriores', async()=>{
  let attempts=0, conflict=false;
  const {autosave,timers,states}=autosaveFixture({write:async()=>{attempts++;throw Object.assign(new Error('Fallo'),{status:conflict?409:503});}});
  await autosave.start(); autosave.changed();
  const callback=[...timers.values()][0]; timers.clear(); await callback();
  assert.equal(attempts,1); assert.equal(timers.size,0); assert.equal(states.at(-1)[0],'error');
  conflict=true; autosave.changed(); await autosave.flush();
  autosave.changed(); await autosave.flush(); assert.equal(attempts,2); autosave.destroy();
});

test('Guardar el formulario renueva la versión sin aceptar respuestas tardías del autoguardado', async()=>{
  let readCount=0, finish; const writes=[];
  const {autosave,states}=autosaveFixture({
    read:async()=>({version:0,base_revision:++readCount===1?'anterior':'guardada',draft:null}),
    write:payload=>{writes.push(payload);return new Promise(resolve=>{finish=resolve;});},
  });
  await autosave.start(); autosave.changed(); const pending=autosave.flush();
  const reset=autosave.saved(); finish({version:9}); await pending; await reset;
  assert.equal(states.filter(([state])=>state==='saved').length,0);
  autosave.changed(); const next=autosave.flush();
  assert.equal(writes[1].base_revision,'guardada'); assert.equal(writes[1].version,0);
  finish({version:1}); await next; autosave.destroy();
});

test('Cerrar el editor descarta respuestas pendientes y la carga inicial puede recuperarse de un fallo', async()=>{
  let attempt=0, finish;
  const {autosave,states}=autosaveFixture({read:async()=>{if(++attempt===1)throw new Error('Sin conexión');return {version:0,base_revision:null,draft:null};},write:()=>new Promise(resolve=>{finish=resolve;})});
  await autosave.start(); assert.equal(states.at(-1)[0],'error');
  autosave.changed(); await Promise.resolve(); assert.equal(states.at(-1)[0],'ready');
  const pending=autosave.flush(); autosave.destroy(); const count=states.length;
  finish({version:1}); await pending; assert.equal(states.length,count);
});

test('El menú se invoca al empezar un párrafo sin interceptar rutas o direcciones', () => {
  assert.equal(slashQuery('/'), '');
  assert.equal(slashQuery('/código'), 'codigo');
  for (const value of ['https://example.com/', 'Consulta /archivo', '/etc/nginx', 'Un párrafo normal']) assert.equal(slashQuery(value), null);
});
test('La búsqueda encuentra herramientas sin distinguir tildes y conserva opciones útiles', () => {
  assert.equal(matchingCommands('codigo')[0][2], 'sq_code');
  assert.equal(matchingCommands('imagen')[0][2], 'sq_image');
  assert.deepEqual(matchingCommands('ningun-elemento'), []);
  const custom = [['Aviso', 'Información, nota o advertencia', 'sq_notice'], ['Tabla', 'Filas y columnas', 'sq_table']];
  assert.deepEqual(matchingCommands('aviso', custom).map(command => command[2]), ['sq_notice']);
  assert.ok(matchingCommands('').some(command => command[2] === 'paragraph'));
});

test('El contador reconoce palabras con formato y títulos sin contar los datos de los archivos', () => {
  assert.deepEqual(documentStats({ type: 'doc', content: [] }), { words: 0, headings: 0 });
  assert.deepEqual(documentStats({ type: 'doc', content: [
    { type: 'heading', attrs: { level: 2 }, content: [{ type: 'text', text: 'Configuración de Linux' }] },
    { type: 'paragraph', content: [
      { type: 'text', text: 'Una ' }, { type: 'text', text: 'pala', marks: [{ type: 'bold' }] },
      { type: 'text', text: 'bra' }, { type: 'hardBreak' }, { type: 'text', text: 'más.' },
    ] },
    { type: 'heading', attrs: { level: 3 }, content: [{ type: 'text', text: 'Paso 2' }] },
    { type: 'customBlock', attrs: { id: 'image', config: { caption: 'Archivo adjunto' }, preview: 'base64 not written text' } },
  ] }), { words: 8, headings: 2 });
});

test('Las actualizaciones se agrupan y nunca se ejecutan después de cerrar el editor', () => {
  const frames = new Map(); let next = 0, calls = 0;
  const scheduler = createFrameScheduler(() => calls++, callback => { frames.set(++next, callback); return next; }, id => frames.delete(id));
  for (let i = 0; i < 1000; i++) scheduler.schedule();
  assert.equal(calls, 0);
  assert.equal(frames.size, 1);
  const callback = frames.get(1); frames.delete(1); callback();
  assert.equal(calls, 1);
  scheduler.schedule();
  const pending = frames.get(2);
  scheduler.destroy(); pending(); scheduler.schedule();
  assert.equal(frames.size, 0);
  assert.equal(calls, 1);
});

test('El menú se mantiene estable y no modifica directamente el DOM del documento', t => {
  const frames = new Map(); let next = 0, mutations = 0, optionUpdates = 0, timers = 0;
  class Element {
    children = []; style = {}; dataset = {}; attributes = {}; listeners = {};
    classes = new Set();
    classList = { toggle: (name, active) => active ? this.classes.add(name) : this.classes.delete(name), contains: name => this.classes.has(name) };
    set hidden(value) { this.isHidden = value; mutations++; }
    get hidden() { return this.isHidden; }
    setAttribute(name, value) { this.attributes[name] = value; mutations++; }
    removeAttribute(name) { delete this.attributes[name]; mutations++; }
    append(...children) { this.children.push(...children); mutations++; }
    replaceChildren() { this.children = []; mutations++; }
    addEventListener(name, listener) { this.listeners[name] = listener; }
    removeEventListener() {}
    remove() {}
    contains(target) { return this === target || this.children.some(child => child.contains(target)); }
    closest() { return null; }
    querySelector() { return null; }
    scrollIntoView() {}
  }
  const document = new Element(); document.body = new Element(); document.createElement = () => new Element();
  const window = new Element(); window.innerWidth = 1200; window.innerHeight = 900;
  const wrapper = new Element();
  const focusButton = new Element(), focusLabel = new Element();
  focusButton.querySelector = () => focusLabel;
  wrapper.querySelector = selector => selector === '[data-rich-editor-tool="sq_focus"]' ? focusButton : null;
  const host = new Element(); wrapper.append(host);
  const dom = new Element(); dom.closest = selector => selector === '[data-rich-editor-key]' ? host : wrapper;
  dom.setAttribute = dom.removeAttribute = () => assert.fail('El documento debe permanecer bajo el control del editor.');
  let prefix = 'Texto normal';
  const editor = {
    isFocused: true, isDestroyed: false,
    options: { editorProps: { attributes: { role: 'textbox' } } },
    getJSON: () => ({ type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: prefix }] }] }),
    setOptions(options) { optionUpdates++; this.options = options; },
    view: { dom, coordsAtPos: () => ({ left: 100, bottom: 200 }) },
    get state() { return { selection: { empty: true, $from: { pos: prefix.length + 1, parentOffset: prefix.length, parent: { type: { name: 'paragraph' }, textBetween: () => prefix } } } }; },
  };
  const globals = { document, window, requestAnimationFrame: callback => { frames.set(++next, callback); return next; }, cancelAnimationFrame: id => frames.delete(id), setTimeout: () => { timers++; } };
  const originals = Object.fromEntries(Object.keys(globals).map(key => [key, Object.getOwnPropertyDescriptor(globalThis, key)]));
  for (const [key, value] of Object.entries(globals)) Object.defineProperty(globalThis, key, { configurable: true, writable: true, value });
  let ui;
  t.after(() => { ui?.destroy(); for (const [key, descriptor] of Object.entries(originals)) { if (descriptor) Object.defineProperty(globalThis, key, descriptor); else delete globalThis[key]; } });
  ui = createUI(editor);
  assert.deepEqual(wrapper.children, [host], 'El contador no debe convertirse en una columna junto al editor.');
  assert.ok(!host.children.some(element => element.className === 'sq-live-preview'), 'La vista previa vive en la cabecera del editor, no dentro del documento.');
  const counter = host.children.find(element => element.className === 'sq-document-footer').children[0];
  assert.equal(counter.textContent, '2 palabras de texto · 0 títulos');
  const flush = () => { const queued = [...frames.values()]; frames.clear(); for (const callback of queued) callback(); };
  const initialMutations = mutations;
  for (let i = 0; i < 100; i++) { ui.update(); ui.changed(); }
  flush();
  assert.equal(mutations, initialMutations);
  assert.equal(timers, 0, 'No debe programar vistas previas cuando están cerradas.');

  prefix = '/imagen'; ui.update(); flush();
  const menu = document.body.children[0];
  assert.equal(menu.hidden, false);
  assert.equal(menu.children[0].children[0].textContent, 'Imagen');
  assert.equal(optionUpdates, 1);
  const openMutations = mutations;
  ui.update(); flush();
  assert.equal(mutations, openMutations);
  assert.equal(optionUpdates, 1);

  prefix = 'Texto'; ui.update(); flush();
  assert.equal(menu.hidden, true);
  assert.equal(editor.options.editorProps.attributes['aria-controls'], undefined);
  assert.equal(editor.options.editorProps.attributes.role, 'textbox');
  const closedMutations = mutations;
  ui.update(); flush();
  assert.equal(mutations, closedMutations);

  ui.changed(); flush();
  assert.equal(counter.textContent, '1 palabra de texto · 0 títulos');
  document.body.style.overflow = 'auto';
  const originalDocument = editor.getJSON();
  ui.toggleFocus();
  assert.equal(wrapper.classList.contains('sq-focus-mode'), true);
  assert.equal(document.body.style.overflow, 'hidden');
  assert.equal(focusLabel.textContent, 'Salir de enfoque');
  assert.equal(focusButton.attributes['aria-label'], 'Salir de enfoque');
  assert.equal(ui.keydown({ key: 'Escape' }), true);
  assert.equal(wrapper.classList.contains('sq-focus-mode'), false);
  assert.equal(document.body.style.overflow, 'auto');
  assert.equal(focusLabel.textContent, 'Enfoque');
  assert.deepEqual(editor.getJSON(), originalDocument);
  ui.toggleFocus();
  ui.changed();
  ui.destroy(); ui = null;
  assert.equal(document.body.style.overflow, 'auto');
  assert.equal(wrapper.classList.contains('sq-focus-mode'), false);
  assert.equal(frames.size, 0);
});

import { markdownHTML } from '../../../site/editor/document-tools.js';

test('Pegar Markdown conserva títulos, negritas, citas, listas y enlaces',()=>{
  const html=markdownHTML('## Un título\n\nTexto **fuerte** y *cursiva*.\n\n> Una cita\n\n- Uno\n- Dos\n\n[Fuente](https://example.com)');
  for(const fragment of ['<h2>Un título</h2>','<strong>fuerte</strong>','<em>cursiva</em>','<blockquote>','<ul><li>','href="https://example.com"']) assert.ok(html.includes(fragment),fragment);
});

test('El código pegado conserva símbolos literales y no interpreta HTML como ejecutable',()=>{
  const html=markdownHTML('## Código\n\n`**literal**`\n\n```html\n<script>alert(1)</script>\n```');
  assert.ok(html.includes('<code>**literal**</code>'));
  assert.ok(html.includes('&lt;script&gt;alert(1)&lt;/script&gt;'));
  assert.ok(!html.includes('<script>'));
  assert.equal(markdownHTML('Un párrafo corriente.'),null);
});

test('a block from the panel goes where the writer is looking when the cursor is off screen', async () => {
  const { insertionTarget } = await import('../../../site/editor/solqubit-editor.js');
  const blocks = [
    { from: 0, to: 40, top: -900, bottom: -700, textblock: true },
    { from: 40, to: 90, top: 120, bottom: 300, textblock: true },
    { from: 90, to: 95, top: 320, bottom: 600, textblock: false },
    { from: 95, to: 140, top: 640, bottom: 900, textblock: true },
  ];
  assert.equal(insertionTarget(blocks, 76, 900), 89, 'Al final del párrafo que se lee arriba de la pantalla.');
  assert.equal(insertionTarget(blocks.slice(2), 76, 900), 95, 'Después de un bloque que no es de texto.');
  assert.equal(insertionTarget([], 76, 900), null);
});

test('focus given back to the document by code never scrolls the page', async () => {
  const { keepFocusInPlace } = await import('../../../site/editor/solqubit-editor.js');
  const calls = [];
  const element = { focus(options) { calls.push(options); } };
  const restore = keepFocusInPlace(element);
  element.focus();
  element.focus({ focusVisible: true });
  assert.deepEqual(calls, [{ preventScroll: true }, { focusVisible: true, preventScroll: true }]);
  restore();
  element.focus();
  assert.deepEqual(calls.at(-1), undefined);
});
