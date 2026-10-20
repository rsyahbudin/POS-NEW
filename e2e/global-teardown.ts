import { existsSync, renameSync } from 'node:fs';

export default function globalTeardown(): void {
    if (existsSync('public/hot.e2e-backup')) {
        renameSync('public/hot.e2e-backup', 'public/hot');
    }
}
