const escape = value => String(value).replace(/[&<>"']/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[character]));

export function markdownHTML(text) {
  if (!/(^|\n)(?:#{1,6} |[-*+] |\d+\. |> |```|---\s*$)|\*\*[^*]+\*\*/m.test(text) || text.length > 200000) return null;
  const inline = value => {
    const code=[];
    return escape(value).replace(/`([^`]+)`/g,(_,text)=>'\u0000'+(code.push('<code>'+text+'</code>')-1)+'\u0000')
      .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>').replace(/__([^_]+)__/g, '<strong>$1</strong>').replace(/\*([^*\n]+)\*/g, '<em>$1</em>').replace(/~~([^~]+)~~/g, '<s>$1</s>').replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2">$1</a>')
      .replace(/\u0000(\d+)\u0000/g,(_,index)=>code[index]||'');
  };
  const lines = text.replace(/\r\n?/g, '\n').split('\n'), result=[];
  let paragraph=[], list=null, quote=[], code=null, language='';
  const flushParagraph=()=>{if(paragraph.length){result.push('<p>'+inline(paragraph.join(' '))+'</p>');paragraph=[];}};
  const flushList=()=>{if(list){result.push('<'+list.type+'>'+list.items.map(item=>'<li><p>'+inline(item)+'</p></li>').join('')+'</'+list.type+'>');list=null;}};
  const flushQuote=()=>{if(quote.length){result.push('<blockquote><p>'+inline(quote.join(' '))+'</p></blockquote>');quote=[];}};
  const flush=()=>{flushParagraph();flushList();flushQuote();};
  for(const line of lines) {
    if(code!==null) { if(/^\s*```/.test(line)){result.push('<pre><code class="language-'+escape(language)+'">'+escape(code.join('\n'))+'</code></pre>');code=null;}else code.push(line);continue; }
    const fence=/^\s*```([\w-]*)/.exec(line); if(fence){flush();code=[];language=fence[1];continue;}
    if(!line.trim()){flush();continue;}
    const heading=/^(#{1,6})\s+(.+)$/.exec(line); if(heading){flush();const level=heading[1].length<=2?2:3;result.push('<h'+level+'>'+inline(heading[2])+'</h'+level+'>');continue;}
    if(/^\s*(?:---+|\*\*\*+)\s*$/.test(line)){flush();result.push('<hr>');continue;}
    const item=/^\s*(?:([-*+])|\d+[.)])\s+(.+)$/.exec(line); if(item){flushParagraph();flushQuote();const type=item[1]?'ul':'ol';if(list&&list.type!==type)flushList();list??={type,items:[]};list.items.push(item[2]);continue;}
    const citation=/^>\s?(.*)$/.exec(line); if(citation){flushParagraph();flushList();quote.push(citation[1]);continue;}
    flushList();flushQuote();paragraph.push(line);
  }
  if(code!==null)result.push('<pre><code>'+escape(code.join('\n'))+'</code></pre>');
  flush(); return result.join('');
}

export function cleanPastedHTML(html) {
  const document = new DOMParser().parseFromString(html, 'text/html');
  const omittedImages = document.querySelectorAll('img').length;
  document.querySelectorAll('script,style,iframe,object,embed,svg,math,button,input,textarea,select,nav,form,img,video,audio').forEach(element=>element.remove());
  const allowed = new Set(['P','BR','H2','H3','STRONG','B','EM','I','U','S','CODE','PRE','BLOCKQUOTE','UL','OL','LI','A','HR','TABLE','THEAD','TBODY','TFOOT','TR','TH','TD']);
  for(const element of [...document.body.querySelectorAll('*')].reverse()) {
    if(['H1','H4','H5','H6'].includes(element.tagName)) {
      const replacement=document.createElement(element.tagName==='H1'?'h2':'h3');replacement.append(...element.childNodes);element.replaceWith(replacement);continue;
    }
    if(!allowed.has(element.tagName)) {
      if(['DIV','ARTICLE','SECTION'].includes(element.tagName) && !element.querySelector('p,h2,h3,ul,ol,pre,blockquote,table') && element.textContent.trim()) {
        const paragraph=document.createElement('p');paragraph.append(...element.childNodes);element.replaceWith(paragraph);
      } else element.replaceWith(...element.childNodes);
      continue;
    }
    const href=element.tagName==='A'?element.getAttribute('href'):null;
    const start=element.tagName==='OL'?element.getAttribute('start'):null;
    for(const attribute of [...element.attributes]) element.removeAttribute(attribute.name);
    if(href && /^(?:https?:\/\/|mailto:)/i.test(href))element.setAttribute('href',href);
    if(start && /^\d{1,4}$/.test(start))element.setAttribute('start',start);
  }
  return {html:document.body.innerHTML, omittedImages};
}

export function topLevelBlock(state) {
  const position=state.selection.from; let result=null;
  state.doc.forEach((node,offset,index)=>{if(position>=offset&&position<offset+node.nodeSize&&!result)result={node,offset,index};});
  return result;
}

export function moveDocumentBlock(editor, position, direction) {
  const blocks=[];editor.state.doc.forEach((node,offset)=>blocks.push({node,offset}));
  const index=blocks.findIndex(block=>block.offset===position), target=index+direction;
  if(index<0 || target<0 || target>=blocks.length)return false;
  const current=blocks[index], adjacent=blocks[target], from=Math.min(current.offset,adjacent.offset);
  const content=direction<0?[current.node,adjacent.node]:[adjacent.node,current.node];
  const {Fragment}=window.FilamentRichEditor.tiptap.pmModel;
  const {Selection}=window.FilamentRichEditor.tiptap.pmState;
  const transaction=editor.state.tr.replaceWith(from,from+current.node.nodeSize+adjacent.node.nodeSize,Fragment.fromArray(content));
  const next=direction<0?from:from+adjacent.node.nodeSize;
  transaction.setSelection(Selection.near(transaction.doc.resolve(next+1)));
  editor.view.dispatch(transaction.scrollIntoView());return true;
}

const inlineTypes=new Set(['notice','code','details','list','links']);
export function installInlineBlocks(editor) {
  const original=editor.view.props.nodeViews||{};
  if(!original.customBlock)return;
  const views={...original,customBlock:(node,view,getPos,...rest)=>inlineTypes.has(node.attrs.id)?inlineBlockView(editor,node,view,getPos):original.customBlock(node,view,getPos,...rest)};
  editor.setOptions({editorProps:{...editor.options.editorProps,nodeViews:views}});
}

function inlineBlockView(editor,initial,view,getPos) {
  let node=initial;
  const dom=document.createElement('div');dom.className='sq-inline-block';dom.dataset.type='customBlock';dom.dataset.id=node.attrs.id;dom.contentEditable='false';
  const controls=document.createElement('div');controls.className='sq-block-controls';controls.setAttribute('role','toolbar');controls.setAttribute('aria-label','Organizar elemento');
  const action=(label,handler)=>{const button=document.createElement('button');button.type='button';button.textContent=label;button.addEventListener('click',handler);controls.append(button);};
  action('↑',()=>moveDocumentBlock(editor,getPos(),-1));controls.lastChild.setAttribute('aria-label','Mover elemento arriba');
  action('↓',()=>moveDocumentBlock(editor,getPos(),1));controls.lastChild.setAttribute('aria-label','Mover elemento abajo');
  action('Duplicar',()=>editor.chain().insertContentAt(getPos()+node.nodeSize,node.toJSON()).run());
  action('+ Párrafo',()=>editor.chain().focus().insertContentAt(getPos()+node.nodeSize,{type:'paragraph'}).run());
  action('Eliminar',()=>editor.chain().focus().deleteRange({from:getPos(),to:getPos()+node.nodeSize}).run());
  const surface=document.createElement('div');surface.className='sq-inline-surface';dom.append(controls,surface);
  const bindings=[];
  function commit(key,value) {
    const position=getPos(); if(typeof position!=='number')return;
    const current=view.state.doc.nodeAt(position);if(!current||current.type.name!=='customBlock')return;
    view.dispatch(view.state.tr.setNodeMarkup(position,undefined,{...current.attrs,config:{...current.attrs.config,[key]:value},preview:''}));
  }
  function resize(input) {if(input.tagName==='TEXTAREA'){input.style.height='auto';input.style.height=Math.max(input.closest('.sq-inline-row')?26:50,input.scrollHeight)+'px';}}
  function field(key,label,{multiline=false,parent=surface,onChange=null,value=null}={}) {
    const input=document.createElement(multiline?'textarea':'input');if(!multiline)input.type='text';else input.rows=parent===surface?2:1;
    input.setAttribute('aria-label',label);input.placeholder=label;input.value=value??node.attrs.config?.[key]??'';
    input.addEventListener('input',()=>{onChange?onChange(input.value):commit(key,input.value);resize(input);});
    parent.append(input);if(!onChange)bindings.push({input,key});queueMicrotask(()=>resize(input));return input;
  }
  if(node.attrs.id==='notice') {
    const tone=document.createElement('select');tone.className='sq-inline-tone';tone.setAttribute('aria-label','Tipo de aviso');
    for(const [value,label] of [['info','Información'],['note','Nota'],['warning','Advertencia']]){const option=document.createElement('option');option.value=value;option.textContent=label;tone.append(option);}
    tone.value=node.attrs.config?.tone||'info';tone.addEventListener('change',()=>commit('tone',tone.value));surface.append(tone);bindings.push({input:tone,key:'tone'});
    field('title','Título del aviso (opcional)');field('text','Escribe tu aviso…',{multiline:true});
  } else if(node.attrs.id==='code') {
    const input=field('text','Escribe o pega tu código…',{multiline:true});input.spellcheck=false;
    input.addEventListener('keydown',event=>{if(event.key==='Tab'){event.preventDefault();const start=input.selectionStart,end=input.selectionEnd;input.setRangeText('  ',start,end,'end');input.dispatchEvent(new Event('input',{bubbles:true}));}});
  } else if(node.attrs.id==='details') {
    field('title','Pregunta o título');field('text','Escribe la respuesta…',{multiline:true});
  } else {
    if(node.attrs.id==='list')field('title','Título de la lista (opcional)');
    const rows=document.createElement('div');surface.append(rows);
    let signature='';
    const renderRows=()=>{
      const items=node.attrs.config?.items||[], next=JSON.stringify(items);if(signature===next)return;signature=next;
      if(rows.children.length===items.length){
        items.forEach((item,index)=>{
          const inputs=rows.children[index].querySelectorAll('input,textarea');
          const values=node.attrs.id==='list'?[item.text||'']:[item.label||'',item.url||''];
          inputs.forEach((input,fieldIndex)=>{if(input.value!==values[fieldIndex])input.value=values[fieldIndex];resize(input);});
        });
        return;
      }
      rows.replaceChildren();
      items.forEach((item,index)=>{
        const row=document.createElement('div');row.className=node.attrs.id==='list'?'sq-inline-row':'sq-inline-link-row';rows.append(row);
        const editItem=(key,value)=>commit('items',(node.attrs.config?.items||[]).map((entry,i)=>i===index?{...entry,[key]:value}:entry));
        if(node.attrs.id==='list'){const bullet=document.createElement('span');bullet.textContent=node.attrs.config?.ordered?(index+1)+'.':'•';row.append(bullet);field('text','Texto del elemento',{multiline:true,parent:row,value:item.text||'',onChange:value=>editItem('text',value)});}
        else {field('label','Texto del enlace',{parent:row,value:item.label||'',onChange:value=>editItem('label',value)});field('url','https://…',{parent:row,value:item.url||'',onChange:value=>editItem('url',value)});}
        const remove=document.createElement('button');remove.type='button';remove.textContent='×';remove.setAttribute('aria-label','Eliminar elemento '+(index+1));remove.addEventListener('click',()=>commit('items',(node.attrs.config.items||[]).filter((_,i)=>i!==index)));row.append(remove);
      });
    };
    bindings.push({render:renderRows});renderRows();
    const add=document.createElement('button');add.type='button';add.className='sq-inline-add';add.textContent=node.attrs.id==='list'?'+ Añadir elemento':'+ Añadir enlace';add.addEventListener('click',()=>commit('items',[...(node.attrs.config.items||[]),node.attrs.id==='list'?{text:''}:{label:'',url:''}]));surface.append(add);
  }
  dom.addEventListener('keydown',event=>{
    if((event.ctrlKey||event.metaKey)&&['z','y'].includes(event.key.toLowerCase())){event.preventDefault();event.stopPropagation();(event.key.toLowerCase()==='y'||event.shiftKey)?editor.commands.redo():editor.commands.undo();}
  });
  dom.dataset.tone=node.attrs.config?.tone||'info';
  return {dom,stopEvent:event=>dom.contains(event.target)&&!['dragstart','dragover','drop'].includes(event.type),ignoreMutation:()=>true,
    update(next){if(next.type!==node.type||next.attrs.id!==node.attrs.id)return false;node=next;dom.dataset.tone=node.attrs.config?.tone||'info';for(const binding of bindings){if(binding.render){binding.render();continue;}const value=node.attrs.config?.[binding.key]??'';if(binding.input.value!==String(value))binding.input.value=value;resize(binding.input);}return true;},
    selectNode(){dom.classList.add('ProseMirror-selectednode');},deselectNode(){dom.classList.remove('ProseMirror-selectednode');},
  };
}

export function createSelectionTools(editor) {
  const toolbar=document.createElement('div');toolbar.className='sq-selection-tools';toolbar.hidden=true;toolbar.setAttribute('role','toolbar');toolbar.setAttribute('aria-label','Organizar contenido');document.body.append(toolbar);
  let block=null,signature='';
  const add=(label,handler)=>{const button=document.createElement('button');button.type='button';button.textContent=label;button.addEventListener('mousedown',event=>event.preventDefault());button.addEventListener('click',handler);toolbar.append(button);};
  function update() {
    block=topLevelBlock(editor.state);
    if(!block||!editor.isFocused||!editor.state.selection.empty||block.node.type.name==='customBlock'){toolbar.hidden=true;return;}
    const element=editor.view.nodeDOM(block.offset);if(!(element instanceof HTMLElement)){toolbar.hidden=true;return;}
    const bounds=element.getBoundingClientRect();if(bounds.bottom<170||bounds.top>innerHeight-40){toolbar.hidden=true;return;}
    const next=block.node.type.name;
    if(signature!==next){signature=next;toolbar.replaceChildren();
      add('↑',()=>moveDocumentBlock(editor,block.offset,-1));toolbar.lastChild.setAttribute('aria-label','Mover contenido arriba');
      add('↓',()=>moveDocumentBlock(editor,block.offset,1));toolbar.lastChild.setAttribute('aria-label','Mover contenido abajo');
      add('Duplicar',()=>editor.chain().focus().insertContentAt(block.offset+block.node.nodeSize,block.node.toJSON()).run());
      add('+ Párrafo',()=>editor.chain().focus().insertContentAt(block.offset+block.node.nodeSize,{type:'paragraph'}).run());
      if(next==='table'){add('+ Fila',()=>editor.chain().focus().addRowAfter().run());add('+ Columna',()=>editor.chain().focus().addColumnAfter().run());add('− Fila',()=>editor.chain().focus().deleteRow().run());add('− Columna',()=>editor.chain().focus().deleteColumn().run());}
      add('Eliminar',()=>editor.chain().focus().deleteRange({from:block.offset,to:block.offset+block.node.nodeSize}).run());
    }
    toolbar.hidden=false;toolbar.style.left=Math.max(8,Math.min(bounds.left,innerWidth-toolbar.offsetWidth-8))+'px';toolbar.style.top=Math.max(154,bounds.top-toolbar.offsetHeight-6)+'px';
  }
  const hide=()=>{toolbar.hidden=true;};document.addEventListener('scroll',hide,true);window.addEventListener('resize',hide);
  return {update,destroy(){toolbar.remove();document.removeEventListener('scroll',hide,true);window.removeEventListener('resize',hide);}};
}
