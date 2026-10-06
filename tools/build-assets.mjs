import {copyFile,mkdir} from 'node:fs/promises';
for(const [name,files] of Object.entries({quill:['dist/quill.js','dist/quill.snow.css','LICENSE'],dompurify:['dist/purify.min.js','LICENSE']})){
 const target=`public/assets/${name==='dompurify'?'purify':name}`;
 await mkdir(target,{recursive:true});
 for(const file of files)await copyFile(`node_modules/${name}/${file}`,`${target}/${file.split('/').pop()}`);
}
