import {copyFile,mkdir} from 'node:fs/promises';
for(const [name,files] of Object.entries({ckeditor5:['dist/browser/ckeditor5.umd.js','dist/browser/ckeditor5.css','dist/translations/fa.umd.js','LICENSE.md','COPYING.GPL'],dompurify:['dist/purify.min.js','LICENSE']})){
 const target=`public/assets/${name==='dompurify'?'purify':'ckeditor'}`;
 await mkdir(target,{recursive:true});
 for(const file of files)await copyFile(`node_modules/${name}/${file}`,`${target}/${file.split('/').pop()}`);
}
