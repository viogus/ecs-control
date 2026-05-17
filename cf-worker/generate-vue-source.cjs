const fs = require('fs');
let content = fs.readFileSync('static/vue.global.prod.js', 'utf8');
// Escape for JS template literal: backslash, backtick, dollar-brace
content = content.replace(/\\/g, '\\\\').replace(/`/g, '\\`').replace(/\$\{/g, '\\${');
fs.writeFileSync('src/vue-source.ts', 'export const VUE_SOURCE: string = `' + content + '`;\n');
console.log('Written from', content.length, 'chars');
