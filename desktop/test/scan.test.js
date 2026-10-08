'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

// FR-SHL-003 / INV-25: the shell holds no data-store driver, host or login secrets.
// The patterns are built from fragments so this file does not match itself.
const FORBIDDEN = [
    ['p', 'g'].join(''),
    ['postg', 'res'].join(''),
    ['my', 'sql'].join(''),
    ['maria', 'db'].join(''),
    ['sql', 'ite'].join(''),
    ['mongo', 'db'].join(''),
    ['red', 'is'].join(''),
    ['sequel', 'ize'].join(''),
    ['kn', 'ex'].join(''),
    ['pris', 'ma'].join(''),
    ['type', 'orm'].join(''),
    ['data', 'base'].join(''),
    ['DB', '_'].join(''),
    ['pass', 'word'].join(''),
    ['pass', 'wd'].join(''),
    ['cred', 'ential'].join(''),
    ['5', '432'].join(''),
].map((fragment) => new RegExp(`(^|[^a-z])${fragment}([^a-z]|$)`, 'i'));

const ROOT = path.join(__dirname, '..');
const SKIP_DIRS = new Set(['node_modules', 'dist', 'out', 'test']);
const SKIP_FILES = new Set(['package-lock.json']);

function listFiles(dir) {
    return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
        if (entry.isDirectory()) {
            return SKIP_DIRS.has(entry.name) ? [] : listFiles(path.join(dir, entry.name));
        }
        return SKIP_FILES.has(entry.name) ? [] : [path.join(dir, entry.name)];
    });
}

test('no data-store driver, host or login secret appears in /desktop', () => {
    const hits = [];
    for (const file of listFiles(ROOT)) {
        const lines = fs.readFileSync(file, 'utf8').split(/\r?\n/);
        lines.forEach((line, index) => {
            for (const pattern of FORBIDDEN) {
                if (pattern.test(line)) {
                    hits.push(`${path.relative(ROOT, file)}:${index + 1}: ${line.trim()}`);
                }
            }
        });
    }
    assert.deepEqual(hits, []);
});

test('package.json lists no data-store dependency', () => {
    const pkg = JSON.parse(fs.readFileSync(path.join(ROOT, 'package.json'), 'utf8'));
    const names = Object.keys({ ...pkg.dependencies, ...pkg.devDependencies });
    const drivers = /^(pg|pg-.*|mysql2?|mariadb|sqlite3?|better-sqlite3|mongodb|mongoose|redis|ioredis|sequelize|knex|prisma|@prisma\/.*|typeorm|drizzle-orm)$/;
    assert.deepEqual(names.filter((name) => drivers.test(name)), []);
});
