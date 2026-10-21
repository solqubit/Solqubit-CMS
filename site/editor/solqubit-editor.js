const { cleanPastedHTML, markdownHTML, installInlineBlocks, createSelectionTools } = await import(new URL('./document-tools.js'+new URL(import.meta.url).search, import.meta.url).href);

const normalize = value => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
export function slashQuery(text) { const match = /^\/([^\s/]*)$/.exec(text); return match ? normalize(match[1]) : null; }
const commands = [
  ['Párrafo', 'Texto normal', 'paragraph'], ['Título H2', 'Sección del contenido', 'h2'], ['Subtítulo H3', 'Subsección', 'h3'],
  ['Lista', 'Viñetas', 'bulletList'], ['Lista numerada', 'Pasos en orden', 'orderedList'], ['Cita', 'Texto destacado', 'blockquote'],
  ['Imagen', 'Biblioteca o subir una imagen', 'sq_image'], ['YouTube', 'Video por enlace', 'sq_video'], ['Video propio', 'MP4 o WebM', 'sq_media_video'],
  ['Código', 'Comandos y fragmentos', 'sq_code'], ['Archivo', 'Descarga para tus lectores', 'sq_file'], ['Tabla', 'Filas y columnas', 'sq_table'],
  ['Aviso', 'Información, nota o advertencia', 'sq_notice'], ['Desplegable', 'Pregunta y respuesta', 'sq_details'],
  ['Lista destacada', 'Resumen informativo', 'sq_list'],
  ['Enlaces', 'Referencias útiles', 'sq_links'], ['Separador', 'Dividir el contenido', 'horizontalRule'],
];
export function matchingCommands(query, list = commands) { return list.filter(([label, detail]) => normalize(label+' '+detail).includes(normalize(query))); }

export function documentStats(document) {
  let text = '', headings = 0;
  function visit(node) {
    if (node.type === 'heading') headings++;
    if (node.type === 'text') text += node.text || '';
    else {
      for (const child of node.content || []) visit(child);
      if (!['doc', 'hardBreak'].includes(node.type)) text += ' ';
      if (node.type === 'hardBreak') text += ' ';
    }
  }
  visit(document);
  return { words: text.match(/[\p{L}\p{N}]+(?:['’\-][\p{L}\p{N}]+)*/gu)?.length || 0, headings };
}

/**
 * Where a block from the panel goes when the cursor is off screen: at the end of the first block the writer can see
 * at the top of the editor, or right after it when it is not a text block. `blocks` are the document's top-level
 * nodes with their positions and on-screen edges.
 */
export function insertionTarget(blocks, viewportTop, viewportBottom) {
  const block = blocks.find(item => item.bottom > viewportTop && item.top < viewportBottom);
  if (!block) return null;
  return block.textblock ? block.to - 1 : block.to;
}

/**
 * Makes every focus given to `element` by code keep the page still. When a dialog closes, the browser hands focus
 * back to the document; if its own caret had moved into the dialog, a plain focus() puts it at the top of the
 * document and scrolls there, although the editor then restores the writer's cursor. Returns the undo.
 */
export function keepFocusInPlace(element) {
  const own = Object.prototype.hasOwnProperty.call(element, 'focus') ? element.focus : null;
  const focus = element.focus;
  element.focus = function (options) { return focus.call(this, { ...(options || {}), preventScroll: true }); };
  return () => { if (own) element.focus = own; else delete element.focus; };
}

export function createFrameScheduler(update, requestFrame = requestAnimationFrame, cancelFrame = cancelAnimationFrame) {
  let frame = null, destroyed = false;
  return {
    schedule() {
      if (destroyed || frame !== null) return;
      frame = requestFrame(() => { frame = null; if (!destroyed) update(); });
    },
    destroy() { destroyed = true; if (frame !== null) cancelFrame(frame); frame = null; },
  };
}

export function createAutosave({ read, write, snapshot, onState, delay = 1800, schedule = setTimeout, cancel = clearTimeout }) {
  let version=0, base=null, ready=false, paused=false, dirty=false, busy=false, destroyed=false, timer=null, saved=null, generation=0, flight=null, loading=false;
  function queue() { if (destroyed || paused || !ready || busy) return; cancel(timer); timer=schedule(flush,delay); }
  async function start() {
    const current=++generation; loading=true; ready=false;
    try {
      const result=await read(); if (destroyed || current!==generation) return;
      version=result.version; base=result.base_revision; saved=result.draft; ready=true; paused=!!saved;
      onState(saved ? 'recover' : 'ready',saved); if (dirty && !paused) queue();
    } catch { if (!destroyed && current===generation) onState('error','No se pudo iniciar el autoguardado. Guarda con el botón del formulario.'); }
    finally { if(current===generation) loading=false; }
  }
  async function flush() {
    if (destroyed || paused || !ready || busy || !dirty) return;
    busy=true; dirty=false; const current=generation; let failed=false; onState('saving');
    try {
      flight=write({...snapshot(),version,base_revision:base});
      const result=await flight;
      if (destroyed || current!==generation) return;
      version=result.version; onState('saved',result.saved_at);
    } catch (error) {
      failed=true;
      if (destroyed || current!==generation) return;
      dirty=true;
      if (error.status===409 || error.status===401 || error.status===419) paused=true;
      if (!destroyed) onState('error',error.message);
    } finally { busy=false; flight=null; if (!destroyed && dirty && !paused && !failed) queue(); }
  }
  return {
    start, changed() { dirty=true; if(!ready && !loading && !destroyed) start(); else queue(); }, flush,
    async saved() {
      ++generation; ready=false; loading=true; paused=false; dirty=false; cancel(timer);
      try { await flight; } catch { /* The explicit save takes precedence over the earlier snapshot. */ }
      if(!destroyed) await start();
    },
    recovered() { saved=null; paused=false; dirty=true; queue(); },
    async discard(remove) { const result=await remove({version,base_revision:base}); if(destroyed) return; version=result.version; saved=null; paused=false; dirty=false; onState('ready'); },
    destroy() { destroyed=true; cancel(timer); },
  };
}

export function createUI(editor) {
  const wrapper = editor.view.dom.closest('.solqubit-editor');
  const host = editor.view.dom.closest('[data-rich-editor-key]');
  if (!wrapper || !host) return { update() {}, changed() {}, keydown() { return false; }, toggleFocus() {}, openInsert() {}, destroy() {} };
  let blockCommands = commands;
  try { if (wrapper.dataset.commands) blockCommands = JSON.parse(new TextDecoder().decode(Uint8Array.from(atob(wrapper.dataset.commands), character => character.charCodeAt(0)))); } catch { blockCommands = commands; }
  const menu = document.createElement('div');
  menu.className = 'sq-slash-menu'; menu.hidden = true; menu.id = 'sq-menu-'+crypto.randomUUID(); menu.setAttribute('role', 'listbox'); menu.setAttribute('aria-label', 'Insertar elemento');
  document.body.append(menu);
  let active = 0, matches = [], range = null, signature = '', rendered = '', dismissed = '', forcedKey = null;
  const footer=document.createElement('div'); footer.className='sq-document-footer';
  const counter=document.createElement('span'); const shortcut=document.createElement('span'); shortcut.textContent='/ para insertar · El autoguardado guarda una copia privada; publica o actualiza para aplicar los cambios';
  footer.append(counter,shortcut); host.append(footer);
  let focusMode=false, previousOverflow='';
  function updateStats() {
    if (editor.isDestroyed) return;
    const {words,headings}=documentStats(editor.getJSON());
    const summary=words+' '+(words===1?'palabra de texto':'palabras de texto')+' · '+headings+' '+(headings===1?'título':'títulos');
    if (counter.textContent!==summary) counter.textContent=summary;
  }
  updateStats();
  const statsUpdates=createFrameScheduler(updateStats);
  function toggleFocus() {
    focusMode=!focusMode;
    if (focusMode) { previousOverflow=document.body.style.overflow; document.body.style.overflow='hidden'; }
    else document.body.style.overflow=previousOverflow;
    wrapper.classList.toggle('sq-focus-mode',focusMode);
    const button=wrapper.querySelector('[data-rich-editor-tool="sq_focus"]');
    const label=focusMode?'Salir de enfoque':'Enfoque';
    button?.setAttribute('aria-label',label);
    const buttonLabel=button?.querySelector('.fi-fo-rich-editor-tool-label');
    if (buttonLabel) buttonLabel.textContent=label;
  }
  const component = () => window.Alpine.$data(host);
  let autosave=null, draftBar=null;
  const editorSaved=event=>{
    if(event.detail.context===wrapper.dataset.editorContext && String(event.detail.recordId)===String(wrapper.dataset.recordId||'new')) autosave?.saved();
  };
  window.addEventListener('editor-saved',editorSaved);
  if (wrapper.dataset.draftUrl) {
    draftBar=document.createElement('div'); draftBar.className='sq-autosave-bar';
    const draftStatus=document.createElement('span'); draftStatus.setAttribute('role','status');
    const recover=document.createElement('button'); recover.type='button'; recover.textContent='Recuperar borrador'; recover.hidden=true;
    const discard=document.createElement('button'); discard.type='button'; discard.textContent='Descartar'; discard.hidden=true;
    draftBar.append(draftStatus,recover,discard); host.append(draftBar);
    let recoverable=null;
    const identity={context:wrapper.dataset.editorContext,record_id:wrapper.dataset.recordId||null};
    async function draftRequest(method,payload={}) {
      const url=new URL(wrapper.dataset.draftUrl,location.href);
      if(method==='GET') Object.entries(identity).forEach(([key,value])=>{if(value!==null)url.searchParams.set(key,value);});
      const controller=new AbortController(), timeout=setTimeout(()=>controller.abort(),12000);
      try {
        const response=await fetch(url,{method,credentials:'same-origin',signal:controller.signal,headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},...(method==='GET'?{}:{body:JSON.stringify({...identity,...payload})})});
        const result=await response.json();
        if(!response.ok) { const error=new Error(response.status===409?'La entrada o su borrador cambió en otra pestaña. Recarga antes de seguir.':response.status===401||response.status===419?'Tu sesión terminó. Copia lo escrito antes de iniciar sesión de nuevo.':Object.values(result.errors||{}).flat()[0]||'No se pudo guardar el borrador. Usa Guardar en el formulario.'); error.status=response.status; throw error; }
        return result;
      } finally { clearTimeout(timeout); }
    }
    autosave=createAutosave({read:()=>draftRequest('GET'),write:payload=>draftRequest('PUT',payload),snapshot:()=>({document:editor.getJSON(),fields:component().$wire.$get('data')||{}}),onState:(state,value)=>{
      draftBar.dataset.state=state;
      recover.hidden=discard.hidden=state!=='recover';
      if(state==='recover') { recoverable=value.payload; draftStatus.textContent=value.outdated?'Hay un borrador anterior a la última versión guardada. Recuperarlo no publica cambios.':'Tienes un borrador de trabajo sin aplicar.'; }
      else draftStatus.textContent=({ready:'Autoguardado activo · borrador privado, sin publicar',saving:'Guardando borrador…',saved:'Borrador guardado · '+(value?new Date(value).toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'}):'')+' · sin publicar',error:value})[state];
    }});
    recover.addEventListener('click',()=>{
      if(!recoverable) return;
      for(const [key,value] of Object.entries(recoverable.fields)) component().$wire.$set('data.'+key,value,false);
      editor.commands.setContent(recoverable.document); recoverable=null; recover.hidden=discard.hidden=true; autosave.recovered();
    });
    discard.addEventListener('click',async()=>{try{await autosave.discard(payload=>draftRequest('DELETE',payload));recoverable=null;}catch(error){draftStatus.textContent=error.message;}});
    autosave.start();
  }
  function menuAccessibility(open) {
    if (editor.isDestroyed) return;
    const current = editor.options.editorProps.attributes || {}, attributes = { ...current };
    if (open) {
      attributes['aria-controls'] = menu.id;
      if (matches.length) attributes['aria-activedescendant'] = menu.id+'-'+active;
      else delete attributes['aria-activedescendant'];
    } else {
      delete attributes['aria-controls']; delete attributes['aria-activedescendant'];
    }
    if (current['aria-controls'] === attributes['aria-controls'] && current['aria-activedescendant'] === attributes['aria-activedescendant']) return;
    // Let ProseMirror own its DOM attributes; direct writes can feed its mutation observer.
    editor.setOptions({ editorProps: { ...editor.options.editorProps, attributes } });
  }
  function hide() {
    range=null; rendered=''; forcedKey=null;
    if (menu.hidden) return;
    menu.hidden=true; menuAccessibility(false);
  }
  function update() {
    if (editor.isDestroyed) return;
    const { selection } = editor.state, from = selection.$from;
    const prefix = from.parent.textBetween(0, from.parentOffset, undefined, '\ufffc');
    const key = from.pos+':'+prefix;
    if (forcedKey !== null && forcedKey !== key) forcedKey = null;
    const forced = forcedKey !== null;
    const query = forced ? '' : (selection.empty && ['paragraph','heading'].includes(from.parent.type.name) ? slashQuery(prefix) : null);
    if (query === null || key === dismissed || (!editor.isFocused && !forced)) { hide(); return; }
    matches = matchingCommands(query, blockCommands); range = forced ? { from: from.pos, to: from.pos } : { from: from.pos-prefix.length, to: from.pos };
    if (signature !== query) { active=0; signature=query; }
    active=Math.min(active, Math.max(0,matches.length-1));
    const nextRendered=key+':'+active;
    if (!menu.hidden && rendered===nextRendered) return;
    rendered=nextRendered;
    menu.replaceChildren(); menu.hidden=false;
    if (!matches.length) { const empty=document.createElement('p'); empty.textContent='No hay elementos con ese nombre.'; menu.append(empty); }
    matches.forEach(([label,detail],index) => {
      const button=document.createElement('button'); button.type='button'; button.id=menu.id+'-'+index; button.setAttribute('role','option'); button.setAttribute('aria-selected',String(index===active));
      const title=document.createElement('strong'); title.textContent=label; const hint=document.createElement('span'); hint.textContent=detail; button.append(title,hint);
      button.addEventListener('mousedown',event=>event.preventDefault()); button.addEventListener('click',()=>insert(index)); menu.append(button);
    });
    const coords=editor.view.coordsAtPos(from.pos), maxHeight=Math.min(320,window.innerHeight-32);
    menu.style.maxHeight=maxHeight+'px'; menu.style.left=Math.max(8,Math.min(coords.left,window.innerWidth-300))+'px';
    menu.style.top=Math.max(8,Math.min(coords.bottom+8,window.innerHeight-maxHeight-8))+'px';
    menuAccessibility(true);
    menu.children[active]?.scrollIntoView({block:'nearest'});
  }
  /** Opens the same menu as "/" at the cursor, listing every element. */
  function openInsert() {
    if (editor.isDestroyed) return;
    editor.commands.focus();
    const { selection } = editor.state, from = selection.$from;
    forcedKey = from.pos+':'+from.parent.textBetween(0, from.parentOffset, undefined, '\ufffc'); dismissed=''; rendered=''; update();
  }
  function insert(index) {
    if (!range || !matches[index]) return;
    const tool = matches[index][2], insertion = range;
    hide(); dismissed=''; editor.chain().focus().deleteRange(insertion).run();
    runCommand(tool);
  }
  /**
   * A writer who scrolled without clicking still has the cursor where it was, often at the very start.
   * Focusing it there would jump the page, so the cursor moves to the text on screen first.
   */
  function keepInsertionInView() {
    const top = (document.querySelector('.sq-writing-header')?.getBoundingClientRect().bottom ?? 0) + 8, bottom = window.innerHeight;
    const caret = editor.view.coordsAtPos(editor.state.selection.from);
    if (caret.bottom > top && caret.top < bottom) return;
    const blocks = [];
    editor.state.doc.forEach((node, offset) => {
      const dom = editor.view.nodeDOM(offset);
      if (!dom?.getBoundingClientRect) return;
      const rect = dom.getBoundingClientRect();
      blocks.push({ from: offset, to: offset + node.nodeSize, top: rect.top, bottom: rect.bottom, textblock: node.isTextblock });
    });
    const target = insertionTarget(blocks, top, bottom);
    if (target === null) return;
    // Selection.near also handles a spot between two blocks, after an image or a table.
    const { Selection } = window.FilamentRichEditor.tiptap.pmState;
    editor.chain().command(({ tr }) => { tr.setSelection(Selection.near(tr.doc.resolve(target))); return true; }).focus(null, { scrollIntoView: false }).run();
  }
  /** Inserts a block or applies a format at the cursor, from the "/" menu or the block panel. */
  function runCommand(tool) {
    if (editor.isDestroyed) return;
    keepInsertionInView();
    const native = { paragraph:()=>editor.chain().focus().setParagraph().run(), h2:()=>editor.chain().focus().toggleHeading({level:2}).run(), h3:()=>editor.chain().focus().toggleHeading({level:3}).run(), bulletList:()=>editor.chain().focus().toggleBulletList().run(), orderedList:()=>editor.chain().focus().toggleOrderedList().run(), blockquote:()=>editor.chain().focus().toggleBlockquote().run(), horizontalRule:()=>editor.chain().focus().setHorizontalRule().run() };
    if (native[tool]) native[tool](); else { editor.commands.focus(null, { scrollIntoView: false }); wrapper.querySelector('[data-rich-editor-tool="'+tool+'"]')?.click(); }
  }
  // The block panel belongs to the writing page, so only its document editor answers it.
  const fromPanel=event=>{ if (wrapper.closest('.sq-writing-document') && event.detail?.command) runCommand(event.detail.command); };
  window.addEventListener?.('sq-insert-block',fromPanel);
  function keydown(event) {
    if (menu.hidden && event.key==='Escape' && focusMode) { toggleFocus(); return true; }
    if (menu.hidden || event.isComposing) return false;
    if (event.key==='Escape') { dismissed=editor.state.selection.from+':'+editor.state.selection.$from.parent.textBetween(0,editor.state.selection.$from.parentOffset); hide(); return true; }
    if (['ArrowDown','ArrowUp'].includes(event.key)) { active=(active+(event.key==='ArrowDown'?1:-1)+Math.max(1,matches.length))%Math.max(1,matches.length); update(); return true; }
    if (event.key==='Enter' && matches.length) { insert(active); return true; }
    return false;
  }
  const form=wrapper.closest('form') || wrapper.closest('[wire\\:id]');
  const draftChanged=event=>{if(!draftBar?.contains(event.target))autosave?.changed();};
  form?.addEventListener('input',draftChanged); form?.addEventListener('change',draftChanged);
  const outside=event=>{ if(!menu.contains(event.target) && !editor.view.dom.contains(event.target)) hide(); };
  const scroll=event=>{ if (!menu.contains(event.target)) hide(); };
  document.addEventListener('pointerdown',outside); window.addEventListener('resize',hide); document.addEventListener('scroll',scroll,true);
  const updates=createFrameScheduler(update);
  return { update:updates.schedule, keydown, toggleFocus, openInsert, changed() { statsUpdates.schedule(); window.dispatchEvent?.(new CustomEvent('sq-document-changed')); autosave?.changed(); }, destroy() { updates.destroy(); statsUpdates.destroy(); autosave?.destroy(); draftBar?.remove(); if (focusMode) toggleFocus(); menu.remove(); footer.remove(); window.removeEventListener?.('sq-insert-block',fromPanel); form?.removeEventListener('input',draftChanged); form?.removeEventListener('change',draftChanged); document.removeEventListener('pointerdown',outside); window.removeEventListener('resize',hide); window.removeEventListener('editor-saved',editorSaved); document.removeEventListener('scroll',scroll,true); } };
}

export default function solqubitEditorExtension() {
  const { Extension }=window.FilamentRichEditor.tiptap.core, { Plugin }=window.FilamentRichEditor.tiptap.pmState;
  let ui, selectionTools, selectionUpdates, pasteStatus, releaseFocus;
  return Extension.create({
    name:'solqubitEditor',
    onCreate() {
      installInlineBlocks(this.editor);
      releaseFocus=keepFocusInPlace(this.editor.view.dom);
      ui=createUI(this.editor);
      selectionTools=createSelectionTools(this.editor);
      selectionUpdates=createFrameScheduler(()=>selectionTools.update());
      pasteStatus=document.createElement('div');pasteStatus.className='sq-paste-status';pasteStatus.setAttribute('role','status');
      this.editor.view.dom.closest('[data-rich-editor-key]')?.append(pasteStatus);
    },
    onTransaction({ transaction }) { if (transaction.docChanged || transaction.selectionSet) {ui?.update();selectionUpdates?.schedule();} },
    onFocus() { ui?.update();selectionUpdates?.schedule(); },
    onUpdate() { ui?.changed(); },
    onBlur() { selectionUpdates?.schedule(); },
    onDestroy() { releaseFocus?.(); ui?.destroy();selectionUpdates?.destroy();selectionTools?.destroy();pasteStatus?.remove(); },
    addCommands() { return { solqubitInsert:()=>()=>{ ui?.openInsert(); return true; }, solqubitFocus:()=>()=>{ ui?.toggleFocus(); return true; } }; },
    addProseMirrorPlugins() {
      const editor=this.editor;
      return [new Plugin({props:{
        handleKeyDown:(_view,event)=>ui?.keydown(event)||false,
        handlePaste:(_view,event)=>{
          if(editor.isActive('codeBlock'))return false;
          const html=event.clipboardData?.getData('text/html');
          const markdown=!html?markdownHTML(event.clipboardData?.getData('text/plain')||''):null;
          if(!html&&!markdown)return false;
          const cleaned=cleanPastedHTML(html||markdown);event.preventDefault();
          editor.chain().focus().insertContent(cleaned.html).run();
          if(pasteStatus)pasteStatus.textContent=cleaned.omittedImages?'Texto pegado con formato. Añade las imágenes desde Imagen para guardarlas en tu biblioteca.':'Texto pegado con formato y adaptado al estilo del sitio.';
          return true;
        },
      }})];
    },
  });
}

