const fs = require('fs');
const path = require('path');

const root = path.join(__dirname, '..');

const copies = [
    ['node_modules/flyonui/flyonui.js', 'public/vendor/flyonui/flyonui.js'],
    ['node_modules/notyf/notyf.min.js', 'public/vendor/notyf/notyf.min.js'],
    ['node_modules/notyf/notyf.min.css', 'public/vendor/notyf/notyf.min.css'],
    ['node_modules/lodash/lodash.min.js', 'public/vendor/lodash/lodash.min.js'],
    ['node_modules/apexcharts/dist/apexcharts.min.js', 'public/vendor/apexcharts/apexcharts.min.js'],
];

for (const [from, to] of copies) {
    const src = path.join(root, from);
    const dest = path.join(root, to);

    if (!fs.existsSync(src)) {
        console.error(`Missing source file: ${from}`);
        process.exit(1);
    }

    fs.mkdirSync(path.dirname(dest), { recursive: true });
    fs.copyFileSync(src, dest);
    console.log(`Copied ${from} -> ${to}`);
}
