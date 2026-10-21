import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const source = path.join(root, '../dist');
const context = vm.createContext({ document: { querySelector() { return {}; } } });
vm.runInContext(fs.readFileSync(path.join(source, 'article-content.js'), 'utf8'), context);
vm.runInContext(fs.readFileSync(path.join(source, 'app.js'), 'utf8').split('function guideCard(')[0], context);
vm.runInContext(fs.readFileSync(path.join(source, 'article-renderer.js'), 'utf8'), context);
const demo = JSON.parse(vm.runInContext('JSON.stringify(posts.map(post=>({...post,cover:coverFor(post),sections:getArticleSections(post),files:attachmentsFor(post)})))', context));
const inline = value => value.replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])).replace(/`([^`]+)`/g,'<code>$1</code>').replace(/\*\*([^*]+)\*\*/g,'<strong>$1</strong>');
function convert(block) {
  switch (block.type) {
    case 'paragraph': return [{type:'paragraph',data:{html:`<p>${inline(block.text)}</p>`}}];
    case 'code': case 'output': return [{type:block.type,data:{text:block.code,label:block.label,language:block.language || 'text'}}];
    case 'table': return [{type:'table',data:{columns:block.headers,rows:block.rows.map(row=>({cells:row.join(' | ')}))}}];
    case 'callout': return [{type:'notice',data:{title:block.title,text:block.text,tone:block.tone==='success'?'note':block.tone}}];
    case 'image': return [{type:'image',data:{asset:block.src.replace('./assets/',''),alt:block.alt,caption:block.caption}}];
    case 'list': return [{type:'list',data:{title:block.title,ordered:false,items:block.items.map(text=>({text}))}}];
    case 'details': return block.items.map(data=>({type:'details',data}));
    case 'links': return [{type:'links',data:{items:block.items.map(item=>({label:item.title,url:item.url}))}}];
    case 'video': return [{type:'video',data:{url:block.url,caption:`${block.title} · ${block.author}`}}];
    default: throw Error(`Unhandled demo block: ${block.type}`);
  }
}
const posts = demo.map(post => ({ slug:post.id,title:post.short||post.title,excerpt:post.description,category:post.category,tags:post.tags,tone:post.cover.tone,cover:`cartoon/${post.cover.image}.webp`,reading_minutes:parseInt(post.time),difficulty:post.level==='Inicial'?'Básico':post.level,status:post.upcoming?'draft':'published',featured:post.id==='nagios',content:[{type:'paragraph',data:{html:`<p>${inline(post.intro)}</p>`}},...post.sections.flatMap(section=>[{type:'heading',data:{text:section.title,level:'h2'}},...section.blocks.flatMap(convert)]),{type:'heading',data:{text:'Archivos de apoyo',level:'h2'}},...post.files.map(file=>({type:'file',data:{label:file.title,filename:file.filename,contents:file.content}}))] }));
fs.mkdirSync(path.join(root,'core/database/seeders/data'),{recursive:true});
fs.writeFileSync(path.join(root,'core/database/seeders/data/demo.json'),JSON.stringify(posts,null,2)+'\n');
const icons = vm.runInContext('JSON.stringify(icons)',context);
fs.writeFileSync(path.join(root,'core/config/icons.php'),`<?php\nreturn json_decode(<<<'JSON'\n${icons}\nJSON, true);\n`);
console.log(`Exported ${posts.length} complete demo entries and original icons.`);
